<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;

use function is_int;

/**
 * Provides helpers to decode the EXIF flash bit field into structured values.
 */
final class ExifFlash
{
    private const FIRED_MASK = 0x01;
    private const RETURN_SHIFT = 1;
    private const MODE_SHIFT = 3;
    private const FUNCTION_SHIFT = 5;
    private const TWO_BIT_MASK = 0x03;
    private const ONE_BIT_MASK = 0x01;
    private const RED_EYE_MASK = 0x40;

    private function __construct()
    {
    }

    /**
     * Creates a FlashInfo instance from the numeric EXIF Flash tag value.
     */
    public static function fromExifValue(int|string|null $value): ?FlashInfo
    {
        if ($value === null) {
            return null;
        }

        $intValue = is_int($value) ? $value : (int) $value;

        $returnBits = ($intValue >> self::RETURN_SHIFT) & self::TWO_BIT_MASK;
        $modeBits = ($intValue >> self::MODE_SHIFT) & self::TWO_BIT_MASK;
        $functionBit = ($intValue >> self::FUNCTION_SHIFT) & self::ONE_BIT_MASK;

        return new FlashInfo(
            fired: ($intValue & self::FIRED_MASK) !== 0,
            mode: FlashMode::tryFrom($modeBits),
            returnDetection: FlashReturn::tryFrom($returnBits),
            functionPresence: FlashFunction::tryFrom($functionBit),
            redEyeReduction: ($intValue & self::RED_EYE_MASK) !== 0,
        );
    }
}
