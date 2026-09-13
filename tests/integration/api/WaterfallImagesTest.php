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
use Lcoy\Waterfall\Model\WaterfallImageLike;
use Lcoy\Waterfall\Model\WaterfallSet;
use PHPUnit\Framework\Attributes\Test;

class WaterfallImagesTest extends TestCase
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
                ['id' => 3, 'username' => 'moderator', 'email' => 'moderator@machine.local', 'is_email_confirmed' => 1],
            ],
            WaterfallImage::class => [
                ['id' => 1, 'user_id' => 2, 'src' => '/file/a.png', 'thumb' => null, 'title' => 'Oldest', 'likes_count' => 0, 'views_count' => 0, 'score' => 0.1, 'status' => 'published', 'created_at' => $now->copy()->subDays(3), 'updated_at' => $now],
                ['id' => 2, 'user_id' => 3, 'src' => '/file/b.png', 'thumb' => null, 'title' => 'Popular', 'likes_count' => 42, 'views_count' => 500, 'score' => 3.4, 'status' => 'published', 'created_at' => $now->copy()->subDays(1), 'updated_at' => $now],
                ['id' => 3, 'user_id' => 2, 'src' => '/file/c.png', 'thumb' => null, 'title' => 'Newest', 'likes_count' => 1, 'views_count' => 2, 'score' => 1.2, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                // The uploader's pending image: visible to its owner only.
                ['id' => 4, 'user_id' => 2, 'src' => '', 'thumb' => null, 'title' => 'Pending', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
                // A failed image: visible to its owner only.
                ['id' => 5, 'user_id' => 2, 'src' => '', 'thumb' => null, 'title' => 'Failed', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'failed', 'error' => 'boom', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    #[Test]
    public function guest_can_list_published_images()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall-images')
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        // Pending and failed images must not be visible to guests.
        $this->assertCount(3, $body['data']);
        $this->assertEquals('Newest', $body['data'][0]['attributes']['title']);
    }

    #[Test]
    public function owner_sees_own_pending_and_failed_images()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall-images', ['authenticatedAs' => 2])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertCount(5, $body['data']);
    }

    #[Test]
    public function list_supports_score_sort()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall-images')
                ->withQueryParams(['sort' => '-score'])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertEquals('Popular', $body['data'][0]['attributes']['title']);
        $this->assertEquals(3.4, (float) $body['data'][0]['attributes']['score']);
    }

    #[Test]
    public function list_supports_user_filter()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall-images')
                ->withQueryParams(['filter' => ['user' => '3'], 'sort' => 'created_at'])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertCount(1, $body['data']);
        $this->assertEquals('Popular', $body['data'][0]['attributes']['title']);
    }

    #[Test]
    public function list_supports_set_filter_with_position_sort_and_pagination()
    {
        $now = Carbon::now();

        // The lightbox appends pages of a set's images through
        // filter[set]=X&sort=position&page[offset]=N (the set's `images`
        // include is capped server-side, so large sets page through here).
        $this->prepareDatabase([
            WaterfallSet::class => [
                ['id' => 10, 'user_id' => 2, 'title' => 'Paged', 'cover_image_id' => 21, 'images_count' => 3, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
            WaterfallImage::class => [
                ['id' => 20, 'user_id' => 2, 'set_id' => 10, 'position' => 2, 'src' => '/file/p3.png', 'thumb' => null, 'title' => 'Third', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 21, 'user_id' => 2, 'set_id' => 10, 'position' => 0, 'src' => '/file/p1.png', 'thumb' => null, 'title' => 'First', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 22, 'user_id' => 2, 'set_id' => 10, 'position' => 1, 'src' => '/file/p2.png', 'thumb' => null, 'title' => 'Second', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);

        // Page one: the set's images only, in position order — the images
        // seeded by setUp (no set) must not leak in.
        $response = $this->send(
            $this->request('GET', '/api/waterfall-images')
                ->withQueryParams(['filter' => ['set' => '10'], 'sort' => 'position'])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertSame(
            ['First', 'Second', 'Third'],
            array_map(fn (array $image) => $image['attributes']['title'], $body['data'])
        );

        // Page two (the lightbox's append semantics): offset past the first
        // row picks up exactly where the previous page ended.
        $response = $this->send(
            $this->request('GET', '/api/waterfall-images')
                ->withQueryParams(['filter' => ['set' => '10'], 'sort' => 'position', 'page' => ['offset' => 1, 'limit' => 1]])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertCount(1, $body['data']);
        $this->assertEquals('Second', $body['data'][0]['attributes']['title']);
    }

    #[Test]
    public function list_includes_user_and_likes()
    {
        $response = $this->send(
            $this->request('GET', '/api/waterfall-images?include=user,likes')
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('included', $body);
        $this->assertContains('users', array_column($body['included'], 'type'));
        $this->assertArrayHasKey('user', $body['data'][0]['relationships']);
        $this->assertArrayHasKey('likes', $body['data'][0]['relationships']);
    }

    #[Test]
    public function guest_cannot_upload()
    {
        $response = $this->send(
            $this->request('POST', '/api/waterfall-images')
                // Bypass CSRF so the request reaches the endpoint's own
                // authentication check and yields 401 rather than 400.
                ->withAttribute('bypassCsrfToken', true)
        );

        $this->assertEquals(401, $response->getStatusCode());
    }

    #[Test]
    public function guest_cannot_like()
    {
        $response = $this->send(
            $this->request('POST', '/api/waterfall-images/2/like')
                ->withAttribute('bypassCsrfToken', true)
        );

        $this->assertEquals(401, $response->getStatusCode());
    }

    #[Test]
    public function like_is_idempotent()
    {
        $response = $this->send(
            $this->request('POST', '/api/waterfall-images/2/like', ['authenticatedAs' => 2])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertEquals(43, $body['data']['attributes']['likesCount']);
        $this->assertTrue($body['data']['attributes']['isLiked']);

        // Liking again must not double-count.
        $response = $this->send(
            $this->request('POST', '/api/waterfall-images/2/like', ['authenticatedAs' => 2])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertEquals(43, $body['data']['attributes']['likesCount']);

        // And the unlike brings it back down.
        $response = $this->send(
            $this->request('DELETE', '/api/waterfall-images/2/like', ['authenticatedAs' => 2])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertEquals(42, $body['data']['attributes']['likesCount']);
        $this->assertFalse($body['data']['attributes']['isLiked']);

        // Unlike physically removes the like row.
        $this->assertEquals(0, WaterfallImageLike::query()->where('image_id', 2)->where('user_id', 2)->count());
    }

    #[Test]
    public function owner_can_delete_own_image()
    {
        $response = $this->send(
            $this->request('DELETE', '/api/waterfall-images/3', ['authenticatedAs' => 2])
        );

        $this->assertEquals(204, $response->getStatusCode());
        $this->assertNull(WaterfallImage::find(3));
    }

    #[Test]
    public function other_user_cannot_delete_image()
    {
        $response = $this->send(
            $this->request('DELETE', '/api/waterfall-images/3', ['authenticatedAs' => 3])
        );

        // User 3 is not a moderator, so deletion must be denied.
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertNotNull(WaterfallImage::find(3));
    }
}
