<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use OutOfBoundsException;

/**
 * Represents an attempt to access bytes outside a declared safe range.
 */
class BoundsError extends OutOfBoundsException
{
}
