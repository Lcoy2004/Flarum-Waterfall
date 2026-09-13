<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\ServiceProvider;

use Flarum\Foundation\AbstractServiceProvider;
use Lcoy\Waterfall\Recommend\DefaultScoreCalculator;
use Lcoy\Waterfall\Recommend\ScoreCalculatorInterface;
use Lcoy\Waterfall\Upload\ExternalImageHostUploader;

class WaterfallServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // The score calculator is bound against its interface so the default
        // heuristic can be replaced (e.g. with a collaborative filtering
        // implementation) by re-binding in another provider or extension.
        $this->container->singleton(ScoreCalculatorInterface::class, DefaultScoreCalculator::class);

        // Shared so the worker keeps one HTTP client — and therefore one warm
        // connection — to the image host across transfers. Transient bindings
        // would rebuild the client (and redo the TLS handshake) for every
        // upload, and each image is transferred twice (original + card copy).
        $this->container->singleton(ExternalImageHostUploader::class, ExternalImageHostUploader::class);
    }
}
