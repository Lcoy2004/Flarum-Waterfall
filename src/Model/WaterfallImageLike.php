<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Model;

use Flarum\Database\AbstractModel;

class WaterfallImageLike extends AbstractModel
{
    protected $table = 'waterfall_image_likes';

    public $timestamps = true;

    protected $guarded = [];

    // The pivot table carries only created_at (no updated_at column).
    public const UPDATED_AT = null;

    protected $casts = [
        'image_id' => 'integer',
        'user_id' => 'integer',
    ];
}
