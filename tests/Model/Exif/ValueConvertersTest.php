<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
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
    /**
     * Ensures rational values represented as numerator/denominator pairs or lists are converted to floats.
     */
    #[Test]
    #[DataProvider('provideValidRationals')]
    public function convertsRationalPairsToFloat(ExifRational|ExifRationalList $value, float $expected): void
    {
        self::assertSame($expected, ValueConverters::rationalToFloat($value));
    }

    /**
     * @return iterable<string, array{ExifRational|ExifRationalList, float}>
     */
    public static function provideValidRationals(): iterable
    {
        yield 'positive integer' => [new ExifRational(3, 1), 3.0];
        yield 'fractional value' => [new ExifRational(5, 2), 2.5];
        yield 'list of rationals' => [
            new ExifRationalList([
                new ExifRational(5, 2),
                new ExifRational(3, 1),
            ]),
            2.5,
        ];
    }

    /**
     * Ensures scalar values fall back to float conversion when no rational pair is provided.
     */
    #[Test]
    #[DataProvider('provideScalarInputs')]
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

    /**
     * Ensures invalid values cannot be converted and return null instead.
     */
    #[Test]
    #[DataProvider('provideInvalidInputs')]
    public function returnsNullForInvalidRationalInputs(array|null $value): void
    {
        self::assertNull(ValueConverters::rationalToFloat($value));
    }

    /**
     * @return iterable<string, array{array|null}>
     */
    public static function provideInvalidInputs(): iterable
    {
        yield 'denominator zero' => [new ExifRational(1, 0)];
        yield 'empty numeric list' => [new ExifNumericList([])];
        yield 'string' => ['invalid'];
        yield 'null' => [null];
    }

    /**
     * Ensures GPS coordinates are converted to floats using degree-minute-second rationals with a positive altitude.
     */
    #[Test]
    public function extractsGpsCoordinatesWithPositiveAltitude(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE      => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(51, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(0, 1),
                    new ExifRational(7, 1),
                    new ExifRational(3000, 100),
                ]),
            ),
            ExifTag::GPS_ALTITUDE_REF  => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE      => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(450, 10),
            ),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertEqualsWithDelta(51.5, $result['lat'], 0.000001);
        self::assertEqualsWithDelta(0.125, $result['lon'], 0.000001);
        self::assertEqualsWithDelta(45.0, $result['alt'], 0.000001);
    }

    /**
     * Ensures GPS coordinates honour southern and western references and invert the altitude when required.
     */
    #[Test]
    public function extractsGpsCoordinatesWithNegativeHemisphereAndAltitude(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'S'),
            ExifTag::GPS_LATITUDE      => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(33, 1),
                    new ExifRational(15, 1),
                    new ExifRational(1800, 100),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'W'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(70, 1),
                    new ExifRational(45, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_ALTITUDE_REF  => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 1),
            ExifTag::GPS_ALTITUDE      => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(250, 2),
            ),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertEqualsWithDelta(-33.255, $result['lat'], 0.000001);
        self::assertEqualsWithDelta(-70.75, $result['lon'], 0.000001);
        self::assertEqualsWithDelta(-125.0, $result['alt'], 0.000001);
    }

    /**
     * Ensures missing altitude entries result in a null altitude while still returning latitude and longitude.
     */
    #[Test]
    public function extractsGpsCoordinatesWithoutAltitude(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_LATITUDE_REF  => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE      => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(10, 1),
                    new ExifRational(0, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(20, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertEqualsWithDelta(10.0, $result['lat'], 0.000001);
        self::assertEqualsWithDelta(20.5, $result['lon'], 0.000001);
        self::assertNull($result['alt']);
    }
}
