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

use Flarum\Queue\AbstractJob;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\Recommend\ScoreCalculatorInterface;

/**
 * Recomputes the recommendation score of a batch of images off the request
 * path, then re-syncs each affected set once.
 *
 * Carrying a batch rather than a single image is what keeps a browsing session
 * cheap: the view endpoint counts a whole lightbox page in one request, and
 * scoring it image-by-image meant one queue delivery *per image*, each of them
 * re-summing the same set's rows to write the same aggregate — thirty
 * deliveries and thirty identical SUMs for one thirty-image set. The scores
 * still move one at a time (each depends on its own counters); only the queue
 * delivery and the aggregate sync are shared.
 *
 * Ids are carried rather than models, for the reason ProcessImageUploadJob
 * gives: AbstractJob's deleteWhenMissingModels would silently drop a job whose
 * serialized model is gone, and a deleted image simply has nothing to score.
 * Ids also keep the payload small when a batch is large.
 */
class RecalculateScoresJob extends AbstractJob
{
    /**
     * @param  int[]  $imageIds
     */
    public function __construct(
        protected array $imageIds
    ) {
        parent::__construct();
    }

    public function handle(ScoreCalculatorInterface $calculator): void
    {
        $images = WaterfallImage::query()->whereIn('id', $this->imageIds)->get();

        // Every image was deleted between queueing and execution (a like
        // immediately followed by a delete, say): re-scoring is a no-op, not a
        // failure.
        if ($images->isEmpty()) {
            return;
        }

        $setIds = [];

        foreach ($images as $image) {
            $image->forceFill(['score' => $calculator->calculate($image)])->save();

            if ($image->set_id) {
                $setIds[$image->set_id] = true;
            }
        }

        // A set's aggregate score is the sum of its images' scores, so it is
        // re-synced once per set instead of once per image — the sum would be
        // recomputed identically for every image of the same set.
        foreach (array_keys($setIds) as $setId) {
            WaterfallSet::syncAggregatesForSet($setId);
        }
    }
}
