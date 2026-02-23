<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Groups capture settings metadata: exposure, white balance, scene, motion, and processing.
 */
final readonly class CaptureSettings
{
    /**
     * @param Exposure            $exposure     Exposure parameters.
     * @param WhiteBalanceDetails $whiteBalance White balance details.
     * @param Scene               $scene        Scene characteristics.
     * @param Motion              $motion       Motion metadata.
     * @param ProcessingSettings  $processing   Processing settings.
     */
    public function __construct(
        public Exposure $exposure,
        public WhiteBalanceDetails $whiteBalance,
        public Scene $scene,
        public Motion $motion,
        public ProcessingSettings $processing,
    ) {
    }
}
