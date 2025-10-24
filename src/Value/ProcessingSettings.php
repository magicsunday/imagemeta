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
 * Describes in-camera processing adjustments such as sharpness and saturation.
 */
final readonly class ProcessingSettings
{
    /**
     * @param int|null    $sharpness                Sharpness adjustment level.
     * @param int|null    $contrast                 Contrast adjustment level.
     * @param int|null    $saturation               Saturation adjustment level.
     * @param string|null $pictureStyle             Vendor specific picture style identifier.
     * @param bool|null   $noiseReduction           Whether noise reduction was applied.
     * @param int|null    $clarity                  Clarity adjustment level.
     * @param int|null    $customRendered           Indicates whether a custom rendering was applied in-camera.
     * @param string|null $deviceSettingDescription Binary device setting description payload.
     */
    public function __construct(
        public ?int $sharpness,
        public ?int $contrast,
        public ?int $saturation,
        public ?string $pictureStyle,
        public ?bool $noiseReduction,
        public ?int $clarity,
        public ?int $customRendered,
        public ?string $deviceSettingDescription,
    ) {
    }
}
