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

use Flarum\Testing\integration\TestCase;
use Lcoy\Waterfall\Upload\Exception\UploadException;
use Lcoy\Waterfall\Upload\ExternalImageHostUploader;
use PHPUnit\Framework\Attributes\Test;

/**
 * The admin test button exists to predict whether uploads will work, so its
 * URL rules may not be weaker than the uploader's. The sharpest gap was the
 * scheme: filter_var alone accepts ftp:// and file:// addresses, which a real
 * transfer rejects outright — the pinned behaviour is that both paths refuse
 * them, with the endpoint naming the setting rather than reporting a
 * connection error that would send the admin chasing a network problem.
 *
 * Both cases stop before any network I/O, so they run without a host.
 */
class TestImageHostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('lcoy-waterfall');
    }

    #[Test]
    public function an_upload_url_with_a_non_http_scheme_is_reported_as_invalid()
    {
        $this->settings['lcoy-waterfall.upload_url'] = 'ftp://example.com/upload';

        $response = $this->send(
            $this->request('POST', '/api/waterfall/test-host', ['authenticatedAs' => 1])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);

        $this->assertFalse($body['ok']);
        $this->assertContains('upload_url_invalid', $body['errors']);
    }

    #[Test]
    public function the_uploader_refuses_a_non_http_scheme_before_any_transfer()
    {
        $this->settings['lcoy-waterfall.upload_url'] = 'ftp://example.com/upload';

        try {
            $this->app()->getContainer()->make(ExternalImageHostUploader::class)->upload('/nonexistent-staged-file.png', 'image.png');

            $this->fail('Expected the ftp:// upload URL to be rejected.');
        } catch (UploadException $e) {
            $this->assertEquals('image_host_not_configured', $e->errorCode);
        }
    }
}
