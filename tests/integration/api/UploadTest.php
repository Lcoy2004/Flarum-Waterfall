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
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\Model\WaterfallUploadLog;
use Lcoy\Waterfall\Upload\Exception\UploadException;
use Lcoy\Waterfall\Upload\ExternalImageHostUploader;
use Lcoy\Waterfall\Upload\UploadResult;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

class UploadTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /**
     * Minimal valid 1x1 transparent PNG (magic bytes satisfy the real-MIME
     * sniffing in UploadValidator).
     */
    protected const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * Minimal 1x1 JPEG, for the card copy. The browser emits WebP or JPEG for
     * it, and both are accepted where an upload's own format is not (a site
     * that allows only PNG uploads still gets card copies).
     */
    protected const JPEG_1X1 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

    /** @var string[] staged files this test created, removed in tearDown. */
    protected array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('lcoy-waterfall');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
            ],
        ]);
    }

    protected function tearDown(): void
    {
        // The harness rolls the database back but knows nothing about the
        // filesystem; without this every test that stages a file would leave
        // it in the system temp dir.
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    #[Test]
    public function upload_succeeds_with_mocked_image_host()
    {
        $container = $this->app()->getContainer();

        // Swap the image host uploader for a fake that returns a fixed src.
        // With the sync queue driver (the default in the test environment)
        // ProcessImageUploadJob runs inline, so the response should already
        // contain the published image.
        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/mock-upload.png'));

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => $this->pngUpload()])
                ->withParsedBody(['title' => 'Mocked upload'])
        );

        $this->assertEquals(201, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertEquals('published', $body['data']['attributes']['status']);
        $this->assertEquals('/file/mock-upload.png', $body['data']['attributes']['src']);
        $this->assertEquals('Mocked upload', $body['data']['attributes']['title']);

        // A success row was written to the upload log.
        $log = WaterfallUploadLog::query()->where('image_id', $body['data']['id'])->first();

        $this->assertNotNull($log);
        $this->assertEquals(WaterfallUploadLog::STATUS_SUCCESS, $log->status);
        $this->assertEquals(200, $log->http_code);
    }

    #[Test]
    public function upload_failure_marks_image_failed_and_logs_reason()
    {
        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader(null));

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => $this->pngUpload()])
                ->withParsedBody(['title' => 'Doomed upload'])
        );

        // The resource itself was created; only the transfer failed.
        $this->assertEquals(201, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertEquals('failed', $body['data']['attributes']['status']);

        $log = WaterfallUploadLog::query()->where('image_id', $body['data']['id'])->first();

        $this->assertNotNull($log);
        $this->assertEquals(WaterfallUploadLog::STATUS_FAILED, $log->status);
        $this->assertEquals('mock image host unavailable', $log->error);
    }

    /**
     * A full upload capacity is reported under a stable `source.pointer`. The
     * upload modal reads that pointer to tell "the server is busy with this
     * uploader's own earlier files, wait and send it again" apart from a real
     * failure, so it is a contract between the two sides — and it cannot be
     * matched on the message, which is translated.
     */
    #[Test]
    public function a_full_upload_capacity_is_reported_under_a_stable_marker()
    {
        $this->settings['lcoy-waterfall.user_concurrent_uploads'] = 1;

        $this->prepareDatabase([
            WaterfallImage::class => [
                ['id' => 7, 'user_id' => 2, 'src' => '', 'thumb' => null, 'title' => 'In flight', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => WaterfallImage::STATUS_PENDING, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ],
        ]);

        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/mock-upload.png'));

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => $this->pngUpload()])
                ->withParsedBody(['title' => 'One too many'])
        );

        $this->assertEquals(422, $response->getStatusCode());

        $errors = json_decode($response->getBody(), true)['errors'];

        $this->assertEquals('/data/attributes/upload_capacity', $errors[0]['source']['pointer']);
    }

    #[Test]
    public function upload_rejects_disallowed_mime_type()
    {
        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/never.png'));

        // A plain text file masquerading as image/png in client metadata:
        // the magic-byte sniffing must reject it.
        $tmp = $this->writeTempFile('this is definitely not an image', 'png');

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'fake.png', 'image/png')])
        );

        $this->assertEquals(422, $response->getStatusCode());
    }

    #[Test]
    public function upload_still_works_when_the_mime_whitelist_is_emptied()
    {
        // Clearing the admin field must not reject every file with "this image
        // type is not allowed": that reads like a broken uploader rather than a
        // cleared setting, and it is a state an admin reaches by accident.
        $this->settings['lcoy-waterfall.mime_whitelist'] = '';

        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/mock-upload.png'));

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => $this->pngUpload()])
                ->withParsedBody(['title' => 'Blank whitelist'])
        );

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('published', json_decode($response->getBody(), true)['data']['attributes']['status']);
    }

    /**
     * The site-wide per-minute quota is what bounds how often the image host is
     * called. Running out of it must fail the upload rather than leave it
     * pending: a pending row is what the uploader's concurrency allowance
     * counts, so a job that quietly ended would lock them out of uploading
     * until they deleted the card by hand.
     */
    #[Test]
    public function an_exhausted_transfer_quota_fails_the_upload_instead_of_leaving_it_pending()
    {
        $this->settings['lcoy-waterfall.global_per_minute_limit'] = 1;

        $container = $this->app()->getContainer();

        // Fill the minute's bucket instead of spending it with a first upload:
        // that keeps the test independent of whatever an earlier test left in a
        // shared cache store.
        $cache = $container->make(CacheRepository::class);
        $key = 'lcoy-waterfall.transfers.'.date('YmdHi');

        $cache->forget($key);
        $cache->add($key, 99, 120);

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/mock-upload.png'));

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => $this->pngUpload()])
                ->withParsedBody(['title' => 'Over quota'])
        );

        // The resource is created; it is the transfer that could not run.
        $this->assertEquals(201, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        // The job runs on the sync driver here, which cannot release itself
        // back onto a queue — the case canBeDeferred() exists for. It has to
        // end in a definitive failure instead.
        $this->assertEquals('failed', $body['data']['attributes']['status']);

        $log = WaterfallUploadLog::query()->where('image_id', $body['data']['id'])->first();

        $this->assertEquals(WaterfallUploadLog::STATUS_FAILED, $log->status);
    }

    #[Test]
    public function browser_thumbnail_is_transferred_under_its_own_name()
    {
        $container = $this->app()->getContainer();

        // What the job actually asks the host to do: the filename of each
        // transfer, and the budget it was given.
        $transfers = [];

        $container->instance(
            ExternalImageHostUploader::class,
            $this->scriptedUploader(
                ['/file/orig.png', '/file/card.jpeg'],
                function (string $filename, ?int $budget) use (&$transfers): void {
                    $transfers[] = [$filename, $budget];
                }
            )
        );

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => $this->pngUpload(), 'thumb' => $this->jpegThumbUpload()])
                ->withParsedBody(['title' => 'With a card copy'])
        );

        $this->assertEquals(201, $response->getStatusCode());

        $attributes = json_decode($response->getBody(), true)['data']['attributes'];

        $this->assertEquals('/file/orig.png', $attributes['src']);
        // The host's second answer, not the full-size src it would fall back
        // to if the browser's copy had never been sent.
        $this->assertEquals('/file/card.jpeg', $attributes['thumb']);

        $this->assertCount(2, $transfers);

        // Only the extension travels: the uploader's own filename would put
        // raw UTF-8 (or a quote) inside the multipart header, which the site's
        // WAF reads as a malformed packet.
        $this->assertEquals('image.png', $transfers[0][0]);
        // The copy is named for what it is, so the host keeps the browser's
        // WebP/JPEG instead of re-encoding the original's format.
        $this->assertEquals('thumb.jpeg', $transfers[1][0]);

        // Both transfers carry a budget: that is what keeps the delivery
        // inside the queue's retry_after and stops a second worker from
        // uploading the same bytes again.
        $this->assertNotNull($transfers[0][1]);
        $this->assertGreaterThan(0, $transfers[0][1]);
        $this->assertNotNull($transfers[1][1]);
        $this->assertGreaterThan(0, $transfers[1][1]);
    }

    #[Test]
    public function a_failed_thumbnail_transfer_falls_back_to_the_full_image()
    {
        $container = $this->app()->getContainer();

        $container->instance(
            ExternalImageHostUploader::class,
            $this->scriptedUploader(['/file/orig.png', null])
        );

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => $this->pngUpload(), 'thumb' => $this->jpegThumbUpload()])
                ->withParsedBody(['title' => 'Card copy failed'])
        );

        $this->assertEquals(201, $response->getStatusCode());

        $attributes = json_decode($response->getBody(), true)['data']['attributes'];

        // Losing the card copy costs the card some bytes, never the upload.
        $this->assertEquals('published', $attributes['status']);
        $this->assertEquals('/file/orig.png', $attributes['src']);
        // The mock host offers no thumbnail of its own either, so the chain
        // lands on the full image.
        $this->assertEquals('/file/orig.png', $attributes['thumb']);
    }

    #[Test]
    public function hourly_rate_limit_is_enforced()
    {
        $this->settings['lcoy-waterfall.user_hourly_limit'] = 1;

        // Must be queued before the app boots: populateDatabase() only runs
        // on the first app() call, so seeding afterwards is a no-op.
        $this->prepareDatabase([
            WaterfallImage::class => [
                ['id' => 10, 'user_id' => 2, 'src' => '/file/limit.png', 'thumb' => null, 'title' => 'Existing', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'published', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ],
        ]);

        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/mock-upload.png'));

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => $this->pngUpload()])
        );

        $this->assertEquals(422, $response->getStatusCode());
    }

    #[Test]
    public function concurrent_uploads_limit_is_enforced()
    {
        $this->settings['lcoy-waterfall.user_concurrent_uploads'] = 1;

        // Queued before the app boots (see hourly_rate_limit_is_enforced).
        $this->prepareDatabase([
            WaterfallImage::class => [
                ['id' => 11, 'user_id' => 2, 'src' => '', 'thumb' => null, 'title' => 'Stuck', 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'pending', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ],
        ]);

        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/mock-upload.png'));

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => $this->pngUpload()])
        );

        $this->assertEquals(422, $response->getStatusCode());
    }

    #[Test]
    public function upload_attaches_to_set_with_position_and_updates_aggregates()
    {
        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/mock-upload.png'));

        // The uploader creates the set first (as the frontend does), then
        // sends each file carrying the set id and its dragged-in position.
        $setResponse = $this->send(
            $this->request('POST', '/api/waterfall-sets', [
                'authenticatedAs' => 2,
                'json' => ['data' => ['attributes' => ['title' => 'Trip photos']]],
            ])
        );

        $this->assertEquals(201, $setResponse->getStatusCode());

        $setId = (int) json_decode($setResponse->getBody(), true)['data']['id'];

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => $this->pngUpload()])
                ->withParsedBody(['title' => 'First photo', 'set_id' => (string) $setId, 'position' => '2'])
        );

        $this->assertEquals(201, $response->getStatusCode());

        $imageId = (int) json_decode($response->getBody(), true)['data']['id'];
        $image = WaterfallImage::find($imageId);

        $this->assertEquals($setId, $image->set_id);
        $this->assertEquals(2, $image->position);

        // The sync queue ran ProcessImageUploadJob inline, so the set is now
        // published with one image and uses it as its cover.
        $set = WaterfallSet::find($setId);

        $this->assertEquals(WaterfallSet::STATUS_PUBLISHED, $set->status);
        $this->assertEquals(1, $set->images_count);
        $this->assertEquals($imageId, $set->cover_image_id);
    }

    #[Test]
    public function drag_reorder_is_preserved_in_the_set_order()
    {
        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/mock-upload.png'));

        $setResponse = $this->send(
            $this->request('POST', '/api/waterfall-sets', [
                'authenticatedAs' => 2,
                'json' => ['data' => ['attributes' => ['title' => 'Dragged order']]],
            ])
        );

        $this->assertEquals(201, $setResponse->getStatusCode());

        $setId = (int) json_decode($setResponse->getBody(), true)['data']['id'];

        // Simulate the upload modal's drag-to-reorder: the user picked A, B, C
        // but dragged C to the front, so the queue (and the position sent with
        // each sequential upload, exactly like the frontend) is C=0, A=1, B=2.
        $titlesById = [];

        foreach ([['C', 0], ['A', 1], ['B', 2]] as [$title, $position]) {
            $response = $this->send(
                $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                    ->withUploadedFiles(['file' => $this->pngUpload()])
                    ->withParsedBody(['title' => $title, 'set_id' => (string) $setId, 'position' => (string) $position])
            );

            $this->assertEquals(201, $response->getStatusCode());

            $imageId = (int) json_decode($response->getBody(), true)['data']['id'];
            $titlesById[$imageId] = $title;
        }

        $response = $this->send(
            $this->request('GET', "/api/waterfall-sets/$setId")
                ->withQueryParams(['include' => 'images'])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        // Relationship linkage carries the dragged order...
        $linkageIds = array_map(
            fn (array $ref) => (int) $ref['id'],
            $body['data']['relationships']['images']['data']
        );

        $this->assertSame(
            ['C', 'A', 'B'],
            array_map(fn (int $id) => $titlesById[$id], $linkageIds),
            'images linkage must follow the dragged position order'
        );

        // ...and so do the included resources.
        $includedTitles = array_map(
            fn (array $image) => $image['attributes']['title'],
            array_values(array_filter($body['included'], fn (array $r) => $r['type'] === 'waterfall-images'))
        );

        $this->assertSame(['C', 'A', 'B'], $includedTitles);

        // The cover follows the dragged order too: the first published image.
        $set = WaterfallSet::find($setId);

        $this->assertSame('C', $titlesById[$set->cover_image_id]);
        $this->assertEquals(WaterfallSet::STATUS_PUBLISHED, $set->status);
        $this->assertEquals(3, $set->images_count);
    }

    #[Test]
    public function repeated_positions_fall_back_to_a_stable_id_order()
    {
        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/mock-upload.png'));

        $setResponse = $this->send(
            $this->request('POST', '/api/waterfall-sets', [
                'authenticatedAs' => 2,
                'json' => ['data' => ['attributes' => ['title' => 'Clash']]],
            ])
        );

        $this->assertEquals(201, $setResponse->getStatusCode());

        $setId = (int) json_decode($setResponse->getBody(), true)['data']['id'];

        // Two uploads claim the same position (e.g. a retried drag). The
        // orderBy('id') tie-breaker must keep the order deterministic.
        foreach ([['First', 5], ['Second', 5]] as [$title, $position]) {
            $response = $this->send(
                $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                    ->withUploadedFiles(['file' => $this->pngUpload()])
                    ->withParsedBody(['title' => $title, 'set_id' => (string) $setId, 'position' => (string) $position])
            );

            $this->assertEquals(201, $response->getStatusCode());
        }

        $response = $this->send(
            $this->request('GET', "/api/waterfall-sets/$setId")
                ->withQueryParams(['include' => 'images'])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $includedTitles = array_map(
            fn (array $image) => $image['attributes']['title'],
            array_values(array_filter($body['included'], fn (array $r) => $r['type'] === 'waterfall-images'))
        );

        $this->assertSame(['First', 'Second'], $includedTitles);
    }

    #[Test]
    public function upload_rejects_set_owned_by_another_user()
    {
        // images into it. Queued before the app boots so the row really
        // exists (a post-boot prepareDatabase is a no-op) — otherwise this
        // would pass vacuously through the "set not found" branch.
        $this->prepareDatabase([
            WaterfallSet::class => [
                ['id' => 20, 'user_id' => 1, 'title' => 'Admin set', 'images_count' => 0, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'pending', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ],
        ]);

        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/mock-upload.png'));

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => $this->pngUpload()])
                ->withParsedBody(['set_id' => '20', 'position' => '0'])
        );

        $this->assertEquals(422, $response->getStatusCode());
    }

    /**
     * The mirror of the rejection above. This is the branch the moderate
     * permission exists for, and it had no coverage: the set belongs to
     * somebody else and the upload is allowed anyway.
     */
    #[Test]
    public function moderator_can_upload_into_another_users_set()
    {
        $this->prepareDatabase([
            User::class => [
                ['id' => 4, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
            ],
            // Group 4 is Flarum's moderators group, and the extension's
            // permission migration is what grants lcoy-waterfall.moderate to
            // it — seeding the membership exercises that grant too.
            'group_user' => [
                ['user_id' => 4, 'group_id' => Group::MODERATOR_ID],
            ],
            WaterfallSet::class => [
                ['id' => 20, 'user_id' => 2, 'title' => 'Their set', 'images_count' => 0, 'likes_count' => 0, 'views_count' => 0, 'score' => 0, 'status' => 'pending', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ],
        ]);

        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/mock-upload.png'));

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 4])
                ->withUploadedFiles(['file' => $this->pngUpload()])
                ->withParsedBody(['set_id' => '20', 'position' => '0'])
        );

        $this->assertEquals(201, $response->getStatusCode());

        $image = WaterfallImage::query()->find(json_decode($response->getBody(), true)['data']['id']);

        $this->assertEquals(20, $image->set_id);
    }

    #[Test]
    public function admin_can_read_upload_log()
    {
        $this->prepareDatabase([
            WaterfallUploadLog::class => [
                ['id' => 1, 'image_id' => 1, 'user_id' => 2, 'status' => WaterfallUploadLog::STATUS_SUCCESS, 'http_code' => 200, 'duration_ms' => 120, 'attempts' => 1, 'error' => null, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ],
        ]);

        $response = $this->send(
            $this->request('GET', '/api/waterfall-upload-logs', ['authenticatedAs' => 1])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertEquals('success', $body['data'][0]['attributes']['status']);

        // Non-admins are rejected.
        $response = $this->send(
            $this->request('GET', '/api/waterfall-upload-logs', ['authenticatedAs' => 2])
        );

        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Build an uploader fake: returns the given src, or throws when null.
     */
    protected function mockUploader(?string $src): ExternalImageHostUploader
    {
        $container = $this->app()->getContainer();

        return new class(
            $container->make(SettingsRepositoryInterface::class),
            $container->make(LoggerInterface::class),
            $src
        ) extends ExternalImageHostUploader {
            public function __construct(
                SettingsRepositoryInterface $settings,
                LoggerInterface $logger,
                private readonly ?string $mockSrc
            ) {
                parent::__construct($settings, $logger);
            }

            public function upload(string $filePath, string $filename, ?int $budgetSeconds = null): UploadResult
            {
                if ($this->mockSrc === null) {
                    throw new UploadException(
                        'image_host_http_error',
                        'mock image host unavailable',
                        503,
                        3,
                        42
                    );
                }

                return new UploadResult(src: $this->mockSrc, httpCode: 200, attempts: 1, durationMs: 5);
            }
        };
    }

    /**
     * An uploader fake that answers one call at a time: each entry in $srcs
     * answers a transfer, a null entry throws the way an unreachable host
     * does, and the last entry repeats for any call beyond the script.
     *
     * $record receives the filename and budget of every transfer, which is how
     * a test sees what the job actually asked the host to do.
     */
    protected function scriptedUploader(array $srcs, ?callable $record = null): ExternalImageHostUploader
    {
        $container = $this->app()->getContainer();

        return new class(
            $container->make(SettingsRepositoryInterface::class),
            $container->make(LoggerInterface::class),
            $srcs,
            $record
        ) extends ExternalImageHostUploader {
            private int $call = 0;

            public function __construct(
                SettingsRepositoryInterface $settings,
                LoggerInterface $logger,
                private readonly array $srcs,
                private readonly mixed $record
            ) {
                parent::__construct($settings, $logger);
            }

            public function upload(string $filePath, string $filename, ?int $budgetSeconds = null): UploadResult
            {
                if ($this->record !== null) {
                    ($this->record)($filename, $budgetSeconds);
                }

                $src = $this->srcs[min($this->call, count($this->srcs) - 1)];
                $this->call++;

                if ($src === null) {
                    throw new UploadException('image_host_http_error', 'mock image host unavailable', 503, 1, 5);
                }

                return new UploadResult(src: $src, httpCode: 200, attempts: 1, durationMs: 5);
            }
        };
    }

    protected function pngUpload(): UploadedFile
    {
        $path = $this->writeTempFile(base64_decode(self::PNG_1X1), 'png');

        return new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'test.png', 'image/png');
    }

    protected function jpegThumbUpload(): UploadedFile
    {
        $path = $this->writeTempFile(base64_decode(self::JPEG_1X1), 'jpg');

        return new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'thumb.jpg', 'image/jpeg');
    }

    /**
     * Write $contents to a tracked temp file with the given extension.
     *
     * tempnam() creates the file it names, so the path is renamed rather than
     * suffixed: that keeps the extension the MIME sniffing needs without
     * leaving an empty leftover behind on every call.
     */
    protected function writeTempFile(string $contents, string $suffix): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wf-test');
        $path = $tmp.'.'.$suffix;

        rename($tmp, $path);
        file_put_contents($path, $contents);

        $this->tempFiles[] = $path;

        return $path;
    }
}
