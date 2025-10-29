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
 *
 * EXIF 3.0 §4.6 encodes many enumerations as numeric values; earlier EXIF 2.x
 * revisions frequently serialise them as numeric strings, which this helper
 * normalises for enum-backed value objects.
 */
trait EnumFromIntStringNullable
{
    /**
     * Normalises EXIF backed-enum values represented as ints or numeric strings.
     *
     * EXIF encoders frequently emit numeric codes as strings; this helper keeps
     * the callers concise by handling the null/empty cases and by converting
     * numeric strings to integers before forwarding them to {@see self::tryFrom()}.
     *
     * @param int|string|null $value Raw EXIF value as delivered by the decoder.
     *
     * @return self|null Normalised enum value or null when the payload is invalid.
     */
    public static function fromExifValue(int|string|null $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        // int|string → int
        $intValue = is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
        if ($intValue === null) {
            return null;
        }

        return self::tryFrom($intValue);
    }
}
