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
 * filter[set]=<setId> — only show images belonging to the given image set.
 *
 * The set's `images` include is capped server-side (see
 * WaterfallSetResource::MAX_IMAGES_INCLUDE), so the lightbox appends further
 * pages through this filter with sort=position and offset pagination.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class SetFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'set';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $setId = $this->asInt($value);

        $state->getQuery()->where(
            $state->getQuery()->getModel()->qualifyColumn('set_id'),
            $negate ? '!=' : '=',
            $setId
        );
    }
}
