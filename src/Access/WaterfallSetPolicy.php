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
use Lcoy\Waterfall\Model\WaterfallSet;

class WaterfallSetPolicy extends AbstractPolicy
{
    public function rename(User $actor, WaterfallSet $set): ?string
    {
        return $this->owns($actor, $set);
    }

    public function delete(User $actor, WaterfallSet $set): ?string
    {
        return $this->owns($actor, $set);
    }

    /**
     * Own sets are always manageable; otherwise the moderate permission is
     * required.
     */
    protected function owns(User $actor, WaterfallSet $set): ?string
    {
        if ($actor->id === $set->user_id) {
            return $this->allow();
        }

        return $actor->hasPermission('lcoy-waterfall.moderate')
            ? $this->allow()
            : $this->deny();
    }
}
