<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\DeviceSettingDescription;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the ProcessingSettings value object for image tuning parameters.
 * It verifies contrast, saturation, and sharpness enums are preserved.
 * The suite covers picture style, clarity, and device setting descriptions when present.
 * This ensures processing metadata is stable for downstream rendering or display.
 */
#[CoversClass(ProcessingSettings::class)]
final class ProcessingSettingsTest extends TestCase
{
    /**
     * Stores basic processing settings enums.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithBasicSettings(): void
    {
        $settings = new ProcessingSettings(
            sharpness: Sharpness::NORMAL,
            contrast: Contrast::NORMAL,
            saturation: Saturation::NORMAL,
            pictureStyle: null,
            clarity: null,
            customRendered: null,
            deviceSettingDescription: null,
        );

        self::assertSame(Sharpness::NORMAL, $settings->sharpness);
        self::assertSame(Contrast::NORMAL, $settings->contrast);
        self::assertSame(Saturation::NORMAL, $settings->saturation);
    }

    /**
     * Stores full processing settings including device descriptions.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithAllProcessingInfo(): void
    {
        $deviceDesc = new DeviceSettingDescription(
            columns: 5,
            rows: 10,
            settings: ['Camera settings'],
        );

        $settings = new ProcessingSettings(
            sharpness: Sharpness::HARD,
            contrast: Contrast::HARD,
            saturation: Saturation::HIGH,
            pictureStyle: 'Vivid',
            clarity: 25,
            customRendered: 1,
            deviceSettingDescription: $deviceDesc,
        );

        self::assertSame(Sharpness::HARD, $settings->sharpness);
        self::assertSame(Contrast::HARD, $settings->contrast);
        self::assertSame(Saturation::HIGH, $settings->saturation);
        self::assertSame('Vivid', $settings->pictureStyle);
        self::assertSame(25, $settings->clarity);
        self::assertSame(1, $settings->customRendered);
        self::assertSame($deviceDesc, $settings->deviceSettingDescription);
    }

    /**
     * Accepts null processing settings values.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $settings = new ProcessingSettings(
            sharpness: null,
            contrast: null,
            saturation: null,
            pictureStyle: null,
            clarity: null,
            customRendered: null,
            deviceSettingDescription: null,
        );

        self::assertNull($settings->sharpness);
        self::assertNull($settings->contrast);
        self::assertNull($settings->saturation);
        self::assertNull($settings->pictureStyle);
    }
}
