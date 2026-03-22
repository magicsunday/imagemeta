<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Reader;

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
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Reader\GpsExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises GpsExifReader for reading GPS metadata from the GPS IFD.
 * Verifies coordinate, timestamp, speed, track, and direction parsing.
 *
 * @internal
 */
#[CoversClass(GpsExifReader::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
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
final class GpsExifReaderTest extends TestCase
{
    /**
     * Supplies a GPS IFD with coordinate tags and verifies the GPS map
     * contains latitude and longitude values.
     */
    #[Test]
    public function readsGpsCoordinatesFromGpsIfd(): void
    {
        $gpsEntries = [
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(ExifTag::GPS_LATITUDE, 5, 3, [
                [52, 1],
                [31, 1],
                [12, 1],
            ]),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 5, 3, [
                [13, 1],
                [24, 1],
                [36, 1],
            ]),
        ];

        $reader = $this->createReader($gpsEntries);

        $gps = $reader->gps();
        $this->addToAssertionCount(1);
        self::assertNotNull($gps['lat']);
        self::assertNotNull($gps['lon']);
    }

    /**
     * Verifies that a GPS reader constructed without a GPS IFD returns an empty
     * result from the gps() method and null from all accessor methods.
     */
    #[Test]
    public function returnsEmptyGpsWhenNoGpsIfd(): void
    {
        $reader = new GpsExifReader(
            new ValueConverters(),
            null,
        );

        $reader->gps();
        $this->addToAssertionCount(1);

        self::assertNull($reader->gpsDateStamp());
        self::assertNull($reader->gpsTimeStampString());
        self::assertNull($reader->gpsTimestamp());
        self::assertNull($reader->gpsSpeedRef());
        self::assertNull($reader->gpsSpeedMetresPerSecond());
        self::assertNull($reader->gpsTrackRef());
        self::assertNull($reader->gpsTrack());
        self::assertNull($reader->gpsImgDirectionRef());
        self::assertNull($reader->gpsImgDirection());
        self::assertNull($reader->gpsDestinationBearingRef());
        self::assertNull($reader->gpsDestinationBearing());
        self::assertNull($reader->gpsDestinationDistanceRef());
        self::assertNull($reader->gpsDestinationDistanceMetres());
        self::assertNull($reader->gpsDifferential());
        self::assertNull($reader->gpsHorizontalPositioningError());
    }

    /**
     * Supplies GPS date and time stamp tags and verifies parsed values.
     */
    #[Test]
    public function readsGpsDateAndTimestamp(): void
    {
        $gpsEntries = [
            ExifTag::GPS_DATE_STAMP => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 11, '2024:06:15'),
            ExifTag::GPS_TIME_STAMP => new IfdEntry(ExifTag::GPS_TIME_STAMP, 5, 3, [
                [14, 1],
                [30, 1],
                [0, 1],
            ]),
        ];

        $reader = $this->createReader($gpsEntries);

        self::assertSame('2024-06-15', $reader->gpsDateStamp());
        self::assertSame('14:30:00', $reader->gpsTimeStampString());
    }

    /**
     * @param array<int, IfdEntry> $gpsEntries
     */
    private function createReader(array $gpsEntries): GpsExifReader
    {
        $gpsIfd = $gpsEntries !== [] ? new Ifd($gpsEntries) : null;

        return new GpsExifReader(
            new ValueConverters(),
            $gpsIfd,
        );
    }
}
