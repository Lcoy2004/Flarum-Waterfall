<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Lcoy\Waterfall\Model\WaterfallImage;

class WaterfallImagePolicy extends AbstractPolicy
{
    public function delete(User $actor, WaterfallImage $image): ?string
    {
        // Own images are always deletable; otherwise the moderate permission
        // is required.
        if ($actor->id === $image->user_id) {
            return $this->allow();
        }

        return $actor->hasPermission('lcoy-waterfall.moderate')
            ? $this->allow()
            : $this->deny();
    }
}
