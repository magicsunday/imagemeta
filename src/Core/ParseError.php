<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use RuntimeException;

/**
 * Represents a generic parsing failure triggered by malformed input data.
 */
class ParseError extends RuntimeException
{
}
