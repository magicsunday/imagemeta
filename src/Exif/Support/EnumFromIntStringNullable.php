<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Support;

use function gettype;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Reusable helper for backed-enums: normalizes int|string|null to ?self.
 *
 * EXIF 3.0 §4.6 encodes many enumerations as numeric values; earlier EXIF 2.x
 * revisions frequently serialise them as numeric strings, which this helper
 * normalises for enum-backed value objects.
 *
 * Supports both int-backed enums (e.g., GpsAltitudeRef) and string-backed enums
 * (e.g., GpsSpeedRef, GpsDirectionRef) per EXIF 3.0 §4.6.6 Table 27.
 */
trait EnumFromIntStringNullable
{
    /**
     * Normalises EXIF backed-enum values for both int-backed and string-backed enums.
     *
     * For int-backed enums: converts numeric strings to integers before forwarding
     * to {@see self::tryFrom()}.
     *
     * For string-backed enums: passes string values directly to {@see self::tryFrom()}.
     *
     * EXIF 3.0 §4.6.6 Table 27 defines GPS tags using both integer values
     * (e.g., GPSAltitudeRef) and single-character strings (e.g., GPSSpeedRef: 'K'/'M'/'N').
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

        // Get the backing type of the enum via reflection
        // self::cases()[0]->value gives us access to a case value to determine the backing type
        $cases = self::cases();
        if ($cases === []) {
            return null;
        }

        $firstCase   = $cases[0];
        $backingType = gettype($firstCase->value);

        // Handle int-backed enums
        if ($backingType === 'integer') {
            if (is_int($value)) {
                $normalizedValue = $value;
            } elseif (is_numeric($value)) {
                $normalizedValue = (int) $value;
            } else {
                return null;
            }

            return self::tryFrom($normalizedValue);
        }

        // Handle string-backed enums
        if ($backingType === 'string') {
            // For string-backed enums, pass the value as-is if it's a string,
            // or convert to string if it's an integer (for numeric string values)
            if (is_string($value)) {
                return self::tryFrom($value);
            }

            if (is_int($value)) {
                return self::tryFrom((string) $value);
            }

            return null;
        }

        return null;
    }
}
