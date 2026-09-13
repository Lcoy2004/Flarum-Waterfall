<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Api;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Lcoy\Waterfall\Model\WaterfallUploadLog;

/**
 * @extends AbstractDatabaseResource<WaterfallUploadLog>
 *
 * Read-only log of image host transfers, surfaced in the admin upload log
 * panel (most recent 100 entries).
 */
class WaterfallUploadLogResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'waterfall-upload-logs';
    }

    public function model(): string
    {
        return WaterfallUploadLog::class;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->admin()
                ->paginate(100, 100)
                ->defaultSort('-createdAt'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('imageId')
                ->property('image_id')
                ->nullable(),
            Schema\Integer::make('userId')
                ->property('user_id')
                ->nullable(),
            // pending | deferred | success | failed
            Schema\Str::make('status'),
            Schema\Integer::make('httpCode')
                ->property('http_code')
                ->nullable(),
            Schema\Integer::make('durationMs')
                ->property('duration_ms')
                ->nullable(),
            Schema\Integer::make('attempts'),
            Schema\Str::make('error')
                ->nullable(),
            Schema\DateTime::make('createdAt'),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt'),
        ];
    }
}
