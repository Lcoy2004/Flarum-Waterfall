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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lcoy\Waterfall\Model\Concerns\HasPublishStatus;

/**
 * An image set: one upload (one or more files chosen together) becomes one
 * set. The set owns the editable title and the aggregate counters, while every
 * image keeps its own likes/views/score. The denormalised counters let the feed
 * sort and display a card without joining the images table.
 */
class WaterfallSet extends AbstractModel
{
    use HasPublishStatus;

    protected $table = 'waterfall_sets';

    public $timestamps = true;

    // Writes are guarded at the API resource layer, so allow all attributes.
    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'cover_image_id' => 'integer',
        'images_count' => 'integer',
        'likes_count' => 'integer',
        'views_count' => 'integer',
        'score' => 'float',
        'tags' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Images of this set, in the display order the uploader dragged them into.
     */
    public function images(): HasMany
    {
        return $this->hasMany(WaterfallImage::class, 'set_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Cover shown on the feed card: the first published image by position.
     */
    public function coverImage(): BelongsTo
    {
        return $this->belongsTo(WaterfallImage::class, 'cover_image_id');
    }

    /**
     * Recompute every denormalised counter from the set's images. Cheap enough
     * to run whenever the images change (sets hold only a handful of rows) and
     * self-healing: it always writes absolute values, never deltas.
     */
    public function syncAggregates(): void
    {
        $this->newQuery()->getConnection()->transaction(function () {
            // Hold the set's row lock across the whole read-sum-then-write.
            // The view path's counter delta (WaterfallImageResource::
            // recordView) takes the same lock, so a delta can no longer land
            // between this read and write only to be overwritten by the older
            // sum — a lost update nothing would later correct for an
            // otherwise quiet set. Concurrent syncs serialise here too.
            if (static::query()->lockForUpdate()->find($this->getKey()) === null) {
                return; // the set was deleted while this sync waited on the lock
            }

            // Only the columns the aggregation needs: src/error and other wide
            // fields would otherwise be transferred on every re-sync (which runs
            // on each upload, like, view and delete of an image in the set).
            $images = $this->images()
                ->select('id', 'status', 'likes_count', 'views_count', 'score')
                ->get();

            $published = $images->where('status', WaterfallImage::STATUS_PUBLISHED);
            $failed = $images->where('status', WaterfallImage::STATUS_FAILED);

            $this->images_count = $images->count();
            $this->likes_count = (int) $images->sum('likes_count');
            $this->views_count = (int) $images->sum('views_count');
            $this->score = (float) $images->sum('score');
            $this->cover_image_id = $published->first()?->id;

            if ($published->isNotEmpty()) {
                $this->status = self::STATUS_PUBLISHED;
            } elseif ($images->isNotEmpty() && $failed->count() === $images->count()) {
                $this->status = self::STATUS_FAILED;
            } else {
                $this->status = self::STATUS_PENDING;
            }

            $this->save();
        });
    }

    /**
     * Recompute the aggregates of the set an image belongs to (no-op when the
     * image is not attached to a set, e.g. legacy/test fixtures).
     */
    public static function syncAggregatesForImage(WaterfallImage $image): void
    {
        if (! $image->set_id) {
            return;
        }

        $set = static::query()->find($image->set_id);

        if ($set) {
            $set->syncAggregates();
        }
    }
}
