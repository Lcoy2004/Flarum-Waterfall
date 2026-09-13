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
use Flarum\Group\Group;

return Migration::addPermissions([
    'lcoy-waterfall.upload' => Group::MEMBER_ID,
    'lcoy-waterfall.like' => Group::MEMBER_ID,
    'lcoy-waterfall.moderate' => Group::MODERATOR_ID,
]);
