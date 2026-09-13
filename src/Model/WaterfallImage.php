<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WaterfallImage extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    protected $table = 'waterfall_images';

    // Flarum's AbstractModel disables timestamps by default; this model needs
    // created_at/updated_at for sorting, recency scoring and rate limiting.
    public $timestamps = true;

    // Writes are guarded at the API resource layer, so allow all attributes.
    protected $guarded = [];

    protected $casts = [
        'set_id' => 'integer',
        'position' => 'integer',
        'likes_count' => 'integer',
        'views_count' => 'integer',
        'score' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The image set this image belongs to (null for legacy rows / test
     * fixtures that were created before sets existed).
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(WaterfallSet::class, 'set_id');
    }

    /**
     * Images the given actor is allowed to see: everything published, plus the
     * actor's own pending/failed rows. The API resource and the searcher both
     * go through this scope so the two can never drift apart.
     */
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        $status = $this->qualifyColumn('status');
        $userId = $this->qualifyColumn('user_id');

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

    /**
     * Users that liked this image. Read-only: writes go through the
     * WaterfallImageLike model.
     *
     * The pivot table carries no updated_at column, so no withTimestamps().
     * Ordering is owned by the `likes` relationship scope on the API resource
     * (current user's like first, then most recent), not by the relation.
     */
    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'waterfall_image_likes', 'image_id', 'user_id');
    }
}
