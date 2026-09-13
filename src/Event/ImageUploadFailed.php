<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Event;

use Flarum\User\User;
use Lcoy\Waterfall\Model\WaterfallImage;

class ImageUploadFailed
{
    public function __construct(
        public readonly WaterfallImage $image,
        public readonly User $actor,
        public readonly string $errorCode,
        public readonly string $message
    ) {
    }
}
