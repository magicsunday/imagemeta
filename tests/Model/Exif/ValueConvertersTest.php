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
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Model\Exif\ValueConverters
 */
#[CoversClass(ValueConverters::class)]
final class ValueConvertersTest extends TestCase
{
    #[Test]
    #[DataProvider('provideValidRationals')]
    /**
     * Ensures rational values represented as numerator/denominator pairs or lists are converted to floats.
     *
     * @param array{0:int,1:int}|list<array{0:int,1:int}> $value
     */
    public function convertsRationalPairsToFloat(array $value, float $expected): void
    {
        self::assertSame($expected, ValueConverters::rationalToFloat($value));
    }

    /**
     * @return iterable<string, array{array{0:int,1:int}|list<array{0:int,1:int}>, float}>
     */
    public static function provideValidRationals(): iterable
    {
        yield 'positive integer' => [[3, 1], 3.0];
        yield 'fractional value' => [[5, 2], 2.5];
        yield 'list of rationals' => [[[5, 2], [3, 1]], 2.5];
    }

    #[Test]
    #[DataProvider('provideScalarInputs')]
    /**
     * Ensures scalar values fall back to float conversion when no rational pair is provided.
     */
    public function convertsScalarsToFloat(int|float $value, float $expected): void
    {
        self::assertSame($expected, ValueConverters::rationalToFloat($value));
    }

    /**
     * @return iterable<string, array{int|float, float}>
     */
    public static function provideScalarInputs(): iterable
    {
        yield 'integer' => [42, 42.0];
        yield 'float' => [3.1415, 3.1415];
    }

    #[Test]
    #[DataProvider('provideInvalidInputs')]
    /**
     * Ensures invalid values cannot be converted and return null instead.
     */
    public function returnsNullForInvalidRationalInputs(array|null $value): void
    {
        self::assertNull(ValueConverters::rationalToFloat($value));
    }

    /**
     * @return iterable<string, array{array|null}>
     */
    public static function provideInvalidInputs(): iterable
    {
        yield 'denominator zero' => [[[1, 0]]];
        yield 'not enough elements' => [[[1]]];
        yield 'non rational list' => [[[1, 2, 3]]];
        yield 'null' => [null];
    }

    #[Test]
    /**
     * Ensures GPS coordinates are converted to floats using degree-minute-second rationals with a positive altitude.
     */
    public function extractsGpsCoordinatesWithPositiveAltitude(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE      => new IfdEntry(ExifTag::GPS_LATITUDE, 5, 3, [[51, 1], [30, 1], [0, 1]]),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 5, 3, [[0, 1], [7, 1], [3000, 100]]),
            ExifTag::GPS_ALTITUDE_REF  => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE      => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, [450, 10]),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertEqualsWithDelta(51.5, $result['lat'], 0.000001);
        self::assertEqualsWithDelta(0.125, $result['lon'], 0.000001);
        self::assertEqualsWithDelta(45.0, $result['alt'], 0.000001);
    }

    #[Test]
    /**
     * Ensures GPS coordinates honour southern and western references and invert the altitude when required.
     */
    public function extractsGpsCoordinatesWithNegativeHemisphereAndAltitude(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'S'),
            ExifTag::GPS_LATITUDE      => new IfdEntry(ExifTag::GPS_LATITUDE, 5, 3, [[33, 1], [15, 1], [1800, 100]]),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'W'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 5, 3, [[70, 1], [45, 1], [0, 1]]),
            ExifTag::GPS_ALTITUDE_REF  => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 1),
            ExifTag::GPS_ALTITUDE      => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, [250, 2]),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertEqualsWithDelta(-33.255, $result['lat'], 0.000001);
        self::assertEqualsWithDelta(-70.75, $result['lon'], 0.000001);
        self::assertEqualsWithDelta(-125.0, $result['alt'], 0.000001);
    }

    #[Test]
    /**
     * Ensures missing altitude entries result in a null altitude while still returning latitude and longitude.
     */
    public function extractsGpsCoordinatesWithoutAltitude(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE      => new IfdEntry(ExifTag::GPS_LATITUDE, 5, 3, [[10, 1], [0, 1], [0, 1]]),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(ExifTag::GPS_LONGITUDE, 5, 3, [[20, 1], [30, 1], [0, 1]]),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertEqualsWithDelta(10.0, $result['lat'], 0.000001);
        self::assertEqualsWithDelta(20.5, $result['lon'], 0.000001);
        self::assertNull($result['alt']);
    }
}
