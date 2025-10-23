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

/**
 * Represents flash related capture information as exposed by EXIF and XMP.
 */
final readonly class FlashInfo
{
    /**
     * @param bool                    $fired            Whether the flash fired.
     * @param FlashMode|null          $mode             Selected flash mode.
     * @param FlashReturn|null        $returnDetection  Detected return light status.
     * @param FlashFunction|null      $functionPresence Whether the camera features a flash function.
     * @param bool                    $redEyeReduction  Indicates red-eye reduction support.
     */
    public function __construct(
        public bool $fired,
        public ?FlashMode $mode = null,
        public ?FlashReturn $returnDetection = null,
        public ?FlashFunction $functionPresence = null,
        public bool $redEyeReduction = false,
    ) {
    }

    /**
     * Creates an instance from the numeric EXIF Flash tag value.
     */
    public static function fromExifValue(int|string|null $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $intValue = is_int($value) ? $value : (int) $value;

        return new self(
            fired: (bool) ($intValue & 0x01),
            mode: FlashMode::fromFlashBits($intValue),
            returnDetection: FlashReturn::fromFlashBits($intValue),
            functionPresence: FlashFunction::fromFlashBits($intValue),
            redEyeReduction: (bool) ($intValue & 0x40),
        );
    }
}
