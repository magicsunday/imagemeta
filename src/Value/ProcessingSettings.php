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
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;

/**
 * Describes in-camera processing adjustments such as sharpness and saturation.
 */
final readonly class ProcessingSettings
{
    /**
     * @param Sharpness|null  $sharpness                Sharpness adjustment level.
     * @param Contrast|null   $contrast                 Contrast adjustment level.
     * @param Saturation|null $saturation               Saturation adjustment level.
     * @param string|null     $pictureStyle             Vendor specific picture style identifier.
     * @param float|null      $noiseReduction           Noise reduction strength as reported by the camera.
     * @param int|null        $clarity                  Clarity adjustment level.
     * @param int|null        $customRendered           Indicates whether a custom rendering was applied in-camera.
     * @param string|null     $deviceSettingDescription Binary device setting description payload.
     * @param string|null     $processingSoftware       Final processing software recorded by the camera.
     */
    public function __construct(
        public ?Sharpness $sharpness,
        public ?Contrast $contrast,
        public ?Saturation $saturation,
        public ?string $pictureStyle,
        public ?float $noiseReduction,
        public ?int $clarity,
        public ?int $customRendered,
        public ?string $deviceSettingDescription,
        public ?string $processingSoftware,
    ) {
    }

    /**
     * Returns the sharpness adjustment level.
     */
    public function sharpness(): ?Sharpness
    {
        return $this->sharpness;
    }

    /**
     * Returns the contrast adjustment level.
     */
    public function contrast(): ?Contrast
    {
        return $this->contrast;
    }

    /**
     * Returns the saturation adjustment level.
     */
    public function saturation(): ?Saturation
    {
        return $this->saturation;
    }

    /**
     * Returns the vendor specific picture style identifier.
     */
    public function pictureStyle(): ?string
    {
        return $this->pictureStyle;
    }

    /**
     * Returns the noise reduction strength reported by the camera.
     */
    public function noiseReduction(): ?float
    {
        return $this->noiseReduction;
    }

    /**
     * Returns the clarity adjustment level.
     */
    public function clarity(): ?int
    {
        return $this->clarity;
    }

    /**
     * Indicates whether a custom rendering was applied in-camera.
     */
    public function customRendered(): ?int
    {
        return $this->customRendered;
    }

    /**
     * Returns the binary device setting description payload.
     */
    public function deviceSettingDescription(): ?string
    {
        return $this->deviceSettingDescription;
    }

    /**
     * Returns the final processing software recorded by the camera.
     */
    public function processingSoftware(): ?string
    {
        return $this->processingSoftware;
    }
}
