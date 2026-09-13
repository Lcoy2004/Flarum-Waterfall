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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\Recommend\ScoreCalculatorInterface;

/**
 * Recomputes an image's recommendation score off the request path. Dispatched
 * after likes, unlikes and views — uploads score the image as part of the
 * publish write (see ProcessImageUploadJob), so they never need this job.
 */
class RecalculateScoreJob extends AbstractJob
{
    public function __construct(
        protected WaterfallImage $image
    ) {
        parent::__construct();
    }

    public function handle(ScoreCalculatorInterface $calculator): void
    {
        // The image row may have been deleted between queueing and execution
        // (e.g. a like immediately followed by a delete). refresh() resolves
        // the row with firstOrFail(), so it throws ModelNotFoundException in
        // that case — re-scoring is then simply a no-op, not a job failure.
        try {
            $this->image->refresh();
        } catch (ModelNotFoundException) {
            return;
        }

        $this->image->forceFill([
            'score' => $calculator->calculate($this->image),
        ])->save();

        // The set's aggregate score is the sum of its images' scores.
        WaterfallSet::syncAggregatesForImage($this->image);
    }
}
