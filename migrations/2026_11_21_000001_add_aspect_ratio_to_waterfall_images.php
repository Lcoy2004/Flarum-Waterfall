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

// The feed's cards are no longer a fixed 3:4 frame: each one reserves the
// shape its own image will have, so the columns can balance without measuring
// anything that has loaded. That requires knowing the shape up front, which is
// what this column holds — width / height, orientation already applied, as
// reported by the transfer that published the row.
//
// Nullable on purpose. Rows published before this migration have no ratio, and
// a file whose size could not be read still uploads fine; both simply fall back
// to the 3:4 frame in the stylesheet.
//
// Both directions check for the column first (Migration::addColumns has no such
// guard), so a re-run or a partially applied install cannot abort a migration.
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('waterfall_images', 'aspect_ratio')) {
            return;
        }

        $schema->table('waterfall_images', function (Blueprint $table) {
            $table->decimal('aspect_ratio', 10, 6)->nullable()->after('thumb');
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('waterfall_images', 'aspect_ratio')) {
            return;
        }

        $schema->table('waterfall_images', function (Blueprint $table) {
            $table->dropColumn('aspect_ratio');
        });
    },
];
