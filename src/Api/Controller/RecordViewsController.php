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

use Laminas\Diactoros\Response\JsonResponse;
use Lcoy\Waterfall\View\ViewRecorder;
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
 * What is counted is defined by ViewRecorder, which the per-image view endpoint
 * adapts to as well, so the two surfaces can never disagree on the rule.
 */
class RecordViewsController implements RequestHandlerInterface
{
    public function __construct(
        protected ViewRecorder $recorder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // (array) around the parse: the body is a decoded document, and a
        // hand-written request can make it a scalar rather than the object
        // whose `ids` is expected — indexing a string would be an error, not a
        // refusal.
        $ids = (array) (((array) $request->getParsedBody())['ids'] ?? []);

        $counted = $this->recorder->record(
            $ids,
            (string) $request->getAttribute('ipAddress', '')
        );

        return new JsonResponse(['counted' => count($counted)]);
    }
}
