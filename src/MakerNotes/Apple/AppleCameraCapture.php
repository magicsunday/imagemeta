<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

/**
 * Camera hardware and capture settings extracted from Apple maker notes.
 */
final readonly class AppleCameraCapture
{
    /**
     * @param string|int|null  $cameraType            Describes the hardware camera (e.g. "Wide", "Tele").
     * @param string|null      $imageCaptureType      Capture type enumeration label.
     * @param string|null      $makerNoteVersion      Normalized maker note version string reported by the device.
     * @param string|null      $qualityHint           Quality hint reported by the processing pipeline.
     * @param string|null      $oisMode               Optical image stabilisation mode.
     * @param int|null         $colorTemperature      White balance colour temperature in Kelvin.
     * @param list<float>|null $colorCorrectionMatrix Color correction matrix components in row-major order.
     */
    public function __construct(
        public string|int|null $cameraType,
        public ?string $imageCaptureType,
        public ?string $makerNoteVersion,
        public ?string $qualityHint,
        public ?string $oisMode,
        public ?int $colorTemperature,
        public ?array $colorCorrectionMatrix,
    ) {
    }
}
