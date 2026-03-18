<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\FlashInfo;

use function is_int;

/**
 * Provides helpers to decode the EXIF flash bit field into structured values.
 */
final class ExifFlash
{
    private const int FIRED_MASK     = 0x01;

    private const int RETURN_SHIFT   = 1;

    private const int MODE_SHIFT     = 3;

    private const int FUNCTION_SHIFT = 5;

    private const int TWO_BIT_MASK   = 0x03;

    private const int ONE_BIT_MASK   = 0x01;

    private const int RED_EYE_MASK   = 0x40;

    /**
     * Prevents instantiation of the utility class.
     */
    private function __construct()
    {
    }

    /**
     * Creates a FlashInfo instance from the numeric EXIF Flash tag value.
     *
     * EXIF 3.0 §4.6.6.7.21 (Flash) defines the grouped bit fields decoded here:
     * bit 0 (fired), bits 1-2 (return detection), bits 3-4 (mode), bit 5 (function presence),
     * bit 6 (red-eye reduction support).
     *
     * @param int|string|null $value Raw EXIF Flash tag encoding the capture state.
     *
     * @return FlashInfo|null Structured flash details or null when no information is provided.
     */
    public static function fromExifValue(int|string|null $value): ?FlashInfo
    {
        if ($value === null) {
            return null;
        }

        $flashBits   = is_int($value) ? $value : (int) $value;

        // EXIF 3.0 §4.6.6.7.21 defines the grouped Flash tag bit layout decoded below.
        $returnBits  = ($flashBits >> self::RETURN_SHIFT) & self::TWO_BIT_MASK;
        $modeBits    = ($flashBits >> self::MODE_SHIFT) & self::TWO_BIT_MASK;
        $functionBit = ($flashBits >> self::FUNCTION_SHIFT) & self::ONE_BIT_MASK;

        return new FlashInfo(
            fired: ($flashBits & self::FIRED_MASK) !== 0,
            mode: FlashMode::tryFrom($modeBits),
            returnDetection: FlashReturn::tryFrom($returnBits),
            functionPresence: FlashFunction::tryFrom($functionBit),
            redEyeReduction: ($flashBits & self::RED_EYE_MASK) !== 0,
        );
    }
}
