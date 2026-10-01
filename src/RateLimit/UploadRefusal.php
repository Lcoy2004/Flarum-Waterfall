<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\RateLimit;

/**
 * The outcome of an UploadQuota check the upload did not pass: the pointer the
 * API layer must throw the refusal under, and the translated message to report.
 * UploadQuota owns both, so "which limit refused" and "what to tell the user"
 * can never drift apart; the pointer is a plain string on purpose — keeping the
 * translator and HTTP types out of here is what lets this stay a dumb value.
 */
class UploadRefusal
{
    public function __construct(
        public readonly string $pointer,
        public readonly string $message
    ) {
    }
}
