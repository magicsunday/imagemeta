<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\CorrectionApplied;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\DevelopmentCharacteristic;
use MagicSunday\ImageMeta\Value\Enum\DevelopmentDefault;
use MagicSunday\ImageMeta\Value\Enum\NoiseReduction;

/**
 * Describes in-camera processing adjustments such as custom rendering and corrections.
 */
final readonly class ProcessingSettings
{
    /**
     * Creates a processing settings metadata value object.
     *
     * @param string|null                    $pictureStyle                  Vendor specific picture style identifier.
     * @param int|null                       $clarity                       Clarity adjustment level.
     * @param CustomRendered|null            $customRendered                Indicates whether a custom rendering was applied in-camera.
     * @param DeviceSettingDescription|null  $deviceSettingDescription      Structured device setting description.
     * @param CorrectionApplied|null         $distortionCorrection          Whether distortion correction was applied; EXIF 3.1 §4.6.6.7.49.
     * @param CorrectionApplied|null         $chromaticAberrationCorrection Whether chromatic aberration correction was applied; EXIF 3.1 §4.6.6.7.50.
     * @param CorrectionApplied|null         $shadingCorrection             Whether shading correction was applied; EXIF 3.1 §4.6.6.7.51.
     * @param NoiseReduction|null            $noiseReduction                Noise reduction tendency; EXIF 3.1 §4.6.6.7.52.
     * @param DevelopmentCharacteristic|null $developmentCharacteristic     Development characteristic; EXIF 3.1 §4.6.6.7.47.
     * @param DevelopmentDefault|null        $developmentDefault            Factory default comparison; EXIF 3.1 §4.6.6.7.47.
     * @param string|null                    $developmentTypeDescription    Development description; EXIF 3.1 §4.6.6.7.48.
     */
    public function __construct(
        public ?string $pictureStyle,
        public ?int $clarity,
        public ?CustomRendered $customRendered,
        public ?DeviceSettingDescription $deviceSettingDescription,
        public ?CorrectionApplied $distortionCorrection = null,
        public ?CorrectionApplied $chromaticAberrationCorrection = null,
        public ?CorrectionApplied $shadingCorrection = null,
        public ?NoiseReduction $noiseReduction = null,
        public ?DevelopmentCharacteristic $developmentCharacteristic = null,
        public ?DevelopmentDefault $developmentDefault = null,
        public ?string $developmentTypeDescription = null,
    ) {
    }
}
