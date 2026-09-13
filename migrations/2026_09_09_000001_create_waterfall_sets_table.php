<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Image sets: one upload (one or more files chosen together) becomes one set.
// The set owns the editable title and the aggregate counters, while every image
// keeps its own likes/views/score; `position` stores the display order the
// uploader dragged the images into.
return [
    'up' => function (Builder $schema) {
        $schema->create('waterfall_sets', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('title', 200)->nullable();
            // First published image (by position); drives the grid cover.
            $table->unsignedInteger('cover_image_id')->nullable();
            // Denormalised aggregates of the set's images, kept in sync by
            // WaterfallSet::syncAggregates() so the feed can sort and display
            // without joining.
            $table->unsignedInteger('images_count')->default(0);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->decimal('score', 10, 4)->default(0);
            // pending -> published (at least one image published) | failed (all failed).
            $table->string('status', 20)->default('pending');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['status', 'created_at']);
            $table->index(['status', 'score']);
            $table->index(['user_id', 'created_at']);
            $table->index('likes_count');
        });

        $schema->table('waterfall_images', function (Blueprint $table) {
            // Nullable so pre-existing rows (and test fixtures that insert
            // images directly) stay valid; the uploader always fills it in.
            $table->unsignedInteger('set_id')->nullable()->after('user_id');
            $table->unsignedInteger('position')->default(0)->after('set_id');

            $table->index(['set_id', 'position']);
            $table->foreign('set_id')->references('id')->on('waterfall_sets')->onDelete('cascade');
        });

        // Backfill: every existing image becomes a single-image set so the
        // feed keeps working unchanged after the upgrade.
        $connection = $schema->getConnection();

        foreach ($connection->table('waterfall_images')->orderBy('id')->get() as $image) {
            $setId = $connection->table('waterfall_sets')->insertGetId([
                'user_id' => $image->user_id,
                'title' => $image->title,
                'cover_image_id' => $image->status === 'published' ? $image->id : null,
                'images_count' => 1,
                'likes_count' => $image->likes_count,
                'views_count' => $image->views_count,
                'score' => $image->score,
                'status' => $image->status,
                'created_at' => $image->created_at,
                'updated_at' => $image->updated_at,
            ]);

            $connection->table('waterfall_images')->where('id', $image->id)->update([
                'set_id' => $setId,
                'position' => 0,
            ]);
        }
    },

    'down' => function (Builder $schema) {
        $schema->table('waterfall_images', function (Blueprint $table) {
            $table->dropForeign(['set_id']);
            $table->dropIndex(['set_id', 'position']);
            $table->dropColumn(['set_id', 'position']);
        });

        $schema->drop('waterfall_sets');
    },
];
