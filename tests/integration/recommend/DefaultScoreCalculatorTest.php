<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Tests\integration\recommend;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Recommend\ScoreCalculatorInterface;
use Lcoy\Waterfall\Recommend\DefaultScoreCalculator;
use PHPUnit\Framework\Attributes\Test;

/**
 * The score is written into a decimal column on every upload and every
 * recalculation, so what this class returns has to be a number that fits one.
 * The admin field that feeds it is not guarded — Flarum's Form renders a plain
 * div and saves on a button click, so the browser never runs the field's
 * constraint validation, and the setting can also be written from the CLI.
 */
class DefaultScoreCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('lcoy-waterfall');
    }

    #[Test]
    public function a_negative_decay_lambda_cannot_produce_an_infinite_score()
    {
        // A month-old image at lambda = -1 evaluates exp(+720), which is INF by
        // the time it is rounded — and INF does not fit the column.
        $this->settings['lcoy-waterfall.decay_lambda'] = -1;

        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'member', 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
            ],
            WaterfallImage::class => [
                ['id' => 40, 'user_id' => 2, 'set_id' => null, 'position' => 0, 'src' => '/file/old.png', 'thumb' => null, 'title' => 'Old', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => Carbon::now()->subDays(30), 'updated_at' => Carbon::now()],
            ],
        ]);

        $score = $this->calculator()->calculate(WaterfallImage::query()->find(40));

        $this->assertIsFloat($score);
        $this->assertFalse(is_infinite($score));
        $this->assertFalse(is_nan($score));
    }

    #[Test]
    public function a_negative_decay_lambda_is_read_as_no_decay()
    {
        $this->settings['lcoy-waterfall.decay_lambda'] = -1;

        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'member', 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
            ],
            WaterfallImage::class => [
                ['id' => 41, 'user_id' => 2, 'set_id' => null, 'position' => 0, 'src' => '/file/old.png', 'thumb' => null, 'title' => 'Old', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => Carbon::now()->subDays(30), 'updated_at' => Carbon::now()],
            ],
        ]);

        // Weights default to 1.0 / 0.3 / 1.0 with no likes and no views, so the
        // recency term is the whole score — and with the decay clamped away it
        // is at full strength rather than inverted.
        $this->assertEqualsWithDelta(1.0, $this->calculator()->calculate(WaterfallImage::query()->find(41)), 0.0001);
    }

    #[Test]
    public function a_sane_decay_lambda_still_discounts_an_old_image()
    {
        $this->settings['lcoy-waterfall.decay_lambda'] = 0.05;

        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'member', 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
            ],
            WaterfallImage::class => [
                ['id' => 42, 'user_id' => 2, 'set_id' => null, 'position' => 0, 'src' => '/file/old.png', 'thumb' => null, 'title' => 'Old', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => Carbon::now()->subDays(30), 'updated_at' => Carbon::now()],
            ],
        ]);

        // exp(-0.05 * 720) is far below 1: the clamp must not have flattened
        // the normal case into "no decay at all".
        $this->assertLessThan(0.01, $this->calculator()->calculate(WaterfallImage::query()->find(42)));
    }

    protected function calculator(): ScoreCalculatorInterface
    {
        return $this->app()->getContainer()->make(DefaultScoreCalculator::class);
    }
}
