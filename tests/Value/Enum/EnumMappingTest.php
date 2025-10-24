<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value\Enum;

use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\DepthFormat;
use MagicSunday\ImageMeta\Value\Enum\DepthMeasureType;
use MagicSunday\ImageMeta\Value\Enum\DepthUnits;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Value\Enum\Compression
 * @covers \MagicSunday\ImageMeta\Value\Enum\Photometric
 * @covers \MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration
 * @covers \MagicSunday\ImageMeta\Value\Enum\ResolutionUnit
 * @covers \MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning
 * @covers \MagicSunday\ImageMeta\Value\Enum\ExposureMode
 * @covers \MagicSunday\ImageMeta\Value\Enum\GainControl
 * @covers \MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange
 * @covers \MagicSunday\ImageMeta\Value\Enum\FileSource
 * @covers \MagicSunday\ImageMeta\Value\Enum\SensingMethod
 * @covers \MagicSunday\ImageMeta\Value\Enum\CompositeImage
 * @covers \MagicSunday\ImageMeta\Value\Enum\DepthFormat
 * @covers \MagicSunday\ImageMeta\Value\Enum\DepthUnits
 * @covers \MagicSunday\ImageMeta\Value\Enum\DepthMeasureType
 */
final class EnumMappingTest extends TestCase
{
    #[Test]
    public function mapsCommonEnumValues(): void
    {
        self::assertSame(Compression::JPEG, Compression::fromExifValue(7));
        self::assertSame(Photometric::YCBCR, Photometric::fromExifValue(6));
        self::assertSame(Photometric::DEPTH_MAP, Photometric::fromExifValue(511));
        self::assertSame(PlanarConfiguration::CHUNKY, PlanarConfiguration::fromExifValue(1));
        self::assertSame(ResolutionUnit::CENTIMETER, ResolutionUnit::fromExifValue(3));
        self::assertSame(YCbCrPositioning::CO_SITED, YCbCrPositioning::fromExifValue(2));
        self::assertSame(ExposureMode::AUTO_BRACKET, ExposureMode::fromExifValue(2));
        self::assertSame(GainControl::HIGH_GAIN_UP, GainControl::fromExifValue(2));
        self::assertSame(SubjectDistanceRange::MACRO, SubjectDistanceRange::fromExifValue(SubjectDistanceRange::MACRO->value));
        self::assertSame(FileSource::DIGITAL_CAMERA, FileSource::fromExifValue(3));
        self::assertSame(FileSource::SIGMA_FOVEON, FileSource::fromExifValue(0x8000));
        self::assertSame(SensingMethod::COLOR_SEQUENTIAL_LINEAR, SensingMethod::fromExifValue(8));
        self::assertSame(CompositeImage::CAPTURED_WHILE_SHOOTING, CompositeImage::fromExifValue(3));
        self::assertSame(DepthFormat::INVERSE, DepthFormat::fromExifValue(2));
        self::assertSame(DepthUnits::METERS, DepthUnits::fromExifValue(1));
        self::assertSame(DepthMeasureType::OPTICAL_AXIS, DepthMeasureType::fromExifValue(1));
    }
}
