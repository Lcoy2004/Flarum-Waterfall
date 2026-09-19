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
        static::syncAggregatesForSet((int) $this->getKey());
    }

    /**
     * Recompute the aggregates of the set an image belongs to (no-op when the
     * image is not attached to a set, e.g. legacy/test fixtures).
     */
    public static function syncAggregatesForImage(WaterfallImage $image): void
    {
        if ($image->set_id) {
            static::syncAggregatesForSet((int) $image->set_id);
        }
    }

    /**
     * Recompute one set's counters, addressed by id.
     *
     * The row lock is held across the whole read-images-then-write-totals
     * sequence. The view path's counter delta (WaterfallImageResource::
     * recordView) takes the same lock, so a delta can no longer land between
     * this read and write only to be overwritten by the older sum — a lost
     * update nothing would later correct for an otherwise quiet set.
     * Concurrent syncs serialise here too.
     *
     * The recomputation deliberately runs on the row read *under* that lock
     * rather than on whatever instance the caller happened to hold: the ids
     * are all that is taken from the caller, which also saves the caller's
     * own lookup — an upload used to load the same set row three times (once
     * to authorise the set_id, then once per sync) to reach this point.
     */
    public static function syncAggregatesForSet(int $setId): void
    {
        (new static)->getConnection()->transaction(function () use ($setId) {
            $set = static::query()->lockForUpdate()->find($setId);

            if ($set === null) {
                return; // the set was deleted while this sync waited on the lock
            }

            $set->recomputeAggregates();
        });
    }

    /**
     * Sum this set's images into the denormalised columns and persist them.
     * Assumes the caller holds the set's row lock (see syncAggregatesForSet).
     */
    protected function recomputeAggregates(): void
    {
        // Only the columns the aggregation needs: src/error and other wide
        // fields would otherwise be transferred on every re-sync (which runs
        // on each upload, like, view and delete of an image in the set).
        $images = $this->images()
            ->select('id', 'status', 'likes_count', 'views_count', 'score')
            ->get();

        $published = $images->where('status', WaterfallImage::STATUS_PUBLISHED);
        $failed = $images->where('status', WaterfallImage::STATUS_FAILED);

        // The public count, and the only definition that holds for the people
        // who read the badge: visitors are served the published images alone
        // (HasPublishStatus::scopeVisibleTo), so a set of two published images
        // and one failed one shows two — counting every row advertised three
        // and promised a card nobody but the uploader could open. The cover and
        // the status below are picked from the same subset for the same reason.
        $this->images_count = $published->count();
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
    }
}
