<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Support;

use ReflectionEnum;
use ReflectionNamedType;

use function is_int;
use function is_numeric;

/**
 * Reusable helper for backed-enums: normalizes int|string|null to ?self.
 *
 * EXIF 3.0 §4.6.6 encodes many enumerations as numeric values; EXIF 2.x
 * revisions frequently serialise them as numeric strings. This helper
 * normalises both into the appropriate backed enum case.
 */
trait EnumFromIntStringNullable
{
    /**
     * Normalises EXIF backed-enum values for both int-backed and string-backed enums.
     *
     * Strategy:
     *  1. Try to resolve integer-backed cases first (including numeric strings).
     *  2. Fall back to string-backed cases.
     *
     * This avoids relying on reflection or PHPDoc-backed type guesses and keeps
     * behaviour well-defined for both EXIF 2.x (numeric strings) and EXIF 3.0.
     *
     * @param int|string|null $value Raw EXIF value as delivered by the decoder.
     *
     * @return self|null Normalised enum value or null when the payload is invalid.
     */
    public static function fromExifValue(int|string|null $value): ?self
    {
        if (($value === null) || ($value === '')) {
            return null;
        }

        $reflection = new ReflectionEnum(self::class);
        $backing    = $reflection->getBackingType();

        if (!$backing instanceof ReflectionNamedType) {
            return null;
        }

        if ($backing->getName() === 'int') {
            if (!is_numeric($value)) {
                return null;
            }

            return self::tryFrom(is_int($value) ? $value : (int) $value);
        }

        return self::tryFrom((string) $value);
    }
}
