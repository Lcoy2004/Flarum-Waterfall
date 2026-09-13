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
use Lcoy\Waterfall\Model\WaterfallSet;

/**
 * Database searcher powering GET /api/waterfall-sets: applies the shared
 * visibility scope, filter[user] (via UserFilter), the registered sorts and
 * offset pagination.
 */
class WaterfallSetSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        return WaterfallSet::query()
            ->select('waterfall_sets.*')
            ->visibleTo($actor);
    }
}
