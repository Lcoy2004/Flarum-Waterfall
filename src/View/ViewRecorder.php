<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\View;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Queue;
use Lcoy\Waterfall\Jobs\FlushViewDeltasJob;
use Lcoy\Waterfall\Jobs\RecalculateScoresJob;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Psr\Log\LoggerInterface;

/**
 * The single authority for what a "view" is and how it is recorded.
 *
 * Two HTTP surfaces report views — the batch endpoint the lightbox posts to,
 * and the per-image `/{id}/view` endpoint kept for older clients and other
 * consumers. Both must count identically (one per IP per image per minute,
 * published only, same coalesced score recalculation) or the recommendation
 * score would read different totals depending on which route a client called.
 * The rule lives here once; the endpoints are thin HTTP adapters.
 *
 * Counting is throttled per IP because the counter feeds the recommendation
 * score: an unthrottled client could inflate it. cache add() is atomic (Redis
 * SETNX, and a transaction under the database store), so parallel requests
 * cannot double-count.
 *
 * The throttle is a guard, never a precondition: with the cache unreachable
 * a view is counted rather than refused, because a cache blip should not take
 * the endpoint down — see claimWindow().
 */
class ViewRecorder
{
    /**
     * Upper bound on one batch. The lightbox never sends more than the set it
     * is showing; a hand-written request must not be able to make the server
     * load an unbounded number of rows.
     */
    public const MAX_IDS = 100;

    /**
     * How long the batch path may leave a view in the delta buffer before it is
     * moved into the counters, in seconds.
     *
     * A view used to take the owning image's and set's row locks immediately.
     * A popular image is what everyone contends on, so the lock — not the
     * arithmetic — was the cost. Short enough that a reader never sees a badly
     * stale badge, long enough that a burst of lightbox reports coalesces into
     * a single locked update.
     */
    public const FLUSH_DELAY_SECONDS = 20;

    /**
     * Cache key that deduplicates the flush scheduling: only the first view
     * report in the window arms a job, so a burst of reports arms one flush
     * rather than one per request.
     */
    public const FLUSH_FLAG = 'lcoy-waterfall.views.flush';

    /**
     * How long the flag may live. It is normally forgotten by the flush job as
     * soon as it starts; the TTL only bounds how long a push failure (or a
     * worker restart) can hold the flag, since it expires on its own and the
     * next report arms another flush.
     */
    public const FLUSH_FLAG_TTL = 60;

    public function __construct(
        protected CacheRepository $cache,
        protected Queue $queue,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Record one view for each published image in $imageIds, applying the
     * per-IP window, moving the owning sets' counters, and queueing the
     * coalesced score recalculation.
     *
     * The window is what the caller asked to count; the return value is what
     * reached the rows. The two differ only when an image was deleted between
     * this read and the locked one, which is what the caller should report.
     *
     * @param  int[]  $imageIds
     * @return int[] the subset of $imageIds that actually gained a view
     */
    public function record(array $imageIds, string $ip): array
    {
        [$images, $counted] = $this->claimViews($imageIds, $ip);

        if ($counted === []) {
            return [];
        }

        $applied = $this->applyCounters(array_keys($counted));

        // Queueing is decided by the window rather than by what was applied:
        // the job recomputes the score from the counters, so a row that did not
        // survive is harmless there, and skipping it would just delay the
        // recalculation for the rows that did.
        $this->queueRecalculations($images, $counted);

        return $applied;
    }

    /**
     * Record one view for each published image in $imageIds without taking the
     * counter rows' locks: the views are added to the delta buffer (see
     * FlushViewDeltasJob) and moved into the counters by a delayed job.
     *
     * What is counted is identical to record() — same per-IP window, same
     * published-only rule — because both go through claimViews(). Only the
     * write differs. The return value is therefore what was accepted for
     * counting; it can still be dropped at flush time if the image was deleted
     * in between (there is no row left to carry it), which is the same outcome
     * the synchronous path reports.
     *
     * The cache is what deduplicates the flush, so when it is unreachable this
     * falls back to the synchronous path: a cache outage means no dedupe, and
     * one job per report would be worse than the locks the buffer removes.
     *
     * @param  int[]  $imageIds
     * @return int[] the ids whose view was accepted
     */
    public function recordDeferred(array $imageIds, string $ip): array
    {
        [$images, $counted] = $this->claimViews($imageIds, $ip);

        if ($counted === []) {
            return [];
        }

        $ids = array_keys($counted);

        $armed = $this->claimFlushFlag();

        // The cache is unreachable, so a flush cannot be deduplicated: count
        // synchronously instead of buffering, which is the pre-buffer path.
        if ($armed === null) {
            $applied = $this->applyCounters($ids);
            $this->queueRecalculations($images, $counted);

            return $applied;
        }

        // Buffer before enqueueing: under the sync queue driver the push runs
        // the job inline, and it must find the delta it was armed for.
        $this->bufferCounters($ids);

        if ($armed) {
            $this->enqueueFlush();
        }

        return $ids;
    }

    /**
     * Apply the per-IP window to the published images of a batch — the part of
     * counting that must not differ between the synchronous and buffered paths.
     *
     * @param  int[]  $imageIds
     * @return array{0: iterable<WaterfallImage>, 1: array<int, bool>}
     */
    protected function claimViews(array $imageIds, string $ip): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $imageIds),
            fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [[], []];
        }

        $images = WaterfallImage::query()
            ->whereIn('id', array_slice($ids, 0, self::MAX_IDS))
            ->where('status', WaterfallImage::STATUS_PUBLISHED)
            ->get();

        // The client sends the ids once each; the per-IP window is applied here
        // because it is the server that has to hold against a scripted client.
        $ipKey = md5($ip);

        $counted = [];

        foreach ($images as $image) {
            if (! $this->claimWindow('lcoy-waterfall.view.'.$ipKey.'.'.$image->id)) {
                continue;
            }

            $counted[$image->id] = true;
        }

        return [$images, $counted];
    }

    /**
     * Add one to each image's buffered view delta.
     *
     * No transaction is needed: insertOrIgnore() is one statement that only
     * creates the row when it is absent, and increment() is one statement that
     * is atomic on its own. Ordering the ids keeps every request touching the
     * buffer's rows in the same order, which is what stops two batches from
     * deadlocking on overlapping ids.
     *
     * @param  int[]  $imageIds
     */
    protected function bufferCounters(array $imageIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $imageIds)));
        sort($ids);

        $now = Carbon::now();
        $table = (new WaterfallImage)->getConnection()->table('waterfall_view_deltas');

        $table->insertOrIgnore(array_map(
            fn (int $id): array => ['image_id' => $id, 'delta' => 0, 'updated_at' => $now],
            $ids
        ));

        $table->whereIn('image_id', $ids)->increment('delta', 1, ['updated_at' => $now]);
    }

    /**
     * Claim this window's right to arm the flush.
     *
     * @return bool|null true when this report armed the flush, false when one
     *                   was already armed, null when the cache could not be
     *                   reached (the caller must count synchronously then,
     *                   because buffering without a way to dedupe the flush
     *                   would leave the counters behind).
     */
    protected function claimFlushFlag(): ?bool
    {
        try {
            // add() is atomic (SETNX / transactional under the database store):
            // exactly one report per window arms the job.
            return (bool) $this->cache->add(self::FLUSH_FLAG, 1, self::FLUSH_FLAG_TTL);
        } catch (\Throwable $e) {
            $this->logger->warning('lcoy-waterfall: the view flush could not be armed ({message}); counting synchronously', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Queue the delayed flush. A failure here does not lose the deltas: they
     * are already in the table, and the flag expires on its own
     * (FLUSH_FLAG_TTL), after which the next report arms another flush — the
     * outage delays the counters rather than dropping them.
     */
    protected function enqueueFlush(): void
    {
        try {
            $this->queue->later(self::FLUSH_DELAY_SECONDS, new FlushViewDeltasJob());
        } catch (\Throwable $e) {
            $this->logger->error('lcoy-waterfall: the view flush could not be queued ({message}); deltas stay buffered', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Claim the short-lived window the given key names, answering true when
     * the caller may proceed.
     *
     * A window is a guard against a client inflating a counter, so the only
     * two answers that mean anything are "this is the first one" and "this one
     * is a repeat". A cache that cannot be reached is neither, and refusing
     * there would turn a cache blip into an endpoint that 500s for as long as
     * the outage lasts — so the window opens and the failure is logged, since
     * it means every window is open until it clears.
     */
    protected function claimWindow(string $key): bool
    {
        try {
            return (bool) $this->cache->add($key, 1, WaterfallImage::VIEW_WINDOW_SECONDS);
        } catch (\Throwable $e) {
            $this->logger->warning('lcoy-waterfall: {key} could not be checked ({message}); counting without it', [
                'key' => $key,
                'message' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Move the counters in as few statements as the invariants allow.
     *
     * The locks are taken in the order the single-image path used to take them
     * — the set's row first, then the image's — because the two orders are the
     * two halves of a deadlock: a batch holding image locks while it waits for
     * a set lock, against a single view that holds that set lock while it
     * waits for the image. Both paths are live at once (a browser running an
     * older bundle still posts single views), and a deadlock here costs the
     * whole batch: the per-IP window is consumed above, so the views are gone
     * rather than merely delayed.
     *
     * Sets are locked in id order so two batches sharing sets cannot deadlock
     * each other either.
     *
     * @param  int[]  $imageIds  the images that gained a view (already
     *                           de-duplicated and window-checked)
     * @return int[] the ids that were still there to gain it
     */
    protected function applyCounters(array $imageIds): array
    {
        $connection = (new WaterfallImage)->getConnection();

        // The sets the first read put them in, locked in ascending id order.
        $setIds = WaterfallImage::query()
            ->whereIn('id', $imageIds)
            ->whereNotNull('set_id')
            ->pluck('set_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $connection->transaction(function () use ($imageIds, $setIds): array {
            $lockedSets = [];

            foreach ($setIds as $setId) {
                // A set that vanished (its last image was deleted) is skipped,
                // exactly as the single view would skip it.
                if (WaterfallSet::query()->lockForUpdate()->find($setId) !== null) {
                    $lockedSets[$setId] = true;
                }
            }

            // Read the images again under their own locks: what gains a view
            // has to be what this transaction can still see. An image deleted
            // since the first read would otherwise add one to its set without
            // gaining one itself — a total that stays ahead of the rows it
            // sums until the next aggregate sync.
            $surviving = WaterfallImage::query()
                ->whereIn('id', $imageIds)
                ->where('status', WaterfallImage::STATUS_PUBLISHED)
                ->lockForUpdate()
                ->get(['id', 'set_id']);

            if ($surviving->isEmpty()) {
                return [];
            }

            // One statement for the whole batch — every surviving row gains
            // exactly one.
            WaterfallImage::query()->whereIn('id', $surviving->modelKeys())->increment('views_count');

            $deltas = [];

            foreach ($surviving as $image) {
                if ($image->set_id && isset($lockedSets[$image->set_id])) {
                    $deltas[$image->set_id] = ($deltas[$image->set_id] ?? 0) + 1;
                }
            }

            foreach ($deltas as $setId => $delta) {
                WaterfallSet::query()->whereKey($setId)->increment('views_count', $delta);
            }

            return $surviving->modelKeys();
        });
    }

    /**
     * Ask for the score recalculation a view implies — still at most one per
     * image per minute, but delivered as a single job for the whole batch
     * rather than one job per image (see RecalculateScoresJob).
     *
     * @param  iterable<WaterfallImage>  $images
     * @param  array<int, bool>  $counted
     */
    protected function queueRecalculations(iterable $images, array $counted): void
    {
        $ids = [];

        foreach ($images as $image) {
            if (isset($counted[$image->id])) {
                $ids[] = $image->id;
            }
        }

        $this->queueScoreRecalculation($ids);
    }

    /**
     * Queue the coalesced score recalculation for a set of image ids.
     *
     * Public because the flush job is the one that knows which counters moved:
     * a buffered view is not scored until its delta reaches the row, so the
     * recalculation is queued from there rather than at report time. The
     * per-image window keeps the "at most one recompute per image per minute"
     * rule that the synchronous path has always had.
     *
     * @param  int[]  $imageIds
     */
    public function queueScoreRecalculation(array $imageIds): void
    {
        $recalculate = [];

        foreach (array_unique(array_map('intval', $imageIds)) as $id) {
            if ($id > 0 && $this->claimWindow('lcoy-waterfall.score.'.$id)) {
                $recalculate[] = $id;
            }
        }

        if ($recalculate !== []) {
            $this->queue->push(new RecalculateScoresJob($recalculate));
        }
    }
}
