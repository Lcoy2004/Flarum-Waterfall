<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Jobs;

use Carbon\Carbon;
use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Queue;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\View\ViewRecorder;
use Psr\Log\LoggerInterface;

/**
 * Moves the buffered view deltas into the image and set counters.
 *
 * The batch view endpoint no longer updates a counter per report: it adds to
 * the `waterfall_view_deltas` row of each viewed image (ViewRecorder::
 * bufferCounters), and this job — armed once per window by the first report —
 * does the locked work in one pass. A burst of reports therefore costs one
 * locked update per image instead of one per view, which is what a popular
 * image contends on.
 *
 * The deltas are zeroed rather than deleted after being applied. A report that
 * is already waiting on the row lock when the flush commits must not have its
 * increment removed by a DELETE it could no longer see: reusing the row lets
 * the waiting UPDATE land on the zeroed row and count normally.
 */
class FlushViewDeltasJob extends AbstractJob
{
    /**
     * Rows moved per transaction. Each delivery works in bounded chunks so the
     * locks it holds are short-lived and a large backlog does not turn into one
     * long transaction that blocks every view report for its duration.
     */
    protected const CHUNK = 500;

    /**
     * Chunks per delivery. A backlog longer than CHUNK * MAX_CHUNKS is carried
     * over to a fresh delivery rather than held in one run.
     */
    protected const MAX_CHUNKS = 10;

    public function handle(
        ViewRecorder $recorder,
        CacheRepository $cache,
        Queue $queue,
        LoggerInterface $logger
    ): void {
        // Release the scheduling flag before doing any work: a flush that starts
        // late must not keep the next round from being armed. If this fails the
        // flag expires on its own (ViewRecorder::FLUSH_FLAG_TTL).
        try {
            $cache->forget(ViewRecorder::FLUSH_FLAG);
        } catch (\Throwable $e) {
            $logger->warning('lcoy-waterfall: the view flush flag could not be cleared ({message})', [
                'message' => $e->getMessage(),
            ]);
        }

        $connection = (new WaterfallImage)->getConnection();

        for ($chunk = 0; $chunk < self::MAX_CHUNKS; $chunk++) {
            $result = $connection->transaction(function () use ($connection): ?array {
                $rows = $connection->table('waterfall_view_deltas')
                    ->where('delta', '>', 0)
                    ->orderBy('image_id')
                    ->limit(self::CHUNK)
                    ->lockForUpdate()
                    ->get(['image_id', 'delta']);

                if ($rows->isEmpty()) {
                    return null; // nothing left to flush
                }

                $deltas = [];

                foreach ($rows as $row) {
                    $deltas[(int) $row->image_id] = (int) $row->delta;
                }

                $imageIds = array_keys($deltas);

                // The same lock order the synchronous path takes (sets first,
                // then images): a flush and a single-image view can both be
                // running, and the opposite order is the two halves of a
                // deadlock. Sets are locked in id order for the same reason.
                $setIds = WaterfallImage::query()
                    ->whereIn('id', $imageIds)
                    ->whereNotNull('set_id')
                    ->pluck('set_id')
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $lockedSets = [];

                foreach ($setIds as $setId) {
                    $setId = (int) $setId;

                    // A set deleted since the report is skipped, exactly as the
                    // synchronous path skips it.
                    if (WaterfallSet::query()->lockForUpdate()->find($setId) !== null) {
                        $lockedSets[$setId] = true;
                    }
                }

                // Only rows that are still published gain a view, matching what
                // the synchronous path counts: the rest are dropped with the
                // rest of the chunk.
                $surviving = WaterfallImage::query()
                    ->whereIn('id', $imageIds)
                    ->where('status', WaterfallImage::STATUS_PUBLISHED)
                    ->lockForUpdate()
                    ->get(['id', 'set_id']);

                // Rows that share a delta are moved by one statement; a lightbox
                // page is almost always all ones, so the whole page is one
                // UPDATE rather than one per image.
                $byDelta = [];

                foreach ($surviving as $image) {
                    $delta = $deltas[(int) $image->id] ?? 0;

                    if ($delta > 0) {
                        $byDelta[$delta][] = (int) $image->id;
                    }
                }

                foreach ($byDelta as $delta => $ids) {
                    WaterfallImage::query()
                        ->whereIn('id', $ids)
                        ->increment('views_count', (int) $delta);
                }

                // The set counter moves with the images, before the score
                // recalculation runs: it is the number the feed shows, and it
                // must not wait on a window that may coalesce the recompute.
                $setDeltas = [];

                foreach ($surviving as $image) {
                    $setId = (int) $image->set_id;
                    $delta = $deltas[(int) $image->id] ?? 0;

                    if ($setId > 0 && $delta > 0 && isset($lockedSets[$setId])) {
                        $setDeltas[$setId] = ($setDeltas[$setId] ?? 0) + $delta;
                    }
                }

                foreach ($setDeltas as $setId => $delta) {
                    WaterfallSet::query()->whereKey($setId)->increment('views_count', $delta);
                }

                // Zero, not delete — see the class docblock.
                $connection->table('waterfall_view_deltas')
                    ->whereIn('image_id', $imageIds)
                    ->update(['delta' => 0, 'updated_at' => Carbon::now()]);

                return [
                    'ids' => array_map('intval', $surviving->modelKeys()),
                    'full' => $rows->count() === self::CHUNK,
                ];
            });

            if ($result === null) {
                return;
            }

            if ($result['ids'] !== []) {
                // The scores read these counters, so they are recomputed after
                // the deltas land — never from a report that has not been
                // flushed yet.
                $recorder->queueScoreRecalculation($result['ids']);
            }

            if (! $result['full']) {
                return;
            }
        }

        // The backlog outlived the chunk budget: hand the rest to a fresh
        // delivery instead of holding this one open.
        try {
            $queue->push(new static());
        } catch (\Throwable $e) {
            $logger->error('lcoy-waterfall: the rest of the view flush could not be queued ({message})', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
