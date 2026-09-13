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
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use PHPUnit\Framework\Attributes\Test;

class WaterfallSetsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('lcoy-waterfall');

        $now = Carbon::now();

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
            ],
            WaterfallSet::class => [
                ['id' => 1, 'user_id' => 2, 'title' => 'Mine', 'cover_image_id' => 2, 'images_count' => 2, 'likes_count' => 5, 'views_count' => 10, 'score' => 2.5, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'user_id' => 3, 'title' => 'Theirs', 'cover_image_id' => null, 'images_count' => 0, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now->copy()->subDay(), 'updated_at' => $now],
                // Owner-only sets: pending and failed.
                ['id' => 3, 'user_id' => 2, 'title' => 'Pending', 'cover_image_id' => null, 'images_count' => 1, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 4, 'user_id' => 2, 'title' => 'Failed', 'cover_image_id' => null, 'images_count' => 1, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'failed', 'created_at' => $now, 'updated_at' => $now],
            ],
            WaterfallImage::class => [
                // Position 1 before position 0 on purpose: the include must
                // return them ordered by position, not by id.
                ['id' => 1, 'user_id' => 2, 'set_id' => 1, 'position' => 1, 'src' => '/file/a.png', 'thumb' => null, 'title' => 'Second', 'likes_count' => 3, 'views_count' => 5, 'score' => 1.0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'user_id' => 2, 'set_id' => 1, 'position' => 0, 'src' => '/file/b.png', 'thumb' => null, 'title' => 'First', 'likes_count' => 2, 'views_count' => 5, 'score' => 1.5, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    #[Test]
    public function guest_can_list_published_sets()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall-sets')
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        // Only the two published sets are visible to guests.
        $this->assertCount(2, $body['data']);
        $this->assertEquals('Mine', $body['data'][0]['attributes']['title']);
        $this->assertEquals(2, $body['data'][0]['attributes']['imagesCount']);
        $this->assertEquals(5, $body['data'][0]['attributes']['likesCount']);
        $this->assertEquals(10, $body['data'][0]['attributes']['viewsCount']);
    }

    #[Test]
    public function owner_sees_own_pending_and_failed_sets()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall-sets', ['authenticatedAs' => 2])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertCount(4, $body['data']);
    }

    #[Test]
    public function poll_fetches_sets_by_id()
    {
        // The frontend pending-upload poll fetches sets by id (it must reach
        // them even under "-score" sorting, where pending sets never appear
        // on the first page). This must filter on the sets table, not the
        // images table — a regression here 500s every poll while an upload
        // is in flight.
        $response = $this->send(
            $this->request('GET', '/api/waterfall-sets', ['authenticatedAs' => 2])
                ->withQueryParams(['filter' => ['id' => '3,1'], 'sort' => '-score'])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertCount(2, $body['data']);
        $this->assertEqualsCanonicalizing(['Mine', 'Pending'], array_map(
            fn (array $set) => $set['attributes']['title'],
            $body['data']
        ));
    }

    #[Test]
    public function show_includes_images_ordered_by_position()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall-sets/1')->withQueryParams(['include' => 'images'])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $images = array_values(array_filter($body['included'], fn (array $item) => $item['type'] === 'waterfall-images'));

        $this->assertCount(2, $images);
        $this->assertEquals('First', $images[0]['attributes']['title']);
        $this->assertEquals('Second', $images[1]['attributes']['title']);
    }

    #[Test]
    public function user_can_create_a_set_and_title_is_normalised()
    {
        $response = $this->send(
            $this->request('POST', '/api/waterfall-sets', [
                'authenticatedAs' => 2,
                'json' => [
                    'data' => [
                        'attributes' => [
                            'title' => '  My   New   Set  ',
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertEquals('My New Set', $body['data']['attributes']['title']);
        $this->assertEquals('pending', $body['data']['attributes']['status']);
        $this->assertEquals(2, (int) WaterfallSet::find($body['data']['id'])->user_id);
    }

    #[Test]
    public function set_tags_are_normalised_on_create()
    {
        $response = $this->send(
            $this->request('POST', '/api/waterfall-sets', [
                'authenticatedAs' => 2,
                'json' => [
                    'data' => [
                        'attributes' => [
                            // Whitespace trimmed, duplicates dropped
                            // (case-insensitively), non-strings and empties
                            // skipped, capped at 5 tags / 20 chars.
                            'tags' => ['  Beach  ', 'beach', '', 42, '夏天去海边看日落非常漂亮的一张照片啊', 'sunset', 'holiday', 'extra', 'more'],
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertEquals(
            ['Beach', '夏天去海边看日落非常漂亮的一张照片啊', 'sunset', 'holiday', 'extra'],
            $body['data']['attributes']['tags']
        );
        $this->assertEquals(
            ['Beach', '夏天去海边看日落非常漂亮的一张照片啊', 'sunset', 'holiday', 'extra'],
            WaterfallSet::find($body['data']['id'])->tags
        );
    }

    #[Test]
    public function empty_tags_are_stored_as_null()
    {
        $response = $this->send(
            $this->request('POST', '/api/waterfall-sets', [
                'authenticatedAs' => 2,
                'json' => ['data' => ['attributes' => ['tags' => ['  ', '']]]],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertNull($body['data']['attributes']['tags']);
    }

    #[Test]
    public function other_user_cannot_edit_tags()
    {
        $response = $this->send(
            $this->request('PATCH', '/api/waterfall-sets/1', [
                'authenticatedAs' => 3,
                'json' => ['data' => ['attributes' => ['tags' => ['hijacked']]]],
            ])
        );

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertNull(WaterfallSet::find(1)->tags);
    }

    #[Test]
    public function guest_cannot_create_a_set()
    {
        $response = $this->send(
            $this->request('POST', '/api/waterfall-sets', [
                'json' => ['data' => ['attributes' => ['title' => 'Nope']]],
            ])->withAttribute('bypassCsrfToken', true)
        );

        $this->assertEquals(401, $response->getStatusCode());
    }

    #[Test]
    public function owner_can_rename_own_set()
    {
        $response = $this->send(
            $this->request('PATCH', '/api/waterfall-sets/1', [
                'authenticatedAs' => 2,
                'json' => [
                    'data' => [
                        'attributes' => [
                            'title' => '  Renamed   Set ',
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertEquals('Renamed Set', $body['data']['attributes']['title']);
        $this->assertEquals('Renamed Set', WaterfallSet::find(1)->title);
    }

    #[Test]
    public function empty_title_is_stored_as_null()
    {
        $response = $this->send(
            $this->request('PATCH', '/api/waterfall-sets/1', [
                'authenticatedAs' => 2,
                'json' => ['data' => ['attributes' => ['title' => '   ']]],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $this->assertNull(WaterfallSet::find(1)->title);
    }

    #[Test]
    public function other_user_cannot_rename_set()
    {
        $response = $this->send(
            $this->request('PATCH', '/api/waterfall-sets/1', [
                'authenticatedAs' => 3,
                'json' => ['data' => ['attributes' => ['title' => 'Hijacked']]],
            ])
        );

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals('Mine', WaterfallSet::find(1)->title);
    }

    #[Test]
    public function owner_can_delete_own_set()
    {
        $response = $this->send(
            $this->request('DELETE', '/api/waterfall-sets/1', ['authenticatedAs' => 2])
        );

        $this->assertEquals(204, $response->getStatusCode());
        $this->assertNull(WaterfallSet::find(1));
    }

    #[Test]
    public function other_user_cannot_delete_set()
    {
        $response = $this->send(
            $this->request('DELETE', '/api/waterfall-sets/1', ['authenticatedAs' => 3])
        );

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertNotNull(WaterfallSet::find(1));
    }
}
