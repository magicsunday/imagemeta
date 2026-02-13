<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises shooting-condition enums derived from EXIF tags.
 * It verifies exposure program, custom rendered, white balance, and scene type mappings.
 * The suite checks file source and sensing method conversions from raw values.
 * This ensures capture-condition metadata is normalized into enums reliably.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifShootingConditionsTest extends TestCase
{
    /**
     * Maps the EXIF exposure program value to the shutter-priority enum.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
    #[Test]
    public function returnsExposureProgramEnumFromExifValue(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXPOSURE_PROGRAM => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, 4),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(ExposureProgram::SHUTTER_PRIORITY, $parsedExif->exposureProgram());
    }

    /**
     * Returns the custom-rendered enum when the tag is present.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
    #[Test]
    public function returnsCustomRenderedValue(): void
    {
        $exifIfd = new Ifd([
            ExifTag::CUSTOM_RENDERED => new IfdEntry(ExifTag::CUSTOM_RENDERED, 3, 1, 1),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(CustomRendered::CUSTOM_PROCESS, $parsedExif->customRendered());
    }

    /**
     * Treats a zero digital zoom ratio as missing and normalizes non-zero ratios.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
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

    /**
     * Maps white balance and exposure mode values while ignoring reserved scene capture types.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
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

    /**
     * Exposes raw and typed flash information from the EXIF Flash bit field.
     * It verifies representative bit decoding for fired state, return detection, and mode flags.
     *
     * @return void
     */
    #[Test]
    public function returnsRawAndTypedFlashInformation(): void
    {
        $exifIfd = new Ifd([
            ExifTag::FLASH => new IfdEntry(ExifTag::FLASH, 3, 1, 0x7D),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);
        $flashInfo  = $parsedExif->flashInfo();

        self::assertSame(0x7D, $parsedExif->flash());
        self::assertNotNull($flashInfo);
        self::assertTrue($flashInfo->fired);
        self::assertSame(FlashMode::AUTO, $flashInfo->mode);
        self::assertSame(FlashReturn::RETURN_NOT_DETECTED, $flashInfo->returnDetection);
        self::assertSame(FlashFunction::ABSENT, $flashInfo->functionPresence);
        self::assertTrue($flashInfo->redEyeReduction);
    }

    /**
     * Returns no flash metadata when the optional Flash tag is absent.
     * It ensures both raw and typed accessors stay nullable.
     *
     * @return void
     */
    #[Test]
    public function returnsNullForMissingFlashTag(): void
    {
        $parsedExif = new ParsedExif(new Ifd([]), null, null, null, null);

        self::assertNull($parsedExif->flash());
        self::assertNull($parsedExif->flashInfo());
    }

    /**
     * Ignores reserved exposure program values.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function returnsNullForReservedExposureProgramValue(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXPOSURE_PROGRAM => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, 9),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->exposureProgram());
    }

    /**
     * Trims NUL padding from spectral sensitivity strings.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
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

    /**
     * Exposes the photographic sensitivity alias value.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
    #[Test]
    public function returnsPhotographicSensitivityViaAlias(): void
    {
        $exifIfd = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 640),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(640, $parsedExif->photographicSensitivity());
    }

    /**
     * Converts exposure index rationals to floating-point values.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
    #[Test]
    public function returnsExposureIndexFromRational(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXPOSURE_INDEX => new IfdEntry(ExifTag::EXPOSURE_INDEX, 5, 1, [[320, 2]]),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(160.0, $parsedExif->exposureIndex());
    }

    /**
     * Maps sensing method codes to the corresponding enum.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
    #[Test]
    public function returnsSensingMethodEnum(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SENSING_METHOD => new IfdEntry(ExifTag::SENSING_METHOD, 3, 1, SensingMethod::ONE_CHIP_COLOR_AREA->value),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(SensingMethod::ONE_CHIP_COLOR_AREA, $parsedExif->sensingMethod());
    }

    /**
     * Ignores reserved sensing method codes.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
    #[Test]
    public function ignoresReservedSensingMethodCodes(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SENSING_METHOD => new IfdEntry(ExifTag::SENSING_METHOD, 3, 1, 6),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->sensingMethod());
    }

    /**
     * Parses file source values stored as undefined bytes.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
    #[Test]
    public function returnsFileSourceFromUndefinedByte(): void
    {
        $exifIfd = new Ifd([
            ExifTag::FILE_SOURCE => new IfdEntry(ExifTag::FILE_SOURCE, 7, 1, "\x03"),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(FileSource::DIGITAL_CAMERA, $parsedExif->fileSource());
    }

    /**
     * Parses scene type values stored as undefined bytes.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
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
