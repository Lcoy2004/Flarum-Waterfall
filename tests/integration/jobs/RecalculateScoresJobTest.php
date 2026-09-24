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
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Lcoy\Waterfall\Jobs\RecalculateScoresJob;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\Recommend\ScoreCalculatorInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * The job carries a batch so that scoring a page of viewed images costs one
 * queue delivery — and one aggregate sum per set — instead of one of each per
 * image.
 */
class RecalculateScoresJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('lcoy-waterfall');

        $now = Carbon::now();

        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'member', 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
            ],
            WaterfallSet::class => [
                ['id' => 20, 'user_id' => 2, 'title' => 'Pair', 'cover_image_id' => 30, 'images_count' => 2, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
            WaterfallImage::class => [
                ['id' => 30, 'user_id' => 2, 'set_id' => 20, 'position' => 0, 'src' => '/file/s1.png', 'thumb' => null, 'title' => 'First', 'likes_count' => 3, 'views_count' => 30, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 31, 'user_id' => 2, 'set_id' => 20, 'position' => 1, 'src' => '/file/s2.png', 'thumb' => null, 'title' => 'Second', 'likes_count' => 1, 'views_count' => 5, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                // A legacy row without a set: it is scored on its own, and
                // there is no aggregate to re-sync for it.
                ['id' => 32, 'user_id' => 2, 'set_id' => null, 'position' => 0, 'src' => '/file/legacy.png', 'thumb' => null, 'title' => 'Legacy', 'likes_count' => 0, 'views_count' => 2, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    #[Test]
    public function batch_scores_every_image_and_resyncs_each_set_once()
    {
        $this->runJob([30, 31, 32, 999]);

        $first = WaterfallImage::query()->find(30);
        $second = WaterfallImage::query()->find(31);
        $legacy = WaterfallImage::query()->find(32);

        // Every id carried by the batch was scored — not just the first — and
        // the unknown id was skipped rather than failing the delivery.
        $this->assertGreaterThan(0, $first->score);
        $this->assertGreaterThan(0, $second->score);
        $this->assertGreaterThan(0, $legacy->score);

        // A set's aggregate is the sum of its images' scores, and it was
        // re-synced from the batch's results.
        $this->assertEqualsWithDelta($first->score + $second->score, WaterfallSet::query()->find(20)->score, 0.0001);
    }

    #[Test]
    public function a_batch_whose_images_are_gone_is_a_no_op()
    {
        // Booting first: the fixtures above are inserted raw, and touching a
        // model before the container exists has no connection to resolve.
        $this->app();

        WaterfallImage::query()->whereIn('id', [30, 31])->delete();

        // No exception, nothing written: the rows were deleted between
        // queueing and execution, which is not a failure.
        $this->runJob([30, 31]);

        $this->assertEquals(0, WaterfallSet::query()->find(20)->score);
    }

    /**
     * @param  int[]  $imageIds
     */
    protected function runJob(array $imageIds): void
    {
        (new RecalculateScoresJob($imageIds))->handle(
            $this->app()->getContainer()->make(ScoreCalculatorInterface::class),
            // The queue is only touched when a batch is long enough to be split
            // (MAX_IMAGES_PER_JOB); under the sync driver any remainder runs
            // inline, which is exactly what these tests then observe.
            $this->app()->getContainer()->make(Queue::class)
        );
    }
}
