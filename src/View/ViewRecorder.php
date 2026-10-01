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

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Queue;
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
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $imageIds),
            fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
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
        $recalculate = [];

        foreach ($images as $image) {
            if (isset($counted[$image->id]) && $this->claimWindow('lcoy-waterfall.score.'.$image->id)) {
                $recalculate[] = $image->id;
            }
        }

        if ($recalculate !== []) {
            $this->queue->push(new RecalculateScoresJob($recalculate));
        }
    }
}
