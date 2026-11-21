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

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Lcoy\Waterfall\Model\WaterfallSet;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Poll target for cards that are still transferring: GET
 * /api/waterfall/set-status?ids=1,2,3 -> {"1": "pending", "3": "published"}.
 *
 * The feed used to poll the JSON:API endpoint for the same answer, which
 * meant a resource lookup, an eager load of authors and covers, and a full
 * document build — every few seconds, per uploader, for as long as a transfer
 * ran. The only thing the page does with that response is compare a status
 * string, so this returns exactly that: no document, no includes, one column.
 *
 * A two-second cache sits in front of the query. It is there for the requests
 * that arrive together (two tabs, a retried poll) rather than to hold the
 * answer back; the window is half the fastest poll interval, so a card never
 * appears to lag behind its own transfer.
 *
 * The visibility rule is the same one the feed uses (scopeVisibleTo), so this
 * endpoint cannot become a way to learn that someone else's upload exists.
 */
class WaterfallSetStatusController implements RequestHandlerInterface
{
    /** How many ids one call may ask about. */
    protected const MAX_IDS = 50;

    /** Seconds a result is reused. */
    protected const CACHE_TTL = 2;

    public function __construct(
        protected CacheRepository $cache
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $ids = $this->ids($request);

        if ($ids === []) {
            // An object, not an array: the client parses this as a map, and
            // `[]` would arrive as a list and read as "no sets" either way.
            return new JsonResponse((object) []);
        }

        $key = 'lcoy-waterfall.set-status.'.($actor->id ?: 'guest').'.'.sha1(implode(',', $ids));

        try {
            $cached = $this->cache->get($key);

            if (is_array($cached)) {
                return new JsonResponse((object) $cached);
            }
        } catch (\Throwable) {
            // A status check is worthless if it can fail on its own cache, so
            // an unreachable cache falls through to the query rather than
            // erroring the poll.
        }

        $statuses = WaterfallSet::query()
            ->visibleTo($actor)
            ->whereIn('id', $ids)
            ->pluck('status', 'id')
            ->all();

        $result = [];

        foreach ($statuses as $id => $status) {
            // String keys: JSON objects, and the ids the client holds are
            // strings, so it can index the response without converting.
            $result[(string) $id] = $status;
        }

        try {
            $this->cache->put($key, $result, self::CACHE_TTL);
        } catch (\Throwable) {
            // Same reasoning as the read above: a cache that cannot write is
            // not a reason to fail the request.
        }

        return new JsonResponse((object) $result);
    }

    /**
     * Deduplicated ids, in the order they were asked for, capped.
     *
     * Only digits are accepted: the ids go into an integer comparison, and a
     * hand-written `?ids=1,abc` must be ignored rather than cast to 1 or 0.
     *
     * @return int[]
     */
    protected function ids(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();

        // Not every request object populates getQueryParams(): a request built
        // by code — the test harness, an internal dispatch — can carry the
        // query only in the URI. Reading it as a fallback keeps the endpoint
        // working there without making the URI a second source of truth.
        if (! isset($params['ids'])) {
            parse_str($request->getUri()->getQuery(), $params);
        }

        $raw = (string) ($params['ids'] ?? '');
        $ids = [];

        foreach (explode(',', $raw) as $value) {
            $value = trim($value);

            if ($value === '' || ! ctype_digit($value)) {
                continue;
            }

            $ids[(int) $value] = (int) $value;

            if (count($ids) >= self::MAX_IDS) {
                break;
            }
        }

        return array_values($ids);
    }
}
