<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Recommend;

use Lcoy\Waterfall\Model\WaterfallImage;

/**
 * Computes the recommendation score of a waterfall image.
 *
 * Kept as an interface so the default log-based heuristic can later be swapped
 * for collaborative filtering or vector-recall implementations without
 * touching call sites (only the container binding changes).
 */
interface ScoreCalculatorInterface
{
    public function calculate(WaterfallImage $image): float;
}
