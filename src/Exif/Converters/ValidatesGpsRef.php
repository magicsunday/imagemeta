<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use function in_array;

/**
 * Validates a GPS reference string against an allowed set.
 *
 * @internal
 */
trait ValidatesGpsRef
{
    /**
     * @param list<string> $allowed
     */
    private function validateGpsRef(?string $value, array $allowed): ?string
    {
        if (($value === null) || ($value === '')) {
            return null;
        }

        return in_array($value, $allowed, true) ? $value : null;
    }
}
