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
     * @param bool               $fired            Whether the flash fired.
     * @param FlashMode|null     $mode             Selected flash mode.
     * @param FlashReturn|null   $returnDetection  Detected return light status.
     * @param FlashFunction|null $functionPresence Whether the camera features a flash function.
     * @param bool               $redEyeReduction  Indicates red-eye reduction support.
     */
    public function __construct(
        public readonly bool $fired,
        public readonly ?FlashMode $mode = null,
        public readonly ?FlashReturn $returnDetection = null,
        public readonly ?FlashFunction $functionPresence = null,
        public readonly bool $redEyeReduction = false,
    ) {
    }

    /**
     * Indicates whether the flash fired.
     */

    /**
     * Indicates whether the camera features a flash function.
     */

    /**
     * Indicates whether red-eye reduction support is reported.
     */
}
