<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable(
    'waterfall_upload_logs',
    function (Blueprint $table) {
        $table->increments('id');
        // Deliberately not foreign keys. A transfer is an event, not state:
        // the row records what was sent to the image host, so it has to outlive
        // the image (and the uploader) it names — deleting either must not
        // erase the audit trail. Two consequences are intended rather than
        // broken: an id here can stop resolving, and the table is bounded by
        // age instead of by cascades (ProcessImageUploadJob prunes rows older
        // than three days). The admin log panel is the only reader.
        $table->unsignedInteger('image_id')->nullable();
        $table->unsignedInteger('user_id')->nullable();
        // pending | deferred | success | failed
        $table->string('status', 20);
        $table->unsignedInteger('http_code')->nullable();
        $table->unsignedInteger('duration_ms')->nullable();
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->text('error')->nullable();
        $table->dateTime('created_at');
        $table->dateTime('updated_at');

        $table->index('created_at');
        $table->index('image_id');
    }
);
