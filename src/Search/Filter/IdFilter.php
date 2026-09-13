<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * filter[id]=1,2,3 — fetch specific records regardless of the active sort.
 *
 * Used by the frontend pending-upload poll (which fetches sets by id and must
 * reach them even under "-score" sorting), mirroring core's
 * PostFilter\IdFilter. Registered on both the set and the image searcher, so
 * the column is derived from the query's model rather than hardcoded.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class IdFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'id';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $ids = $this->asIntArray($value);

        $state->getQuery()->whereIn(
            $state->getQuery()->getModel()->getQualifiedKeyName(),
            $ids,
            'and',
            $negate
        );
    }
}
