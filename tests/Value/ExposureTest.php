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
#[CoversClass(Exposure::class)]
final class ExposureTest extends TestCase
{
    /**
     * Stores exposure measurements and enum metadata.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function storesMeasurementAndEnumValues(): void
    {
        $flashInfo = new FlashInfo(true, FlashMode::AUTO, FlashReturn::RETURN_NOT_DETECTED, FlashFunction::PRESENT, true);

        $exposure = new Exposure(
            iso: 200,
            exposureTimeSec: 0.01,
            fNumber: 4.0,
            exposureBiasEv: -0.3,
            program: ExposureProgram::MANUAL,
            meteringMode: MeteringMode::SPOT,
            flash: $flashInfo,
            whiteBalance: WhiteBalance::MANUAL,
            brightnessEv: 6.5,
            exposureMode: ExposureMode::MANUAL,
            gainControl: GainControl::HIGH_GAIN_UP,
            contrast: Contrast::HARD,
            saturation: Saturation::HIGH,
            sharpness: Sharpness::HARD,
            digitalZoomRatio: 1.5,
            shutterSpeedEv: 7.0,
            apertureEv: 4.0,
            isoLatitudeYyy: 180,
            isoLatitudeZzz: 220,
            exposureIndex: 160.0,
            flashEnergy: 1.2,
        );

        self::assertSame(200, $exposure->iso);
        self::assertSame(0.01, $exposure->exposureTimeSec);
        self::assertSame(4.0, $exposure->fNumber);
        self::assertSame(-0.3, $exposure->exposureBiasEv);
        self::assertSame(ExposureProgram::MANUAL, $exposure->program);
        self::assertSame(MeteringMode::SPOT, $exposure->meteringMode);
        self::assertSame($flashInfo, $exposure->flash);
        self::assertSame(WhiteBalance::MANUAL, $exposure->whiteBalance);
        self::assertSame(6.5, $exposure->brightnessEv);
        self::assertSame(ExposureMode::MANUAL, $exposure->exposureMode);
        self::assertSame(GainControl::HIGH_GAIN_UP, $exposure->gainControl);
        self::assertSame(Contrast::HARD, $exposure->contrast);
        self::assertSame(Saturation::HIGH, $exposure->saturation);
        self::assertSame(Sharpness::HARD, $exposure->sharpness);
        self::assertSame(1.5, $exposure->digitalZoomRatio);
        self::assertSame(7.0, $exposure->shutterSpeedEv);
        self::assertSame(4.0, $exposure->apertureEv);
        self::assertSame(180, $exposure->isoLatitudeYyy);
        self::assertSame(220, $exposure->isoLatitudeZzz);
        self::assertSame(160.0, $exposure->exposureIndex);
        self::assertSame(1.2, $exposure->flashEnergy);
    }
}
