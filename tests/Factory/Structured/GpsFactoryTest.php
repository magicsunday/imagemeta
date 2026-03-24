<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Structured;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Core\Util\DateTimeUtil;
use MagicSunday\ImageMeta\Core\Util\Iso6709Parser;
use MagicSunday\ImageMeta\Core\Util\StringUtil;
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
use MagicSunday\ImageMeta\Exif\Converters\ValidatesGpsRef;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\GpsExifReader;
use MagicSunday\ImageMeta\Exif\Text\UndefinedTextMarker;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\Structured\GpsFactory;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Riff\RiffInfoLookup;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDifferential;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use MagicSunday\ImageMeta\Value\Enum\GpsMeasureMode;
use MagicSunday\ImageMeta\Value\Enum\GpsSpeedRef;
use MagicSunday\ImageMeta\Value\Enum\GpsStatus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\GpsCoordinate;
use MagicSunday\ImageMeta\Value\GpsDestination;
use MagicSunday\ImageMeta\Value\GpsMeasurement;
use MagicSunday\ImageMeta\Value\GpsMovement;
use MagicSunday\ImageMeta\Value\GpsPosition;
use MagicSunday\ImageMeta\Value\GpsTiming;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function sprintf;
use function strlen;

/**
 * Exercises GpsFactory for mapping EXIF GPS tags into the Gps value object.
 * It verifies coordinates, altitude, speed, and timestamp fields are derived correctly.
 * The suite covers enum conversions for references, status, and measurement modes.
 * This ensures GPS metadata is normalized consistently from EXIF inputs.
 *
 * @internal
 */
#[CoversClass(GpsFactory::class)]
#[UsesClass(Iso6709Parser::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(DateTimeUtil::class)]
#[UsesClass(StringUtil::class)]
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
#[UsesTrait(ValidatesGpsRef::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(GpsExifReader::class)]
#[UsesClass(UndefinedTextMarker::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(RiffInfoLookup::class)]
#[UsesClass(GpsAltitudeRef::class)]
#[UsesClass(Gps::class)]
#[UsesClass(GpsCoordinate::class)]
#[UsesClass(GpsDestination::class)]
#[UsesClass(GpsMeasurement::class)]
#[UsesClass(GpsMovement::class)]
#[UsesClass(GpsPosition::class)]
#[UsesClass(GpsTiming::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class GpsFactoryTest extends TestCase
{
    /**
     * Supplies ParsedExif GPS tags covering coordinates, altitude, speed, and timestamp.
     * Verifies GpsFactory maps fields correctly and computes derived values like speed in m/s.
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: GpsLatLonRef::North,
            lat: 52.520008,
            lonRef: GpsLatLonRef::East,
            lon: 13.404954,
            altitudeRef: GpsAltitudeRef::AboveSeaLevel,
            altitude: 35.0,
            version: '2.4.0.0',
            satellites: '10',
            status: GpsStatus::MeasurementInProgress,
            measureMode: GpsMeasureMode::ThreeDimensional,
            dop: 1.5,
            speedRef: GpsSpeedRef::KilometersPerHour,
            speedMs: 5.0,
            track: 90.0,
            mapDatum: 'WGS-84',
            processingMethod: "ASCII\0\0\0GPS",
            areaInformation: "ASCII\0\0\0Berlin",
            date: '2023-06-15',
            time: '14:30:00',
            differential: 0,
            hPositioningError: 3.0,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertNotNull($gps->measurement);
        self::assertNotNull($gps->movement);
        self::assertNotNull($gps->timing);

        self::assertSame(52.520008, $gps->position->latitude);
        self::assertSame(13.404954, $gps->position->longitude);
        self::assertSame(GpsLatLonRef::North, $gps->position->latitudeRef);
        self::assertSame(GpsLatLonRef::East, $gps->position->longitudeRef);
        self::assertSame(35.0, $gps->position->altitude);
        self::assertSame(GpsAltitudeRef::AboveSeaLevel, $gps->position->altitudeRef);
        self::assertSame('2.4.0.0', $gps->version);
        self::assertSame('10', $gps->measurement->satellites);
        self::assertSame(GpsStatus::MeasurementInProgress, $gps->measurement->status);
        self::assertSame(GpsMeasureMode::ThreeDimensional, $gps->measurement->measureMode);
        self::assertSame(1.5, $gps->measurement->dop);
        self::assertSame(GpsSpeedRef::KilometersPerHour, $gps->movement->speedRef);
        self::assertSame(5.0 / 3.6, $gps->movement->speedMs);
        self::assertSame(90.0, $gps->movement->track);
        self::assertSame('WGS-84', $gps->position->mapDatum);
        self::assertSame('GPS', $gps->processingMethod);
        self::assertSame('Berlin', $gps->areaInformation);
        self::assertSame('2023-06-15', $gps->timing->date);
        self::assertSame('14:30:00', $gps->timing->time);
        self::assertInstanceOf(DateTimeImmutable::class, $gps->timing->timestamp);
        self::assertSame(GpsDifferential::NoCorrection, $gps->measurement->differential);
        self::assertSame(3.0, $gps->measurement->horizontalPositioningError);
    }

    /**
     * Provides EXIF GPS timing and measurement fields without coordinates,
     * alongside a QuickTime ISO 6709 position.
     * Verifies the factory merges the QuickTime position into the existing
     * EXIF GPS data rather than replacing it.
     */
    #[Test]
    public function mergesQuickTimePositionWithExifGpsFields(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: '2.4.0.0',
            satellites: '08',
            status: GpsStatus::MeasurementInProgress,
            measureMode: GpsMeasureMode::ThreeDimensional,
            dop: 2.5,
            speedRef: null,
            speedMs: null,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: '2023-06-15',
            time: '14:30:00',
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: new QuickTimeMeta([
                'com.apple.quicktime.location.ISO6709' => '+48.1372+011.5755+519/',
            ]),
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        // QuickTime position must be present
        self::assertNotNull($gps->position);
        self::assertEqualsWithDelta(48.1372, $gps->position->latitude, 0.001);
        self::assertEqualsWithDelta(11.5755, $gps->position->longitude, 0.001);

        // EXIF timing and measurement must be preserved (not discarded)
        self::assertSame('2.4.0.0', $gps->version);
        self::assertNotNull($gps->timing);
        self::assertSame('2023-06-15', $gps->timing->date);
        self::assertSame('14:30:00', $gps->timing->time);
        self::assertNotNull($gps->measurement);
        self::assertSame('08', $gps->measurement->satellites);
        self::assertSame(GpsMeasureMode::ThreeDimensional, $gps->measurement->measureMode);
        self::assertSame(2.5, $gps->measurement->dop);
    }

    /**
     * Builds Metadata without an EXIF document.
     * Ensures the GPS value object contains null coordinates when no data is available.
     */
    #[Test]
    public function createsEmptyGpsWithNullExifDoc(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * Provides a GPS speed in kilometers per hour.
     * Confirms the factory converts the speed to metres per second.
     */
    #[Test]
    public function convertsSpeedToMetresPerSecond(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: GpsSpeedRef::KilometersPerHour,
            speedMs: 36.0,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: null,
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->movement);

        self::assertSame(GpsSpeedRef::KilometersPerHour, $gps->movement->speedRef);
        self::assertSame(36.0 / 3.6, $gps->movement->speedMs);
    }

    /**
     * Provides a GPS speed in miles per hour.
     * Confirms the factory converts the speed to metres per second.
     */
    #[Test]
    public function convertsMphSpeedToMetresPerSecond(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: GpsSpeedRef::MilesPerHour,
            speedMs: 60.0,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: null,
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->movement);

        self::assertSame(GpsSpeedRef::MilesPerHour, $gps->movement->speedRef);
        self::assertSame(60.0 * 0.44704, $gps->movement->speedMs);
    }

    /**
     * Provides a GPS speed in knots.
     * Confirms the factory converts the speed to metres per second.
     */
    #[Test]
    public function convertsKnotsSpeedToMetresPerSecond(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: GpsSpeedRef::Knots,
            speedMs: 20.0,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: null,
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->movement);

        self::assertSame(GpsSpeedRef::Knots, $gps->movement->speedRef);
        self::assertSame(20.0 * 0.5144444444444444, $gps->movement->speedMs);
    }

    /**
     * Verifies the parsedExif helper actually uses the $time parameter.
     * The previous implementation hardcoded 14:30:00 regardless of $time.
     */
    #[Test]
    public function parsesTimeStampFromExifParameter(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: null,
            speedMs: null,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: '2023-06-15',
            time: '09:15:45',
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->timing);

        self::assertSame('09:15:45', $gps->timing->time);
    }

    /**
     * Uses the EXIF GPS date format with colon separators.
     * Ensures the factory normalizes the date to ISO-style YYYY-MM-DD.
     */
    #[Test]
    public function normalizesDateFormat(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: null,
            speedMs: null,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: '2023:06:15',
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->timing);

        self::assertSame('2023-06-15', $gps->timing->date);
    }

    /**
     * Supplies a GPS date with no time component.
     * Confirms the factory populates the date field but leaves time and timestamp null.
     */
    #[Test]
    public function createsTimingWithDateOnlyWhenTimeIsAbsent(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: null,
            speedMs: null,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: '2023-06-15',
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->timing);

        self::assertSame('2023-06-15', $gps->timing->date);
        self::assertNull($gps->timing->time);
        self::assertNull($gps->timing->timestamp);
    }

    /**
     * XMP altitude with ref 3 (below sea level) yields negative altitude.
     */
    #[Test]
    #[DataProvider('provideXmpNegativeAltitudeRefs')]
    public function xmpAltitudeWithBelowRefYieldsNegative(int $ref): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSAltitude', self::NS_EXIF)    => '100.0',
            sprintf('{%s}GPSAltitudeRef', self::NS_EXIF) => (string) $ref,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);

        self::assertSame(-100.0, $gps->position->altitude);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideXmpNegativeAltitudeRefs(): iterable
    {
        yield 'ref 1 (below sea level)' => [1];
        yield 'ref 3 (below ellipsoidal surface)' => [3];
    }

    /**
     * XMP altitude with ref 0/2 (above) yields positive altitude.
     */
    #[Test]
    #[DataProvider('provideXmpPositiveAltitudeRefs')]
    public function xmpAltitudeWithAboveRefYieldsPositive(int $ref): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSAltitude', self::NS_EXIF)    => '100.0',
            sprintf('{%s}GPSAltitudeRef', self::NS_EXIF) => (string) $ref,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);

        self::assertSame(100.0, $gps->position->altitude);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideXmpPositiveAltitudeRefs(): iterable
    {
        yield 'ref 0 (above sea level)' => [0];
        yield 'ref 2 (above ellipsoidal surface)' => [2];
    }

    /**
     * XMP speed with invalid ref does not produce a converted m/s value.
     */
    #[Test]
    public function xmpSpeedWithInvalidRefYieldsNullSpeed(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSSpeed', self::NS_EXIF)    => '50.0',
            sprintf('{%s}GPSSpeedRef', self::NS_EXIF) => 'X',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->movement);
        self::assertNull($gps->movement->speedMs);
    }

    /**
     * XMP speed with valid ref K yields correct m/s conversion.
     */
    #[Test]
    public function xmpSpeedWithKilometresRefYieldsMetresPerSecond(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSSpeed', self::NS_EXIF)    => '36.0',
            sprintf('{%s}GPSSpeedRef', self::NS_EXIF) => 'K',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->movement);

        self::assertSame(36.0 / 3.6, $gps->movement->speedMs);
    }

    /**
     * XMP coordinate with missing ref does not produce a parsed value.
     */
    #[Test]
    public function xmpCoordinateWithMissingRefYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF) => '52,31,15',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * XMP coordinate with invalid ref does not produce a parsed value.
     */
    #[Test]
    public function xmpCoordinateWithInvalidRefYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,31,15',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'X',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * XMP coordinate with valid ref parses correctly.
     */
    #[Test]
    public function xmpCoordinateWithValidRefParsesCorrectly(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,31,15',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);

        self::assertEqualsWithDelta(52.520833, $gps->position->latitude, 0.001);
    }

    /**
     * XMP DMS coordinate with minutes >= 60 yields null.
     */
    #[Test]
    public function xmpDmsWithMinutesOutOfRangeYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,61,15',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * XMP DMS coordinate with seconds >= 60 yields null.
     */
    #[Test]
    public function xmpDmsWithSecondsOutOfRangeYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,31,60',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * XMP negative decimal magnitude with valid ref yields null.
     */
    #[Test]
    public function xmpNegativeDecimalMagnitudeYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '-52.5',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'S',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * XMP negative DMS degree component yields null.
     */
    #[Test]
    public function xmpNegativeDmsDegreeComponentYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '-10,30,0',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * XMP coordinate with 2 tokens yields null.
     */
    #[Test]
    public function xmpCoordinateWithTwoTokensYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52 31',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * XMP coordinate with 4 tokens yields null.
     */
    #[Test]
    public function xmpCoordinateWithFourTokensYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '12 34 56 78',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * XMP latitude above 90 yields null.
     */
    #[Test]
    public function xmpLatitudeAboveNinetyYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '91.0',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * XMP longitude above 180 yields null.
     */
    #[Test]
    public function xmpLongitudeAboveOneEightyYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLongitude', self::NS_EXIF)    => '181.0',
            sprintf('{%s}GPSLongitudeRef', self::NS_EXIF) => 'E',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * XMP GPSDateTime with valid ISO 8601 value parses to UTC.
     */
    #[Test]
    public function xmpGpsDateTimeWithValidIsoParses(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDateTime', self::NS_EXIF) => '2023-06-15T14:30:00Z',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->timing);

        self::assertInstanceOf(DateTimeImmutable::class, $gps->timing->timestamp);
        self::assertSame('2023-06-15T14:30:00+00:00', $gps->timing->timestamp->format('Y-m-d\TH:i:sP'));
    }

    /**
     * XMP GPSDateTime with non-ISO free-form string yields null.
     */
    #[Test]
    public function xmpGpsDateTimeWithFreeFormStringYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDateTime', self::NS_EXIF) => 'June 15, 2023 2:30 PM',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->timing);
    }

    /**
     * XMP GPSDateTime without timezone is interpreted as UTC, not local time.
     */
    #[Test]
    public function xmpGpsDateTimeWithoutTimezoneIsTreatedAsUtc(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $xmpDoc = new XmpDocument([
                sprintf('{%s}GPSDateTime', self::NS_EXIF) => '2023-06-15T14:30:00',
            ]);

            $metadata = new Metadata(
                exifBlobs: [],
                quickTime: null,
                xmpDoc: $xmpDoc,
            );

            $factory = new GpsFactory();
            $gps     = $factory->create($metadata);

            self::assertNotNull($gps->timing);

            self::assertInstanceOf(DateTimeImmutable::class, $gps->timing->timestamp);
            self::assertSame('2023-06-15T14:30:00+00:00', $gps->timing->timestamp->format('Y-m-d\TH:i:sP'));
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    /**
     * XMP GPS date in non-conformant format yields null date.
     */
    #[Test]
    public function xmpNonConformantDateYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDateStamp', self::NS_EXIF) => 'June 15, 2023',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->timing);
    }

    /**
     * XMP GPS date in EXIF colon format is normalized.
     */
    #[Test]
    public function xmpConformantDateIsNormalized(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDateStamp', self::NS_EXIF) => '2023:06:15',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->timing);

        self::assertSame('2023-06-15', $gps->timing->date);
    }

    /**
     * XMP destination latitude is surfaced when EXIF value is missing.
     */
    #[Test]
    public function xmpDestinationLatitudeIsMapped(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDestLatitude', self::NS_EXIF)    => '48,51,24',
            sprintf('{%s}GPSDestLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->destination);

        self::assertEqualsWithDelta(48.856667, $gps->destination->latitude, 0.001);
        self::assertSame(GpsLatLonRef::North, $gps->destination->latitudeRef);
    }

    /**
     * XMP destination bearing is surfaced when EXIF value is missing.
     */
    #[Test]
    public function xmpDestinationBearingIsMapped(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDestBearing', self::NS_EXIF)    => '120.5',
            sprintf('{%s}GPSDestBearingRef', self::NS_EXIF) => 'T',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->destination);

        self::assertSame(120.5, $gps->destination->bearing);
    }

    /**
     * XMP navigation context fields are surfaced when EXIF values are missing.
     */
    #[Test]
    public function xmpNavigationContextFieldsAreMapped(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSStatus', self::NS_EXIF)          => 'A',
            sprintf('{%s}GPSMeasureMode', self::NS_EXIF)     => '3',
            sprintf('{%s}GPSDOP', self::NS_EXIF)             => '1.5',
            sprintf('{%s}GPSTrack', self::NS_EXIF)           => '90.0',
            sprintf('{%s}GPSTrackRef', self::NS_EXIF)        => 'T',
            sprintf('{%s}GPSImgDirection', self::NS_EXIF)    => '180.0',
            sprintf('{%s}GPSImgDirectionRef', self::NS_EXIF) => 'M',
            sprintf('{%s}GPSMapDatum', self::NS_EXIF)        => 'WGS-84',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->measurement);
        self::assertNotNull($gps->movement);
        self::assertNotNull($gps->position);

        self::assertSame(GpsStatus::MeasurementInProgress, $gps->measurement->status);
        self::assertSame(GpsMeasureMode::ThreeDimensional, $gps->measurement->measureMode);
        self::assertSame(1.5, $gps->measurement->dop);
        self::assertSame(90.0, $gps->movement->track);
        self::assertSame(180.0, $gps->movement->imageDirection);
        self::assertSame('WGS-84', $gps->position->mapDatum);
    }

    /**
     * Ensures the resolveGps orchestration is split into dedicated fallback helpers.
     *
     * This refactor guard keeps coordinate, movement/speed, destination, and
     * data-presence flow in separate methods for a flatter resolveGps() path.
     */
    #[Test]
    public function resolveGpsFlowIsSplitIntoFallbackHelpers(): void
    {
        $reflection = new ReflectionClass(GpsFactory::class);

        self::assertTrue($reflection->hasMethod('applyCoordinateFallbacks'));
        self::assertTrue($reflection->hasMethod('applyMovementFallbacks'));
        self::assertTrue($reflection->hasMethod('applyDestinationFallbacks'));
        self::assertTrue($reflection->hasMethod('hasAnyGpsData'));
    }

    /**
     * Verifies that ISO 6709 location from QuickTime metadata is used when no EXIF GPS is present.
     */
    #[Test]
    public function fallsBackToQuickTimeGpsFromXyz(): void
    {
        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.location.ISO6709' => '+48.1234+011.5678+500.000/',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertEqualsWithDelta(48.1234, $gps->position->latitude, 0.0001);
        self::assertSame(GpsLatLonRef::North, $gps->position->latitudeRef);
        self::assertEqualsWithDelta(11.5678, $gps->position->longitude, 0.0001);
        self::assertSame(GpsLatLonRef::East, $gps->position->longitudeRef);
        self::assertEqualsWithDelta(500.0, $gps->position->altitude, 0.01);
        self::assertSame(GpsAltitudeRef::AboveEllipsoidalSurface, $gps->position->altitudeRef);
    }

    /**
     * Verifies that separate numeric latitude/longitude from QuickTime metadata is used
     * when no EXIF GPS or ISO 6709 string is present.
     */
    #[Test]
    public function fallsBackToQuickTimeDjiNumericGps(): void
    {
        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.location.latitude'  => 48.1234,
            'com.apple.quicktime.location.longitude' => 11.5678,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertEqualsWithDelta(48.1234, $gps->position->latitude, 0.0001);
        self::assertSame(GpsLatLonRef::North, $gps->position->latitudeRef);
        self::assertEqualsWithDelta(11.5678, $gps->position->longitude, 0.0001);
        self::assertSame(GpsLatLonRef::East, $gps->position->longitudeRef);
    }

    /**
     * Verifies that EXIF GPS takes precedence over QuickTime location metadata.
     */
    #[Test]
    public function exifGpsTakesPrecedenceOverQuickTime(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: GpsLatLonRef::North,
            lat: 52.520008,
            lonRef: GpsLatLonRef::East,
            lon: 13.404954,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: null,
            speedMs: null,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: null,
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.location.ISO6709' => '+48.1234+011.5678+500.000/',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(52.520008, $gps->position->latitude);
        self::assertSame(GpsLatLonRef::North, $gps->position->latitudeRef);
        self::assertSame(13.404954, $gps->position->longitude);
        self::assertSame(GpsLatLonRef::East, $gps->position->longitudeRef);
    }

    /**
     * Verifies that XMP coordinate with South ref produces negative latitude.
     * Kills coordinateSign MatchArmRemoval mutant that removes 'S' from the 'S','W' arm.
     */
    #[Test]
    public function xmpCoordinateWithSouthRefProducesNegativeLatitude(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52.5',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'S',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(-52.5, $gps->position->latitude);
        self::assertSame(GpsLatLonRef::South, $gps->position->latitudeRef);
    }

    /**
     * Verifies that XMP coordinate with West ref produces negative longitude.
     * Kills coordinateSign MatchArmRemoval mutant that removes 'W' from the 'S','W' arm.
     */
    #[Test]
    public function xmpCoordinateWithWestRefProducesNegativeLongitude(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLongitude', self::NS_EXIF)    => '13.4',
            sprintf('{%s}GPSLongitudeRef', self::NS_EXIF) => 'W',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(-13.4, $gps->position->longitude);
        self::assertSame(GpsLatLonRef::West, $gps->position->longitudeRef);
    }

    /**
     * Verifies that XMP coordinate with East ref produces positive longitude.
     * Kills coordinateSign MatchArmRemoval mutant that removes 'E' from the 'N','E' arm.
     */
    #[Test]
    public function xmpCoordinateWithEastRefProducesPositiveLongitude(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLongitude', self::NS_EXIF)    => '13.4',
            sprintf('{%s}GPSLongitudeRef', self::NS_EXIF) => 'E',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(13.4, $gps->position->longitude);
        self::assertSame(GpsLatLonRef::East, $gps->position->longitudeRef);
    }

    /**
     * Verifies XMP DMS coordinate where all components are zero is valid.
     * Kills LessThan mutants that change ($deg < 0.0) to ($deg <= 0.0).
     */
    #[Test]
    public function xmpDmsWithZeroDegreeComponentIsValid(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '0,30,15',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertEqualsWithDelta(0.504167, $gps->position->latitude, 0.001);
    }

    /**
     * Verifies XMP DMS coordinate where minutes are zero is valid.
     * Kills LessThan mutant that changes ($min < 0.0) to ($min <= 0.0).
     */
    #[Test]
    public function xmpDmsWithZeroMinutesComponentIsValid(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,0,30',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertEqualsWithDelta(52.008333, $gps->position->latitude, 0.001);
    }

    /**
     * Verifies XMP DMS coordinate where seconds are zero is valid.
     * Kills LessThan mutant that changes ($sec < 0.0) to ($sec <= 0.0).
     */
    #[Test]
    public function xmpDmsWithZeroSecondsComponentIsValid(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,31,0',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertEqualsWithDelta(52.516667, $gps->position->latitude, 0.001);
    }

    /**
     * Verifies XMP DMS coordinate with minutes exactly 59.99 is valid.
     * Kills GreaterThanOrEqualTo mutant that changes ($min >= 60.0) to ($min > 60.0).
     */
    #[Test]
    public function xmpDmsWithMinutesJustBelow60IsValid(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,59.99,0',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertEqualsWithDelta(52.99983, $gps->position->latitude, 0.001);
    }

    /**
     * Verifies XMP decimal coordinate produces value rounded to 6 decimal places.
     * Kills RoundingFamily, IncrementInteger, DecrementInteger mutants on round($coordinate, 6).
     */
    #[Test]
    public function xmpDecimalCoordinateIsRoundedToSixDecimalPlaces(): void
    {
        // 52.1234567 should be rounded to 52.123457
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52.12345674',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(52.123457, $gps->position->latitude);
    }

    /**
     * Verifies XMP DMS coordinate produces value rounded to 6 decimal places.
     * Kills RoundingFamily, IncrementInteger, DecrementInteger mutants on DMS round().
     */
    #[Test]
    public function xmpDmsCoordinateIsRoundedToSixDecimalPlaces(): void
    {
        // 52 deg, 7 min, 24.1234 sec = 52 + 7/60 + 24.1234/3600 = 52.123368
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,7,24.1234',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        // 52 + 7/60 + 24.1234/3600 = 52.1233676... rounded to 6 places
        self::assertSame(round(52.0 + (7.0 / 60.0) + (24.1234 / 3600.0), 6), $gps->position->latitude);
    }

    /**
     * Verifies XMP decimal coordinate of exactly zero yields zero, not null.
     * Kills LessThan mutant that changes ($numeric < 0.0) to ($numeric <= 0.0).
     */
    #[Test]
    public function xmpDecimalCoordinateOfZeroYieldsZero(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '0.0',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(0.0, $gps->position->latitude);
    }

    /**
     * Verifies XMP decimal value applies the sign from coordinateSign(ref).
     * Kills Multiplication mutant that changes ($numeric * $sign) to ($numeric / $sign).
     */
    #[Test]
    public function xmpDecimalCoordinateAppliesCorrectSign(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '45.0',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'S',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        // S => sign = -1.0 => 45.0 * -1.0 = -45.0
        self::assertSame(-45.0, $gps->position->latitude);
    }

    /**
     * Verifies latitude exactly at +90 is valid.
     * Kills GreaterThan mutant that changes ($coordinate > $limit) to ($coordinate >= $limit).
     */
    #[Test]
    public function xmpLatitudeExactlyAt90IsValid(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '90.0',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(90.0, $gps->position->latitude);
    }

    /**
     * Verifies latitude exactly at -90 (S) is valid.
     * Kills LessThan mutant that changes ($coordinate < -$limit) to ($coordinate <= -$limit).
     */
    #[Test]
    public function xmpLatitudeExactlyAtMinus90IsValid(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '90.0',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'S',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(-90.0, $gps->position->latitude);
    }

    /**
     * Verifies longitude exactly at +180 (E) is valid.
     * Kills GreaterThan and validateCoordinateRange mutants.
     */
    #[Test]
    public function xmpLongitudeExactlyAt180IsValid(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLongitude', self::NS_EXIF)    => '180.0',
            sprintf('{%s}GPSLongitudeRef', self::NS_EXIF) => 'E',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(180.0, $gps->position->longitude);
    }

    /**
     * Verifies longitude exactly at -180 (W) is valid.
     * Kills LessThan mutant on lower-bound check.
     */
    #[Test]
    public function xmpLongitudeExactlyAtMinus180IsValid(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLongitude', self::NS_EXIF)    => '180.0',
            sprintf('{%s}GPSLongitudeRef', self::NS_EXIF) => 'W',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(-180.0, $gps->position->longitude);
    }

    /**
     * Verifies validateCoordinateRange uses 90 limit for latitude and 180 for longitude.
     * Kills LogicalOrAllSubExprNegation mutant on isLatitude check and Identical mutant on 'S'.
     */
    #[Test]
    public function xmpLongitudeAt100IsValidButLatitudeAt100IsNot(): void
    {
        // Longitude 100 with E ref should be valid (limit 180)
        $xmpDocLon = new XmpDocument([
            sprintf('{%s}GPSLongitude', self::NS_EXIF)    => '100.0',
            sprintf('{%s}GPSLongitudeRef', self::NS_EXIF) => 'E',
        ]);

        $metadataLon = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDocLon,
        );

        $factory = new GpsFactory();
        $gpsLon  = $factory->create($metadataLon);

        self::assertNotNull($gpsLon->position);
        self::assertSame(100.0, $gpsLon->position->longitude);

        // Latitude 100 with N ref should be invalid (limit 90)
        $xmpDocLat = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '100.0',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadataLat = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDocLat,
        );

        $gpsLat = $factory->create($metadataLat);

        self::assertNull($gpsLat->position);
    }

    /**
     * Verifies negative DMS minute component yields null.
     * Kills LogicalOr mutant that changes || to && in negative component check.
     */
    #[Test]
    public function xmpNegativeDmsMinuteComponentYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,-1,15',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * Verifies negative DMS second component yields null.
     * Kills LogicalOr mutant that changes || to && in negative component check.
     */
    #[Test]
    public function xmpNegativeDmsSecondComponentYieldsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSLatitude', self::NS_EXIF)    => '52,31,-1',
            sprintf('{%s}GPSLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->position);
    }

    /**
     * Verifies XMP speed with miles per hour ref 'M' yields correct m/s conversion.
     * Kills MatchArmRemoval mutant that removes 'M' arm from convertSpeedToMetresPerSecond.
     */
    #[Test]
    public function xmpSpeedWithMilesRefYieldsMetresPerSecond(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSSpeed', self::NS_EXIF)    => '60.0',
            sprintf('{%s}GPSSpeedRef', self::NS_EXIF) => 'M',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->movement);
        self::assertSame(60.0 * 0.44704, $gps->movement->speedMs);
    }

    /**
     * Verifies XMP speed with knots ref 'N' yields correct m/s conversion.
     * Kills MatchArmRemoval mutant that removes 'N' arm from convertSpeedToMetresPerSecond.
     */
    #[Test]
    public function xmpSpeedWithKnotsRefYieldsMetresPerSecond(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSSpeed', self::NS_EXIF)    => '20.0',
            sprintf('{%s}GPSSpeedRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->movement);
        self::assertSame(20.0 * 0.5144444444444444, $gps->movement->speedMs);
    }

    /**
     * Verifies that EXIF-only satellites field makes hasAnyGpsData() return true.
     * Kills ArrayItemRemoval mutant on satellites in measurement group.
     */
    #[Test]
    public function satellitesOnlyExifDataIsNotNull(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: '08',
            status: null,
            measureMode: null,
            dop: null,
            speedRef: null,
            speedMs: null,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: null,
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->measurement);
        self::assertSame('08', $gps->measurement->satellites);
    }

    /**
     * Verifies that EXIF-only speedRef field makes hasAnyGpsData() return true.
     * Kills ArrayItemRemoval mutant that removes speedRef from movement group.
     */
    #[Test]
    public function speedRefOnlyExifDataIsNotNull(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: GpsSpeedRef::KilometersPerHour,
            speedMs: null,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: null,
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->movement);
        self::assertSame(GpsSpeedRef::KilometersPerHour, $gps->movement->speedRef);
    }

    /**
     * Verifies that EXIF-only version field makes hasAnyGpsData() return true.
     * Kills ArrayItemRemoval mutant that removes version from version group.
     */
    #[Test]
    public function versionOnlyExifDataIsNotNull(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: '2.4.0.0',
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: null,
            speedMs: null,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: null,
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertSame('2.4.0.0', $gps->version);
    }

    /**
     * Verifies that XMP-only destLatRef field makes hasAnyGpsData() return true.
     * Kills ArrayItemRemoval mutant that removes destLatRef from destination group.
     */
    #[Test]
    public function destLatRefOnlyXmpDataIsNotNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDestLatitudeRef', self::NS_EXIF) => 'N',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->destination);
        self::assertSame(GpsLatLonRef::North, $gps->destination->latitudeRef);
    }

    /**
     * Verifies normalizeDate rejects dates with extra characters after the valid pattern.
     * Kills PregMatchRemoveDollar mutants on both regex patterns.
     */
    #[Test]
    public function normalizeDateRejectsDateWithTrailingCharacters(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDateStamp', self::NS_EXIF) => '2023:06:15 extra',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->timing);
    }

    /**
     * Verifies normalizeDate rejects dates with extra characters before the valid pattern.
     * Kills PregMatchRemoveCaret mutants on both regex patterns.
     */
    #[Test]
    public function normalizeDateRejectsDateWithLeadingCharacters(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDateStamp', self::NS_EXIF) => 'x2023:06:15',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->timing);
    }

    /**
     * Verifies normalizeDate rejects ISO dates with leading characters.
     * Kills PregMatchRemoveCaret mutant on second regex pattern.
     */
    #[Test]
    public function normalizeDateRejectsIsoDashDateWithLeadingCharacters(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDateStamp', self::NS_EXIF) => 'x2023-06-15',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->timing);
    }

    /**
     * Verifies normalizeDate rejects ISO dates with trailing characters.
     * Kills PregMatchRemoveDollar mutant on second regex pattern.
     */
    #[Test]
    public function normalizeDateRejectsIsoDashDateWithTrailingCharacters(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDateStamp', self::NS_EXIF) => '2023-06-15 extra',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNull($gps->timing);
    }

    /**
     * Verifies QuickTime GPS at zero latitude yields North ref.
     * Kills GreaterThanOrEqualTo mutant that changes ($lat >= 0) to ($lat > 0).
     */
    #[Test]
    public function quickTimeZeroLatitudeYieldsNorthRef(): void
    {
        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.location.ISO6709' => '+00.0000+011.5678/',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(GpsLatLonRef::North, $gps->position->latitudeRef);
        self::assertEqualsWithDelta(0.0, $gps->position->latitude, 0.001);
    }

    /**
     * Verifies QuickTime GPS at zero longitude yields East ref.
     * Kills GreaterThanOrEqualTo mutant that changes ($lon >= 0) to ($lon > 0).
     */
    #[Test]
    public function quickTimeZeroLongitudeYieldsEastRef(): void
    {
        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.location.ISO6709' => '+48.1234+000.0000/',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(GpsLatLonRef::East, $gps->position->longitudeRef);
        self::assertEqualsWithDelta(0.0, $gps->position->longitude, 0.001);
    }

    /**
     * Verifies QuickTime GPS at zero altitude yields AboveEllipsoidalSurface ref.
     * Kills GreaterThanOrEqualTo mutant that changes ($alt >= 0) to ($alt > 0).
     */
    #[Test]
    public function quickTimeZeroAltitudeYieldsAboveRef(): void
    {
        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.location.ISO6709' => '+48.1234+011.5678+000.000/',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        self::assertSame(GpsAltitudeRef::AboveEllipsoidalSurface, $gps->position->altitudeRef);
        self::assertEqualsWithDelta(0.0, $gps->position->altitude, 0.001);
    }

    /**
     * Verifies XMP altitude without explicit ref defaults to above (ref 0).
     * Kills IncrementInteger/DecrementInteger mutants on ($altRef ?? 0) and AssignCoalesce mutant.
     */
    #[Test]
    public function xmpAltitudeWithoutRefDefaultsToAbove(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSAltitude', self::NS_EXIF) => '100.0',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        // Without ref, defaults to 0 (above) so altitude stays positive
        self::assertSame(100.0, $gps->position->altitude);
        self::assertNull($gps->position->altitudeRef);
    }

    /**
     * Verifies EXIF altitudeRef is preserved even when XMP provides the altitude value.
     * Kills AssignCoalesce mutant that changes ($altitudeRef ??= $altRefXmp) to ($altitudeRef = $altRefXmp).
     */
    #[Test]
    public function exifAltitudeRefPreservedWhenXmpProvidesAltitude(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: null,
            lat: null,
            lonRef: null,
            lon: null,
            altitudeRef: GpsAltitudeRef::BelowSeaLevel,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: null,
            speedMs: null,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: null,
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSAltitude', self::NS_EXIF)    => '50.0',
            sprintf('{%s}GPSAltitudeRef', self::NS_EXIF) => '0',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        // EXIF altitudeRef (3 = below sea level) should be preserved, not overwritten by XMP (0)
        self::assertSame(GpsAltitudeRef::BelowSeaLevel, $gps->position->altitudeRef);
        // The XMP altitude should be negated because EXIF ref says "below"
        self::assertSame(-50.0, $gps->position->altitude);
    }

    /**
     * Verifies XMP track fallback is applied when EXIF track is null.
     * Kills Identical mutant that flips ($trackRef === null) check.
     */
    #[Test]
    public function xmpTrackFallbackAppliedWhenExifTrackIsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSTrack', self::NS_EXIF)    => '123.45',
            sprintf('{%s}GPSTrackRef', self::NS_EXIF) => 'T',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->movement);
        self::assertSame(123.45, $gps->movement->track);
    }

    /**
     * Verifies XMP imgDirection fallback is applied when EXIF imgDir is null.
     * Kills Identical mutant that flips ($imgDirRef === null) check.
     */
    #[Test]
    public function xmpImgDirectionFallbackAppliedWhenExifImgDirIsNull(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSImgDirection', self::NS_EXIF)    => '270.0',
            sprintf('{%s}GPSImgDirectionRef', self::NS_EXIF) => 'M',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->movement);
        self::assertSame(270.0, $gps->movement->imageDirection);
    }

    /**
     * Verifies XMP speedOriginalRef is set from XMP when EXIF is absent.
     * Kills Identical mutant that flips ($speedOriginalRef === null) check.
     */
    #[Test]
    public function xmpSpeedOriginalRefFallbackApplied(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSSpeed', self::NS_EXIF)    => '50.0',
            sprintf('{%s}GPSSpeedRef', self::NS_EXIF) => 'K',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->movement);
        self::assertSame(GpsSpeedRef::KilometersPerHour, $gps->movement->speedOriginalRef);
        self::assertSame(50.0, $gps->movement->speedOriginal);
    }

    /**
     * Verifies XMP destination bearing ref fallback is applied.
     * Kills Identical mutant that flips ($destBearRef === null) check.
     */
    #[Test]
    public function xmpDestBearingRefFallbackApplied(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDestBearing', self::NS_EXIF)    => '45.0',
            sprintf('{%s}GPSDestBearingRef', self::NS_EXIF) => 'T',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->destination);
        self::assertSame(45.0, $gps->destination->bearing);
    }

    /**
     * Verifies XMP destination distance ref and value fallback is applied.
     * Kills Identical mutants that flip ($destDistRef === null) and ($destDistOriginalRef === null).
     */
    #[Test]
    public function xmpDestDistanceFallbackApplied(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDestDistance', self::NS_EXIF)    => '10.0',
            sprintf('{%s}GPSDestDistanceRef', self::NS_EXIF) => 'K',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->destination);
        self::assertSame(10.0 * 1000.0, $gps->destination->distanceMetres);
        self::assertSame(10.0, $gps->destination->distanceOriginal);
    }

    /**
     * Verifies that combineDateAndTime produces a timestamp when both date and time are present.
     * Kills Identical mutant that flips ($date !== null) in combineDateAndTime.
     */
    #[Test]
    public function combineDateAndTimeProducesTimestamp(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDateStamp', self::NS_EXIF) => '2023-06-15',
            sprintf('{%s}GPSTimeStamp', self::NS_EXIF) => '14:30:00',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->timing);
        self::assertInstanceOf(DateTimeImmutable::class, $gps->timing->timestamp);
        self::assertSame('2023-06-15T14:30:00+00:00', $gps->timing->timestamp->format('Y-m-d\TH:i:sP'));
    }

    /**
     * Verifies that combineDateAndTime with empty time string yields null timestamp.
     * Kills the Identical mutant on ($time === '') check in combineDateAndTime.
     */
    #[Test]
    public function combineDateAndTimeWithEmptyTimeYieldsNullTimestamp(): void
    {
        $xmpDoc = new XmpDocument([
            sprintf('{%s}GPSDateStamp', self::NS_EXIF) => '2023-06-15',
            sprintf('{%s}GPSTimeStamp', self::NS_EXIF) => ' ',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->timing);
        self::assertSame('2023-06-15', $gps->timing->date);
        self::assertNull($gps->timing->timestamp);
    }

    /**
     * Verifies EXIF with latitude but null longitude triggers QuickTime fallback.
     * Kills LogicalOr mutant that changes || to && in the position null check.
     */
    #[Test]
    public function exifWithLatitudeOnlyTriggersQuickTimeFallback(): void
    {
        $parsedExif = $this->parsedExif(
            latRef: GpsLatLonRef::North,
            lat: 52.520008,
            lonRef: null,
            lon: null,
            altitudeRef: null,
            altitude: null,
            version: null,
            satellites: null,
            status: null,
            measureMode: null,
            dop: null,
            speedRef: null,
            speedMs: null,
            track: null,
            mapDatum: null,
            processingMethod: null,
            areaInformation: null,
            date: null,
            time: null,
            differential: null,
            hPositioningError: null,
        );

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.location.ISO6709' => '+48.1372+011.5755/',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            exifDoc: $parsedExif,
        );

        $factory = new GpsFactory();
        $gps     = $factory->create($metadata);

        self::assertNotNull($gps->position);
        // Should use QuickTime position since EXIF longitude is null
        self::assertEqualsWithDelta(48.1372, $gps->position->latitude, 0.001);
        self::assertEqualsWithDelta(11.5755, $gps->position->longitude, 0.001);
    }

    private const string NS_EXIF = 'http://ns.adobe.com/exif/1.0/';

    private function parsedExif(
        ?GpsLatLonRef $latRef,
        ?float $lat,
        ?GpsLatLonRef $lonRef,
        ?float $lon,
        ?GpsAltitudeRef $altitudeRef,
        ?float $altitude,
        ?string $version,
        ?string $satellites,
        ?GpsStatus $status,
        ?GpsMeasureMode $measureMode,
        ?float $dop,
        ?GpsSpeedRef $speedRef,
        ?float $speedMs,
        ?float $track,
        ?string $mapDatum,
        ?string $processingMethod,
        ?string $areaInformation,
        ?string $date,
        ?string $time,
        ?int $differential,
        ?float $hPositioningError,
    ): ParsedExif {
        $gpsEntries = [];

        if ($latRef instanceof GpsLatLonRef) {
            $gpsEntries[ExifTag::GPS_LATITUDE_REF] = new IfdEntry(
                ExifTag::GPS_LATITUDE_REF,
                2,
                2,
                $latRef->value,
            );
        }

        if ($lat !== null) {
            // Store as three SRATIONALs: deg, min, sec*1000
            $deg      = floor($lat);
            $minFloat = ($lat - $deg) * 60.0;
            $min      = floor($minFloat);
            $sec      = ($minFloat - $min) * 60.0;

            $pairs = [
                [$deg, 1],
                [$min, 1],
                [$sec * 1000, 1000],
            ];

            $gpsEntries[ExifTag::GPS_LATITUDE] = new IfdEntry(
                ExifTag::GPS_LATITUDE,
                10,
                3,
                $pairs,
            );
        }

        if ($lonRef instanceof GpsLatLonRef) {
            $gpsEntries[ExifTag::GPS_LONGITUDE_REF] = new IfdEntry(
                ExifTag::GPS_LONGITUDE_REF,
                2,
                2,
                $lonRef->value,
            );
        }

        if ($lon !== null) {
            $deg      = floor($lon);
            $minFloat = ($lon - $deg) * 60.0;
            $min      = floor($minFloat);
            $sec      = ($minFloat - $min) * 60.0;

            $pairs = [
                [$deg, 1],
                [$min, 1],
                [$sec * 1000, 1000],
            ];

            $gpsEntries[ExifTag::GPS_LONGITUDE] = new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                10,
                3,
                $pairs,
            );
        }

        if ($altitudeRef instanceof GpsAltitudeRef) {
            $gpsEntries[ExifTag::GPS_ALTITUDE_REF] = new IfdEntry(
                ExifTag::GPS_ALTITUDE_REF,
                1,
                1,
                $altitudeRef->value,
            );
        }

        if ($altitude !== null) {
            $gpsEntries[ExifTag::GPS_ALTITUDE] = new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                $altitude,
            );
        }

        if ($version !== null) {
            $versionParts                        = array_map(intval(...), explode('.', $version));
            $gpsEntries[ExifTag::GPS_VERSION_ID] = new IfdEntry(
                ExifTag::GPS_VERSION_ID,
                1,
                4,
                new ExifNumericList($versionParts),
            );
        }

        if ($satellites !== null) {
            $gpsEntries[ExifTag::GPS_SATELLITES] = new IfdEntry(
                ExifTag::GPS_SATELLITES,
                2,
                strlen($satellites),
                $satellites,
            );
        }

        if ($status instanceof GpsStatus) {
            $gpsEntries[ExifTag::GPS_STATUS] = new IfdEntry(
                ExifTag::GPS_STATUS,
                2,
                2,
                $status->value,
            );
        }

        if ($measureMode instanceof GpsMeasureMode) {
            $gpsEntries[ExifTag::GPS_MEASURE_MODE] = new IfdEntry(
                ExifTag::GPS_MEASURE_MODE,
                2,
                2,
                $measureMode->value,
            );
        }

        if ($dop !== null) {
            $gpsEntries[ExifTag::GPS_DOP] = new IfdEntry(
                ExifTag::GPS_DOP,
                5,
                1,
                $dop,
            );
        }

        if ($speedRef instanceof GpsSpeedRef) {
            $gpsEntries[ExifTag::GPS_SPEED_REF] = new IfdEntry(
                ExifTag::GPS_SPEED_REF,
                2,
                2,
                $speedRef->value,
            );
        }

        if ($speedMs !== null) {
            // Store directly as metres per second in GPS speed tag (unit via ref)
            $gpsEntries[ExifTag::GPS_SPEED] = new IfdEntry(
                ExifTag::GPS_SPEED,
                5,
                1,
                $speedMs,
            );
        }

        if ($track !== null) {
            $gpsEntries[ExifTag::GPS_TRACK] = new IfdEntry(
                ExifTag::GPS_TRACK,
                5,
                1,
                $track,
            );
        }

        if ($mapDatum !== null) {
            $gpsEntries[ExifTag::GPS_MAP_DATUM] = new IfdEntry(
                ExifTag::GPS_MAP_DATUM,
                2,
                strlen($mapDatum),
                $mapDatum,
            );
        }

        if ($processingMethod !== null) {
            $gpsEntries[ExifTag::GPS_PROCESSING_METHOD] = new IfdEntry(
                ExifTag::GPS_PROCESSING_METHOD,
                7,
                strlen($processingMethod),
                $processingMethod,
            );
        }

        if ($areaInformation !== null) {
            $gpsEntries[ExifTag::GPS_AREA_INFORMATION] = new IfdEntry(
                ExifTag::GPS_AREA_INFORMATION,
                7,
                strlen($areaInformation),
                $areaInformation,
            );
        }

        if ($date !== null) {
            $dateStamp = str_replace('-', ':', $date);

            $gpsEntries[ExifTag::GPS_DATE_STAMP] = new IfdEntry(
                ExifTag::GPS_DATE_STAMP,
                2,
                strlen($dateStamp),
                $dateStamp,
            );
        }

        if ($time !== null) {
            $parts = explode(':', $time);

            $gpsEntries[ExifTag::GPS_TIME_STAMP] = new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                [
                    [(int) $parts[0], 1],
                    [(int) $parts[1], 1],
                    [(int) $parts[2], 1],
                ],
            );
        }

        if ($differential !== null) {
            $gpsEntries[ExifTag::GPS_DIFFERENTIAL] = new IfdEntry(
                ExifTag::GPS_DIFFERENTIAL,
                3,
                1,
                $differential,
            );
        }

        if ($hPositioningError !== null) {
            $gpsEntries[ExifTag::GPS_H_POSITIONING_ERROR] = new IfdEntry(
                ExifTag::GPS_H_POSITIONING_ERROR,
                5,
                1,
                $hPositioningError,
            );
        }

        $ifd0   = new Ifd([]);
        $gpsIfd = new Ifd($gpsEntries);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: new Ifd([]),
            gpsIfd: $gpsIfd,
            interopIfd: null,
            ifd1: null,
        );
    }
}
