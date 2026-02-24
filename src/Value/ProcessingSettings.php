<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;

/**
 * Describes in-camera processing adjustments such as sharpness and saturation.
 */
final readonly class ProcessingSettings
{
    /**
     * Creates a processing settings metadata value object.
     *
     * @param Sharpness|null                $sharpness                Sharpness adjustment level.
     * @param Contrast|null                 $contrast                 Contrast adjustment level.
     * @param Saturation|null               $saturation               Saturation adjustment level.
     * @param string|null                   $pictureStyle             Vendor specific picture style identifier.
     * @param int|null                      $clarity                  Clarity adjustment level.
     * @param CustomRendered|null           $customRendered           Indicates whether a custom rendering was applied in-camera.
     * @param DeviceSettingDescription|null $deviceSettingDescription Structured device setting description.
     */
    public function __construct(
        public ?Sharpness $sharpness,
        public ?Contrast $contrast,
        public ?Saturation $saturation,
        public ?string $pictureStyle,
        public ?int $clarity,
        public ?CustomRendered $customRendered,
        public ?DeviceSettingDescription $deviceSettingDescription,
    ) {
    }
}
