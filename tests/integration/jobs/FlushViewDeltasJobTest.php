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
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Queue;
use Lcoy\Waterfall\Jobs\FlushViewDeltasJob;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\View\ViewRecorder;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

/**
 * The batch view endpoint leaves its counts in the delta table; this job is
 * what moves them into the counters the feed and the score read.
 */
class FlushViewDeltasJobTest extends TestCase
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
                ['id' => 60, 'user_id' => 2, 'title' => 'Pair', 'cover_image_id' => 60, 'images_count' => 2, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
            WaterfallImage::class => [
                ['id' => 60, 'user_id' => 2, 'set_id' => 60, 'position' => 0, 'src' => '/file/f1.png', 'thumb' => null, 'title' => 'First', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 61, 'user_id' => 2, 'set_id' => 60, 'position' => 1, 'src' => '/file/f2.png', 'thumb' => null, 'title' => 'Second', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                // No set: it counts on its own, there is no aggregate to move.
                ['id' => 62, 'user_id' => 2, 'set_id' => null, 'position' => 0, 'src' => '/file/f3.png', 'thumb' => null, 'title' => 'Legacy', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                // Still being processed: it has no public view to carry.
                ['id' => 63, 'user_id' => 2, 'set_id' => 60, 'position' => 2, 'src' => '', 'thumb' => null, 'title' => 'Pending', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    #[Test]
    public function buffered_deltas_are_moved_into_the_image_and_set_counters()
    {
        $this->buffer([
            60 => 2,
            61 => 1,
            62 => 1,
            63 => 1, // pending: dropped like the synchronous path drops it
        ]);

        $this->flush();

        $this->assertEquals(2, WaterfallImage::query()->find(60)->views_count);
        $this->assertEquals(1, WaterfallImage::query()->find(61)->views_count);
        $this->assertEquals(1, WaterfallImage::query()->find(62)->views_count);
        $this->assertEquals(0, WaterfallImage::query()->find(63)->views_count);

        // The set total moved before the (windowed) score recalculation.
        $this->assertEquals(3, WaterfallSet::query()->find(60)->views_count);

        // Every consumed row is zeroed, so it cannot be applied a second time.
        $this->assertEquals([0, 0, 0, 0], $this->deltas());
    }

    #[Test]
    public function a_delta_for_a_deleted_image_is_discarded()
    {
        $this->buffer([
            60 => 1,
            999 => 4, // deleted between the report and the flush
        ]);

        $this->flush();

        $this->assertEquals(1, WaterfallImage::query()->find(60)->views_count);
        $this->assertEquals([0, 0], $this->deltas());
    }

    #[Test]
    public function flushing_twice_does_not_count_the_same_deltas_again()
    {
        $this->buffer([60 => 2]);

        $this->flush();
        $this->flush();

        // The second run finds nothing left to move.
        $this->assertEquals(2, WaterfallImage::query()->find(60)->views_count);
    }

    /**
     * @param  array<int, int>  $deltas  image id => buffered views
     */
    protected function buffer(array $deltas): void
    {
        $this->app();

        $now = Carbon::now();

        $this->connection()->table('waterfall_view_deltas')->insert(array_map(
            fn (int $id, int $delta): array => [
                'image_id' => $id,
                'delta' => $delta,
                'updated_at' => $now,
            ],
            array_keys($deltas),
            array_values($deltas)
        ));
    }

    /**
     * @return int[] the buffered deltas, ordered by image id
     */
    protected function deltas(): array
    {
        return $this->connection()->table('waterfall_view_deltas')
            ->orderBy('image_id')
            ->pluck('delta')
            ->map(fn ($delta): int => (int) $delta)
            ->all();
    }

    protected function flush(): void
    {
        $container = $this->app()->getContainer();

        (new FlushViewDeltasJob())->handle(
            $container->make(ViewRecorder::class),
            $container->make(CacheRepository::class),
            $container->make(Queue::class),
            $container->make(LoggerInterface::class)
        );
    }

    protected function connection()
    {
        return (new WaterfallImage)->getConnection();
    }
}
