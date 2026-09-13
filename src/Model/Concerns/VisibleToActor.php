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
 * Row visibility: published for everyone, plus the actor's own pending and
 * failed rows.
 *
 * Every API resource and searcher reaches this scope, so the list endpoints and
 * the serialized resources can never disagree about what a given actor may see.
 * It lives here, in one place, because it is a security rule: the image and set
 * tables carry the same status semantics, and a copy of this that drifted in
 * one model would silently widen what that endpoint exposes.
 *
 * The using model must define STATUS_PUBLISHED, STATUS_PENDING and
 * STATUS_FAILED.
 */
trait VisibleToActor
{
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        // The table is read off the query's own model rather than $this: the
        // scope also runs against a relation's builder, and this keeps the
        // qualification correct (and resolvable) without depending on the
        // trait's host.
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
