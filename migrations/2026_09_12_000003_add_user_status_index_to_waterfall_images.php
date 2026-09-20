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

// The concurrent-upload limit counts a user's pending images on every upload
// request. None of the existing indexes covers that pair — they lead with
// `status` or with `user_id` alone — so the count scanned the user's whole
// history instead of stopping at the (few) rows it is about. The name is
// spelled out so the rollback can find it again.
return [
    'up' => function (Builder $schema) {
        $schema->table('waterfall_images', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'waterfall_images_user_id_status_index');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('waterfall_images', function (Blueprint $table) {
            $table->dropIndex('waterfall_images_user_id_status_index');
        });
    },
];
