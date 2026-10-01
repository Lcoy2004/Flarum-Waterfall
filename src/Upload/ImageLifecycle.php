<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Upload;

use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\Recommend\ScoreCalculatorInterface;

/**
 * The single authority on the upload state machine — what each status means,
 * which transitions are legal, and what each one does to the owning set's
 * denormalised row.
 *
 * The transitions used to be scattered across the upload endpoint, the queue
 * job, and WaterfallSet::syncAggregates, each reaching for syncAggregates at a
 * different moment and each re-deriving the rule for when an image is no
 * longer pending. That made the set's counters depend on every caller
 * remembering to sync after its own write; here each lifecycle method carries
 * its transition and its sync together, so a status change and the counter
 * move it implies can never drift apart.
 */
class ImageLifecycle
{
    public function __construct(
        protected ScoreCalculatorInterface $calculator
    ) {
    }

    /**
     * Mark the staged upload as transferred and live: point it at the host's
     * URLs, publish it, and score it in one write. The score is part of the
     * same row the status moves in — recalculating it in a separate job would
     * leave the set aggregate to be recomputed a second time (the sync below
     * already covers it).
     *
     * @return bool whether the transition happened. False means the row was
     *              concurrently deleted (e.g. by a queued delete) or is no
     *              longer pending; the caller then treats the transfer as
     *              moot rather than publishing a dead row.
     */
    public function publish(WaterfallImage $image, string $src, ?string $thumb): bool
    {
        $affected = WaterfallImage::query()
            ->whereKey($image->id)
            ->where('status', WaterfallImage::STATUS_PENDING)
            ->update([
                'src' => $src,
                'thumb' => $thumb,
                'status' => WaterfallImage::STATUS_PUBLISHED,
                'error' => null,
                'score' => $this->calculator->calculate($image),
            ]);

        if ($affected === 0) {
            return false;
        }

        $image->refresh();

        // Publishing changes the set's cover/status/counters.
        WaterfallSet::syncAggregatesForImage($image);

        return true;
    }

    /**
     * Mark the upload as failed, recording why. A failed image can change the
     * set's cover/status/counters (it may stop being the cover, or push a set
     * whose images all failed into STATUS_FAILED).
     *
     * @return bool whether the row was pending and is now failed. The caller
     *              uses this to decide whether the failure is worth logging
     *              and firing an event for: a row already terminal (published
     *              by an earlier delivery, or deleted) has nothing left to
     *              fail.
     */
    public function fail(WaterfallImage $image, string $message): bool
    {
        $affected = WaterfallImage::query()
            ->whereKey($image->id)
            ->where('status', WaterfallImage::STATUS_PENDING)
            ->update([
                'status' => WaterfallImage::STATUS_FAILED,
                'error' => $message,
            ]);

        if ($affected === 0) {
            return false;
        }

        $image->refresh();

        WaterfallSet::syncAggregatesForImage($image);

        return true;
    }
}
