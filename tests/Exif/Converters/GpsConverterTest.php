<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\Converters\ValidatesGpsRef;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises GpsConverter validation of EXIF GPS reference and status tags.
 * It verifies that invalid reference values are rejected per EXIF 3.0 §4.6.7.
 * The suite covers all enumerated GPS ref/status tags and their valid/invalid inputs.
 * This ensures invalid metadata does not produce incorrect derived GPS fields.
 *
 * @internal
 */
#[CoversClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesTrait(ValidatesGpsRef::class)]
final class GpsConverterTest extends TestCase
{
    private GpsConverter $converter;

    private GpsUnitConverter $unitConverter;

    protected function setUp(): void
    {
        $numericConverter  = new NumericConverter();
        $rationalConverter = new RationalConverter($numericConverter);
        $stringConverter   = new StringConverter();

        $coordinateConverter = new GpsCoordinateConverter($rationalConverter, $numericConverter);
        $this->unitConverter = new GpsUnitConverter($rationalConverter);
        $directionConverter  = new GpsDirectionConverter($rationalConverter);
        $timestampConverter  = new GpsTimestampConverter($rationalConverter, $stringConverter);

        $this->converter = new GpsConverter(
            $coordinateConverter,
            $this->unitConverter,
            $directionConverter,
            $timestampConverter,
            $rationalConverter,
            $stringConverter,
        );
    }

    /**
     * Verifies that valid GPS reference values are accepted and produce correct results.
     *
     * @param array<int, IfdEntry> $entries     IFD entries to parse.
     * @param array<string, mixed> $sameChecks  Keys to assert with assertSame.
     * @param list<string>         $notNullKeys Keys to assert with assertNotNull.
     */
    #[Test]
    #[DataProvider('provideValidRefValues')]
    public function acceptsValidRefValues(array $entries, array $sameChecks, array $notNullKeys = []): void
    {
        $result = $this->converter->fromIfd(new Ifd($entries));

        foreach ($sameChecks as $key => $expected) {
            self::assertSame($expected, $result[$key], 'Key: ' . $key);
        }

        foreach ($notNullKeys as $key) {
            self::assertNotNull($result[$key], 'Key: ' . $key);
        }
    }

    /**
     * @return iterable<string, array{0: array<int, IfdEntry>, 1: array<string, mixed>, 2?: list<string>}>
     */
    public static function provideValidRefValues(): iterable
    {
        yield 'latitude ref N' => [
            [
                ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
                ExifTag::GPS_LATITUDE     => new IfdEntry(ExifTag::GPS_LATITUDE, 10, 3, [[52, 1], [31, 1], [12000, 1000]]),
            ],
            ['lat_ref' => 'N'],
            ['lat'],
        ];

        yield 'longitude ref W' => [
            [
                ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'W'),
                ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 10, 3, [[52, 1], [31, 1], [12000, 1000]]),
            ],
            ['lon_ref' => 'W'],
            ['lon'],
        ];

        yield 'status A' => [
            [ExifTag::GPS_STATUS => new IfdEntry(ExifTag::GPS_STATUS, 2, 2, 'A')],
            ['status' => 'A'],
        ];

        yield 'measure mode 3' => [
            [ExifTag::GPS_MEASURE_MODE => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 2, '3')],
            ['measure_mode' => '3'],
        ];

        yield 'speed ref K' => [
            [
                ExifTag::GPS_SPEED_REF => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 2, 'K'),
                ExifTag::GPS_SPEED     => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, 36.0),
            ],
            ['speed_ref' => 'K', 'speed_ms' => 36.0 / 3.6],
        ];

        yield 'track ref T' => [
            [
                ExifTag::GPS_TRACK_REF => new IfdEntry(ExifTag::GPS_TRACK_REF, 2, 2, 'T'),
                ExifTag::GPS_TRACK     => new IfdEntry(ExifTag::GPS_TRACK, 5, 1, 90.0),
            ],
            ['track_ref' => 'T', 'track' => 90.0],
        ];

        yield 'img direction ref M' => [
            [
                ExifTag::GPS_IMG_DIRECTION_REF => new IfdEntry(ExifTag::GPS_IMG_DIRECTION_REF, 2, 2, 'M'),
                ExifTag::GPS_IMG_DIRECTION     => new IfdEntry(ExifTag::GPS_IMG_DIRECTION, 5, 1, 180.0),
            ],
            ['img_direction_ref' => 'M', 'img_direction' => 180.0],
        ];

        yield 'dest bearing ref T' => [
            [
                ExifTag::GPS_DEST_BEARING_REF => new IfdEntry(ExifTag::GPS_DEST_BEARING_REF, 2, 2, 'T'),
                ExifTag::GPS_DEST_BEARING     => new IfdEntry(ExifTag::GPS_DEST_BEARING, 5, 1, 45.0),
            ],
            ['dest_bearing_ref' => 'T', 'dest_bearing' => 45.0],
        ];

        yield 'dest distance ref K' => [
            [
                ExifTag::GPS_DEST_DISTANCE_REF => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 2, 'K'),
                ExifTag::GPS_DEST_DISTANCE     => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, 10.0),
            ],
            ['dest_distance_ref' => 'K', 'dest_distance_m' => 10_000.0],
        ];

        yield 'lowercase status a normalized to A' => [
            [ExifTag::GPS_STATUS => new IfdEntry(ExifTag::GPS_STATUS, 2, 2, 'a')],
            ['status' => 'A'],
        ];

        yield 'measure mode 2' => [
            [ExifTag::GPS_MEASURE_MODE => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 2, '2')],
            ['measure_mode' => '2'],
        ];
    }

    /**
     * Verifies that invalid GPS reference values are nulled per EXIF 3.0 §4.6.7.
     *
     * @param array<int, IfdEntry> $entries  IFD entries to parse.
     * @param list<string>         $nullKeys Result keys that must be null.
     */
    #[Test]
    #[DataProvider('provideInvalidRefValues')]
    public function rejectsInvalidRefValues(array $entries, array $nullKeys): void
    {
        $result = $this->converter->fromIfd(new Ifd($entries));

        foreach ($nullKeys as $key) {
            self::assertNull($result[$key], sprintf('Expected %s to be null', $key));
        }
    }

    /**
     * @return iterable<string, array{0: array<int, IfdEntry>, 1: list<string>}>
     */
    public static function provideInvalidRefValues(): iterable
    {
        yield 'invalid latitude ref X' => [
            [
                ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'X'),
                ExifTag::GPS_LATITUDE     => new IfdEntry(ExifTag::GPS_LATITUDE, 10, 3, [[52, 1], [31, 1], [12000, 1000]]),
            ],
            ['lat_ref', 'lat'],
        ];

        yield 'invalid longitude ref Z' => [
            [
                ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'Z'),
                ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 10, 3, [[52, 1], [31, 1], [12000, 1000]]),
            ],
            ['lon_ref', 'lon'],
        ];

        yield 'invalid status X' => [
            [ExifTag::GPS_STATUS => new IfdEntry(ExifTag::GPS_STATUS, 2, 2, 'X')],
            ['status'],
        ];

        yield 'invalid measure mode 1' => [
            [ExifTag::GPS_MEASURE_MODE => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 2, '1')],
            ['measure_mode'],
        ];

        yield 'invalid speed ref X' => [
            [
                ExifTag::GPS_SPEED_REF => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 2, 'X'),
                ExifTag::GPS_SPEED     => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, 36.0),
            ],
            ['speed_ref', 'speed_ms'],
        ];

        yield 'invalid track ref X' => [
            [
                ExifTag::GPS_TRACK_REF => new IfdEntry(ExifTag::GPS_TRACK_REF, 2, 2, 'X'),
                ExifTag::GPS_TRACK     => new IfdEntry(ExifTag::GPS_TRACK, 5, 1, 90.0),
            ],
            ['track_ref', 'track'],
        ];

        yield 'invalid img direction ref X' => [
            [
                ExifTag::GPS_IMG_DIRECTION_REF => new IfdEntry(ExifTag::GPS_IMG_DIRECTION_REF, 2, 2, 'X'),
                ExifTag::GPS_IMG_DIRECTION     => new IfdEntry(ExifTag::GPS_IMG_DIRECTION, 5, 1, 180.0),
            ],
            ['img_direction_ref', 'img_direction'],
        ];

        yield 'invalid dest bearing ref X' => [
            [
                ExifTag::GPS_DEST_BEARING_REF => new IfdEntry(ExifTag::GPS_DEST_BEARING_REF, 2, 2, 'X'),
                ExifTag::GPS_DEST_BEARING     => new IfdEntry(ExifTag::GPS_DEST_BEARING, 5, 1, 45.0),
            ],
            ['dest_bearing_ref', 'dest_bearing'],
        ];

        yield 'invalid dest distance ref X' => [
            [
                ExifTag::GPS_DEST_DISTANCE_REF => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 2, 'X'),
                ExifTag::GPS_DEST_DISTANCE     => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, 10.0),
            ],
            ['dest_distance_ref', 'dest_distance_m'],
        ];
    }

    /**
     * Verifies that multi-character ref values do not leak into any reference fields.
     *
     * @param array<int, IfdEntry> $entries  IFD entries to parse.
     * @param list<string>         $nullKeys Result keys that must be null.
     */
    #[Test]
    #[DataProvider('provideMultiCharacterRefValues')]
    public function rejectsMultiCharacterRefValues(array $entries, array $nullKeys): void
    {
        $result = $this->converter->fromIfd(new Ifd($entries));

        foreach ($nullKeys as $key) {
            self::assertNull($result[$key], sprintf('Expected %s to be null', $key));
        }
    }

    /**
     * @return iterable<string, array{0: array<int, IfdEntry>, 1: list<string>}>
     */
    public static function provideMultiCharacterRefValues(): iterable
    {
        yield 'multi-char speed ref KM' => [
            [
                ExifTag::GPS_SPEED_REF => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 3, 'KM'),
                ExifTag::GPS_SPEED     => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, 36.0),
            ],
            ['speed_ref', 'speed_ms', 'speed_original_ref'],
        ];

        yield 'multi-char dest distance ref NM' => [
            [
                ExifTag::GPS_DEST_DISTANCE_REF => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 3, 'NM'),
                ExifTag::GPS_DEST_DISTANCE     => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, 10.0),
            ],
            ['dest_distance_ref', 'dest_distance_m', 'dest_distance_original_ref'],
        ];
    }

    /**
     * Verifies that non-standard DMS component counts produce null coordinates.
     *
     * @param array<int, IfdEntry> $entries IFD entries to parse.
     * @param string               $nullKey Result key that must be null.
     */
    #[Test]
    #[DataProvider('provideWrongDmsComponentCounts')]
    public function rejectsWrongDmsComponentCount(array $entries, string $nullKey): void
    {
        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result[$nullKey]);
    }

    /**
     * @return iterable<string, array{0: array<int, IfdEntry>, 1: string}>
     */
    public static function provideWrongDmsComponentCounts(): iterable
    {
        yield 'latitude with 2 components' => [
            [
                ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
                ExifTag::GPS_LATITUDE     => new IfdEntry(ExifTag::GPS_LATITUDE, 10, 2, [[52, 1], [31, 1]]),
            ],
            'lat',
        ];

        yield 'longitude with 4 components' => [
            [
                ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
                ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 10, 4, [[13, 1], [24, 1], [17820, 1000], [0, 1]]),
            ],
            'lon',
        ];

        yield 'latitude with 1 component' => [
            [
                ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'S'),
                ExifTag::GPS_LATITUDE     => new IfdEntry(ExifTag::GPS_LATITUDE, 10, 1, [[33, 1]]),
            ],
            'lat',
        ];
    }

    /**
     * Accepts edge values for capture and destination coordinates.
     */
    #[Test]
    public function acceptsCoordinateRangeEdges(): void
    {
        $entries = [
            ExifTag::GPS_LATITUDE_REF       => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE           => new IfdEntry(ExifTag::GPS_LATITUDE, 10, 3, [[90, 1], [0, 1], [0, 1]]),
            ExifTag::GPS_LONGITUDE_REF      => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'W'),
            ExifTag::GPS_LONGITUDE          => new IfdEntry(ExifTag::GPS_LONGITUDE, 10, 3, [[180, 1], [0, 1], [0, 1]]),
            ExifTag::GPS_DEST_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_DEST_LATITUDE_REF, 2, 2, 'S'),
            ExifTag::GPS_DEST_LATITUDE      => new IfdEntry(ExifTag::GPS_DEST_LATITUDE, 10, 3, [[90, 1], [0, 1], [0, 1]]),
            ExifTag::GPS_DEST_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_DEST_LONGITUDE     => new IfdEntry(ExifTag::GPS_DEST_LONGITUDE, 10, 3, [[180, 1], [0, 1], [0, 1]]),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame(90.0, $result['lat']);
        self::assertSame(-180.0, $result['lon']);
        self::assertSame(-90.0, $result['dest_lat']);
        self::assertSame(180.0, $result['dest_lon']);
    }

    /**
     * Rejects negative DMS components for capture and destination coordinates.
     *
     * @param list<array{0:int,1:int}> $dms
     */
    #[Test]
    #[DataProvider('provideNegativeDmsComponents')]
    public function rejectsNegativeDmsComponents(int $refTag, int $valueTag, string $ref, array $dms): void
    {
        $entries = [
            $refTag   => new IfdEntry($refTag, 2, 2, $ref),
            $valueTag => new IfdEntry($valueTag, 10, 3, $dms),
        ];

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1467);
        $this->expectExceptionMessage('non-negative');

        $this->converter->fromIfd(new Ifd($entries));
    }

    /**
     * @return iterable<string, array{0:int, 1:int, 2:string, 3:list<array{0:int,1:int}>}>
     */
    public static function provideNegativeDmsComponents(): iterable
    {
        yield 'latitude-negative-degrees' => [
            ExifTag::GPS_LATITUDE_REF,
            ExifTag::GPS_LATITUDE,
            'N',
            [[-12, 1], [34, 1], [56, 1]],
        ];

        yield 'longitude-negative-minutes' => [
            ExifTag::GPS_LONGITUDE_REF,
            ExifTag::GPS_LONGITUDE,
            'E',
            [[12, 1], [-34, 1], [56, 1]],
        ];

        yield 'longitude-negative-seconds' => [
            ExifTag::GPS_LONGITUDE_REF,
            ExifTag::GPS_LONGITUDE,
            'W',
            [[12, 1], [34, 1], [-56, 1]],
        ];

        yield 'dest-latitude-negative-minutes' => [
            ExifTag::GPS_DEST_LATITUDE_REF,
            ExifTag::GPS_DEST_LATITUDE,
            'S',
            [[12, 1], [-1, 1], [0, 1]],
        ];

        yield 'dest-longitude-negative-seconds' => [
            ExifTag::GPS_DEST_LONGITUDE_REF,
            ExifTag::GPS_DEST_LONGITUDE,
            'E',
            [[12, 1], [0, 1], [-1, 1]],
        ];
    }

    /**
     * Rejects DMS minutes or seconds >= 60 for capture and destination coordinates.
     *
     * @param list<array{0:int,1:int}> $dms
     */
    #[Test]
    #[DataProvider('provideOutOfRangeDmsComponents')]
    public function rejectsOutOfRangeDmsComponents(int $refTag, int $valueTag, string $ref, array $dms): void
    {
        $entries = [
            $refTag   => new IfdEntry($refTag, 2, 2, $ref),
            $valueTag => new IfdEntry($valueTag, 10, 3, $dms),
        ];

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1470);
        $this->expectExceptionMessage('must be in range [0, 60)');

        $this->converter->fromIfd(new Ifd($entries));
    }

    /**
     * @return iterable<string, array{0:int, 1:int, 2:string, 3:list<array{0:int,1:int}>}>
     */
    public static function provideOutOfRangeDmsComponents(): iterable
    {
        yield 'latitude-minutes-60' => [
            ExifTag::GPS_LATITUDE_REF, ExifTag::GPS_LATITUDE, 'N',
            [[12, 1], [60, 1], [0, 1]],
        ];

        yield 'latitude-seconds-60' => [
            ExifTag::GPS_LATITUDE_REF, ExifTag::GPS_LATITUDE, 'S',
            [[12, 1], [30, 1], [60, 1]],
        ];

        yield 'longitude-minutes-61' => [
            ExifTag::GPS_LONGITUDE_REF, ExifTag::GPS_LONGITUDE, 'E',
            [[13, 1], [61, 1], [0, 1]],
        ];

        yield 'longitude-seconds-70' => [
            ExifTag::GPS_LONGITUDE_REF, ExifTag::GPS_LONGITUDE, 'W',
            [[13, 1], [30, 1], [70, 1]],
        ];

        yield 'dest-latitude-minutes-60' => [
            ExifTag::GPS_DEST_LATITUDE_REF, ExifTag::GPS_DEST_LATITUDE, 'N',
            [[45, 1], [60, 1], [0, 1]],
        ];

        yield 'dest-longitude-seconds-60' => [
            ExifTag::GPS_DEST_LONGITUDE_REF, ExifTag::GPS_DEST_LONGITUDE, 'E',
            [[90, 1], [0, 1], [60, 1]],
        ];
    }

    /**
     * Rejects coordinate values that exceed their geographic range.
     *
     * @param list<array{0:int,1:int}> $dms
     */
    #[Test]
    #[DataProvider('provideOutOfRangeCoordinateValues')]
    public function rejectsOutOfRangeCoordinateValues(int $refTag, int $valueTag, string $ref, array $dms, int $errorCode, string $messageFragment): void
    {
        $entries = [
            $refTag   => new IfdEntry($refTag, 2, 2, $ref),
            $valueTag => new IfdEntry($valueTag, 10, 3, $dms),
        ];

        $this->expectException(ParseError::class);
        $this->expectExceptionCode($errorCode);
        $this->expectExceptionMessage($messageFragment);

        $this->converter->fromIfd(new Ifd($entries));
    }

    /**
     * @return iterable<string, array{0:int, 1:int, 2:string, 3:list<array{0:int,1:int}>, 4:int, 5:string}>
     */
    public static function provideOutOfRangeCoordinateValues(): iterable
    {
        yield 'latitude above 90 N' => [
            ExifTag::GPS_LATITUDE_REF, ExifTag::GPS_LATITUDE, 'N',
            [[91, 1], [0, 1], [0, 1]], 1463, 'outside the valid latitude range',
        ];

        yield 'longitude above 180 W' => [
            ExifTag::GPS_LONGITUDE_REF, ExifTag::GPS_LONGITUDE, 'W',
            [[181, 1], [0, 1], [0, 1]], 1464, 'outside the valid longitude range',
        ];

        yield 'dest latitude above 90 S' => [
            ExifTag::GPS_DEST_LATITUDE_REF, ExifTag::GPS_DEST_LATITUDE, 'S',
            [[91, 1], [0, 1], [0, 1]], 1463, 'outside the valid latitude range',
        ];
    }

    /**
     * Rejects out-of-range GPS bearing values for track/image/destination bearings.
     */
    #[Test]
    #[DataProvider('provideOutOfRangeBearingValues')]
    public function rejectsOutOfRangeGpsBearingValues(int $refTag, int $valueTag, string $ref, float $value): void
    {
        $entries = [
            $refTag   => new IfdEntry($refTag, 2, 2, $ref),
            $valueTag => new IfdEntry($valueTag, 5, 1, $value),
        ];

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1460);
        $this->expectExceptionMessage('outside the valid range');

        $this->converter->fromIfd(new Ifd($entries));
    }

    /**
     * @return iterable<string, array{0:int,1:int,2:string,3:float}>
     */
    public static function provideOutOfRangeBearingValues(): iterable
    {
        yield 'track negative' => [
            ExifTag::GPS_TRACK_REF, ExifTag::GPS_TRACK, 'T', -1.0,
        ];

        yield 'image direction >= 360' => [
            ExifTag::GPS_IMG_DIRECTION_REF, ExifTag::GPS_IMG_DIRECTION, 'M', 360.0,
        ];

        yield 'destination bearing far above range' => [
            ExifTag::GPS_DEST_BEARING_REF, ExifTag::GPS_DEST_BEARING, 'T', 720.0,
        ];
    }

    /**
     * Combines valid GPSDateStamp and GPSTimeStamp values into a UTC timestamp.
     */
    #[Test]
    public function combinesValidGpsDateAndTimeStamp(): void
    {
        $result = $this->converter->fromIfd(
            $this->buildIfdWithDateAndTime('2025:02:28', [[23, 1], [59, 1], [15, 1]]),
        );

        self::assertSame('2025-02-28', $result['date']);
        self::assertSame('23:59:15', $result['time']);
        self::assertSame('2025-02-28T23:59:15+00:00', $result['timestamp']?->format('c'));
    }

    /**
     * Accepts fractional seconds in valid range for GPSTimeStamp.
     */
    #[Test]
    public function acceptsGpsTimeStampWithFractionalSeconds(): void
    {
        $result = $this->converter->fromIfd(
            $this->buildIfdWithDateAndTime('2025:03:01', [[10, 1], [20, 1], [12345, 1000]]),
        );
        $timestamp = $result['timestamp'];

        self::assertSame('10:20:12.345', $result['time']);
        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame('2025-03-01T10:20:12+00:00', $timestamp->format('Y-m-d\TH:i:sP'));
        self::assertSame('345000', $timestamp->format('u'));
    }

    /**
     * Verifies that seconds very close to 60 carry into minutes after microsecond rounding.
     */
    #[Test]
    public function carriesSecondsNear60IntoMinutes(): void
    {
        // 599999999/10000000 = 59.9999999 — passes < 60.0 check, rounds to seconds=60
        $result = $this->converter->fromIfd(
            $this->buildIfdWithDateAndTime('2025:03:01', [[10, 1], [20, 1], [599999999, 10000000]]),
        );

        self::assertSame('10:21:00', $result['time']);
        self::assertSame('2025-03-01T10:21:00+00:00', $result['timestamp']?->format('c'));
    }

    /**
     * Verifies that seconds carry cascades through minutes into hours at 23:59.
     */
    #[Test]
    public function carriesSecondsNear60AtEndOfHour(): void
    {
        // 23:59:59.9999999 — should become 00:00:00 but clamps to 23:59:59.999999
        $result = $this->converter->fromIfd(
            $this->buildIfdWithDateAndTime('2025:03:01', [[23, 1], [59, 1], [599999999, 10000000]]),
        );

        self::assertSame('23:59:59.999999', $result['time']);
    }

    /**
     * Rejects invalid GPS timestamp inputs (bad dates and out-of-range time components).
     *
     * @param list<array{0:int,1:int}> $timeRationals
     */
    #[Test]
    #[DataProvider('provideInvalidGpsTimestampInputs')]
    public function rejectsInvalidGpsTimestampInputs(string $date, array $timeRationals, int $errorCode, string $messageFragment): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode($errorCode);
        $this->expectExceptionMessage($messageFragment);

        $this->converter->fromIfd(
            $this->buildIfdWithDateAndTime($date, $timeRationals),
        );
    }

    /**
     * @return iterable<string, array{0:string, 1:list<array{0:int,1:int}>, 2:int, 3:string}>
     */
    public static function provideInvalidGpsTimestampInputs(): iterable
    {
        yield 'invalid calendar date' => ['2025:02:30', [[12, 1], [34, 1], [56, 1]], 1465, 'GPSDateStamp'];
        yield 'hour above 23' => ['2025:03:01', [[24, 1], [0, 1], [0, 1]], 1466, 'GPSTimeStamp'];
        yield 'minute above 59' => ['2025:03:01', [[23, 1], [60, 1], [0, 1]], 1466, 'GPSTimeStamp'];
        yield 'second equal 60' => ['2025:03:01', [[23, 1], [59, 1], [60, 1]], 1466, 'GPSTimeStamp'];
        yield 'second below 0' => ['2025:03:01', [[23, 1], [59, 1], [-1, 1]], 1466, 'GPSTimeStamp'];
        yield 'fractional hour component' => ['2025:03:01', [[109, 10], [20, 1], [0, 1]], 1466, 'GPSTimeStamp'];
        yield 'fractional minute component' => ['2025:03:01', [[10, 1], [205, 10], [0, 1]], 1466, 'GPSTimeStamp'];
    }

    /**
     * Accepts integral GPSAltitudeRef values in the EXIF-defined enum domain.
     */
    #[Test]
    #[DataProvider('provideValidAltitudeRefValues')]
    public function acceptsValidAltitudeReferenceValues(int|string $value, int $expected): void
    {
        self::assertSame($expected, $this->unitConverter->normalizeAltitudeRef($value));
    }

    /**
     * @return iterable<string, array{0:int|string, 1:int}>
     */
    public static function provideValidAltitudeRefValues(): iterable
    {
        yield 'zero integer' => [0, 0];
        yield 'one integer' => [1, 1];
        yield 'two integer' => [2, 2];
        yield 'three integer' => [3, 3];
        yield 'numeric string' => ['1', 1];
    }

    /**
     * Rejects non-integer, fractional, non-numeric, and out-of-domain GPSAltitudeRef values.
     */
    #[Test]
    #[DataProvider('provideInvalidAltitudeRefValues')]
    public function rejectsInvalidAltitudeReferenceValues(int|float|string|ExifRational $value): void
    {
        self::assertNull($this->unitConverter->normalizeAltitudeRef($value));
    }

    /**
     * @return iterable<string, array{0:int|float|string|ExifRational}>
     */
    public static function provideInvalidAltitudeRefValues(): iterable
    {
        yield 'float below one' => [0.4];
        yield 'float midpoint' => [1.5];
        yield 'numeric string 2.1' => ['2.1'];
        yield 'rational value' => [new ExifRational(3, 2)];
        yield 'negative integer' => [-1];
        yield 'above range integer' => [4];
        yield 'above range string' => ['5'];
        yield 'non-numeric string' => ['invalid'];
    }

    /**
     * Verifies that GPSAltitudeRef sign is correctly applied to the altitude value.
     */
    #[Test]
    #[DataProvider('provideAltitudeRefSigns')]
    public function appliesAltitudeRefSign(int $ref, float $expectedAlt): void
    {
        $entries = [
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, $ref),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(500, 1),
            ),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame($ref, $result['alt_ref']);
        self::assertEqualsWithDelta($expectedAlt, $result['alt'], 0.000001);
    }

    /**
     * @return iterable<string, array{0: int, 1: float}>
     */
    public static function provideAltitudeRefSigns(): iterable
    {
        yield 'ref 0 (above sea level)' => [0, 500.0];
        yield 'ref 1 (below sea level)' => [1, -500.0];
        yield 'ref 2 (above ellipsoid)' => [2, 500.0];
        yield 'ref 3 (below ellipsoid)' => [3, -500.0];
    }

    /**
     * Missing GPSAltitudeRef defaults to 0 (above sea level) per EXIF 3.0 §4.6.7.1.6.
     */
    #[Test]
    public function defaultsAltitudeRefToZeroWhenMissing(): void
    {
        $entries = [
            ExifTag::GPS_ALTITUDE => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(250, 1),
            ),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame(0, $result['alt_ref']);
        self::assertEqualsWithDelta(250.0, $result['alt'], 0.000001);
    }

    /**
     * Validates GPSDifferential values: 0 and 1 are accepted, others are nulled.
     */
    #[Test]
    #[DataProvider('provideGpsDifferentialValues')]
    public function validatesGpsDifferential(int $input, ?int $expected): void
    {
        $entries = [
            ExifTag::GPS_DIFFERENTIAL => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, $input),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame($expected, $result['differential']);
    }

    /**
     * @return iterable<string, array{0: int, 1: ?int}>
     */
    public static function provideGpsDifferentialValues(): iterable
    {
        yield 'valid zero (no correction)' => [0, 0];
        yield 'valid one (differential corrected)' => [1, 1];
        yield 'invalid two (out of range)' => [2, null];
    }

    /**
     * Rejects GPS coordinate ref/value pairs that appear without their counterpart.
     *
     * @param array<int, IfdEntry> $entries IFD entries to parse.
     */
    #[Test]
    #[DataProvider('provideCoordinateRefValueMismatches')]
    public function rejectsCoordinateRefValueMismatch(array $entries): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1472);
        $this->expectExceptionMessage('without matching');

        $this->converter->fromIfd(new Ifd($entries));
    }

    /**
     * @return iterable<string, array{0: array<int, IfdEntry>}>
     */
    public static function provideCoordinateRefValueMismatches(): iterable
    {
        yield 'latitude value without ref' => [
            [ExifTag::GPS_LATITUDE => new IfdEntry(ExifTag::GPS_LATITUDE, 10, 3, [[45, 1], [30, 1], [0, 1]])],
        ];

        yield 'longitude value without ref' => [
            [ExifTag::GPS_LONGITUDE => new IfdEntry(ExifTag::GPS_LONGITUDE, 10, 3, [[45, 1], [30, 1], [0, 1]])],
        ];

        yield 'dest latitude value without ref' => [
            [ExifTag::GPS_DEST_LATITUDE => new IfdEntry(ExifTag::GPS_DEST_LATITUDE, 10, 3, [[45, 1], [30, 1], [0, 1]])],
        ];

        yield 'dest longitude value without ref' => [
            [ExifTag::GPS_DEST_LONGITUDE => new IfdEntry(ExifTag::GPS_DEST_LONGITUDE, 10, 3, [[45, 1], [30, 1], [0, 1]])],
        ];

        yield 'latitude ref without value' => [
            [ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N')],
        ];

        yield 'longitude ref without value' => [
            [ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E')],
        ];

        yield 'dest latitude ref without value' => [
            [ExifTag::GPS_DEST_LATITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LATITUDE_REF, 2, 2, 'S')],
        ];

        yield 'dest longitude ref without value' => [
            [ExifTag::GPS_DEST_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LONGITUDE_REF, 2, 2, 'W')],
        ];
    }

    /**
     * Rejects negative rational magnitudes for GPS scalar fields.
     *
     * @param array<int, IfdEntry> $entries         IFD entries to parse.
     * @param int                  $errorCode       Expected ParseError code.
     * @param string               $messageFragment Expected message substring.
     */
    #[Test]
    #[DataProvider('provideNegativeRationalValues')]
    public function rejectsNegativeRationalMagnitude(array $entries, int $errorCode, string $messageFragment): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode($errorCode);
        $this->expectExceptionMessage($messageFragment);

        $this->converter->fromIfd(new Ifd($entries));
    }

    /**
     * @return iterable<string, array{0: array<int, IfdEntry>, 1: int, 2: string}>
     */
    public static function provideNegativeRationalValues(): iterable
    {
        yield 'negative DOP' => [
            [ExifTag::GPS_DOP => new IfdEntry(ExifTag::GPS_DOP, 5, 1, new ExifRational(-1, 1))],
            1469, 'GPSDOP',
        ];

        yield 'negative HPositioningError' => [
            [ExifTag::GPS_H_POSITIONING_ERROR => new IfdEntry(ExifTag::GPS_H_POSITIONING_ERROR, 5, 1, new ExifRational(-1, 1))],
            1468, 'GPSHPositioningError',
        ];

        yield 'negative altitude' => [
            [
                ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
                ExifTag::GPS_ALTITUDE     => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, new ExifRational(-100, 1)),
            ],
            1471, 'GPSAltitude',
        ];
    }

    /**
     * Accepts non-negative GPSHPositioningError values.
     */
    #[Test]
    public function acceptsNonNegativeGpsHPositioningError(): void
    {
        $entries = [
            ExifTag::GPS_H_POSITIONING_ERROR => new IfdEntry(
                ExifTag::GPS_H_POSITIONING_ERROR,
                5,
                1,
                new ExifRational(15, 10),
            ),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertEqualsWithDelta(1.5, $result['h_positioning_error'], 0.000001);
    }

    /**
     * @param list<array{0:int,1:int}> $timeRationals
     */
    private function buildIfdWithDateAndTime(string $dateStamp, array $timeRationals): Ifd
    {
        $timeValues = [];
        foreach ($timeRationals as [$numerator, $denominator]) {
            $timeValues[] = new ExifRational($numerator, $denominator);
        }

        return new Ifd([
            ExifTag::GPS_DATE_STAMP => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 11, $dateStamp),
            ExifTag::GPS_TIME_STAMP => new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                new ExifRationalList($timeValues),
            ),
        ]);
    }
}
