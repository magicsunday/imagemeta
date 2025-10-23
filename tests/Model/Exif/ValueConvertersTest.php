<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\FlashInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Model\Exif\ValueConverters
 */
#[CoversClass(ValueConverters::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(FlashInfo::class)]
final class ValueConvertersTest extends TestCase
{
    /**
     * Ensures rational values represented as numerator/denominator pairs or lists are converted to floats.
     *
     * @param ExifRational|ExifRationalList $value    The rational value to convert.
     * @param float                         $expected The expected float representation.
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
     *
     * @param int|float $value    The scalar input value.
     * @param float     $expected The expected float representation.
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
     *
     * @param mixed $value The invalid rational input to convert.
     */
    #[Test]
    #[DataProvider('provideInvalidInputs')]
    public function returnsNullForInvalidRationalInputs(mixed $value): void
    {
        self::assertNull(ValueConverters::rationalToFloat($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideInvalidInputs(): iterable
    {
        yield 'denominator zero' => [new ExifRational(1, 0)];
        yield 'empty numeric list' => [new ExifNumericList([])];
        yield 'string' => ['invalid'];
        yield 'null' => [null];
    }

    /**
     * @param mixed       $value    The APEX encoded value.
     * @param float|null  $expected The expected f-number.
     */
    #[Test]
    #[DataProvider('provideApexValues')]
    public function convertsApexValuesToFNumber(mixed $value, ?float $expected): void
    {
        $result = ValueConverters::apexToFNumber($value);

        if ($expected === null) {
            self::assertNull($result);

            return;
        }

        self::assertNotNull($result);
        self::assertEqualsWithDelta($expected, $result, 0.000001);
    }

    /**
     * @return iterable<string, array{mixed, float|null}>
     */
    public static function provideApexValues(): iterable
    {
        yield 'zero apex results in f1' => [new ExifRational(0, 1), 1.0];
        yield 'positive apex rational' => [new ExifRational(5, 1), 2 ** (5 / 2)];
        yield 'numeric string apex' => ['3', 2 ** 1.5];
        yield 'invalid rational' => [new ExifRational(1, 0), null];
    }

    /**
     * @param string|null $ref      The speed reference.
     * @param mixed       $value    The raw speed value.
     * @param float|null  $expected The expected metres per second.
     */
    #[Test]
    #[DataProvider('provideGpsSpeedValues')]
    public function convertsGpsSpeedToMetresPerSecond(?string $ref, mixed $value, ?float $expected): void
    {
        $result = ValueConverters::gpsSpeedToMs($ref, $value);

        if ($expected === null) {
            self::assertNull($result);

            return;
        }

        self::assertNotNull($result);
        self::assertEqualsWithDelta($expected, $result, 0.000001);
    }

    /**
     * @return iterable<string, array{string|null, mixed, float|null}>
     */
    public static function provideGpsSpeedValues(): iterable
    {
        yield 'kilometres per hour' => ['K', new ExifRational(360, 10), 10.0];
        yield 'miles per hour' => ['M', new ExifRational(223, 10), 22.3 * 0.44704];
        yield 'knots' => ['N', new ExifRational(40, 1), 20.577777777777776];
        yield 'string numeric value' => ['K', '54', 15.0];
        yield 'unknown reference' => ['X', new ExifRational(36, 1), null];
        yield 'null reference' => [null, new ExifRational(36, 1), null];
        yield 'invalid rational value' => ['K', new ExifRational(1, 0), null];
    }

    /**
     * Ensures flash bit fields are converted into value objects.
     */
    #[Test]
    public function convertsFlashShortToValueObject(): void
    {
        $info = ValueConverters::flashFromShort(new ExifNumericList([63]));

        self::assertInstanceOf(FlashInfo::class, $info);
        self::assertTrue($info->fired);
        self::assertSame(FlashMode::AUTO, $info->mode);
        self::assertSame(FlashReturn::DETECTED, $info->returnDetection);
        self::assertSame(FlashFunction::ABSENT, $info->functionPresence);
        self::assertFalse($info->redEyeReduction);
    }

    /**
     * Ensures invalid flash values return null to avoid runtime errors.
     */
    #[Test]
    public function returnsNullForInvalidFlashValue(): void
    {
        self::assertNull(ValueConverters::flashFromShort(new ExifRational(1, 0)));
        self::assertNull(ValueConverters::flashFromShort('invalid'));
    }

    /**
     * @param mixed       $value    The raw offset representation.
     * @param string|null $expected The expected canonical offset string.
     */
    #[Test]
    #[DataProvider('provideOffsetStrings')]
    public function normalisesOffsetStrings(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, ValueConverters::parseOffsetString($value));
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function provideOffsetStrings(): iterable
    {
        yield 'already normalised' => ['+01:30', '+01:30'];
        yield 'missing sign with colon' => ['05:45', '+05:45'];
        yield 'compact digits' => ['0530', '+05:30'];
        yield 'decimal hours' => ['-5.5', '-05:30'];
        yield 'utc prefix' => ['UTC-4', '-04:00'];
        yield 'zulu designator' => ['Z', '+00:00'];
        yield 'invalid string' => ['abc', null];
        yield 'out of range' => ['+15:00', null];
    }

    /**
     * @param mixed     $value    The raw offset value.
     * @param int|null  $expected Expected minutes from UTC.
     */
    #[Test]
    #[DataProvider('provideOffsetMinutes')]
    public function convertsOffsetToMinutes(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, ValueConverters::offsetToMinutes($value));
    }

    /**
     * @return iterable<string, array{mixed, int|null}>
     */
    public static function provideOffsetMinutes(): iterable
    {
        yield 'positive offset' => ['+01:30', 90];
        yield 'negative compact' => ['-0330', -210];
        yield 'decimal hours' => ['2.25', 135];
        yield 'invalid input' => ['invalid', null];
    }

    /**
     * Ensures GPS coordinates are converted to floats using degree-minute-second rationals with a positive altitude.
     */
    #[Test]
    public function extractsGpsCoordinatesWithPositiveAltitude(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
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
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(
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
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'S'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
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
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 1),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(
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
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
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
