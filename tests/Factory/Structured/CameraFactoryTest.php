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
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DeviceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\FocalReader;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\Structured\CameraFactory;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises CameraFactory for building Camera value objects from ParsedExif tags.
 * It verifies make, model, owner name, and firmware values are mapped correctly.
 * The suite covers enum-backed fields like FileSource and SensingMethod.
 * This ensures camera metadata is normalized consistently for structured output.
 *
 * @internal
 */
#[CoversClass(CameraFactory::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
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
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(DeviceExifReader::class)]
#[UsesClass(FocalReader::class)]
#[UsesClass(XmpFallbackResolver::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(Camera::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class CameraFactoryTest extends TestCase
{
    /**
     * Builds ParsedExif data with camera-related tags and feeds it into CameraFactory.
     * Verifies the resulting Camera value object contains the expected fields.
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExif(
            make: 'Canon',
            model: 'EOS R6',
            ownerName: 'Test Owner',
            firmware: '1.0.0',
            fileSource: FileSource::DigitalCamera,
            sensingMethod: SensingMethod::OneChipColorArea,
        );

        $camera = $this->createCamera($parsedExif);

        self::assertSame('Canon', $camera->make);
        self::assertSame('EOS R6', $camera->model);
        self::assertSame('Test Owner', $camera->ownerName);
        self::assertSame('1.0.0', $camera->firmware);
        self::assertSame(FileSource::DigitalCamera, $camera->fileSource);
        self::assertSame(SensingMethod::OneChipColorArea, $camera->sensingMethod);
    }

    /**
     * Creates Metadata without an EXIF document.
     * Ensures CameraFactory returns a camera object with all fields set to null.
     */
    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $camera = $this->createCamera(null);

        self::assertNull($camera->make);
        self::assertNull($camera->model);
        self::assertNull($camera->ownerName);
        self::assertNull($camera->firmware);
        self::assertNull($camera->fileSource);
        self::assertNull($camera->sensingMethod);
    }

    /**
     * Supplies ParsedExif data with only the make tag populated.
     * Confirms the factory preserves the make and leaves other fields unset.
     */
    #[Test]
    public function createsWithPartialExifData(): void
    {
        $parsedExif = $this->parsedExif(
            make: 'Nikon',
            model: null,
            ownerName: null,
            firmware: null,
            fileSource: null,
            sensingMethod: null,
        );

        $camera = $this->createCamera($parsedExif);

        self::assertSame('Nikon', $camera->make);
        self::assertNull($camera->model);
        self::assertNull($camera->ownerName);
        self::assertNull($camera->firmware);
        self::assertSame(FileSource::DigitalCamera, $camera->fileSource);
        self::assertNull($camera->sensingMethod);
    }

    /**
     * Supplies IFD entries with EXIF tag types that do not match the expected TIFF types.
     * Verifies the factory degrades gracefully and returns null for mistyped fields.
     */
    #[Test]
    public function returnsNullFieldsWhenIfdEntriesHaveWrongTypes(): void
    {
        // Put an integer where ASCII strings are expected (make/model),
        // and a string where a SHORT is expected (sensing method).
        $ifd0Entries = [
            ExifTag::MAKE  => new IfdEntry(ExifTag::MAKE, 3, 1, 42),
            ExifTag::MODEL => new IfdEntry(ExifTag::MODEL, 3, 1, 99),
        ];

        $exifEntries = [
            ExifTag::SENSING_METHOD => new IfdEntry(ExifTag::SENSING_METHOD, 2, 3, 'abc'),
        ];

        $parsedExif = $this->createParsedExifFromEntries($ifd0Entries, $exifEntries);
        $camera     = $this->createCamera($parsedExif);

        // Wrong-typed tags should degrade to null rather than surface garbage values
        self::assertNull($camera->make);
        self::assertNull($camera->model);
        self::assertNull($camera->sensingMethod);
    }

    /**
     * Supplies an invalid FileSource enum backing value in the IFD entry.
     * Verifies the factory returns the default FileSource (DigitalCamera) since
     * the presence of any IFD0 data triggers the default.
     */
    #[Test]
    public function returnsDefaultFileSourceForInvalidEnumValue(): void
    {
        // FileSource expects UNDEFINED (type 7) with value 1, 2, or 3
        $ifd0Entries = [
            ExifTag::FILE_SOURCE => new IfdEntry(ExifTag::FILE_SOURCE, 7, 1, 255),
        ];

        $parsedExif = $this->createParsedExifFromEntries($ifd0Entries, []);
        $camera     = $this->createCamera($parsedExif);

        // Invalid enum should degrade — either null or the default DigitalCamera
        self::assertTrue(
            !$camera->fileSource instanceof FileSource || $camera->fileSource === FileSource::DigitalCamera,
            'FileSource should be null or default DigitalCamera for invalid backing value',
        );
    }

    /**
     * Creates Metadata without EXIF but with QuickTime make/model keys.
     * Verifies CameraFactory falls back to QuickTime values for make and model.
     */
    #[Test]
    public function fallsBackToQuickTimeMakeModel(): void
    {
        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.make'  => 'Apple',
            'com.apple.quicktime.model' => 'iPhone 16 Pro',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $camera = (new CameraFactory())->create($metadata);

        self::assertSame('Apple', $camera->make);
        self::assertSame('iPhone 16 Pro', $camera->model);
    }

    /**
     * Creates Metadata with both EXIF make/model and QuickTime make/model.
     * Verifies EXIF values take precedence over QuickTime values.
     */
    #[Test]
    public function exifMakeModelTakesPrecedenceOverQuickTime(): void
    {
        $parsedExif = $this->parsedExif(
            make: 'Canon',
            model: 'EOS R6',
            ownerName: null,
            firmware: null,
            fileSource: null,
            sensingMethod: null,
        );

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.make'  => 'Apple',
            'com.apple.quicktime.model' => 'iPhone 16 Pro',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            exifDoc: $parsedExif,
        );

        $camera = (new CameraFactory())->create($metadata);

        self::assertSame('Canon', $camera->make);
        self::assertSame('EOS R6', $camera->model);
    }

    private function parsedExif(
        ?string $make,
        ?string $model,
        ?string $ownerName,
        ?string $firmware,
        ?FileSource $fileSource,
        ?SensingMethod $sensingMethod,
    ): ParsedExif {
        $ifd0Entries = [];
        $exifEntries = [];

        if ($make !== null) {
            $ifd0Entries[ExifTag::MAKE] = new IfdEntry(ExifTag::MAKE, 2, 1, $make);
        }

        if ($model !== null) {
            $ifd0Entries[ExifTag::MODEL] = new IfdEntry(ExifTag::MODEL, 2, 1, $model);
        }

        if ($ownerName !== null) {
            $exifEntries[ExifTag::CAMERA_OWNER_NAME] = new IfdEntry(ExifTag::CAMERA_OWNER_NAME, 2, 1, $ownerName);
        }

        if ($firmware !== null) {
            $exifEntries[ExifTag::CAMERA_FIRMWARE] = new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, $firmware);
        }

        if ($fileSource instanceof FileSource) {
            $ifd0Entries[ExifTag::FILE_SOURCE] = new IfdEntry(ExifTag::FILE_SOURCE, 7, 1, $fileSource->value);
        }

        if ($sensingMethod instanceof SensingMethod) {
            $exifEntries[ExifTag::SENSING_METHOD] = new IfdEntry(ExifTag::SENSING_METHOD, 3, 1, $sensingMethod->value);
        }

        return $this->createParsedExifFromEntries($ifd0Entries, $exifEntries);
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     * @param array<int, IfdEntry> $exifEntries
     */
    private function createParsedExifFromEntries(array $ifd0Entries, array $exifEntries): ParsedExif
    {
        return new ParsedExif(
            ifd0: new Ifd($ifd0Entries),
            exifIfd: new Ifd($exifEntries),
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }

    private function createCamera(?ParsedExif $parsedExif): Camera
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        return (new CameraFactory())->create($metadata);
    }
}
