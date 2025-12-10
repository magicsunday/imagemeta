<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedExif::class)]
final class ParsedExifShootingConditionsTest extends TestCase
{
    #[Test]
    public function returnsExposureProgramEnumFromExifValue(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXPOSURE_PROGRAM => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, 4),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(ExposureProgram::SHUTTER_PRIORITY, $parsedExif->exposureProgram());
    }

    #[Test]
    public function returnsCustomRenderedValue(): void
    {
        $exifIfd = new Ifd([
            ExifTag::CUSTOM_RENDERED => new IfdEntry(ExifTag::CUSTOM_RENDERED, 3, 1, 1),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(CustomRendered::CUSTOM_PROCESS, $parsedExif->customRendered());
    }

    #[Test]
    public function normalizesDigitalZoomRatioAndTreatsZeroAsMissing(): void
    {
        $ratioIfd = new Ifd([
            ExifTag::DIGITAL_ZOOM_RATIO => new IfdEntry(ExifTag::DIGITAL_ZOOM_RATIO, 5, 1, [0, 10]),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $ratioIfd, null, null, null);

        self::assertNull($parsedExif->digitalZoomRatio());

        $ratioIfd = new Ifd([
            ExifTag::DIGITAL_ZOOM_RATIO => new IfdEntry(ExifTag::DIGITAL_ZOOM_RATIO, 5, 1, [150, 100]),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $ratioIfd, null, null, null);

        self::assertSame(1.5, $parsedExif->digitalZoomRatio());
    }

    #[Test]
    public function returnsWhiteBalanceAndExposureModeEnums(): void
    {
        $exifIfd = new Ifd([
            ExifTag::WHITE_BALANCE      => new IfdEntry(ExifTag::WHITE_BALANCE, 3, 1, 1),
            ExifTag::EXPOSURE_MODE      => new IfdEntry(ExifTag::EXPOSURE_MODE, 3, 1, 0),
            ExifTag::SCENE_CAPTURE_TYPE => new IfdEntry(ExifTag::SCENE_CAPTURE_TYPE, 3, 1, 4),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(WhiteBalance::MANUAL, $parsedExif->whiteBalance());
        self::assertSame(ExposureMode::AUTO, $parsedExif->exposureMode());
        self::assertNull($parsedExif->sceneCaptureType());
    }

    #[Test]
    public function returnsNullForReservedExposureProgramValue(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXPOSURE_PROGRAM => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, 9),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->exposureProgram());
    }

    #[Test]
    public function returnsSpectralSensitivityString(): void
    {
        $spectralString = "ISO 12232 SOS\0";
        $exifIfd        = new Ifd([
            ExifTag::SPECTRAL_SENSITIVITY => new IfdEntry(ExifTag::SPECTRAL_SENSITIVITY, 2, 14, $spectralString),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame('ISO 12232 SOS', $parsedExif->spectralSensitivity());
    }

    #[Test]
    public function returnsPhotographicSensitivityViaAlias(): void
    {
        $exifIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 640),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(640, $parsedExif->photographicSensitivity());
    }

    #[Test]
    public function returnsExposureIndexFromRational(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXPOSURE_INDEX => new IfdEntry(ExifTag::EXPOSURE_INDEX, 5, 1, [[320, 2]]),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(160.0, $parsedExif->exposureIndex());
    }

    #[Test]
    public function returnsSensingMethodEnum(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SENSING_METHOD => new IfdEntry(ExifTag::SENSING_METHOD, 3, 1, SensingMethod::ONE_CHIP_COLOR_AREA->value),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(SensingMethod::ONE_CHIP_COLOR_AREA, $parsedExif->sensingMethod());
    }

    #[Test]
    public function ignoresReservedSensingMethodCodes(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SENSING_METHOD => new IfdEntry(ExifTag::SENSING_METHOD, 3, 1, 6),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->sensingMethod());
    }

    #[Test]
    public function returnsFileSourceFromUndefinedByte(): void
    {
        $exifIfd = new Ifd([
            ExifTag::FILE_SOURCE => new IfdEntry(ExifTag::FILE_SOURCE, 7, 1, "\x03"),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(FileSource::DIGITAL_CAMERA, $parsedExif->fileSource());
    }

    #[Test]
    public function returnsSceneTypeFromUndefinedByte(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SCENE_TYPE => new IfdEntry(ExifTag::SCENE_TYPE, 7, 1, "\x01"),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(SceneType::DIRECTLY_PHOTOGRAPHED_IMAGE, $parsedExif->sceneType());
    }
}
