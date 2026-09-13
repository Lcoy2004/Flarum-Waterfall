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

// Migration::createTable automatically provides the rollback (drop table).
return Migration::createTable(
    'waterfall_images',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('user_id');
        $table->string('src');
        $table->string('thumb')->nullable();
        $table->unsignedInteger('width')->nullable();
        $table->unsignedInteger('height')->nullable();
        $table->string('title', 200)->nullable();
        $table->unsignedInteger('likes_count')->default(0);
        $table->unsignedInteger('views_count')->default(0);
        $table->decimal('score', 10, 4)->default(0);
        // Queue lifecycle: pending -> published | failed.
        $table->string('status', 20)->default('pending');
        $table->text('error')->nullable();
        $table->dateTime('created_at');
        $table->dateTime('updated_at');

        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

        // Indexes matched to the real query patterns: the published feeds
        // (status + created_at / status + score), the owner's pending or
        // failed images plus the hourly per-user count (user_id + created_at)
        // and the popular sort (likes_count).
        $table->index(['status', 'created_at']);
        $table->index(['status', 'score']);
        $table->index(['user_id', 'created_at']);
        $table->index('likes_count');
    }
);
