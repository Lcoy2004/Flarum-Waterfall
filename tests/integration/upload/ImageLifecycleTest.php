<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Tests\integration\upload;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\Upload\ImageLifecycle;
use PHPUnit\Framework\Attributes\Test;

/**
 * The upload state machine. The happy paths are covered end-to-end through the
 * queue job in UploadTest; what is pinned here is the part only this class can
 * answer — what a transition reports, and what it must refuse to do, when the
 * row is not in the state the caller assumed.
 *
 * That second half is the reason the transitions are guarded at all: a
 * delivery is not necessarily the only one (Flarum hands a job to a second
 * worker once retry_after passes), so a late run can arrive after another one
 * has already published the image. It must not be able to un-publish it.
 */
class ImageLifecycleTest extends TestCase
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
                ['id' => 20, 'user_id' => 2, 'title' => 'Pair', 'cover_image_id' => 30, 'images_count' => 1, 'likes_count' => 0, 'views_count' => 30, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
            WaterfallImage::class => [
                // Already live: what a late delivery finds.
                ['id' => 30, 'user_id' => 2, 'set_id' => 20, 'position' => 0, 'src' => '/file/live.png', 'thumb' => null, 'title' => 'Live', 'likes_count' => 0, 'views_count' => 30, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                // Still staged: what a transfer is about to move. views_count is
                // non-zero so the score it gains is visibly above zero.
                ['id' => 31, 'user_id' => 2, 'set_id' => 20, 'position' => 1, 'src' => '', 'thumb' => null, 'title' => 'Staged', 'likes_count' => 0, 'views_count' => 30, 'score' => 0, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    #[Test]
    public function publishing_a_staged_row_moves_it_and_its_set_with_it()
    {
        $lifecycle = $this->lifecycle();

        $this->assertTrue($lifecycle->publish($this->image(31), '/file/uploaded.png', '/file/uploaded-thumb.png'));

        $image = $this->image(31);

        $this->assertEquals(WaterfallImage::STATUS_PUBLISHED, $image->status);
        $this->assertEquals('/file/uploaded.png', $image->src);
        $this->assertEquals('/file/uploaded-thumb.png', $image->thumb);
        $this->assertNull($image->error);
        // Scored in the same write, from the counters the row already had.
        $this->assertGreaterThan(0, $image->score);

        // And the set's denormalised row followed, without the caller having to
        // ask for it.
        $set = WaterfallSet::query()->find(20);

        $this->assertEquals(2, $set->images_count);
        $this->assertEquals(WaterfallSet::STATUS_PUBLISHED, $set->status);
    }

    #[Test]
    public function failing_a_staged_row_marks_it_and_resyncs_its_set()
    {
        $this->assertTrue($this->lifecycle()->fail($this->image(31), 'image host timed out'));

        $image = $this->image(31);

        $this->assertEquals(WaterfallImage::STATUS_FAILED, $image->status);
        $this->assertEquals('image host timed out', $image->error);

        // The failed row drops out of the public count.
        $this->assertEquals(1, WaterfallSet::query()->find(20)->images_count);
    }

    #[Test]
    public function a_late_delivery_cannot_fail_an_image_that_is_already_live()
    {
        $image = $this->image(30);

        // What the job does when its transfer throws after another delivery
        // has already published the row.
        $this->assertFalse($this->lifecycle()->fail($image, 'image host unavailable'));

        $image = $this->image(30);

        $this->assertEquals(WaterfallImage::STATUS_PUBLISHED, $image->status);
        $this->assertNull($image->error);
    }

    #[Test]
    public function publishing_a_row_that_is_no_longer_staged_is_a_no_op()
    {
        $image = $this->image(30);

        $this->assertFalse($this->lifecycle()->publish($image, '/file/second-copy.png', null));

        // The copy the first delivery published is left as it was: a late run
        // must not repoint a live row at its own upload.
        $this->assertEquals('/file/live.png', $this->image(30)->src);
    }

    #[Test]
    public function publishing_a_row_that_was_deleted_mid_transfer_reports_moot()
    {
        $image = $this->image(31);

        WaterfallImage::query()->whereKey(31)->delete();

        $this->assertFalse($this->lifecycle()->publish($image, '/file/orphan.png', null));

        $this->assertNull($this->image(31));
    }

    protected function lifecycle(): ImageLifecycle
    {
        return $this->app()->getContainer()->make(ImageLifecycle::class);
    }

    /**
     * Load a fixture row. Goes through here rather than calling the model
     * directly because the app has to be booted before a model can resolve a
     * connection — the fixtures are inserted raw, and a test that reads one
     * before its first app() call would fail on a null connection.
     */
    protected function image(int $id): ?WaterfallImage
    {
        $this->app();

        return WaterfallImage::query()->find($id);
    }
}
