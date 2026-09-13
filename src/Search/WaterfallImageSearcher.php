<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Search;

use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Lcoy\Waterfall\Model\WaterfallImage;

/**
 * Database searcher powering GET /api/waterfall-images: applies the shared
 * visibility scope, filter[user]/filter[set], the registered sorts and offset
 * pagination.
 */
class WaterfallImageSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        return WaterfallImage::query()
            ->select('waterfall_images.*')
            ->visibleTo($actor);
    }
}
