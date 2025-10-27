<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Support;

use function is_int;
use function is_numeric;

/**
 * Reusable helper for backed-enums: normalizes int|string|null to ?self.
 */
trait EnumFromIntStringNullable
{
    public static function fromExifValue(int|string|null $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        // int|string → int
        $int = is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
        if ($int === null) {
            return null;
        }

        return self::tryFrom($int);
    }
}
