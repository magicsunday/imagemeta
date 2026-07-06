<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Structured;

use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\ExifFlash;
use MagicSunday\ImageMeta\Exif\Converters\FlashConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\ExposureParameterReader;
use MagicSunday\ImageMeta\Exif\Reader\IsoSensitivityReader;
use MagicSunday\ImageMeta\Exif\Reader\SceneModeReader;
use MagicSunday\ImageMeta\Exif\Reconciliation\ExifXmpMapping;
use MagicSunday\ImageMeta\Exif\Reconciliation\ExifXmpMappingRegistry;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\Structured\ExposureFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Riff\RiffInfoLookup;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\ExposureAdjustments;
use MagicSunday\ImageMeta\Value\ExposureSettings;
use MagicSunday\ImageMeta\Value\FlashInfo;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ExposureFactory for mapping EXIF exposure tags to Exposure values.
 * It verifies ISO, flash state, and exposure parameters are derived correctly.
 * The suite checks FlashInfo construction and proper handling of missing fields.
 * This ensures exposure metadata is normalized consistently from EXIF input.
 *
 * @internal
 */
#[CoversClass(ExposureFactory::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(ExifFlash::class)]
#[UsesClass(FlashConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(FallbackIfdSet::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ExposureParameterReader::class)]
#[UsesClass(IsoSensitivityReader::class)]
#[UsesClass(SceneModeReader::class)]
#[UsesClass(ExifXmpMapping::class)]
#[UsesClass(ExifXmpMappingRegistry::class)]
#[UsesClass(XmpFallbackResolver::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(RiffInfoLookup::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(Exposure::class)]
#[UsesClass(ExposureAdjustments::class)]
#[UsesClass(ExposureSettings::class)]
#[UsesClass(FlashInfo::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class ExposureFactoryTest extends TestCase
{
    /**
     * Builds ParsedExif data with ISO and flash tags and passes it through ExposureFactory.
     * Verifies ISO is set and FlashInfo indicates the flash fired.
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExifWithIsoAndFlash(100, 0x0001);

        $exposure = $this->createExposure($parsedExif);

        self::assertNotNull($exposure->settings);
        self::assertSame(100, $exposure->settings->iso);
        self::assertInstanceOf(FlashInfo::class, $exposure->flash);
        self::assertTrue($exposure->flash->fired);
    }

    /**
     * Creates Metadata without an EXIF document attached.
     * Ensures ISO is null and flash is null when no EXIF data exists.
     */
    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $exposure = $this->createExposure(null);

        self::assertNotNull($exposure->settings);
        self::assertNull($exposure->settings->iso);
        self::assertNull($exposure->flash);
    }

    /**
     * Supplies only flash information without ISO data in the EXIF IFD.
     * Confirms flash details are parsed even when ISO is missing.
     */
    #[Test]
    public function parsesFlashInformation(): void
    {
        $parsedExif = $this->parsedExifWithIsoAndFlash(null, 0x0019);

        $exposure = $this->createExposure($parsedExif);

        self::assertNotNull($exposure->settings);
        self::assertNull($exposure->settings->iso);
        self::assertInstanceOf(FlashInfo::class, $exposure->flash);
        self::assertTrue($exposure->flash->fired);
    }

    /**
     * Supplies an IFD entry with a wrong TIFF type for ISO (ASCII instead of SHORT).
     * Verifies the factory degrades gracefully and returns null ISO.
     */
    #[Test]
    public function returnsNullIsoWhenTagHasWrongType(): void
    {
        $entries = [
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                2,
                3,
                'abc',
            ),
        ];

        $parsedExif = $this->createParsedExifWithExifEntries($entries);
        $exposure   = $this->createExposure($parsedExif);

        self::assertNotNull($exposure->settings);
        self::assertNull($exposure->settings->iso);
    }

    /**
     * Supplies IFD entries with empty EXIF IFD (no flash, no ISO).
     * Confirms flash is null and ISO stays null when neither tag is present.
     */
    #[Test]
    public function emptyExifIfdYieldsNullFlashAndNullIso(): void
    {
        $parsedExif = $this->createParsedExifWithExifEntries([]);
        $exposure   = $this->createExposure($parsedExif);

        self::assertNotNull($exposure->settings);
        self::assertNull($exposure->settings->iso);
        self::assertNull($exposure->flash);
    }

    /**
     * Provides both EXIF and XMP values for exposureProgram.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForExposureProgramEnum(): void
    {
        $entries = [
            ExifTag::EXPOSURE_PROGRAM => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, 3),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}ExposureProgram' => '5',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertSame(ExposureProgram::AperturePriority, $exposure->program);
    }

    /**
     * Provides both EXIF and XMP values for meteringMode.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForMeteringModeEnum(): void
    {
        $entries = [
            ExifTag::METERING_MODE => new IfdEntry(ExifTag::METERING_MODE, 3, 1, 3),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}MeteringMode' => '5',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertSame(MeteringMode::Spot, $exposure->meteringMode);
    }

    /**
     * Provides both EXIF and XMP values for whiteBalance.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForWhiteBalanceEnum(): void
    {
        $entries = [
            ExifTag::WHITE_BALANCE => new IfdEntry(ExifTag::WHITE_BALANCE, 3, 1, 1),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}WhiteBalance' => '0',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->adjustments);
        self::assertSame(WhiteBalance::Manual, $exposure->adjustments->whiteBalance);
    }

    /**
     * Provides both EXIF and XMP values for ISO sensitivity.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForIso(): void
    {
        $entries = [
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
        ];

        $xmpData = [
            '{http://cipa.jp/exif/1.0/}PhotographicSensitivity' => '800',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->settings);
        self::assertSame(200, $exposure->settings->iso);
    }

    /**
     * Provides both EXIF and XMP values for exposureIndex.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForExposureIndex(): void
    {
        $entries = [
            ExifTag::EXPOSURE_INDEX => new IfdEntry(ExifTag::EXPOSURE_INDEX, 5, 1, new ExifRational(100, 1)),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}ExposureIndex' => '200/1',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->settings);
        self::assertSame(100.0, $exposure->settings->exposureIndex);
    }

    /**
     * Provides both EXIF and XMP values for isoLatitudeYyy.
     * Includes ISO_SPEED and ISO_SPEED_LATITUDE_ZZZ entries required by the gating check.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForIsoLatitudeYyy(): void
    {
        $entries = [
            ExifTag::ISO_SPEED              => new IfdEntry(ExifTag::ISO_SPEED, 4, 1, 400),
            ExifTag::ISO_SPEED_LATITUDE_YYY => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_YYY, 4, 1, 500),
            ExifTag::ISO_SPEED_LATITUDE_ZZZ => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_ZZZ, 4, 1, 300),
        ];

        $xmpData = [
            '{http://cipa.jp/exif/1.0/}ISOSpeedLatitudeyyy' => '999',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->settings);
        self::assertSame(500, $exposure->settings->isoLatitudeYyy);
    }

    /**
     * Provides both EXIF and XMP values for isoLatitudeZzz.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForIsoLatitudeZzz(): void
    {
        $entries = [
            ExifTag::ISO_SPEED_LATITUDE_ZZZ => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_ZZZ, 4, 1, 300),
        ];

        $xmpData = [
            '{http://cipa.jp/exif/1.0/}ISOSpeedLatitudezzz' => '999',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->settings);
        self::assertSame(300, $exposure->settings->isoLatitudeZzz);
    }

    /**
     * Provides both EXIF and XMP values for exposureTime.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForExposureTime(): void
    {
        $entries = [
            ExifTag::EXPOSURE_TIME => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, new ExifRational(1, 125)),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}ExposureTime' => '1/250',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->settings);
        self::assertEqualsWithDelta(1.0 / 125.0, $exposure->settings->exposureTimeSec, 0.0001);
    }

    /**
     * Provides both EXIF and XMP values for shutterSpeedValue.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForShutterSpeedEv(): void
    {
        $entries = [
            ExifTag::SHUTTER_SPEED_VALUE => new IfdEntry(ExifTag::SHUTTER_SPEED_VALUE, 10, 1, new ExifRational(7, 1)),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}ShutterSpeedValue' => '10/1',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->settings);
        self::assertSame(7.0, $exposure->settings->shutterSpeedEv);
    }

    /**
     * Provides both EXIF and XMP values for fNumber.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForFNumber(): void
    {
        $entries = [
            ExifTag::F_NUMBER => new IfdEntry(ExifTag::F_NUMBER, 5, 1, new ExifRational(28, 10)),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}FNumber' => '56/10',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->settings);
        self::assertSame(2.8, $exposure->settings->fNumber);
    }

    /**
     * Provides both EXIF and XMP values for apertureValue.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForApertureEv(): void
    {
        $entries = [
            ExifTag::APERTURE_VALUE => new IfdEntry(ExifTag::APERTURE_VALUE, 5, 1, new ExifRational(3, 1)),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}ApertureValue' => '5/1',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->settings);
        self::assertSame(3.0, $exposure->settings->apertureEv);
    }

    /**
     * Provides both EXIF and XMP values for exposureBiasValue.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForExposureBiasEv(): void
    {
        $entries = [
            ExifTag::EXPOSURE_BIAS_VALUE => new IfdEntry(ExifTag::EXPOSURE_BIAS_VALUE, 10, 1, new ExifRational(1, 3)),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}ExposureBiasValue' => '2/3',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->settings);
        self::assertEqualsWithDelta(1.0 / 3.0, $exposure->settings->exposureBiasEv, 0.0001);
    }

    /**
     * Provides both EXIF and XMP values for brightnessValue.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForBrightnessEv(): void
    {
        $entries = [
            ExifTag::BRIGHTNESS_VALUE => new IfdEntry(ExifTag::BRIGHTNESS_VALUE, 10, 1, new ExifRational(5, 1)),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}BrightnessValue' => '8/1',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->settings);
        self::assertSame(5.0, $exposure->settings->brightnessEv);
    }

    /**
     * Provides both EXIF and XMP values for contrast.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForContrastEnum(): void
    {
        $entries = [
            ExifTag::CONTRAST => new IfdEntry(ExifTag::CONTRAST, 3, 1, 2),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}Contrast' => '1',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->adjustments);
        self::assertSame(Contrast::Hard, $exposure->adjustments->contrast);
    }

    /**
     * Provides both EXIF and XMP values for saturation.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForSaturationEnum(): void
    {
        $entries = [
            ExifTag::SATURATION => new IfdEntry(ExifTag::SATURATION, 3, 1, 2),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}Saturation' => '1',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->adjustments);
        self::assertSame(Saturation::High, $exposure->adjustments->saturation);
    }

    /**
     * Provides both EXIF and XMP values for sharpness.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForSharpnessEnum(): void
    {
        $entries = [
            ExifTag::SHARPNESS => new IfdEntry(ExifTag::SHARPNESS, 3, 1, 2),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}Sharpness' => '1',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->adjustments);
        self::assertSame(Sharpness::Hard, $exposure->adjustments->sharpness);
    }

    /**
     * Provides both EXIF and XMP values for digitalZoomRatio.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForDigitalZoomRatio(): void
    {
        $entries = [
            ExifTag::DIGITAL_ZOOM_RATIO => new IfdEntry(ExifTag::DIGITAL_ZOOM_RATIO, 5, 1, new ExifRational(2, 1)),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}DigitalZoomRatio' => '4/1',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->adjustments);
        self::assertSame(2.0, $exposure->adjustments->digitalZoomRatio);
    }

    /**
     * Provides both EXIF and XMP values for gainControl.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForGainControlEnum(): void
    {
        $entries = [
            ExifTag::GAIN_CONTROL => new IfdEntry(ExifTag::GAIN_CONTROL, 3, 1, 1),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}GainControl' => '3',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertNotNull($exposure->adjustments);
        self::assertSame(GainControl::LowGainUp, $exposure->adjustments->gainControl);
    }

    /**
     * Provides both EXIF and XMP values for exposureMode.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForExposureModeEnum(): void
    {
        $entries = [
            ExifTag::EXPOSURE_MODE => new IfdEntry(ExifTag::EXPOSURE_MODE, 3, 1, 1),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}ExposureMode' => '2',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertSame(ExposureMode::Manual, $exposure->exposureMode);
    }

    /**
     * Provides both EXIF and XMP values for flashEnergy.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForFlashEnergy(): void
    {
        $entries = [
            ExifTag::FLASH_ENERGY => new IfdEntry(ExifTag::FLASH_ENERGY, 5, 1, new ExifRational(50, 1)),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}FlashEnergy' => '100/1',
        ];

        $exposure = $this->createExposureWithExifAndXmp($entries, $xmpData);

        self::assertSame(50.0, $exposure->flashEnergy);
    }

    /**
     * Provides only XMP values (no EXIF document) for all exposure fields.
     * Asserts XMP fallback values are used when EXIF data is absent.
     */
    #[Test]
    public function xmpFallbackUsedWhenExifDocIsNull(): void
    {
        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}ExposureProgram'    => '4',
            '{http://ns.adobe.com/exif/1.0/}MeteringMode'       => '3',
            '{http://ns.adobe.com/exif/1.0/}WhiteBalance'       => '1',
            '{http://ns.adobe.com/exif/1.0/}ExposureMode'       => '2',
            '{http://ns.adobe.com/exif/1.0/}FlashEnergy'        => '75/1',
            '{http://cipa.jp/exif/1.0/}PhotographicSensitivity' => '400',
            '{http://ns.adobe.com/exif/1.0/}ExposureIndex'      => '200/1',
            '{http://ns.adobe.com/exif/1.0/}ExposureTime'       => '1/500',
            '{http://ns.adobe.com/exif/1.0/}ShutterSpeedValue'  => '9/1',
            '{http://ns.adobe.com/exif/1.0/}FNumber'            => '40/10',
            '{http://ns.adobe.com/exif/1.0/}ApertureValue'      => '4/1',
            '{http://ns.adobe.com/exif/1.0/}ExposureBiasValue'  => '1/2',
            '{http://ns.adobe.com/exif/1.0/}BrightnessValue'    => '6/1',
            '{http://ns.adobe.com/exif/1.0/}Contrast'           => '1',
            '{http://ns.adobe.com/exif/1.0/}Saturation'         => '2',
            '{http://ns.adobe.com/exif/1.0/}Sharpness'          => '1',
            '{http://ns.adobe.com/exif/1.0/}DigitalZoomRatio'   => '3/1',
            '{http://ns.adobe.com/exif/1.0/}GainControl'        => '2',
        ];

        $xmpDoc   = new XmpDocument($xmpData);
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $exposure = (new ExposureFactory())->create($metadata);

        self::assertSame(ExposureProgram::ShutterPriority, $exposure->program);
        self::assertSame(MeteringMode::Spot, $exposure->meteringMode);
        self::assertSame(ExposureMode::AutoBracket, $exposure->exposureMode);
        self::assertSame(75.0, $exposure->flashEnergy);

        self::assertNotNull($exposure->adjustments);
        self::assertSame(WhiteBalance::Manual, $exposure->adjustments->whiteBalance);
        self::assertSame(Contrast::Soft, $exposure->adjustments->contrast);
        self::assertSame(Saturation::High, $exposure->adjustments->saturation);
        self::assertSame(Sharpness::Soft, $exposure->adjustments->sharpness);
        self::assertSame(3.0, $exposure->adjustments->digitalZoomRatio);
        self::assertSame(GainControl::HighGainUp, $exposure->adjustments->gainControl);

        self::assertNotNull($exposure->settings);
        self::assertSame(400, $exposure->settings->iso);
        self::assertSame(200.0, $exposure->settings->exposureIndex);
        self::assertEqualsWithDelta(1.0 / 500.0, $exposure->settings->exposureTimeSec, 0.0001);
        self::assertSame(9.0, $exposure->settings->shutterSpeedEv);
        self::assertSame(4.0, $exposure->settings->fNumber);
        self::assertSame(4.0, $exposure->settings->apertureEv);
        self::assertSame(0.5, $exposure->settings->exposureBiasEv);
        self::assertSame(6.0, $exposure->settings->brightnessEv);
    }

    private function parsedExifWithIsoAndFlash(?int $iso, ?int $flash): ParsedExif
    {
        $entries = [];

        if ($iso !== null) {
            $entries[ExifTag::PHOTOGRAPHIC_SENSITIVITY] = new IfdEntry(
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                3,
                1,
                $iso,
            );
        }

        if ($flash !== null) {
            $entries[ExifTag::FLASH] = new IfdEntry(
                ExifTag::FLASH,
                3,
                1,
                $flash,
            );
        }

        return $this->createParsedExifWithExifEntries($entries);
    }

    /**
     * @param array<int, IfdEntry> $entries
     */
    private function createParsedExifWithExifEntries(array $entries): ParsedExif
    {
        return new ParsedExif(
            ifd0: new Ifd([]),
            exifIfd: new Ifd($entries),
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }

    private function createExposure(?ParsedExif $parsedExif): Exposure
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        return (new ExposureFactory())->create($metadata);
    }

    /**
     * Creates an Exposure from both EXIF IFD entries and an XMP document.
     *
     * @param array<int, IfdEntry>  $entries EXIF IFD entries keyed by tag ID.
     * @param array<string, string> $xmpData XMP data keyed by Clark notation.
     */
    private function createExposureWithExifAndXmp(array $entries, array $xmpData): Exposure
    {
        $parsedExif = $this->createParsedExifWithExifEntries($entries);
        $xmpDoc     = new XmpDocument($xmpData);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
            xmpDoc: $xmpDoc,
        );

        return (new ExposureFactory())->create($metadata);
    }
}
