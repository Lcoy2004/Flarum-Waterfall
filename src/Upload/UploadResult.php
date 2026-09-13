<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Upload;

/**
 * Immutable result of a successful image host transfer.
 */
final class UploadResult
{
    public function __construct(
        public readonly string $src,
        public readonly int $httpCode,
        public readonly int $attempts,
        public readonly int $durationMs,
        public readonly ?string $thumb = null
    ) {
    }
}
