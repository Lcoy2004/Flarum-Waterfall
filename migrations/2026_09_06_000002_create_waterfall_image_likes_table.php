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
    'waterfall_image_likes',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('image_id');
        $table->unsignedInteger('user_id');
        $table->dateTime('created_at');

        $table->foreign('image_id')->references('id')->on('waterfall_images')->onDelete('cascade');
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->unique(['image_id', 'user_id']);
    }
);
