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

use Flarum\Settings\SettingsRepositoryInterface;
use Lcoy\Waterfall\Model\WaterfallImage;

/**
 * Default scoring heuristic:
 *
 *   score = w1 * log10(1 + likes_count)
 *         + w2 * log10(1 + views_count)
 *         + w3 * exp(-lambda * age_in_hours)
 *
 * Weights (w1, w2, w3) and the decay coefficient lambda are configurable in
 * the admin panel; defaults are w1=1.0, w2=0.3, w3=1.0, lambda=0.05.
 */
class DefaultScoreCalculator implements ScoreCalculatorInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function calculate(WaterfallImage $image): float
    {
        $w1 = (float) $this->settings->get('lcoy-waterfall.weight_likes', 1.0);
        $w2 = (float) $this->settings->get('lcoy-waterfall.weight_views', 0.3);
        $w3 = (float) $this->settings->get('lcoy-waterfall.weight_recency', 1.0);
        $lambda = (float) $this->settings->get('lcoy-waterfall.decay_lambda', 0.05);

        $likes = max(0, (int) $image->likes_count);
        $views = max(0, (int) $image->views_count);

        $ageHours = 0.0;

        if ($image->created_at) {
            $ageHours = max(0.0, (time() - strtotime((string) $image->created_at)) / 3600);
        }

        return round(
            $w1 * log10(1 + $likes)
            + $w2 * log10(1 + $views)
            + $w3 * exp(-$lambda * $ageHours),
            4
        );
    }
}
