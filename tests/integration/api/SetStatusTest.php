<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Lcoy\Waterfall\Model\WaterfallSet;
use PHPUnit\Framework\Attributes\Test;

/**
 * The feed's poll target: GET /api/waterfall/set-status?ids=...
 *
 * It exists to answer one question cheaply, so what matters is that the
 * answer is the same one the feed would give — in particular that it cannot
 * be used to discover someone else's unfinished upload.
 */
class SetStatusTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('lcoy-waterfall');

        $now = Carbon::now();

        $row = fn (int $id, int $user, string $status) => [
            'id' => $id,
            'user_id' => $user,
            'title' => 'Set '.$id,
            'images_count' => 0,
            'likes_count' => 0,
            'views_count' => 0,
            'score' => 0,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
            ],
            WaterfallSet::class => [
                $row(5, 2, 'published'),
                // Owned by user 3: user 2 must not learn that it exists.
                $row(6, 3, 'pending'),
                $row(7, 2, 'pending'),
            ],
        ]);
    }

    #[Test]
    public function a_guest_sees_only_published_sets()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall/set-status?ids=5,6,7')
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertSame(['5' => 'published'], $body);
    }

    #[Test]
    public function the_owner_sees_their_own_pending_set()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall/set-status?ids=5,6,7', ['authenticatedAs' => 2])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        // Their own pending set, and the published one; not user 3's.
        $this->assertSame(['5' => 'published', '7' => 'pending'], $body);
    }

    #[Test]
    public function ids_that_are_not_numbers_are_ignored()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall/set-status?ids=abc,5,,%20')
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame(['5' => 'published'], json_decode($response->getBody(), true));
    }

    #[Test]
    public function an_empty_request_answers_with_an_empty_map()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall/set-status')
        );

        $this->assertEquals(200, $response->getStatusCode());
        // An object, not `[]`: the client indexes it by id.
        $this->assertSame('{}', (string) $response->getBody());
    }
}
