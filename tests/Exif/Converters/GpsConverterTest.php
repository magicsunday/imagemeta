<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
