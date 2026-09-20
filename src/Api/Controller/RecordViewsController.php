<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Api\Controller;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\JsonResponse;
use Lcoy\Waterfall\Jobs\RecalculateScoresJob;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Batch view counter: POST /api/waterfall-images/views with { ids: [...] }.
 *
 * The lightbox reports every image it shows, and that used to be one request
 * per image — each of which boots the whole framework, resolves the router and
 * serializes a response, only to add one to a counter. A reader paging through
 * a thirty-image set therefore cost the server thirty framework boots and
 * thirty transactions. This endpoint takes the batch instead: one boot, and a
 * handful of statements for the counters.
 *
 * What is counted is unchanged, so both endpoints stay interchangeable:
 * published images only, one counted view per IP per image per minute (an
 * atomic cache add, so two parallel batches cannot count the same view twice),
 * and the same coalesced score recalculation.
 */
class RecordViewsController implements RequestHandlerInterface
{
    /**
     * Upper bound on one batch. The lightbox never sends more than the set it
     * is showing; a hand-written request must not be able to make the server
     * load an unbounded number of rows.
     */
    protected const MAX_IDS = 100;

    /**
     * One counted view per IP per image per minute, matching the single-image
     * endpoint.
     */
    protected const VIEW_WINDOW_SECONDS = 60;

    public function __construct(
        protected CacheRepository $cache,
        protected Queue $queue
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($body['ids'] ?? [])),
            fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return new JsonResponse(['counted' => 0]);
        }

        $images = WaterfallImage::query()
            ->whereIn('id', array_slice($ids, 0, static::MAX_IDS))
            ->where('status', WaterfallImage::STATUS_PUBLISHED)
            ->get();

        // The client sends the ids once each; the per-IP window is applied here
        // because it is the server that has to hold against a scripted client.
        $ip = md5((string) $request->getAttribute('ipAddress', ''));

        $counted = [];
        $setDeltas = [];

        foreach ($images as $image) {
            if (! $this->cache->add('lcoy-waterfall.view.'.$ip.'.'.$image->id, 1, static::VIEW_WINDOW_SECONDS)) {
                continue;
            }

            $counted[$image->id] = true;

            if ($image->set_id) {
                $setDeltas[$image->set_id] = ($setDeltas[$image->set_id] ?? 0) + 1;
            }
        }

        if ($counted === []) {
            return new JsonResponse(['counted' => 0]);
        }

        $this->applyCounters(array_keys($counted), $setDeltas);
        $this->queueRecalculations($images, $counted);

        return new JsonResponse(['counted' => count($counted)]);
    }

    /**
     * Move the counters in as few statements as the invariants allow.
     *
     * Images: one statement for the whole batch — every counted row gains
     * exactly one. Sets: their totals are deltas on top of the images, so each
     * set needs its own amount, but the row lock the single-image path takes
     * per view is taken once per set here, in id order so two batches touching
     * the same sets cannot deadlock each other. A set that vanished (its last
     * image was deleted) is skipped, exactly as the single view would skip it.
     *
     * @param  int[]  $imageIds
     * @param  array<int, int>  $setDeltas  set id => counted views
     */
    protected function applyCounters(array $imageIds, array $setDeltas): void
    {
        $connection = (new WaterfallImage)->getConnection();

        $connection->transaction(function () use ($imageIds, $setDeltas) {
            WaterfallImage::query()->whereIn('id', $imageIds)->increment('views_count');

            ksort($setDeltas);

            foreach ($setDeltas as $setId => $delta) {
                if (WaterfallSet::query()->lockForUpdate()->find($setId) === null) {
                    continue;
                }

                WaterfallSet::query()->whereKey($setId)->increment('views_count', $delta);
            }
        });
    }

    /**
     * Ask for the score recalculation the single-image endpoint would have
     * asked for — still at most one per image per minute, but delivered as a
     * single job for the whole batch rather than one job per image (see
     * RecalculateScoresJob).
     *
     * @param  iterable<WaterfallImage>  $images
     * @param  array<int, bool>  $counted
     */
    protected function queueRecalculations(iterable $images, array $counted): void
    {
        $recalculate = [];

        foreach ($images as $image) {
            if (isset($counted[$image->id]) && $this->cache->add('lcoy-waterfall.score.'.$image->id, 1, static::VIEW_WINDOW_SECONDS)) {
                $recalculate[] = $image->id;
            }
        }

        if ($recalculate !== []) {
            $this->queue->push(new RecalculateScoresJob($recalculate));
        }
    }
}
