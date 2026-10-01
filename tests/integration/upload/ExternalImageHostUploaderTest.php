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

use Flarum\Testing\integration\TestCase;
use Lcoy\Waterfall\Upload\ExternalImageHostUploader;
use PHPUnit\Framework\Attributes\Test;

/**
 * Which URLs a finished upload is allowed to point at. The bytes have already
 * been transferred by the time this runs, so a wrong answer here throws away a
 * copy the host is already serving.
 */
class ExternalImageHostUploaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('lcoy-waterfall');
    }

    #[Test]
    public function the_scheme_is_judged_without_regard_to_case()
    {
        // parse_url hands the scheme back exactly as it was written, and a
        // scheme is case-insensitive. Comparing it raw disagreed with
        // urlUsesHttpScheme, which does normalise — so an upload URL the admin
        // typed in capitals, or a host answering in capitals, silently failed
        // the upload it had already completed.
        $this->assertTrue($this->uploader()->srcHasSafeScheme('HTTPS://host/file/x.png'));
        $this->assertTrue($this->uploader()->srcHasSafeScheme('Http://host/file/x.png'));
        $this->assertTrue($this->uploader()->srcHasSafeScheme('http://host/file/x.png'));
    }

    #[Test]
    public function the_upload_url_check_answers_the_same_way_as_the_src_check()
    {
        // urlUsesHttpScheme guards the admin-entered upload URL, and the server
        // preload reaches for it too (to decide whether an image host origin is
        // worth a preconnect) — so the two helpers being consistent is what
        // keeps a host from being accepted at upload time and then ignored
        // everywhere else.
        $this->assertTrue(ExternalImageHostUploader::urlUsesHttpScheme('HTTPS://host/upload'));
        $this->assertTrue(ExternalImageHostUploader::urlUsesHttpScheme('https://host/upload'));

        $this->assertFalse(ExternalImageHostUploader::urlUsesHttpScheme('/file/x.png'));
        $this->assertFalse(ExternalImageHostUploader::urlUsesHttpScheme('ftp://host/upload'));
        $this->assertFalse(ExternalImageHostUploader::urlUsesHttpScheme('javascript:alert(1)'));
    }

    #[Test]
    public function the_schemes_that_are_refused_stay_refused()
    {
        $uploader = $this->uploader();

        $this->assertFalse($uploader->srcHasSafeScheme('javascript:alert(1)'));
        $this->assertFalse($uploader->srcHasSafeScheme('data:text/html;base64,PHNjcmlwdD4='));
        $this->assertFalse($uploader->srcHasSafeScheme('file:///etc/passwd'));
        // Protocol-relative: another host, so not a root-relative path either.
        $this->assertFalse($uploader->srcHasSafeScheme('//elsewhere.tld/x.png'));
        // No scheme at all is not an absolute URL.
        $this->assertFalse($uploader->srcHasSafeScheme('host/x.png'));
    }

    #[Test]
    public function a_root_relative_path_is_accepted_but_a_protocol_relative_one_is_not()
    {
        $uploader = $this->uploader();

        $this->assertTrue($uploader->srcHasSafeScheme('/file/x.png'));
        $this->assertTrue($uploader->srcHasSafeScheme('/a/b/c.png?w=1'));

        $this->assertFalse($uploader->srcHasSafeScheme('//host/x.png'));
    }

    protected function uploader(): ExternalImageHostUploader
    {
        return $this->app()->getContainer()->make(ExternalImageHostUploader::class);
    }
}
