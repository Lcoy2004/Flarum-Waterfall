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
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
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

    #[Test]
    public function upload_rejects_disallowed_mime_type()
    {
        $container = $this->app()->getContainer();

        $container->instance(ExternalImageHostUploader::class, $this->mockUploader('/file/never.png'));

        // A plain text file masquerading as image/png in client metadata:
        // the magic-byte sniffing must reject it.
        $tmp = tempnam(sys_get_temp_dir(), 'wf-test').'.png';
        file_put_contents($tmp, 'this is definitely not an image');

        $response = $this->send(
            $this->request('POST', '/api/waterfall-images', ['authenticatedAs' => 2])
                ->withUploadedFiles(['file' => new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'fake.png', 'image/png')])
        );

        $this->assertEquals(422, $response->getStatusCode());
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

            public function upload(string $filePath, string $filename): UploadResult
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

    protected function pngUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wf-test').'.png';
        file_put_contents($tmp, base64_decode(self::PNG_1X1));

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'test.png', 'image/png');
    }
}
