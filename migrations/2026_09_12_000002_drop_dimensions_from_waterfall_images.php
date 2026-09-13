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

// The image's intrinsic dimensions are no longer stored: the feed renders a
// fixed 3:4 frame, and the upload dialog reads each file's size in the browser
// for its queue label. The two columns only ever held data nothing read.
// Migration::dropColumns reverses itself, so `down` re-creates both.
return Migration::dropColumns('waterfall_images', [
    'width' => ['unsignedInteger', 'nullable' => true],
    'height' => ['unsignedInteger', 'nullable' => true],
]);
