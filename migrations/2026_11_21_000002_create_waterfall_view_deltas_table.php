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

// Views arrive in bursts — a reader paging through a lightbox reports a whole
// screen at once — and each report used to take the owning image and set locks
// to move two counters. The lock, not the arithmetic, is what a popular image
// contends on.
//
// This table decouples the two: a report only adds to a delta row, and a
// periodic flush moves the accumulated deltas into the counters in one go.
// Under load that turns many small locked updates into a few batched ones;
// while idle it costs exactly what it saved, which is the price of the
// simpler request path.
//
// The table is the buffer rather than the cache on purpose: it survives a
// cache outage, it can be enumerated without a Redis-specific index, and a
// flush that does not run simply leaves the deltas where they are.
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('waterfall_view_deltas')) {
            return;
        }

        $schema->create('waterfall_view_deltas', function (Blueprint $table) {
            $table->unsignedBigInteger('image_id')->primary();
            $table->unsignedBigInteger('delta')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('waterfall_view_deltas');
    },
];
