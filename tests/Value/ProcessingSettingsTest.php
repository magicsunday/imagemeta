<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ProcessingSettings value object.
 */
#[CoversClass(ProcessingSettings::class)]
final class ProcessingSettingsTest extends TestCase
{
    #[Test]
    public function constructsWithBasicSettings(): void
    {
        $settings = new ProcessingSettings(
            sharpness: Sharpness::NORMAL,
            contrast: Contrast::NORMAL,
            saturation: Saturation::NORMAL,
            pictureStyle: null,
            noiseReduction: null,
            clarity: null,
            customRendered: null,
            deviceSettingDescription: null,
            processingSoftware: null,
        );

        self::assertSame(Sharpness::NORMAL, $settings->sharpness);
        self::assertSame(Contrast::NORMAL, $settings->contrast);
        self::assertSame(Saturation::NORMAL, $settings->saturation);
    }

    #[Test]
    public function constructsWithAllProcessingInfo(): void
    {
        $settings = new ProcessingSettings(
            sharpness: Sharpness::HARD,
            contrast: Contrast::HARD,
            saturation: Saturation::HIGH,
            pictureStyle: 'Vivid',
            noiseReduction: 0.5,
            clarity: 25,
            customRendered: 1,
            deviceSettingDescription: 'binary_data',
            processingSoftware: 'Camera Firmware 1.0',
        );

        self::assertSame(Sharpness::HARD, $settings->sharpness);
        self::assertSame(Contrast::HARD, $settings->contrast);
        self::assertSame(Saturation::HIGH, $settings->saturation);
        self::assertSame('Vivid', $settings->pictureStyle);
        self::assertSame(0.5, $settings->noiseReduction);
        self::assertSame(25, $settings->clarity);
        self::assertSame(1, $settings->customRendered);
        self::assertSame('binary_data', $settings->deviceSettingDescription);
        self::assertSame('Camera Firmware 1.0', $settings->processingSoftware);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $settings = new ProcessingSettings(
            sharpness: null,
            contrast: null,
            saturation: null,
            pictureStyle: null,
            noiseReduction: null,
            clarity: null,
            customRendered: null,
            deviceSettingDescription: null,
            processingSoftware: null,
        );

        self::assertNull($settings->sharpness);
        self::assertNull($settings->contrast);
        self::assertNull($settings->saturation);
        self::assertNull($settings->pictureStyle);
        self::assertNull($settings->noiseReduction);
    }
}
