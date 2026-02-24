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
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\ExposureAdjustments;
use MagicSunday\ImageMeta\Value\ExposureSettings;
use MagicSunday\ImageMeta\Value\FlashInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Exposure value object for numeric measurements and enum fields.
 * It verifies shutter time, aperture, ISO, and exposure bias are stored correctly.
 * The suite covers flash information and enums like metering mode and white balance.
 * This ensures exposure metadata remains consistent across structured outputs.
 *
 * @internal
 */
#[UsesClass(FlashInfo::class)]
#[UsesClass(ExposureSettings::class)]
#[UsesClass(ExposureAdjustments::class)]
#[CoversClass(Exposure::class)]
final class ExposureTest extends TestCase
{
    /**
     * Stores exposure measurements and enum metadata.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function storesMeasurementAndEnumValues(): void
    {
        $flashInfo = new FlashInfo(true, FlashMode::Auto, FlashReturn::ReturnNotDetected, FlashFunction::Present, true);

        $settings = new ExposureSettings(
            iso: 200,
            exposureIndex: 160.0,
            isoLatitudeYyy: 180,
            isoLatitudeZzz: 220,
            exposureTimeSec: 0.01,
            shutterSpeedEv: 7.0,
            fNumber: 4.0,
            apertureEv: 4.0,
            exposureBiasEv: -0.3,
            brightnessEv: 6.5,
        );

        $adjustments = new ExposureAdjustments(
            whiteBalance: WhiteBalance::Manual,
            contrast: Contrast::Hard,
            saturation: Saturation::High,
            sharpness: Sharpness::Hard,
            digitalZoomRatio: 1.5,
            gainControl: GainControl::HighGainUp,
        );

        $exposure = new Exposure(
            settings: $settings,
            adjustments: $adjustments,
            program: ExposureProgram::Manual,
            exposureMode: ExposureMode::Manual,
            meteringMode: MeteringMode::Spot,
            flash: $flashInfo,
            flashEnergy: 1.2,
        );

        self::assertNotNull($exposure->settings);
        self::assertNotNull($exposure->adjustments);

        self::assertSame(200, $exposure->settings->iso);
        self::assertSame(0.01, $exposure->settings->exposureTimeSec);
        self::assertSame(4.0, $exposure->settings->fNumber);
        self::assertSame(-0.3, $exposure->settings->exposureBiasEv);
        self::assertSame(ExposureProgram::Manual, $exposure->program);
        self::assertSame(MeteringMode::Spot, $exposure->meteringMode);
        self::assertSame($flashInfo, $exposure->flash);
        self::assertSame(WhiteBalance::Manual, $exposure->adjustments->whiteBalance);
        self::assertSame(6.5, $exposure->settings->brightnessEv);
        self::assertSame(ExposureMode::Manual, $exposure->exposureMode);
        self::assertSame(GainControl::HighGainUp, $exposure->adjustments->gainControl);
        self::assertSame(Contrast::Hard, $exposure->adjustments->contrast);
        self::assertSame(Saturation::High, $exposure->adjustments->saturation);
        self::assertSame(Sharpness::Hard, $exposure->adjustments->sharpness);
        self::assertSame(1.5, $exposure->adjustments->digitalZoomRatio);
        self::assertSame(7.0, $exposure->settings->shutterSpeedEv);
        self::assertSame(4.0, $exposure->settings->apertureEv);
        self::assertSame(180, $exposure->settings->isoLatitudeYyy);
        self::assertSame(220, $exposure->settings->isoLatitudeZzz);
        self::assertSame(160.0, $exposure->settings->exposureIndex);
        self::assertSame(1.2, $exposure->flashEnergy);
    }
}
