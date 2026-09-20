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
use Flarum\Group\Group;
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
                // Both are plain members. User 3 used to be called
                // "moderator" without being in the group, which is how the
                // moderate branch below went untested for so long.
                ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
            ],
            // Group 4 is Flarum's moderators group; the extension's permission
            // migration grants lcoy-waterfall.moderate to it.
            'group_user' => [
                ['user_id' => 4, 'group_id' => Group::MODERATOR_ID],
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

    /**
     * The other half of the same policy: lcoy-waterfall.moderate is what the
     * "delete any" ability is actually for, and nothing exercised it.
     */
    #[Test]
    public function moderator_can_delete_another_users_image()
    {
        $response = $this->send(
            $this->request('DELETE', '/api/waterfall-images/3', ['authenticatedAs' => 4])
        );

        $this->assertEquals(204, $response->getStatusCode());
        $this->assertNull(WaterfallImage::find(3));
    }

    /**
     * Only a published image is counted: a card still being processed has no
     * public view to report, and the counter feeds the recommendation score.
     */
    #[Test]
    public function view_of_an_unpublished_image_is_not_counted()
    {
        // Image 4 is the uploader's own pending one, so it is visible to them
        // and the request reaches the counting branch rather than a 404.
        $this->send(
            $this->request('POST', '/api/waterfall-images/4/view', ['authenticatedAs' => 2])
        );

        $this->assertEquals(0, WaterfallImage::query()->find(4)->views_count);
    }

    /**
     * The batch endpoint has to count exactly what the per-image one would:
     * each published id once (a repeat inside the payload is not a second
     * view), nothing for unpublished or unknown ids, and the owning set's
     * total moving with the images it aggregates.
     */
    #[Test]
    public function batch_views_count_each_published_image_once()
    {
        $now = Carbon::now();

        $this->prepareDatabase([
            WaterfallSet::class => [
                ['id' => 20, 'user_id' => 2, 'title' => 'Pair', 'cover_image_id' => 30, 'images_count' => 2, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
            WaterfallImage::class => [
                ['id' => 30, 'user_id' => 2, 'set_id' => 20, 'position' => 0, 'src' => '/file/s1.png', 'thumb' => null, 'title' => 'First', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 31, 'user_id' => 2, 'set_id' => 20, 'position' => 1, 'src' => '/file/s2.png', 'thumb' => null, 'title' => 'Second', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                // A legacy row without a set: it still counts on its own, it
                // just has no set total to move.
                ['id' => 32, 'user_id' => 2, 'set_id' => null, 'position' => 0, 'src' => '/file/legacy.png', 'thumb' => null, 'title' => 'Legacy', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 33, 'user_id' => 2, 'set_id' => 20, 'position' => 2, 'src' => '', 'thumb' => null, 'title' => 'Pending', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);

        // Anonymous, like a reader who is not logged in: the view counter is
        // open to guests, so it is the guest path that has to be covered.
        $response = $this->send(
            $this->requestWithCsrfToken(
                $this->requestWithJsonBody(
                    $this->request('POST', '/api/waterfall-images/views'),
                    ['ids' => [30, 31, 32, 33, 999, 30]]
                )
            )
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertEquals(['counted' => 3], json_decode((string) $response->getBody(), true));

        $this->assertEquals(1, WaterfallImage::query()->find(30)->views_count);
        $this->assertEquals(1, WaterfallImage::query()->find(31)->views_count);
        $this->assertEquals(1, WaterfallImage::query()->find(32)->views_count);
        $this->assertEquals(0, WaterfallImage::query()->find(33)->views_count);
        $this->assertEquals(2, WaterfallSet::query()->find(20)->views_count);
    }

    /**
     * A counted view is one per IP per image per minute, so replaying a batch
     * — the same reader reopening the lightbox, or a scripted client — must
     * leave the counters where they were.
     */
    #[Test]
    public function batch_views_are_counted_once_per_ip()
    {
        $now = Carbon::now();

        $this->prepareDatabase([
            WaterfallImage::class => [
                ['id' => 40, 'user_id' => 2, 'set_id' => null, 'position' => 0, 'src' => '/file/x.png', 'thumb' => null, 'title' => 'Once', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);

        for ($i = 0; $i < 2; $i++) {
            $this->send(
                $this->requestWithCsrfToken(
                    $this->requestWithJsonBody(
                        $this->request('POST', '/api/waterfall-images/views'),
                        ['ids' => [40]]
                    )
                )
            );
        }

        $this->assertEquals(1, WaterfallImage::query()->find(40)->views_count);
    }

    /**
     * The two view endpoints have to agree on what a view is. The batch route
     * is what the lightbox uses; the per-image one stays part of the public
     * API (documented, and what any other client calls), and both write the
     * counter the recommendation score reads. Each direction is covered, since
     * sharing the window is the property, not the call order.
     */
    #[Test]
    public function the_single_and_batch_endpoints_share_the_counting_window()
    {
        $now = Carbon::now();

        $this->prepareDatabase([
            WaterfallImage::class => [
                ['id' => 50, 'user_id' => 2, 'set_id' => null, 'position' => 0, 'src' => '/file/shared-a.png', 'thumb' => null, 'title' => 'Shared A', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 51, 'user_id' => 2, 'set_id' => null, 'position' => 0, 'src' => '/file/shared-b.png', 'thumb' => null, 'title' => 'Shared B', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);

        $single = fn (int $id) => $this->requestWithCsrfToken($this->request('POST', "/api/waterfall-images/$id/view"));
        $batch = fn (int $id) => $this->requestWithCsrfToken(
            $this->requestWithJsonBody(
                $this->request('POST', '/api/waterfall-images/views'),
                ['ids' => [$id]]
            )
        );

        // Image 50: the per-image route first, the batch second.
        $this->send($single(50));
        $this->send($batch(50));

        // Image 51: the other way round.
        $this->send($batch(51));
        $this->send($single(51));

        $this->assertEquals(1, WaterfallImage::query()->find(50)->views_count);
        $this->assertEquals(1, WaterfallImage::query()->find(51)->views_count);
    }

    /**
     * An empty or malformed payload is a no-op rather than an error: the
     * lightbox only ever sends what it viewed, and a client sending nothing
     * must not cost a query.
     */
    #[Test]
    public function batch_views_ignore_an_empty_payload()
    {
        $response = $this->send(
            $this->requestWithCsrfToken(
                $this->requestWithJsonBody(
                    $this->request('POST', '/api/waterfall-images/views'),
                    ['ids' => [0, -1, 'nope']]
                )
            )
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertEquals(['counted' => 0], json_decode((string) $response->getBody(), true));
    }

    /**
     * Deleting an image has to leave its set's denormalised counters and cover
     * in step with the rows that are left: the feed reads those columns without
     * joining the images table. This said the feed's pagination offset came
     * from images_count, which was never true — that offset counts sets
     * (WaterfallState.offset), and images_count is display-only.
     */
    #[Test]
    public function deleting_an_image_refreshes_its_set_aggregates()
    {
        $now = Carbon::now();

        $this->prepareDatabase([
            WaterfallSet::class => [
                ['id' => 20, 'user_id' => 2, 'title' => 'Pair', 'cover_image_id' => 30, 'images_count' => 2, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
            WaterfallImage::class => [
                ['id' => 30, 'user_id' => 2, 'set_id' => 20, 'position' => 0, 'src' => '/file/s1.png', 'thumb' => null, 'title' => 'Cover', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 31, 'user_id' => 2, 'set_id' => 20, 'position' => 1, 'src' => '/file/s2.png', 'thumb' => null, 'title' => 'Second', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);

        $response = $this->send(
            $this->request('DELETE', '/api/waterfall-images/31', ['authenticatedAs' => 2])
        );

        $this->assertEquals(204, $response->getStatusCode());

        $set = WaterfallSet::find(20);

        $this->assertEquals(1, $set->images_count);
        $this->assertEquals(30, $set->cover_image_id);
        $this->assertEquals(WaterfallSet::STATUS_PUBLISHED, $set->status);

        // Removing the last image takes the now-empty set with it: a set with
        // no images has nothing to show and would render as a blank card.
        $response = $this->send(
            $this->request('DELETE', '/api/waterfall-images/30', ['authenticatedAs' => 2])
        );

        $this->assertEquals(204, $response->getStatusCode());
        $this->assertNull(WaterfallSet::find(20));
    }

    /**
     * images_count is the set's *public* count. The card badge that reads it is
     * shown to visitors, who can only open the published images, so a failed or
     * still-processing one must not be added to it — cover_image_id and status
     * are already picked from that same subset.
     */
    #[Test]
    public function set_aggregates_count_only_published_images()
    {
        $now = Carbon::now();

        $this->prepareDatabase([
            WaterfallSet::class => [
                ['id' => 20, 'user_id' => 2, 'title' => 'One of two', 'cover_image_id' => 30, 'images_count' => 2, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
            ],
            WaterfallImage::class => [
                ['id' => 30, 'user_id' => 2, 'set_id' => 20, 'position' => 0, 'src' => '/file/s1.png', 'thumb' => null, 'title' => 'Published', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 31, 'user_id' => 2, 'set_id' => 20, 'position' => 1, 'src' => '', 'thumb' => null, 'title' => 'Failed', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'failed', 'error' => 'boom', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);

        // Deleting the published image forces a recomputation with only the
        // failed one left.
        $response = $this->send(
            $this->request('DELETE', '/api/waterfall-images/30', ['authenticatedAs' => 2])
        );

        $this->assertEquals(204, $response->getStatusCode());

        $set = WaterfallSet::find(20);

        // Zero, not one: the failed image is not part of the public count. The
        // set itself survives — it still holds a row, and the card has to tell
        // its uploader that the transfer failed rather than disappear.
        $this->assertEquals(0, $set->images_count);
        $this->assertNull($set->cover_image_id);
        $this->assertEquals(WaterfallSet::STATUS_FAILED, $set->status);
    }
}
