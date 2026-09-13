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

// Freeform tags the uploader writes when publishing a set (not the forum's
// tag taxonomy): a small JSON array of strings, normalised and bounded by
// WaterfallSetResource::normalizeTags().
return [
    'up' => function (Builder $schema) {
        $schema->table('waterfall_sets', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('title');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('waterfall_sets', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    },
];
