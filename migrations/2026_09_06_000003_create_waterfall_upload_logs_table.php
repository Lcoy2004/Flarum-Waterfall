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
