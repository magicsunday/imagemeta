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
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\ExposureParameterReader;
use MagicSunday\ImageMeta\Exif\Reader\IsoSensitivityReader;
use MagicSunday\ImageMeta\Exif\Reader\SceneModeReader;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\Structured\ExposureFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Riff\RiffInfoLookup;
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
#[UsesClass(FallbackIfdSet::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ExposureParameterReader::class)]
#[UsesClass(IsoSensitivityReader::class)]
#[UsesClass(SceneModeReader::class)]
#[UsesClass(XmpFallbackResolver::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(RiffInfoLookup::class)]
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
}
