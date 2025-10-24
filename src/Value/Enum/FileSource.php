<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

/**
 * Enumerates EXIF 3.0 image acquisition sources.
 */
enum FileSource: int
{
    case OTHER                = 0;
    case TRANSPARENCY_SCANNER = 1;
    case REFLECTION_SCANNER   = 2;
    case DIGITAL_CAMERA       = 3;

    /**
     * Converts a raw EXIF file source into the backed enum.
     *
     * Vendor specific values such as 0x8000 (Sigma Foveon) are ignored because
     * they are not part of the EXIF 3.0 specification.
     */
    public static function fromExifValue(int|string|null $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = is_string($value) ? intval($value, 0) : $value;

        if ($intValue >= 0x8000) {
            return null;
        }

        return self::tryFrom($intValue);
    }
}
