<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Model\Concerns;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A row that moves through the upload lifecycle — pending, published, failed —
 * along with the visibility rule that follows from those states.
 *
 * The constants and the scope belong together: the scope is defined entirely in
 * terms of them, and these are the only rows the feed and the API expose.
 *
 * The rule itself: published rows are public, including to guests, while a row
 * still pending or failed is visible to its owner alone. Every API resource and
 * searcher reaches this scope, so the list endpoints and the serialized
 * resources can never disagree about what a given actor may see. It lives in
 * one place because it is a security rule — a copy that drifted in one model
 * would silently widen what that endpoint exposed.
 */
trait HasPublishStatus
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        // The table is read off the query's own model rather than $this: the
        // scope also runs against a relation's builder, and this keeps the
        // qualification correct without depending on the trait's host.
        $model = $query->getModel();
        $status = $model->qualifyColumn('status');
        $userId = $model->qualifyColumn('user_id');

        $query->where(function (Builder $query) use ($actor, $status, $userId) {
            $query->where($status, self::STATUS_PUBLISHED);

            if ($actor->exists) {
                $query->orWhere(function (Builder $query) use ($actor, $status, $userId) {
                    $query->where($userId, $actor->id)
                        ->whereIn($status, [self::STATUS_PENDING, self::STATUS_FAILED]);
                });
            }
        });
    }
}
