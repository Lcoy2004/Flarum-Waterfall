<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;
use Flarum\Search\Database\DatabaseSearchDriver;
use Lcoy\Waterfall\Api\Controller\RecordViewsController;
use Lcoy\Waterfall\Api\Controller\TestImageHostController;
use Lcoy\Waterfall\Api\WaterfallImageResource;
use Lcoy\Waterfall\Api\WaterfallSetResource;
use Lcoy\Waterfall\Api\WaterfallUploadLogResource;
use Lcoy\Waterfall\Access\WaterfallImagePolicy;
use Lcoy\Waterfall\Access\WaterfallSetPolicy;
use Lcoy\Waterfall\Content\WaterfallContent;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\Recommend\DefaultScoreCalculator;
use Lcoy\Waterfall\Recommend\ScoreCalculatorInterface;
use Lcoy\Waterfall\Search\Filter\IdFilter;
use Lcoy\Waterfall\Search\Filter\SetFilter;
use Lcoy\Waterfall\Search\Filter\UserFilter;
use Lcoy\Waterfall\Search\WaterfallImageSearcher;
use Lcoy\Waterfall\Search\WaterfallSetSearcher;
use Lcoy\Waterfall\ServiceProvider\WaterfallServiceProvider;

return [
    // Forum frontend: JS bundle, stylesheet and the /waterfall route with
    // server-side preloading of the first page for a fast LCP.
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/waterfall', 'waterfall', WaterfallContent::class),

    // Admin frontend: JS bundle and stylesheet.
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    // JSON:API resources (Flarum 2.x ApiResource system).
    new Extend\ApiResource(WaterfallImageResource::class),
    new Extend\ApiResource(WaterfallSetResource::class),
    new Extend\ApiResource(WaterfallUploadLogResource::class),

    // Admin-only image host connectivity/auth check (backing the test button).
    //
    // The batch view counter lives here too rather than on the image resource:
    // it is a plain JSON endpoint, and routing it this way keeps it off the
    // JSON:API path (no document to build, no resource to serialize) — which
    // is most of what makes a per-image request expensive.
    (new Extend\Routes('api'))
        ->post('/waterfall/test-host', 'waterfall.test-host', TestImageHostController::class)
        ->post('/waterfall-images/views', 'waterfall.views', RecordViewsController::class),

    // Expose the upload permission on the forum document so the frontend can
    // show or hide the upload button without an extra request.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            \Flarum\Api\Schema\Boolean::make('waterfallCanUpload')
                ->get(fn ($model, \Flarum\Api\Context $context) => $context->getActor()->can('lcoy-waterfall.upload')),
        ]),

    // Database searcher powering `filter[user]` / `filter[set]` on the list
    // endpoints.
    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(WaterfallImage::class, WaterfallImageSearcher::class)
        ->addFilter(WaterfallImageSearcher::class, IdFilter::class)
        ->addFilter(WaterfallImageSearcher::class, UserFilter::class)
        ->addFilter(WaterfallImageSearcher::class, SetFilter::class)
        ->addSearcher(WaterfallSet::class, WaterfallSetSearcher::class)
        ->addFilter(WaterfallSetSearcher::class, IdFilter::class)
        ->addFilter(WaterfallSetSearcher::class, UserFilter::class),

    // Upload and score jobs are dispatched through Flarum's built-in queue
    // system (sync / database / redis / file, as configured by the site) onto
    // the default queue, so a single `php flarum queue:work` handles them.

    // Attribute defaults. Flarum's AbstractModel resets `$attributes` in its
    // constructor and only merges the values registered here, so a model's own
    // `protected $attributes` property is ignored.
    (new Extend\Model(WaterfallSet::class))
        ->default('status', WaterfallSet::STATUS_PENDING)
        ->default('images_count', 0)
        ->default('likes_count', 0)
        ->default('views_count', 0)
        ->default('score', 0),
    (new Extend\Model(WaterfallImage::class))
        ->default('status', WaterfallImage::STATUS_PENDING)
        ->default('likes_count', 0)
        ->default('views_count', 0)
        ->default('score', 0),

    // Model policies for per-image/per-set abilities.
    (new Extend\Policy())
        ->modelPolicy(WaterfallImage::class, WaterfallImagePolicy::class)
        ->modelPolicy(WaterfallSet::class, WaterfallSetPolicy::class),

    // Extension service provider: interface bindings.
    (new Extend\ServiceProvider())
        ->register(WaterfallServiceProvider::class),

    // Default values for every backend setting. Keys are namespaced with the
    // extension id, as required by the settings system.
    (new Extend\Settings())
        ->default('lcoy-waterfall.upload_url', 'https://your.domain/upload')
        ->default('lcoy-waterfall.extra_params', '')
        ->default('lcoy-waterfall.extra_headers', '')
        ->default('lcoy-waterfall.basic_auth_user', '')
        ->default('lcoy-waterfall.basic_auth_pass', '')
        ->default('lcoy-waterfall.mime_whitelist', 'jpg,jpeg,png,gif,webp')
        ->default('lcoy-waterfall.max_size_mb', 10)
        ->default('lcoy-waterfall.user_hourly_limit', 20)
        ->default('lcoy-waterfall.global_per_minute_limit', 60)
        ->default('lcoy-waterfall.user_concurrent_uploads', 3)
        ->default('lcoy-waterfall.upload_timeout', 30)
        ->default('lcoy-waterfall.weight_likes', 1.0)
        ->default('lcoy-waterfall.weight_views', 0.3)
        ->default('lcoy-waterfall.weight_recency', 1.0)
        ->default('lcoy-waterfall.decay_lambda', 0.05)
        ->default('lcoy-waterfall.per_page', 24)
        ->default('lcoy-waterfall.card_radius', 8)
        ->default('lcoy-waterfall.card_gutter', 12)
        ->default('lcoy-waterfall.show_like_button', true)
        ->default('lcoy-waterfall.slideshow_images', 3)
        ->default('lcoy-waterfall.local_relay', false)
        ->default('lcoy-waterfall.poll_interval', 5)
        // Empty by default: the intro is optional content, and a site that
        // never fills it in should not get a placeholder sentence it has to
        // notice and delete. The frontend hides the element when it is blank.
        ->default('lcoy-waterfall.description', '')
        // Values the forum frontend needs at boot. Saving any of these clears
        // the JS cache so the new values are picked up.
        ->serializeToForum('waterfallPerPage', 'lcoy-waterfall.per_page', 'intVal')
        ->serializeToForum('waterfallCardRadius', 'lcoy-waterfall.card_radius', 'intVal')
        ->serializeToForum('waterfallCardGutter', 'lcoy-waterfall.card_gutter', 'intVal')
        ->serializeToForum('waterfallShowLikeButton', 'lcoy-waterfall.show_like_button', fn ($value) => (bool) $value)
        ->serializeToForum('waterfallSlideshowImages', 'lcoy-waterfall.slideshow_images', 'intVal')
        ->serializeToForum('waterfallPollInterval', 'lcoy-waterfall.poll_interval', 'intVal')
        ->serializeToForum('waterfallMimeWhitelist', 'lcoy-waterfall.mime_whitelist')
        ->serializeToForum('waterfallDescription', 'lcoy-waterfall.description')
        ->resetJsCacheFor('lcoy-waterfall.per_page')
        ->resetJsCacheFor('lcoy-waterfall.card_radius')
        ->resetJsCacheFor('lcoy-waterfall.card_gutter')
        ->resetJsCacheFor('lcoy-waterfall.show_like_button')
        ->resetJsCacheFor('lcoy-waterfall.slideshow_images')
        ->resetJsCacheFor('lcoy-waterfall.poll_interval')
        ->resetJsCacheFor('lcoy-waterfall.mime_whitelist')
        ->resetJsCacheFor('lcoy-waterfall.description'),

    new Extend\Locales(__DIR__.'/locale'),
];
