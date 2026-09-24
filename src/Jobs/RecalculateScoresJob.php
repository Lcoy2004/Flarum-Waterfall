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
use Illuminate\Contracts\Queue\Queue;
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
     * How many images one delivery may re-score; a longer batch is processed
     * across several deliveries, the remainder being queued as its own job.
     *
     * Flarum builds its database queue with a hardcoded retry_after of 60
     * seconds, so a job still running past that is read as abandoned and handed
     * to a second worker — which here would only redo the same work (scores are
     * written as absolute values), but it would hold the worker and delay
     * everything queued behind it while it did. What a delivery costs is
     * dominated by the sets: each one takes a row lock and re-reads its images,
     * so a hundred arriving at once is a hundred locks and a hundred reads.
     */
    protected const MAX_IMAGES_PER_JOB = 25;

    /**
     * @param  int[]  $imageIds
     */
    public function __construct(
        protected array $imageIds
    ) {
        parent::__construct();
    }

    public function handle(ScoreCalculatorInterface $calculator, Queue $queue): void
    {
        $ids = $this->imageIds;
        $overflow = array_slice($ids, static::MAX_IMAGES_PER_JOB);

        if ($overflow !== []) {
            $ids = array_slice($ids, 0, static::MAX_IMAGES_PER_JOB);

            // The rest goes back on the queue as a delivery of its own: a batch
            // that arrived as one burst (a lightbox page) is still scored
            // whole, just across several bounded deliveries rather than one
            // unbounded one.
            $queue->push(new static($overflow));
        }

        $images = WaterfallImage::query()->whereIn('id', $ids)->get();

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
