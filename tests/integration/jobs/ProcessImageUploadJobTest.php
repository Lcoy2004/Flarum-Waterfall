<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Tests\integration\jobs;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Lcoy\Waterfall\Jobs\ProcessImageUploadJob;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\RateLimit\RateLimiter;
use Lcoy\Waterfall\Upload\ExternalImageHostUploader;
use Lcoy\Waterfall\Upload\ImageLifecycle;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

/**
 * The job's ordinary paths run end-to-end through the sync queue in UploadTest.
 * What is pinned here is the delivery that finds its work already done — the
 * one that has to leave the set in a state the earlier run did not reach.
 */
class ProcessImageUploadJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('lcoy-waterfall');
    }

    #[Test]
    public function a_redelivery_repairs_a_set_the_publishing_run_left_unsynced()
    {
        $now = Carbon::now();

        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'member', 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
            ],
            WaterfallSet::class => [
                // What the set looks like when a run published its image and
                // then died at the aggregate sync that follows: the row is live,
                // the set still counts nothing and has no cover.
                ['id' => 60, 'user_id' => 2, 'title' => 'Stale', 'cover_image_id' => null, 'images_count' => 0, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ],
            WaterfallImage::class => [
                ['id' => 61, 'user_id' => 2, 'set_id' => 60, 'position' => 0, 'src' => '/file/live.png', 'thumb' => null, 'title' => 'Live', 'likes_count' => 0, 'views_count' => 0, 'score' => 1.5, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);

        $container = $this->app()->getContainer();

        // No staged file: the run that resolved the row deleted it, so this
        // delivery has nothing to transfer and leaves through the same branch
        // every later one would.
        (new ProcessImageUploadJob(61, '/nowhere/never-staged.png', 'image.png'))->handle(
            $container->make(ExternalImageHostUploader::class),
            $container->make(RateLimiter::class),
            $container->make(SettingsRepositoryInterface::class),
            $container->make(LoggerInterface::class),
            $container->make(Dispatcher::class),
            $container->make(ImageLifecycle::class)
        );

        $set = WaterfallSet::query()->find(60);

        $this->assertEquals(1, $set->images_count);
        $this->assertEquals(61, $set->cover_image_id);
        $this->assertEquals(WaterfallSet::STATUS_PUBLISHED, $set->status);
        $this->assertEqualsWithDelta(1.5, $set->score, 0.0001);
    }
}
