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
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Expression;
use Lcoy\Waterfall\Api\Endpoint\UploadImageEndpoint;
use Lcoy\Waterfall\Event\ImageWasLiked;
use Lcoy\Waterfall\Event\ImageWasUnliked;
use Lcoy\Waterfall\Jobs\RecalculateScoresJob;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallImageLike;
use Lcoy\Waterfall\Model\WaterfallSet;
use Tobyz\JsonApiServer\Context;

/**
 * @extends AbstractDatabaseResource<WaterfallImage>
 */
class WaterfallImageResource extends AbstractDatabaseResource
{
    // The `likes` include is bounded to this many users per image (the
    // current user's own like always sorts first), mirroring flarum/likes.
    protected const MAX_LIKES_INCLUDE = 5;

    public function __construct(
        protected Dispatcher $events,
        protected Queue $queue,
        protected CacheRepository $cache,
        protected TranslatorInterface $translator
    ) {
    }

    public function type(): string
    {
        return 'waterfall-images';
    }

    public function model(): string
    {
        return WaterfallImage::class;
    }

    /**
     * Everyone (including guests) sees published images; pending and failed
     * images remain visible to their owner only. The rule itself lives in the
     * HasPublishStatus trait (scopeVisibleTo), shared with the searcher so the
     * list endpoint and the resource can never disagree.
     */
    public function scope(Builder $query, Context $context): void
    {
        $query->visibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            // The bounded `likes` relation feeds the `isLiked` attribute, so
            // it is eager-loaded up front (one windowed query per page, via
            // eagerLoadWhere) instead of falling back to a per-image exists()
            // check — which the N+1 detector would (rightly) flag.
            // AbstractDatabaseResource skips the relationship buffer's own
            // load when the relation is already loaded, so the include reuses
            // this query rather than issuing a duplicate one.
            Endpoint\Index::make()
                ->paginate(24, 100)
                ->defaultSort('-createdAt')
                ->defaultInclude(['user', 'likes'])
                ->eagerLoadWhere('likes', WaterfallImageResource::scopeLikes(...)),

            Endpoint\Show::make()
                ->defaultInclude(['user', 'likes'])
                ->eagerLoadWhere('likes', WaterfallImageResource::scopeLikes(...)),

            UploadImageEndpoint::make()
                ->authenticated()
                ->can('lcoy-waterfall.upload'),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),

            // Liking is idempotent: liking an already-liked image is a no-op.
            Endpoint\Endpoint::make('like')
                ->route('POST', '/{id}/like')
                ->authenticated()
                ->can('lcoy-waterfall.like')
                ->action(function (FlarumContext $context): ?object {
                    /** @var WaterfallImage $image */
                    $image = $context->model;
                    $actor = $context->getActor();

                    if ($image->status !== WaterfallImage::STATUS_PUBLISHED) {
                        throw new ValidationException(['image' => $this->translator->trans('lcoy-waterfall.api.errors.not_likable')]);
                    }

                    $like = WaterfallImageLike::query()
                        ->where('image_id', $image->id)
                        ->where('user_id', $actor->id)
                        ->first();

                    if (! $like) {
                        $created = false;

                        try {
                            WaterfallImageLike::query()->create([
                                'image_id' => $image->id,
                                'user_id' => $actor->id,
                            ]);

                            $created = true;
                        } catch (\Illuminate\Database\QueryException $e) {
                            // Lost a race against a concurrent like request:
                            // the unique index protected us; treat as an
                            // already-existing like (SQLSTATE 23000 = unique
                            // constraint violation, stored in errorInfo[0]).
                            if (($e->errorInfo[0] ?? null) !== '23000') {
                                throw $e;
                            }
                        }

                        if ($created) {
                            // increment() keeps the in-memory attribute in
                            // sync, so no refresh() is needed afterwards. The
                            // set's own counter is deliberately not touched:
                            // the recalculation pushed below rewrites it from
                            // the images, so a delta here would be a second
                            // write to the same row to no effect.
                            $image->increment('likes_count');

                            $this->events->dispatch(new ImageWasLiked($image, $actor));
                            $this->queue->push(new RecalculateScoresJob([$image->id]));
                        }
                    }

                    return $image;
                }),

            Endpoint\Endpoint::make('unlike')
                ->route('DELETE', '/{id}/like')
                ->authenticated()
                ->can('lcoy-waterfall.like')
                ->action(function (FlarumContext $context): ?object {
                    /** @var WaterfallImage $image */
                    $image = $context->model;
                    $actor = $context->getActor();

                    $deleted = WaterfallImageLike::query()
                        ->where('image_id', $image->id)
                        ->where('user_id', $actor->id)
                        ->delete();

                    if ($deleted > 0) {
                        // Same as the like path: the set counter is rewritten
                        // by the recalculation below, not by a delta here.
                        //
                        // The image's own counter is decremented with a floor,
                        // like the set counters. The row and the counter are
                        // written without a transaction, so a failure between
                        // them (or a like written by anything other than this
                        // endpoint) leaves the counter below its rows — and the
                        // column is unsigned under a strict sql_mode, so a
                        // plain decrement turns the unlike into a 500 with the
                        // row already gone. The attribute is brought down by
                        // hand because the guarded update may deliberately
                        // match no rows.
                        $image->likes_count = max(0, (int) $image->likes_count - 1);

                        WaterfallImage::query()
                            ->whereKey($image->id)
                            ->where('likes_count', '>', 0)
                            ->decrement('likes_count');

                        $this->events->dispatch(new ImageWasUnliked($image, $actor));
                        $this->queue->push(new RecalculateScoresJob([$image->id]));
                    }

                    return $image;
                }),

            // View counter, fired by the frontend lightbox (once per image per
            // session). Open to guests as browsing is public. Server-side, one
            // counted view per IP per image per minute: the counter feeds the
            // recommendation score, so unthrottled increments would let a
            // scripted client inflate it. cache add() is atomic (Redis SETNX,
            // lock-guarded elsewhere), so parallel requests cannot double-count.
            Endpoint\Endpoint::make('view')
                ->route('POST', '/{id}/view')
                ->action(function (FlarumContext $context): ?object {
                    /** @var WaterfallImage $image */
                    $image = $context->model;

                    if ($image->status === WaterfallImage::STATUS_PUBLISHED) {
                        $ip = (string) $context->request->getAttribute('ipAddress', '');

                        $counted = $this->cache->add(
                            'lcoy-waterfall.view.'.md5($ip).'.'.$image->id,
                            1,
                            60
                        );

                        if ($counted) {
                            $this->recordView($image);

                            // Coalesce score recalculation: without this, a
                            // popular image viewed by many distinct IPs would
                            // enqueue one job per view. At most one job per
                            // image per minute keeps the queue bounded; the
                            // score is recomputed from the live counters, so
                            // nothing is lost.
                            if ($this->cache->add('lcoy-waterfall.score.'.$image->id, 1, 60)) {
                                $this->queue->push(new RecalculateScoresJob([$image->id]));
                            }
                        }
                    }

                    return $image;
                }),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('src'),
            Schema\Str::make('thumb')
                ->nullable(),
            Schema\Str::make('title')
                ->nullable()
                ->maxLength(200),
            Schema\Integer::make('likesCount'),
            Schema\Integer::make('viewsCount'),
            Schema\Number::make('score'),
            // pending | published | failed
            Schema\Str::make('status'),
            Schema\Str::make('error')
                ->nullable()
                ->visible(fn (WaterfallImage $image, FlarumContext $context) => $this->errorVisibleTo($image, $context)),
            Schema\DateTime::make('createdAt'),
            Schema\Boolean::make('canLike')
                ->get(function (WaterfallImage $image, FlarumContext $context) {
                    $actor = $context->getActor();

                    return ! $actor->isGuest()
                        && $actor->can('lcoy-waterfall.like')
                        && $image->status === WaterfallImage::STATUS_PUBLISHED;
                }),
            Schema\Boolean::make('canDelete')
                ->get(fn (WaterfallImage $image, FlarumContext $context) => $context->getActor()->can('delete', $image)),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
            // Bounded include (official flarum/likes pattern): at most
            // MAX_LIKES_INCLUDE likers per image, with the current user's own
            // like always first so `isLiked` can be derived client-side too.
            Schema\Relationship\ToMany::make('likes')
                ->type('users')
                ->includable()
                ->scope(WaterfallImageResource::scopeLikes(...)),

            // Kept AFTER the `likes` relationship: the serializer resolves
            // deferred values in field order, so returning the closure defers
            // the check until the likes relation has been loaded.
            //
            // That only pays off where `likes` is actually eager loaded. It is
            // not enough for a relationship to be *declared* here — a relation
            // reached through someone else's include needs its own
            // eagerLoadWhere on that endpoint. Measured: with the set feed's
            // `images` include and no `images.likes` declaration, every
            // included image fell through to the exists() below, costing one
            // query per image (21 extra SELECTs for a 7-set page, 49 vs 28).
            // WaterfallSetResource::endpoints() declares it for exactly that
            // reason; keep the two in step when adding an endpoint that
            // serializes images in bulk.
            //
            // The exists() fallback stays: it is the correctness net for any
            // path that serializes an image without loading its likes.
            Schema\Boolean::make('isLiked')
                ->get(function (WaterfallImage $image, FlarumContext $context) {
                    return function () use ($image, $context) {
                        $actor = $context->getActor();

                        if ($actor->isGuest()) {
                            return false;
                        }

                        if ($image->relationLoaded('likes')) {
                            return $image->likes->contains(fn ($user) => $user->id === $actor->id);
                        }

                        return WaterfallImageLike::query()
                            ->where('image_id', $image->id)
                            ->where('user_id', $actor->id)
                            ->exists();
                    };
                }),
        ];
    }

    /**
     * Bounded `likes` scope, shared by this resource's relationship field and
     * endpoint eagerLoadWhere() calls (including WaterfallSetResource's, via
     * the `coverImage.likes` / `images.likes` nested paths): the current
     * user's own like first, then the most recent, at most MAX_LIKES_INCLUDE
     * likers per image.
     */
    public static function scopeLikes(BelongsToMany $query, FlarumContext $context): void
    {
        $actor = $context->getActor();
        $grammar = $query->getQuery()->getGrammar();

        $query
            ->orderBy(new Expression($grammar->wrap('user_id').' = '.$actor->id), 'desc')
            ->orderBy('created_at')
            ->limit(static::MAX_LIKES_INCLUDE);
    }

    /**
     * Both camelCase (Flarum 2.x convention) and snake_case (classic JSON:API
     * style, e.g. `-created_at` / `-likes_count`) sort names are accepted:
     * both resolve to the same column via the searcher's snake-casing.
     */
    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt'),
            SortColumn::make('created_at'),
            SortColumn::make('score'),
            SortColumn::make('likesCount'),
            SortColumn::make('likes_count'),
            // The lightbox appends pages of a set's images through
            // filter[set]=X&sort=position (the include itself is capped).
            SortColumn::make('position'),
        ];
    }

    protected function errorVisibleTo(WaterfallImage $image, FlarumContext $context): bool
    {
        $actor = $context->getActor();

        return $actor->id === $image->user_id || $actor->can('lcoy-waterfall.moderate');
    }

    /**
     * Apply one counted view to the image and, when it belongs to a set, to
     * the set's denormalised counter — as a single transaction holding the
     * set's row lock.
     *
     * The lock is what keeps the two counters consistent:
     * WaterfallSet::syncAggregates() rewrites the set's counters from the
     * images under the same lock, so either that sync sees this view already
     * applied to the image (its sum then includes it) or it runs entirely
     * before this transaction (and this delta lands on top). Without the lock
     * a sync that read the images a moment earlier could overwrite the set's
     * +1 with the older sum.
     *
     * Views delta the set directly (unlike likes, whose score recalculation
     * rewrites the counters anyway) because the recalculation is coalesced to
     * one job per image per minute: without the delta the set's total would
     * trail every view in between, and stay behind once the viewing stops.
     */
    protected function recordView(WaterfallImage $image): void
    {
        if (! $image->set_id) {
            $image->increment('views_count');

            return;
        }

        $image->newQuery()->getConnection()->transaction(function () use ($image) {
            if (WaterfallSet::query()->lockForUpdate()->find($image->set_id) === null) {
                // The set vanished (its last image was deleted); count the
                // image on its own.
                $image->increment('views_count');

                return;
            }

            $image->increment('views_count');
            WaterfallSet::query()->whereKey($image->set_id)->increment('views_count');
        });
    }

    /**
     * After an image is deleted, refresh its set (or drop the set entirely
     * when its last image is gone).
     */
    public function deleted(object $model, Context $context): void
    {
        /** @var WaterfallImage $image */
        $image = $model;

        if (! $image->set_id) {
            return;
        }

        $set = WaterfallSet::query()->find($image->set_id);

        if ($set === null) {
            return;
        }

        // exists() stops at the first row (the set_id index prefix); a full
        // count() would scan every remaining image just to compare against 0.
        if (! $set->images()->exists()) {
            $set->delete();

            return;
        }

        $set->syncAggregates();
    }
}
