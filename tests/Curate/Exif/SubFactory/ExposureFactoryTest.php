<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Curate\Exif\SubFactory\ExposureFactory;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\ExifFlash;
use MagicSunday\ImageMeta\Value\Exposure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExposureFactory::class)]
final class ExposureFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('isoBestEffort')->willReturn(100);
        $exifDoc->method('exposureTime')->willReturn(0.01);
        $exifDoc->method('fNumber')->willReturn(2.8);
        $exifDoc->method('exposureBias')->willReturn(0.3);
        $exifDoc->method('exposureProgram')->willReturn(ExposureProgram::APERTURE_PRIORITY);
        $exifDoc->method('meteringMode')->willReturn(MeteringMode::SPOT);
        $exifDoc->method('flash')->willReturn(0x0001);
        $exifDoc->method('whiteBalance')->willReturn(WhiteBalance::AUTO);
        $exifDoc->method('brightnessValue')->willReturn(5.0);
        $exifDoc->method('exposureMode')->willReturn(ExposureMode::AUTO);
        $exifDoc->method('gainControl')->willReturn(GainControl::NONE);
        $exifDoc->method('contrast')->willReturn(Contrast::NORMAL);
        $exifDoc->method('saturation')->willReturn(Saturation::NORMAL);
        $exifDoc->method('sharpness')->willReturn(Sharpness::NORMAL);
        $exifDoc->method('digitalZoomRatio')->willReturn(1.0);
        $exifDoc->method('shutterSpeedEv')->willReturn(6.64);
        $exifDoc->method('apertureEv')->willReturn(2.97);
        $exifDoc->method('isoLatitudeYyy')->willReturn(null);
        $exifDoc->method('isoLatitudeZzz')->willReturn(null);
        $exifDoc->method('exposureIndex')->willReturn(null);
        $exifDoc->method('flashEnergy')->willReturn(null);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory  = new ExposureFactory();
        $exposure = $factory->create($metadata);

        self::assertInstanceOf(Exposure::class, $exposure);
        self::assertSame(100, $exposure->iso);
        self::assertSame(0.01, $exposure->exposureTimeSec);
        self::assertSame(2.8, $exposure->fNumber);
        self::assertSame(0.3, $exposure->exposureBiasEv);
        self::assertSame(ExposureProgram::APERTURE_PRIORITY, $exposure->program);
        self::assertSame(MeteringMode::SPOT, $exposure->meteringMode);
        self::assertInstanceOf(ExifFlash::class, $exposure->flash);
        self::assertTrue($exposure->flash->fired);
        self::assertSame(WhiteBalance::AUTO, $exposure->whiteBalance);
        self::assertSame(5.0, $exposure->brightnessEv);
        self::assertSame(ExposureMode::AUTO, $exposure->exposureMode);
        self::assertSame(GainControl::NONE, $exposure->gainControl);
    }

    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $metadata = new Metadata();

        $factory  = new ExposureFactory();
        $exposure = $factory->create($metadata);

        self::assertInstanceOf(Exposure::class, $exposure);
        self::assertNull($exposure->iso);
        self::assertNull($exposure->exposureTimeSec);
        self::assertNull($exposure->fNumber);
        self::assertNull($exposure->exposureBiasEv);
        self::assertNull($exposure->program);
        self::assertNull($exposure->meteringMode);
        self::assertInstanceOf(ExifFlash::class, $exposure->flash);
        self::assertFalse($exposure->flash->fired);
        self::assertNull($exposure->whiteBalance);
    }

    #[Test]
    public function parsesFlashInformation(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('isoBestEffort')->willReturn(null);
        $exifDoc->method('exposureTime')->willReturn(null);
        $exifDoc->method('fNumber')->willReturn(null);
        $exifDoc->method('exposureBias')->willReturn(null);
        $exifDoc->method('exposureProgram')->willReturn(null);
        $exifDoc->method('meteringMode')->willReturn(null);
        $exifDoc->method('flash')->willReturn(0x0019);
        $exifDoc->method('whiteBalance')->willReturn(null);
        $exifDoc->method('brightnessValue')->willReturn(null);
        $exifDoc->method('exposureMode')->willReturn(null);
        $exifDoc->method('gainControl')->willReturn(null);
        $exifDoc->method('contrast')->willReturn(null);
        $exifDoc->method('saturation')->willReturn(null);
        $exifDoc->method('sharpness')->willReturn(null);
        $exifDoc->method('digitalZoomRatio')->willReturn(null);
        $exifDoc->method('shutterSpeedEv')->willReturn(null);
        $exifDoc->method('apertureEv')->willReturn(null);
        $exifDoc->method('isoLatitudeYyy')->willReturn(null);
        $exifDoc->method('isoLatitudeZzz')->willReturn(null);
        $exifDoc->method('exposureIndex')->willReturn(null);
        $exifDoc->method('flashEnergy')->willReturn(null);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory  = new ExposureFactory();
        $exposure = $factory->create($metadata);

        self::assertInstanceOf(Exposure::class, $exposure);
        self::assertInstanceOf(ExifFlash::class, $exposure->flash);
        self::assertTrue($exposure->flash->fired);
    }
}
