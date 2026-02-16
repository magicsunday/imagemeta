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
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
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
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifRationalList::class)]
final class GpsConverterTest extends TestCase
{
    private GpsConverter $converter;

    protected function setUp(): void
    {
        $numericConverter  = new NumericConverter();
        $rationalConverter = new RationalConverter($numericConverter);
        $stringConverter   = new StringConverter();

        $this->converter = new GpsConverter(
            $rationalConverter,
            $stringConverter,
            $numericConverter,
        );
    }

    /**
     * Provides valid GPSLatitudeRef values ('N'/'S') with coordinate data.
     * Verifies that valid latitude references produce non-null latitude values.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidLatitudeRef(): void
    {
        $ifd = $this->buildIfdWithRef(ExifTag::GPS_LATITUDE_REF, 'N', ExifTag::GPS_LATITUDE);

        $result = $this->converter->fromIfd($ifd);

        self::assertSame('N', $result['lat_ref']);
        self::assertNotNull($result['lat']);
    }

    /**
     * Supplies an invalid GPSLatitudeRef value ('X') with coordinate data.
     * Verifies that invalid latitude references are nulled per EXIF 3.0 §4.6.7.1.2.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidLatitudeRef(): void
    {
        $ifd = $this->buildIfdWithRef(ExifTag::GPS_LATITUDE_REF, 'X', ExifTag::GPS_LATITUDE);

        $result = $this->converter->fromIfd($ifd);

        self::assertNull($result['lat_ref']);
        self::assertNull($result['lat']);
    }

    /**
     * Provides valid GPSLongitudeRef values ('E'/'W') with coordinate data.
     * Verifies that valid longitude references produce non-null longitude values.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidLongitudeRef(): void
    {
        $ifd = $this->buildIfdWithRef(ExifTag::GPS_LONGITUDE_REF, 'W', ExifTag::GPS_LONGITUDE);

        $result = $this->converter->fromIfd($ifd);

        self::assertSame('W', $result['lon_ref']);
        self::assertNotNull($result['lon']);
    }

    /**
     * Supplies an invalid GPSLongitudeRef value ('Z') with coordinate data.
     * Verifies that invalid longitude references are nulled per EXIF 3.0 §4.6.7.1.4.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidLongitudeRef(): void
    {
        $ifd = $this->buildIfdWithRef(ExifTag::GPS_LONGITUDE_REF, 'Z', ExifTag::GPS_LONGITUDE);

        $result = $this->converter->fromIfd($ifd);

        self::assertNull($result['lon_ref']);
        self::assertNull($result['lon']);
    }

    /**
     * Provides a valid GPSStatus value ('A').
     * Verifies the status is accepted per EXIF 3.0 §4.6.7.1.10.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidGpsStatus(): void
    {
        $entries = [
            ExifTag::GPS_STATUS => new IfdEntry(ExifTag::GPS_STATUS, 2, 2, 'A'),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame('A', $result['status']);
    }

    /**
     * Supplies an invalid GPSStatus value ('X').
     * Verifies the status is nulled per EXIF 3.0 §4.6.7.1.10.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidGpsStatus(): void
    {
        $entries = [
            ExifTag::GPS_STATUS => new IfdEntry(ExifTag::GPS_STATUS, 2, 2, 'X'),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['status']);
    }

    /**
     * Provides a valid GPSMeasureMode value ('3').
     * Verifies the measure mode is accepted per EXIF 3.0 §4.6.7.1.11.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidGpsMeasureMode(): void
    {
        $entries = [
            ExifTag::GPS_MEASURE_MODE => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 2, '3'),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame('3', $result['measure_mode']);
    }

    /**
     * Supplies an invalid GPSMeasureMode value ('1').
     * Verifies the measure mode is nulled per EXIF 3.0 §4.6.7.1.11.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidGpsMeasureMode(): void
    {
        $entries = [
            ExifTag::GPS_MEASURE_MODE => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 2, '1'),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['measure_mode']);
    }

    /**
     * Provides a valid GPSSpeedRef value ('K') with a speed value.
     * Verifies the speed ref is accepted and speed_ms is computed per EXIF 3.0 §4.6.7.1.13.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidGpsSpeedRef(): void
    {
        $entries = [
            ExifTag::GPS_SPEED_REF => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 2, 'K'),
            ExifTag::GPS_SPEED     => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, 36.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame('K', $result['speed_ref']);
        self::assertSame(36.0 / 3.6, $result['speed_ms']);
    }

    /**
     * Supplies an invalid GPSSpeedRef value ('X') with a speed value.
     * Verifies the speed ref and derived speed_ms are nulled per EXIF 3.0 §4.6.7.1.13.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidGpsSpeedRef(): void
    {
        $entries = [
            ExifTag::GPS_SPEED_REF => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 2, 'X'),
            ExifTag::GPS_SPEED     => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, 36.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['speed_ref']);
        self::assertNull($result['speed_ms']);
    }

    /**
     * Supplies a multi-character GPSSpeedRef value ('KM') with a speed value.
     * Verifies reserved codes do not leak into normalized or original reference fields.
     *
     * @return void
     */
    #[Test]
    public function rejectsMultiCharacterGpsSpeedRef(): void
    {
        $entries = [
            ExifTag::GPS_SPEED_REF => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 3, 'KM'),
            ExifTag::GPS_SPEED     => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, 36.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['speed_ref']);
        self::assertNull($result['speed_ms']);
        self::assertNull($result['speed_original_ref']);
    }

    /**
     * Provides a valid GPSTrackRef value ('T') with a track bearing.
     * Verifies the track ref is accepted and bearing is computed per EXIF 3.0 §4.6.7.1.15.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidGpsTrackRef(): void
    {
        $entries = [
            ExifTag::GPS_TRACK_REF => new IfdEntry(ExifTag::GPS_TRACK_REF, 2, 2, 'T'),
            ExifTag::GPS_TRACK     => new IfdEntry(ExifTag::GPS_TRACK, 5, 1, 90.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame('T', $result['track_ref']);
        self::assertSame(90.0, $result['track']);
    }

    /**
     * Supplies an invalid GPSTrackRef value ('X') with a track bearing.
     * Verifies the track ref and derived track bearing are nulled per EXIF 3.0 §4.6.7.1.15.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidGpsTrackRef(): void
    {
        $entries = [
            ExifTag::GPS_TRACK_REF => new IfdEntry(ExifTag::GPS_TRACK_REF, 2, 2, 'X'),
            ExifTag::GPS_TRACK     => new IfdEntry(ExifTag::GPS_TRACK, 5, 1, 90.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['track_ref']);
        self::assertNull($result['track']);
    }

    /**
     * Provides a valid GPSImgDirectionRef value ('M') with a direction angle.
     * Verifies the ref is accepted and direction is computed per EXIF 3.0 §4.6.7.1.17.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidGpsImgDirectionRef(): void
    {
        $entries = [
            ExifTag::GPS_IMG_DIRECTION_REF => new IfdEntry(ExifTag::GPS_IMG_DIRECTION_REF, 2, 2, 'M'),
            ExifTag::GPS_IMG_DIRECTION     => new IfdEntry(ExifTag::GPS_IMG_DIRECTION, 5, 1, 180.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame('M', $result['img_direction_ref']);
        self::assertSame(180.0, $result['img_direction']);
    }

    /**
     * Supplies an invalid GPSImgDirectionRef value ('X') with a direction angle.
     * Verifies the ref and derived direction are nulled per EXIF 3.0 §4.6.7.1.17.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidGpsImgDirectionRef(): void
    {
        $entries = [
            ExifTag::GPS_IMG_DIRECTION_REF => new IfdEntry(ExifTag::GPS_IMG_DIRECTION_REF, 2, 2, 'X'),
            ExifTag::GPS_IMG_DIRECTION     => new IfdEntry(ExifTag::GPS_IMG_DIRECTION, 5, 1, 180.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['img_direction_ref']);
        self::assertNull($result['img_direction']);
    }

    /**
     * Provides a valid GPSDestBearingRef value ('T') with a bearing angle.
     * Verifies the ref is accepted and bearing is computed per EXIF 3.0 §4.6.7.1.24.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidGpsDestBearingRef(): void
    {
        $entries = [
            ExifTag::GPS_DEST_BEARING_REF => new IfdEntry(ExifTag::GPS_DEST_BEARING_REF, 2, 2, 'T'),
            ExifTag::GPS_DEST_BEARING     => new IfdEntry(ExifTag::GPS_DEST_BEARING, 5, 1, 45.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame('T', $result['dest_bearing_ref']);
        self::assertSame(45.0, $result['dest_bearing']);
    }

    /**
     * Supplies an invalid GPSDestBearingRef value ('X') with a bearing angle.
     * Verifies the ref and derived bearing are nulled per EXIF 3.0 §4.6.7.1.24.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidGpsDestBearingRef(): void
    {
        $entries = [
            ExifTag::GPS_DEST_BEARING_REF => new IfdEntry(ExifTag::GPS_DEST_BEARING_REF, 2, 2, 'X'),
            ExifTag::GPS_DEST_BEARING     => new IfdEntry(ExifTag::GPS_DEST_BEARING, 5, 1, 45.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['dest_bearing_ref']);
        self::assertNull($result['dest_bearing']);
    }

    /**
     * Provides a valid GPSDestDistanceRef value ('K') with a distance value.
     * Verifies the ref is accepted and distance_m is computed per EXIF 3.0 §4.6.7.1.26.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidGpsDestDistanceRef(): void
    {
        $entries = [
            ExifTag::GPS_DEST_DISTANCE_REF => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 2, 'K'),
            ExifTag::GPS_DEST_DISTANCE     => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, 10.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame('K', $result['dest_distance_ref']);
        self::assertSame(10_000.0, $result['dest_distance_m']);
    }

    /**
     * Supplies an invalid GPSDestDistanceRef value ('X') with a distance value.
     * Verifies the ref and derived distance are nulled per EXIF 3.0 §4.6.7.1.26.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidGpsDestDistanceRef(): void
    {
        $entries = [
            ExifTag::GPS_DEST_DISTANCE_REF => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 2, 'X'),
            ExifTag::GPS_DEST_DISTANCE     => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, 10.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['dest_distance_ref']);
        self::assertNull($result['dest_distance_m']);
    }

    /**
     * Supplies a multi-character GPSDestDistanceRef value ('NM') with a distance value.
     * Verifies reserved codes do not leak into normalized or original reference fields.
     *
     * @return void
     */
    #[Test]
    public function rejectsMultiCharacterGpsDestDistanceRef(): void
    {
        $entries = [
            ExifTag::GPS_DEST_DISTANCE_REF => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 3, 'NM'),
            ExifTag::GPS_DEST_DISTANCE     => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, 10.0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['dest_distance_ref']);
        self::assertNull($result['dest_distance_m']);
        self::assertNull($result['dest_distance_original_ref']);
    }

    /**
     * Supplies lowercase ref values and verifies they are uppercased and accepted.
     * EXIF 3.0 §4.6.7 ref values are case-insensitive in practice but stored uppercase.
     *
     * @return void
     */
    #[Test]
    public function normalizesLowercaseRefValues(): void
    {
        $entries = [
            ExifTag::GPS_STATUS       => new IfdEntry(ExifTag::GPS_STATUS, 2, 2, 'a'),
            ExifTag::GPS_MEASURE_MODE => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 2, '2'),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame('A', $result['status']);
        self::assertSame('2', $result['measure_mode']);
    }

    /**
     * Provides a GPSLatitude with only 2 components instead of the required 3.
     * Verifies that non-conformant DMS counts are rejected per EXIF 3.0 §4.6.8.
     *
     * @return void
     */
    #[Test]
    public function rejectsLatitudeWithTwoComponents(): void
    {
        $entries = [
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(ExifTag::GPS_LATITUDE, 10, 2, [
                [52, 1],
                [31, 1],
            ]),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['lat']);
    }

    /**
     * Provides a GPSLongitude with 4 components instead of the required 3.
     * Verifies that excess DMS components are rejected per EXIF 3.0 §4.6.8.
     *
     * @return void
     */
    #[Test]
    public function rejectsLongitudeWithFourComponents(): void
    {
        $entries = [
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 10, 4, [
                [13, 1],
                [24, 1],
                [17820, 1000],
                [0, 1],
            ]),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['lon']);
    }

    /**
     * Provides a GPSLatitude with only 1 component instead of the required 3.
     * Verifies that a single-component DMS value is rejected per EXIF 3.0 §4.6.8.
     *
     * @return void
     */
    #[Test]
    public function rejectsLatitudeWithOneComponent(): void
    {
        $entries = [
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'S'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(ExifTag::GPS_LATITUDE, 10, 1, [
                [33, 1],
            ]),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['lat']);
    }

    /**
     * Accepts edge values for capture and destination coordinates.
     *
     * @return void
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
     * Rejects negative DMS components for capture coordinates.
     *
     * @param int                      $refTag
     * @param int                      $valueTag
     * @param string                   $ref
     * @param list<array{0:int,1:int}> $dms
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideNegativeCaptureDmsComponents')]
    public function rejectsNegativeCaptureDmsComponents(int $refTag, int $valueTag, string $ref, array $dms): void
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
    public static function provideNegativeCaptureDmsComponents(): iterable
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
    }

    /**
     * Rejects negative DMS components for destination coordinates.
     *
     * @return void
     */
    #[Test]
    public function rejectsNegativeDestinationDmsComponents(): void
    {
        $entries = [
            ExifTag::GPS_DEST_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_DEST_LATITUDE_REF, 2, 2, 'S'),
            ExifTag::GPS_DEST_LATITUDE      => new IfdEntry(ExifTag::GPS_DEST_LATITUDE, 10, 3, [[12, 1], [-1, 1], [0, 1]]),
            ExifTag::GPS_DEST_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_DEST_LONGITUDE     => new IfdEntry(ExifTag::GPS_DEST_LONGITUDE, 10, 3, [[12, 1], [0, 1], [-1, 1]]),
        ];

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1467);
        $this->expectExceptionMessage('non-negative');

        $this->converter->fromIfd(new Ifd($entries));
    }

    /**
     * Rejects capture latitude values above +90°.
     *
     * @return void
     */
    #[Test]
    public function rejectsLatitudeAboveNinetyDegrees(): void
    {
        $entries = [
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(ExifTag::GPS_LATITUDE, 10, 3, [[91, 1], [0, 1], [0, 1]]),
        ];

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1463);
        $this->expectExceptionMessage('outside the valid latitude range');

        $this->converter->fromIfd(new Ifd($entries));
    }

    /**
     * Rejects capture longitude values below -180°.
     *
     * @return void
     */
    #[Test]
    public function rejectsLongitudeBelowMinusOneHundredEightyDegrees(): void
    {
        $entries = [
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'W'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 10, 3, [[181, 1], [0, 1], [0, 1]]),
        ];

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1464);
        $this->expectExceptionMessage('outside the valid longitude range');

        $this->converter->fromIfd(new Ifd($entries));
    }

    /**
     * Rejects destination latitude values below -90°.
     *
     * @return void
     */
    #[Test]
    public function rejectsDestinationLatitudeBelowMinusNinetyDegrees(): void
    {
        $entries = [
            ExifTag::GPS_DEST_LATITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LATITUDE_REF, 2, 2, 'S'),
            ExifTag::GPS_DEST_LATITUDE     => new IfdEntry(ExifTag::GPS_DEST_LATITUDE, 10, 3, [[91, 1], [0, 1], [0, 1]]),
        ];

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1463);
        $this->expectExceptionMessage('outside the valid latitude range');

        $this->converter->fromIfd(new Ifd($entries));
    }

    /**
     * Combines valid GPSDateStamp and GPSTimeStamp values into a UTC timestamp.
     *
     * @return void
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
     * Rejects invalid calendar dates in GPSDateStamp.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidGpsCalendarDate(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1465);
        $this->expectExceptionMessage('GPSDateStamp');

        $this->converter->fromIfd(
            $this->buildIfdWithDateAndTime('2025:02:30', [[12, 1], [34, 1], [56, 1]]),
        );
    }

    /**
     * Rejects GPSTimeStamp components outside UTC ranges.
     *
     * @param list<array{0:int,1:int}> $timeRationals
     */
    #[Test]
    #[DataProvider('provideInvalidGpsTimeStampRanges')]
    public function rejectsOutOfRangeGpsTimeStampComponents(array $timeRationals): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1466);
        $this->expectExceptionMessage('GPSTimeStamp');

        $this->converter->fromIfd(
            $this->buildIfdWithDateAndTime('2025:03:01', $timeRationals),
        );
    }

    /**
     * Accepts fractional seconds in valid range for GPSTimeStamp.
     *
     * @return void
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
     * @return iterable<string, array{0:list<array{0:int,1:int}>}>
     */
    public static function provideInvalidGpsTimeStampRanges(): iterable
    {
        yield 'hour above 23' => [[[24, 1], [0, 1], [0, 1]]];
        yield 'minute above 59' => [[[23, 1], [60, 1], [0, 1]]];
        yield 'second equal 60' => [[[23, 1], [59, 1], [60, 1]]];
        yield 'second below 0' => [[[23, 1], [59, 1], [-1, 1]]];
        yield 'fractional hour component' => [[[109, 10], [20, 1], [0, 1]]];
        yield 'fractional minute component' => [[[10, 1], [205, 10], [0, 1]]];
    }

    /**
     * Accepts integral GPSAltitudeRef values in the EXIF-defined enum domain.
     *
     * @param int|string $value
     * @param int        $expected
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideValidAltitudeRefValues')]
    public function acceptsValidAltitudeReferenceValues(int|string $value, int $expected): void
    {
        self::assertSame($expected, $this->converter->normaliseAltitudeRef($value));
    }

    /**
     * Rejects fractional GPSAltitudeRef values instead of coercing them into enum codes.
     *
     * @param float|string|ExifRational $value
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideFractionalAltitudeRefValues')]
    public function rejectsFractionalAltitudeReferenceValues(float|string|ExifRational $value): void
    {
        self::assertNull($this->converter->normaliseAltitudeRef($value));
    }

    /**
     * Rejects non-numeric GPSAltitudeRef text values.
     *
     * @return void
     */
    #[Test]
    public function rejectsNonNumericAltitudeReferenceValue(): void
    {
        self::assertNull($this->converter->normaliseAltitudeRef('invalid'));
    }

    /**
     * Rejects out-of-domain GPSAltitudeRef values outside EXIF's 0..3 range.
     *
     * @param int|string $value
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideOutOfDomainAltitudeRefValues')]
    public function rejectsOutOfDomainAltitudeReferenceValues(int|string $value): void
    {
        self::assertNull($this->converter->normaliseAltitudeRef($value));
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
     * @return iterable<string, array{0:float|string|ExifRational}>
     */
    public static function provideFractionalAltitudeRefValues(): iterable
    {
        yield 'float below one' => [0.4];
        yield 'float midpoint' => [1.5];
        yield 'numeric string' => ['2.1'];
        yield 'rational value' => [new ExifRational(3, 2)];
    }

    /**
     * @return iterable<string, array{0:int|string}>
     */
    public static function provideOutOfDomainAltitudeRefValues(): iterable
    {
        yield 'negative integer' => [-1];
        yield 'above range integer' => [4];
        yield 'above range string' => ['5'];
    }

    /**
     * Provides a valid GPSDifferential value (0).
     * Verifies no-correction is accepted per EXIF 3.0 §4.6.7.1.31.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidGpsDifferentialZero(): void
    {
        $entries = [
            ExifTag::GPS_DIFFERENTIAL => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, 0),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame(0, $result['differential']);
    }

    /**
     * Provides a valid GPSDifferential value (1).
     * Verifies differential-corrected is accepted per EXIF 3.0 §4.6.7.1.31.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidGpsDifferentialOne(): void
    {
        $entries = [
            ExifTag::GPS_DIFFERENTIAL => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, 1),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertSame(1, $result['differential']);
    }

    /**
     * Supplies an invalid GPSDifferential value (2).
     * Verifies out-of-range values are nulled per EXIF 3.0 §4.6.7.1.31.
     *
     * @return void
     */
    #[Test]
    public function rejectsInvalidGpsDifferential(): void
    {
        $entries = [
            ExifTag::GPS_DIFFERENTIAL => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, 2),
        ];

        $result = $this->converter->fromIfd(new Ifd($entries));

        self::assertNull($result['differential']);
    }

    /**
     * Accepts non-negative GPSHPositioningError values.
     *
     * @return void
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
     * Rejects negative GPSHPositioningError values.
     *
     * @return void
     */
    #[Test]
    public function rejectsNegativeGpsHPositioningError(): void
    {
        $entries = [
            ExifTag::GPS_H_POSITIONING_ERROR => new IfdEntry(
                ExifTag::GPS_H_POSITIONING_ERROR,
                5,
                1,
                new ExifRational(-1, 1),
            ),
        ];

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1468);
        $this->expectExceptionMessage('GPSHPositioningError');

        $this->converter->fromIfd(new Ifd($entries));
    }

    /**
     * Builds an IFD containing a GPS reference tag and matching coordinate data.
     *
     * @param int    $refTag   The GPS reference tag constant (e.g. ExifTag::GPS_LATITUDE_REF).
     * @param string $refValue The reference value (e.g. 'N', 'E', 'X').
     * @param int    $coordTag The GPS coordinate tag constant (e.g. ExifTag::GPS_LATITUDE).
     */
    private function buildIfdWithRef(int $refTag, string $refValue, int $coordTag): Ifd
    {
        $entries = [
            $refTag   => new IfdEntry($refTag, 2, 2, $refValue),
            $coordTag => new IfdEntry($coordTag, 10, 3, [
                [52, 1],
                [31, 1],
                [12000, 1000],
            ]),
        ];

        return new Ifd($entries);
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
