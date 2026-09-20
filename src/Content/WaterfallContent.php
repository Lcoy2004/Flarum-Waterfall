<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Content;

use Flarum\Api\Client;
use Flarum\Frontend\Document;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Server-side renderer for the /waterfall forum route. Preloads the first
 * page of the waterfall into the frontend document so the client paints the
 * grid without waiting for an XHR (first-screen LCP).
 */
class WaterfallContent
{
    public function __construct(
        protected Client $api,
        protected TranslatorInterface $translator,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function __invoke(Document $document, Request $request): Document
    {
        $document->title = $this->translator->trans('lcoy-waterfall.forum.page.title');
        $document->meta['description'] = $this->translator->trans('lcoy-waterfall.forum.page.description');

        $firstPage = $this->getFirstPageDocument($request);

        $document->payload['apiDocument'] = $firstPage;
        $this->addImageHostHint($document, $firstPage, $request);

        return $document;
    }

    /**
     * Start the connection to the image host while the page is still parsing.
     *
     * Covers and thumbnails live on whatever host the upload API returned —
     * normally a different origin than the forum — so the first image of a
     * visit otherwise pays DNS + TCP + TLS before a single byte of it can
     * arrive, and all of that sits after the HTML. `preconnect` overlaps the
     * handshake with the rest of the page load instead.
     *
     * The origin is read off the preloaded page rather than a setting because
     * the upload response is what decides it: the configured upload URL is an
     * API endpoint, which need not be the host that ends up serving the files.
     * No preloaded images means nothing to warm, and nothing is emitted.
     *
     * Deliberately without `crossorigin`: images are fetched without CORS, and
     * a CORS-mode preconnect opens a *separate* connection that the image
     * request cannot reuse. Core's own hints carry it because they warm fonts
     * and scripts, which are CORS requests.
     */
    protected function addImageHostHint(Document $document, ?array $firstPage, Request $request): void
    {
        $origin = $this->imageHostOrigin($firstPage);

        $uri = $request->getUri();

        if ($origin === null || $origin === $uri->getScheme().'://'.$uri->getHost()) {
            return;
        }

        $href = e($origin);

        // preHead rather than head: the hint is only worth anything before the
        // stylesheet and the first images are discovered.
        $document->preHead[] = '<link rel="preconnect" href="'.$href.'">';
        $document->preHead[] = '<link rel="dns-prefetch" href="'.$href.'">';
    }

    /**
     * The origin the preloaded images actually point at, or null when the page
     * preloaded no images — or only relative ones, which are same-origin by
     * definition.
     */
    protected function imageHostOrigin(?array $firstPage): ?string
    {
        foreach ($firstPage['included'] ?? [] as $resource) {
            foreach (['src', 'thumb'] as $key) {
                $url = $resource['attributes'][$key] ?? null;

                if (! is_string($url) || ! str_starts_with($url, 'http')) {
                    continue;
                }

                $parts = parse_url($url);

                // parse_url() answers false (not an array) for a string it
                // cannot parse at all, and the URLs here come from the image
                // host's response, so neither shape can be assumed away.
                if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                    continue;
                }

                return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            }
        }

        return null;
    }

    protected function getFirstPageDocument(Request $request): ?array
    {
        // Clamped to the same range the list endpoint paginates at. The admin
        // UI enforces 1-100, but a stale or hand-written value must not make
        // the server-side preload ask for (and generate) a huge first page:
        // the windowed eager loads below scale with the page, so too-large a
        // limit turns a page render into a heavy scan that only gets clamped
        // downstream anyway.
        $perPage = min(100, max(1, (int) $this->settings->get('lcoy-waterfall.per_page', 24)));

        // The card slideshow needs the first few images per set; ask for the
        // `images` include only when the slideshow is actually enabled so the
        // preloaded document stays lean when it is off.
        $slideshow = (int) $this->settings->get('lcoy-waterfall.slideshow_images', 3) >= 2;
        $include = $slideshow ? 'user,coverImage,images' : 'user,coverImage';

        $document = json_decode(
            (string) $this->api
                ->withParentRequest($request)
                ->withQueryParams([
                    'include' => $include,
                    'sort' => '-created_at',
                    // Nested, not "page[limit]": the internal client passes the
                    // array straight to the request (no query-string round
                    // trip), and the endpoint reads page.limit / page.offset as
                    // nested keys. Flat bracket keys are never looked up, so the
                    // preload silently fell back to the endpoint's own default
                    // page size — with per_page above 24 the first page came
                    // back truncated and the feed stopped scrolling there.
                    'page' => ['offset' => 0, 'limit' => $perPage],
                ])
                ->get('/waterfall-sets')
                ->getBody(),
            true
        );

        // Only hand the frontend something it can push into its store. An API
        // failure answers with a JSON:API error document that has no `data`
        // (e.g. a 400 for a bad per_page), and the frontend's pushPayload()
        // resolves resource objects by their type — an error body throws while
        // the page is booting, taking the whole route down with it. Returning
        // null leaves `preloadedApiDocument()` empty instead, which is exactly
        // the path the client already takes whenever the URL differs from the
        // initial route: it makes its own request and reports any error there.
        //
        // The client is deliberately used *with* its error handling. Turning it
        // off (withoutErrorHandling) does not soften anything for an internal
        // call: the middleware that converts failures into that error document
        // is simply removed, so the exception escapes this method and the whole
        // page 500s instead of degrading. Verified: per_page=0 returns null with
        // handling on, and throws BadRequestException with it off.
        return is_array($document) && isset($document['data']) ? $document : null;
    }
}
