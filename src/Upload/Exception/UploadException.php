<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Upload\Exception;

use RuntimeException;

/**
 * Thrown when the image host transfer finally failed after retries. Carries a
 * stable machine code, the last HTTP status (0 for transport errors), the
 * number of attempts and the total duration, so the queue job can persist a
 * dead-letter row in the upload log.
 */
class UploadException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpCode = 0,
        public readonly int $attempts = 0,
        public readonly int $durationMs = 0
    ) {
        parent::__construct($message);
    }
}
