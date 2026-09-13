<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Api;

use Flarum\Api\Context as FlarumContext;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lcoy\Waterfall\Api\WaterfallImageResource;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Tobyz\JsonApiServer\Context;

/**
 * @extends AbstractDatabaseResource<WaterfallSet>
 */
class WaterfallSetResource extends AbstractDatabaseResource
{
    // The `images` include (the lightbox's initial page) is bounded to this
    // many images per set; further pages are appended by the frontend through
    // GET /api/waterfall-images?filter[set]=<id>&sort=position&page[offset]=N.
    protected const MAX_IMAGES_INCLUDE = 50;

    public function type(): string
    {
        return 'waterfall-sets';
    }

    public function model(): string
    {
        return WaterfallSet::class;
    }

    /**
     * Everyone (including guests) sees sets with at least one published image;
     * pending and failed sets remain visible to their owner only. The rule
     * itself lives on the model (scopeVisibleTo), shared with the searcher so
     * the list endpoint and the resource can never disagree.
     */
    public function scope(Builder $query, Context $context): void
    {
        $query->visibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            // Both paths that reach an image need their `likes` loaded for the
            // image's `isLiked` attribute: `coverImage.likes` for the cover,
            // `images.likes` for the slideshow window. Missing either one
            // makes that image fall back to a single exists() query per image
            // (an N+1 — measured at 21 extra SELECTs for one 7-set page, and
            // ~72 for a full one).
            //
            // `images` feeds the card slideshow: bounded to the admin's
            // slideshow count (published images only, display order). When
            // the slideshow is disabled (count < 2) the load is constrained
            // to no rows — eagerLoadWhere always loads, so an unconstrained
            // relation would transfer up to 50 images per set for nothing.
            Endpoint\Index::make()
                ->paginate(24, 100)
                ->defaultSort('-createdAt')
                ->defaultInclude(['user', 'coverImage'])
                ->eagerLoadWhere('coverImage.likes', WaterfallImageResource::scopeLikes(...))
                ->eagerLoadWhere('images', fn (HasMany $query) => static::scopeSlideshowImages($query))
                ->eagerLoadWhere('images.likes', WaterfallImageResource::scopeLikes(...)),

            // The lightbox opens a set through `include=images`. The images
            // path is loaded with the display order (the relationship's own
            // orderBy would not apply through loadMissing, and an unordered
            // preloaded relation would silently break the dragged order) and
            // capped at MAX_IMAGES_INCLUDE rows; the lightbox appends further
            // pages via filter[set]=X&sort=position. Their likes are
            // batch-loaded for `isLiked`.
            Endpoint\Show::make()
                ->defaultInclude(['user', 'coverImage'])
                ->eagerLoadWhere('images', function (HasMany $query, FlarumContext $context) {
                    // Same visibility the image searcher applies: published for
                    // everyone, the owner's own pending/failed too. Without it a
                    // published set that still holds a pending or failed image
                    // (e.g. one shot of a batch failed) would leak those rows to
                    // a stranger opening the lightbox — the lightbox's later
                    // pages come from filter[set] via the searcher, which IS
                    // scoped, so the first page had to match for consistency.
                    // Eloquent resolves the model scope on the relation.
                    $query->visibleTo($context->getActor());

                    return $query->orderBy('position')->orderBy('id')->limit(static::MAX_IMAGES_INCLUDE);
                })
                ->eagerLoadWhere('images.likes', WaterfallImageResource::scopeLikes(...))
                ->eagerLoadWhere('coverImage.likes', WaterfallImageResource::scopeLikes(...)),

            // Creating the set is the first step of an upload; the images are
            // then uploaded one by one carrying this set's id and their
            // dragged-in position.
            Endpoint\Create::make()
                ->authenticated()
                ->can('lcoy-waterfall.upload'),

            // Renaming the set title. Authorization is enforced per-field on
            // `title` below (a non-writable field yields 403), mirroring the
            // core DiscussionResource — `->can('rename')` cannot be used here
            // because `rename` collides with the PHP built-in function.
            Endpoint\Update::make()
                ->authenticated(),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),
        ];
    }

    /**
     * Attach the creator as the owner before the row is inserted.
     */
    public function creating(object $model, Context $context): ?object
    {
        /** @var WaterfallSet $model */
        $model->user_id = $context->getActor()->id;

        return $model;
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title')
                ->nullable()
                ->maxLength(200)
                ->writable(fn (WaterfallSet $set, FlarumContext $context) => $context->creating() || $context->getActor()->can('rename', $set))
                // Normalise the title: trim surrounding whitespace, collapse
                // internal runs, and store an empty title as null.
                ->set(function (WaterfallSet $set, mixed $value, FlarumContext $context) {
                    $set->title = WaterfallSetResource::normalizeTitle((string) $value);
                }),
            // Freeform tags written by the uploader at publish time (not the
            // forum's tag taxonomy). Normalised by normalizeTags: trimmed,
            // deduplicated, at most 5 tags of at most 20 characters each.
            Schema\Arr::make('tags')
                ->nullable()
                ->writable(fn (WaterfallSet $set, FlarumContext $context) => $context->creating() || $context->getActor()->can('rename', $set))
                ->set(function (WaterfallSet $set, mixed $value, FlarumContext $context) {
                    $set->tags = WaterfallSetResource::normalizeTags($value);
                }),
            Schema\Integer::make('imagesCount'),
            Schema\Integer::make('likesCount'),
            Schema\Integer::make('viewsCount'),
            Schema\Number::make('score'),
            // pending | published | failed
            Schema\Str::make('status'),
            Schema\DateTime::make('createdAt'),
            Schema\Boolean::make('canRename')
                ->get(fn (WaterfallSet $set, FlarumContext $context) => $context->getActor()->can('rename', $set)),
            Schema\Boolean::make('canDelete')
                ->get(fn (WaterfallSet $set, FlarumContext $context) => $context->getActor()->can('delete', $set)),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
            Schema\Relationship\ToOne::make('coverImage')
                ->type('waterfall-images')
                ->includable(),
            // The lightbox fetches the set's first page through this include;
            // order is the uploader-defined position, bounded to
            // MAX_IMAGES_INCLUDE rows (further pages come from filter[set]).
            Schema\Relationship\ToMany::make('images')
                ->type('waterfall-images')
                ->includable()
                ->scope(fn (HasMany $query) => $query->orderBy('position')->orderBy('id')->limit(static::MAX_IMAGES_INCLUDE)),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt'),
            SortColumn::make('created_at'),
            SortColumn::make('score'),
            SortColumn::make('likesCount'),
            SortColumn::make('likes_count'),
        ];
    }

    public static function normalizeTitle(string $value): ?string
    {
        // Collapse any run of whitespace (spaces, tabs, newlines) into a single
        // space and trim the ends; an all-whitespace title becomes null.
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $normalized === '' ? null : $normalized;
    }

    public const MAX_TAGS = 5;
    public const MAX_TAG_LENGTH = 20;

    /**
     * Normalise a freeform tag list: keep only non-empty strings, trim each,
     * cap every tag's length, drop duplicates (case-insensitively — "Beach"
     * and "beach" are the same tag to a reader) and keep at most MAX_TAGS.
     * An empty list becomes null so the column stays compact.
     */
    public static function normalizeTags(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $tags = [];
        $seen = [];

        foreach ($value as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $tag = trim($tag);

            if ($tag === '') {
                continue;
            }

            $tag = mb_substr($tag, 0, self::MAX_TAG_LENGTH);
            $key = mb_strtolower($tag);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $tags[] = $tag;

            if (count($tags) >= self::MAX_TAGS) {
                break;
            }
        }

        return $tags === [] ? null : $tags;
    }

    /**
     * Slideshow window over a set's images for the feed cards: published
     * images only, in display order, bounded to the admin's
     * `slideshow_images` setting. A setting below 2 disables the slideshow,
     * in which case the load is constrained to no rows at all.
     */
    public static function scopeSlideshowImages(HasMany $query): void
    {
        $count = (int) resolve(SettingsRepositoryInterface::class)->get('lcoy-waterfall.slideshow_images', 3);

        if ($count < 2) {
            $query->whereRaw('1 = 0');

            return;
        }

        // Cap the window at the lightbox's own first page: the admin UI only
        // goes up to 10, but a hand-written value must not turn this preview
        // into a per-set scan of every image the moment flags change.
        $count = min($count, self::MAX_IMAGES_INCLUDE);

        $query
            ->where('status', WaterfallImage::STATUS_PUBLISHED)
            ->orderBy('position')
            ->orderBy('id')
            ->limit($count);
    }
}
