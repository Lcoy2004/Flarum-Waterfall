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

// `pending_images_count` counted a set's pending images. Nothing reads it: a
// card's "still uploading" state comes from the set's own status, and the
// concurrent-upload limit counts images by user. The column also went stale the
// moment an image left `pending`, and it was introduced by a migration that is
// no longer part of the extension — so an install that has the column kept it
// from an older checkout, while a fresh one never creates it.
//
// Both directions check for the column first: on a fresh database it is absent,
// and a bare dropColumn would abort the install (Migration::dropColumns has no
// such guard).
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('waterfall_sets', 'pending_images_count')) {
            return;
        }

        $schema->table('waterfall_sets', function (Blueprint $table) {
            $table->dropColumn('pending_images_count');
        });
    },

    'down' => function (Builder $schema) {
        if ($schema->hasColumn('waterfall_sets', 'pending_images_count')) {
            return;
        }

        $schema->table('waterfall_sets', function (Blueprint $table) {
            $table->unsignedInteger('pending_images_count')->default(0);
        });
    },
];
