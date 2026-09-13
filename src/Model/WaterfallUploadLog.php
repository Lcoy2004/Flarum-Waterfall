<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Model;

use Flarum\Database\AbstractModel;

/**
 * Read-only audit trail for image host transfers, consumed by the admin
 * "upload log" panel. Rows are written by the upload job.
 */
class WaterfallUploadLog extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DEFERRED = 'deferred';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $table = 'waterfall_upload_logs';

    public $timestamps = true;

    protected $guarded = [];

    protected $casts = [
        'image_id' => 'integer',
        'user_id' => 'integer',
        'http_code' => 'integer',
        'duration_ms' => 'integer',
        'attempts' => 'integer',
    ];
}
