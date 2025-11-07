<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use DateTimeImmutable;
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
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\ExifFlash;
use MagicSunday\ImageMeta\Value\FlashInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;
use function pack;
use function strlen;
use function substr;

#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(FlashInfo::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifFlash::class)]
#[CoversClass(ValueConverters::class)]
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
     * @param ExifRational|ExifNumericList|string|null $value The invalid rational input to convert.
     */
    #[Test]
    #[DataProvider('provideInvalidInputs')]
    public function returnsNullForInvalidRationalInputs(ExifRational|ExifNumericList|string|null $value): void
    {
        self::assertNull(ValueConverters::rationalToFloat($value));
    }

    /**
     * @return iterable<string, array{ExifRational|ExifNumericList|string|null}>
     */
    public static function provideInvalidInputs(): iterable
    {
        yield 'denominator zero' => [new ExifRational(1, 0)];
        yield 'empty numeric list' => [new ExifNumericList([])];
        yield 'string' => ['invalid'];
        yield 'null' => [null];
    }

    /**
     * @param ExifRational|string $value    The APEX encoded value.
     * @param float|null          $expected The expected f-number.
     */
    #[Test]
    #[DataProvider('provideApexValues')]
    public function convertsApexValuesToFNumber(ExifRational|string $value, ?float $expected): void
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
     * Ensures APEX shutter speed values are converted into seconds.
     */
    #[Test]
    public function convertsApexShutterSpeedToSeconds(): void
    {
        self::assertEqualsWithDelta(1 / 128, ValueConverters::apexShutterSpeedToSeconds(new ExifRational(7, 1)), 0.0000001);
        self::assertEqualsWithDelta(1.0, ValueConverters::apexShutterSpeedToSeconds(new ExifRational(0, 1)), 0.0000001);
        self::assertNull(ValueConverters::apexShutterSpeedToSeconds(new ExifRational(1, 0)));
    }

    /**
     * Ensures components configuration values convert to labelled channels.
     */
    #[Test]
    public function formatsComponentsConfiguration(): void
    {
        $values = new ExifNumericList([1, 2, 3, 0]);

        self::assertSame(['Y', 'Cb', 'Cr', '-'], ValueConverters::componentsConfigurationLabels($values));
        self::assertSame('Y Cb Cr -', ValueConverters::componentsConfigurationDescription($values));
        self::assertNull(ValueConverters::componentsConfigurationLabels(new ExifNumericList([])));
    }

    /**
     * Ensures components configuration payloads normalise to integer component lists.
     */
    #[Test]
    public function normalisesComponentsConfigurationPayloads(): void
    {
        $values = new ExifNumericList([1, 2, 3, 0]);

        self::assertSame([1, 2, 3, 0], ValueConverters::componentsConfiguration($values));
        self::assertSame([4, 5], ValueConverters::componentsConfiguration([4, 5]));
        self::assertNull(ValueConverters::componentsConfiguration(new ExifNumericList([])));
        self::assertNull(ValueConverters::componentsConfiguration(null));
    }

    /**
     * Ensures maker note safety flags convert to booleans.
     */
    #[Test]
    public function convertsMakerNoteSafetyFlags(): void
    {
        self::assertTrue(ValueConverters::makerNoteSafety(1));
        self::assertFalse(ValueConverters::makerNoteSafety(0));
        self::assertTrue(ValueConverters::makerNoteSafety(new ExifNumericList([1])));
        self::assertNull(ValueConverters::makerNoteSafety(new ExifNumericList([])));
        self::assertNull(ValueConverters::makerNoteSafety('invalid'));
    }

    /**
     * @return iterable<string, array{ExifRational|string|null, float|null}>
     */
    public static function provideApexValues(): iterable
    {
        yield 'zero apex results in f1' => [new ExifRational(0, 1), 1.0];
        yield 'positive apex rational' => [new ExifRational(5, 1), 2 ** (5 / 2)];
        yield 'numeric string apex' => ['3', 2 ** 1.5];
        yield 'invalid rational' => [new ExifRational(1, 0), null];
    }

    /**
     * @param ExifRational|string|null $value    Raw battery level value.
     * @param float|null               $expected Normalised percentage value.
     */
    #[Test]
    #[DataProvider('provideBatteryLevelValues')]
    public function normalisesBatteryLevelToPercent(ExifRational|string|null $value, ?float $expected): void
    {
        self::assertSame($expected, ValueConverters::batteryLevelToPercent($value));
    }

    /**
     * @return iterable<string, array{ExifRational|string|null, float|null}>
     */
    public static function provideBatteryLevelValues(): iterable
    {
        yield 'rational fraction' => [new ExifRational(1, 2), 50.0];
        yield 'rational percent' => [new ExifRational(75, 1), 75.0];
        yield 'string fraction' => ['1/4', 25.0];
        yield 'string percent' => ['80%', 80.0];
        yield 'ratio string' => ['0.2', 20.0];
        yield 'numeric percent string' => ['55.5', 55.5];
        yield 'invalid string' => ['battery low', null];
        yield 'empty string' => ['', null];
        yield 'null value' => [null, null];
    }

    /**
     * @param string|null         $ref      The speed reference.
     * @param ExifRational|string $value    The raw speed value.
     * @param float|null          $expected The expected metres per second.
     */
    #[Test]
    #[DataProvider('provideGpsSpeedValues')]
    public function convertsGpsSpeedToMetresPerSecond(?string $ref, ExifRational|string $value, ?float $expected): void
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
     * @return iterable<string, array{string|null, ExifRational|string, float|null}>
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
        self::assertSame(FlashReturn::RETURN_DETECTED, $info->returnDetection);
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
     * @param int|float|string|ExifRational|ExifRationalList|null $value    The raw offset representation.
     * @param string|null                                         $expected The expected canonical offset string.
     */
    #[Test]
    #[DataProvider('provideOffsetStrings')]
    public function normalisesOffsetStrings(int|float|string|ExifRational|ExifRationalList|null $value, ?string $expected): void
    {
        self::assertSame($expected, ValueConverters::parseOffsetString($value));
    }

    /**
     * @return iterable<string, array{int|float|string|ExifRational|ExifRationalList|null, string|null}>
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
     * @param int|float|string|ExifRational|ExifRationalList|null $value    The raw offset value.
     * @param int|null                                            $expected Expected minutes from UTC.
     */
    #[Test]
    #[DataProvider('provideOffsetMinutes')]
    public function convertsOffsetToMinutes(int|float|string|ExifRational|ExifRationalList|null $value, ?int $expected): void
    {
        self::assertSame($expected, ValueConverters::offsetToMinutes($value));
    }

    /**
     * @return iterable<string, array{int|float|string|ExifRational|ExifRationalList|null, int|null}>
     */
    public static function provideOffsetMinutes(): iterable
    {
        yield 'positive offset' => ['+01:30', 90];
        yield 'negative compact' => ['-0330', -210];
        yield 'decimal hours' => ['2.25', 135];
        yield 'srational positive' => [new ExifRational(11, 2), 330];
        yield 'srational list negative' => [
            new ExifRationalList([
                new ExifRational(-11, 2),
            ]),
            -330,
        ];
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

    #[Test]
    public function handlesGpsCoordinatesWithNumericListComponents(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 1, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifNumericList([40.0, 30.0, 15.0]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 1, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifNumericList([7.0, 45.0, 30.0]),
            ),
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifNumericList([250.0]),
            ),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertEqualsWithDelta(40.504166, $result['lat'] ?? 0.0, 1e-6);
        self::assertEqualsWithDelta(7.758333, $result['lon'] ?? 0.0, 1e-6);
        self::assertEqualsWithDelta(250.0, $result['alt'] ?? 0.0, 1e-6);
    }

    #[Test]
    public function altitudeReferenceAcceptsStringFlag(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 1, 'S'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(33, 1),
                    new ExifRational(0, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 1, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(18, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 2, 1, '1'),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(120, 1),
            ),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertEqualsWithDelta(-33.0, $result['lat'] ?? 0.0, 1e-6);
        self::assertEqualsWithDelta(18.5, $result['lon'] ?? 0.0, 1e-6);
        self::assertEqualsWithDelta(-120.0, $result['alt'] ?? 0.0, 1e-6);
        self::assertSame(1, $result['alt_ref']);
    }

    #[Test]
    public function returnsNullForGpsCoordinateWithInvalidSeconds(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 1, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(12, 1),
                    new ExifRational(34, 1),
                    new ExifRational(1, 0),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 1, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(56, 1),
                    new ExifRational(0, 1),
                    new ExifRational(0, 1),
                ]),
            ),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertNull($result['lat']);
        self::assertEqualsWithDelta(56.0, $result['lon'] ?? 0.0, 1e-6);
    }

    /**
     * Ensures EXIF 3.0 GPS tags are decoded into a rich metadata map including temporal and navigation fields.
     */
    #[Test]
    public function extractsExtendedGpsMetadata(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_VERSION_ID   => new IfdEntry(ExifTag::GPS_VERSION_ID, 2, 9, '3.0.0.0' . chr(0)),
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
                    new ExifRational(8, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, new ExifRational(150, 1)),
            ExifTag::GPS_TIME_STAMP   => new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(12, 1),
                    new ExifRational(34, 1),
                    new ExifRational(56789, 1000),
                ]),
            ),
            ExifTag::GPS_DATE_STAMP        => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 10, '2024:05:06'),
            ExifTag::GPS_SATELLITES        => new IfdEntry(ExifTag::GPS_SATELLITES, 2, 2, '05'),
            ExifTag::GPS_STATUS            => new IfdEntry(ExifTag::GPS_STATUS, 2, 1, 'A'),
            ExifTag::GPS_MEASURE_MODE      => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 1, '3'),
            ExifTag::GPS_DOP               => new IfdEntry(ExifTag::GPS_DOP, 5, 1, new ExifRational(25, 10)),
            ExifTag::GPS_SPEED_REF         => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 1, 'K'),
            ExifTag::GPS_SPEED             => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, new ExifRational(72000, 1000)),
            ExifTag::GPS_TRACK_REF         => new IfdEntry(ExifTag::GPS_TRACK_REF, 2, 1, 'T'),
            ExifTag::GPS_TRACK             => new IfdEntry(ExifTag::GPS_TRACK, 5, 1, new ExifRational(12345, 100)),
            ExifTag::GPS_IMG_DIRECTION_REF => new IfdEntry(ExifTag::GPS_IMG_DIRECTION_REF, 2, 1, 'M'),
            ExifTag::GPS_IMG_DIRECTION     => new IfdEntry(ExifTag::GPS_IMG_DIRECTION, 5, 1, new ExifRational(2500, 10)),
            ExifTag::GPS_MAP_DATUM         => new IfdEntry(ExifTag::GPS_MAP_DATUM, 2, 6, 'WGS-84'),
            ExifTag::GPS_DEST_LATITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LATITUDE_REF, 2, 1, 'N'),
            ExifTag::GPS_DEST_LATITUDE     => new IfdEntry(
                ExifTag::GPS_DEST_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(41, 1),
                    new ExifRational(0, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_DEST_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LONGITUDE_REF, 2, 1, 'E'),
            ExifTag::GPS_DEST_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_DEST_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(8, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_DEST_BEARING_REF    => new IfdEntry(ExifTag::GPS_DEST_BEARING_REF, 2, 1, 'T'),
            ExifTag::GPS_DEST_BEARING        => new IfdEntry(ExifTag::GPS_DEST_BEARING, 5, 1, new ExifRational(123, 1)),
            ExifTag::GPS_DEST_DISTANCE_REF   => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 1, 'K'),
            ExifTag::GPS_DEST_DISTANCE       => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, new ExifRational(42, 1)),
            ExifTag::GPS_PROCESSING_METHOD   => new IfdEntry(ExifTag::GPS_PROCESSING_METHOD, 7, 11, "ASCII\0\0\0NETWORK"),
            ExifTag::GPS_AREA_INFORMATION    => new IfdEntry(ExifTag::GPS_AREA_INFORMATION, 7, 13, "ASCII\0\0\0AreaName"),
            ExifTag::GPS_DIFFERENTIAL        => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, 2),
            ExifTag::GPS_H_POSITIONING_ERROR => new IfdEntry(ExifTag::GPS_H_POSITIONING_ERROR, 5, 1, new ExifRational(15, 10)),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertEqualsWithDelta(51.5, $result['lat'], 0.000001);
        self::assertEqualsWithDelta(8.5, $result['lon'], 0.000001);
        self::assertEqualsWithDelta(150.0, $result['alt'], 0.000001);
        self::assertSame('3.0.0.0', $result['version']);
        self::assertSame('3.0.0.0' . chr(0), $result['version_raw']);
        self::assertSame('05', $result['satellites']);
        self::assertSame('A', $result['status']);
        self::assertSame('3', $result['measure_mode']);
        self::assertEqualsWithDelta(2.5, $result['dop'], 0.000001);
        self::assertSame('K', $result['speed_ref']);
        self::assertEqualsWithDelta(20.0, $result['speed_ms'], 0.000001);
        self::assertSame('K', $result['speed_original_ref']);
        self::assertEqualsWithDelta(72.0, $result['speed_original'], 0.000001);
        self::assertSame('T', $result['track_ref']);
        self::assertEqualsWithDelta(123.45, $result['track'], 0.000001);
        self::assertSame('M', $result['img_direction_ref']);
        self::assertEqualsWithDelta(250.0, $result['img_direction'], 0.000001);
        self::assertSame('WGS-84', $result['map_datum']);
        self::assertSame('N', $result['dest_lat_ref']);
        self::assertEqualsWithDelta(41.0, $result['dest_lat'], 0.000001);
        self::assertSame('E', $result['dest_lon_ref']);
        self::assertEqualsWithDelta(8.5, $result['dest_lon'], 0.000001);
        self::assertSame('T', $result['dest_bearing_ref']);
        self::assertEqualsWithDelta(123.0, $result['dest_bearing'], 0.000001);
        self::assertSame('K', $result['dest_distance_ref']);
        self::assertEqualsWithDelta(42000.0, $result['dest_distance_m'], 0.000001);
        self::assertSame('K', $result['dest_distance_original_ref']);
        self::assertEqualsWithDelta(42.0, $result['dest_distance_original'], 0.000001);
        self::assertSame('NETWORK', $result['processing_method']);
        self::assertSame('2024:05:06', $result['date_raw']);
        self::assertSame('AreaName', $result['area_information']);
        self::assertSame('2024-05-06', $result['date']);
        self::assertSame('12:34:56.789', $result['time']);

        $timestamp = $result['timestamp'];
        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame('2024-05-06T12:34:56+00:00', $timestamp->format(DATE_ATOM));
        self::assertSame('12:34:56.789000', $timestamp->format('H:i:s.u'));

        self::assertSame(2, $result['differential']);
        self::assertEqualsWithDelta(1.5, $result['h_positioning_error'], 0.000001);
    }

    #[Test]
    public function decodesGpsUndefinedStringsWithEncodings(): void
    {
        $unicodePayload = "UNICODE\0" . pack('v*', 0x6E2C, 0x4F4D, 0x65B9, 0x5F0F) . "\0\0";
        $jisPayload     = "JIS\0\0\0\0\0" . pack('C*', 0x93, 0x8C, 0x8B, 0x9E) . "\0";

        $gps = new Ifd([
            ExifTag::GPS_PROCESSING_METHOD => new IfdEntry(
                ExifTag::GPS_PROCESSING_METHOD,
                7,
                strlen($unicodePayload),
                $unicodePayload,
            ),
            ExifTag::GPS_AREA_INFORMATION => new IfdEntry(
                ExifTag::GPS_AREA_INFORMATION,
                7,
                strlen($jisPayload),
                $jisPayload,
            ),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertSame('測位方式', $result['processing_method']);
        self::assertSame('東京', $result['area_information']);
    }

    #[Test]
    public function returnsNullWhenGpsUndefinedStringEmptyAfterDecoding(): void
    {
        $payload = "UNICODE\0\0\0";

        $gps = new Ifd([
            ExifTag::GPS_PROCESSING_METHOD => new IfdEntry(
                ExifTag::GPS_PROCESSING_METHOD,
                7,
                strlen($payload),
                $payload,
            ),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertNull($result['processing_method']);
    }

    #[Test]
    public function formatsGpsVersionFromNumericList(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_VERSION_ID => new IfdEntry(ExifTag::GPS_VERSION_ID, 1, 4, [2, 3, 4, 5]),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertSame('2.3.4.5', $result['version']);
        self::assertNull($result['version_raw']);
    }

    #[Test]
    public function defaultsGpsVersionWhenEntryMissing(): void
    {
        $gps = new Ifd([]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertSame('2.0.0.0', $result['version']);
        self::assertNull($result['version_raw']);
    }

    #[Test]
    public function defaultsGpsVersionWhenStringPayloadEmpty(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_VERSION_ID => new IfdEntry(ExifTag::GPS_VERSION_ID, 2, 4, "\0\0\0\0"),
        ]);

        $result = ValueConverters::gpsFromIfd($gps);

        self::assertSame('2.0.0.0', $result['version']);
        self::assertSame("\0\0\0\0", $result['version_raw']);
    }

    #[Test]
    public function decodeSpatialFrequencyResponseReturnsLabels(): void
    {
        $payload = pack('n', 1) . pack('n', 1);
        $payload .= "Alpha\0";
        $payload .= "Beta\0";
        $payload .= $this->packSrational(1, 1);

        $result = ValueConverters::decodeSpatialFrequencyResponse($payload);

        self::assertNotNull($result);
        self::assertSame(['Alpha'], $result['labels']['columns']);
        self::assertSame(['Beta'], $result['labels']['rows']);
    }

    #[Test]
    public function decodeSpatialFrequencyResponseParsesTable(): void
    {
        $payload = $this->buildSpatialFrequencyResponsePayload();

        $result = ValueConverters::decodeSpatialFrequencyResponse($payload);

        self::assertNotNull($result);
        self::assertSame(3, $result['columns']);
        self::assertSame(2, $result['rows']);
        self::assertSame(['10lp/mm', '20lp/mm', '40lp/mm'], $result['labels']['columns']);
        self::assertSame(['Luminance', 'Chrominance'], $result['labels']['rows']);
        self::assertEqualsWithDelta(0.9, $result['values'][0][0] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.75, $result['values'][0][1] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.6, $result['values'][0][2] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.85, $result['values'][1][0] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.7, $result['values'][1][1] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.55, $result['values'][1][2] ?? 0.0, 0.0001);
    }

    #[Test]
    public function decodeSpatialFrequencyResponseRejectsInvalidPayload(): void
    {
        $payload = substr($this->buildSpatialFrequencyResponsePayload(), 0, 8);

        self::assertNull(ValueConverters::decodeSpatialFrequencyResponse($payload));
    }

    #[Test]
    public function convertsRationalsAndApexValues(): void
    {
        self::assertSame(0.5, ValueConverters::rationalToFloat([1, 2]));
        self::assertSame(2.8284271247461903, ValueConverters::apexToFNumber(3.0));
    }

    #[Test]
    public function normalisesExifVersionAndFlash(): void
    {
        self::assertSame('1.00', ValueConverters::toExifVersion('0100'));
        self::assertSame('1.00', ValueConverters::toExifVersion("0100\0\0"));
        self::assertSame('2.00', ValueConverters::toExifVersion('0200'));
        self::assertSame('2.20', ValueConverters::toExifVersion('0220'));
        self::assertSame('2.31', ValueConverters::toExifVersion('0231'));
        self::assertSame('3.00', ValueConverters::toExifVersion('0300'));
        self::assertSame('Exif', ValueConverters::toExifVersion("Exif\0\0"));
        self::assertNull(ValueConverters::toExifVersion('0240'));
        self::assertNull(ValueConverters::toExifVersion("\x01\x02\x03\x04"));

        $flash = ValueConverters::flashFromShort(0x59);
        self::assertInstanceOf(FlashInfo::class, $flash);
        self::assertTrue($flash->fired);
        self::assertSame(FlashMode::AUTO, $flash->mode);
        self::assertSame(FlashReturn::NO_STROBE_DETECTION, $flash->returnDetection);
    }

    #[Test]
    public function normalisesOffsetsAndSubjectAreas(): void
    {
        self::assertSame('+01:00', ValueConverters::parseOffset('+01:00')?->getName());
        self::assertSame('+01:00', ValueConverters::parseOffset('+0100')?->getName());
        self::assertSame('+01:00', ValueConverters::parseOffset('+1')?->getName());
        self::assertSame('UTC', ValueConverters::parseOffset('UTC')?->getName());
        self::assertNull(ValueConverters::parseOffset('+15:00'));
        self::assertNull(ValueConverters::parseOffset('+01:61'));

        self::assertSame(
            ['x' => 10, 'y' => 20, 'w' => null, 'h' => null],
            ValueConverters::subjectAreaToRect([10, 20]),
        );
        self::assertSame(
            ['x' => 75, 'y' => 95, 'w' => 50, 'h' => 50],
            ValueConverters::subjectAreaToRect([100, 120, 25]),
        );
        self::assertSame(
            ['x' => 10, 'y' => 20, 'w' => 30, 'h' => 40],
            ValueConverters::subjectAreaToRect([10, 20, 30, 40]),
        );
        self::assertNull(ValueConverters::subjectAreaToRect([10]));
        self::assertNull(ValueConverters::subjectAreaToRect([10, 20, -5]));
        self::assertNull(ValueConverters::subjectAreaToRect(['a', 'b']));
    }

    #[Test]
    public function parsesSamplingAndChromaticities(): void
    {
        self::assertSame([2, 2], ValueConverters::ycbcrSubSamplingToPair('2 2'));

        $list = new ExifRationalList([
            new ExifRational(6400, 10000),
            new ExifRational(3300, 10000),
            new ExifRational(3000, 10000),
            new ExifRational(6000, 10000),
            new ExifRational(1500, 10000),
            new ExifRational(6000, 10000),
        ]);

        self::assertSame([0.64, 0.33, 0.3, 0.6, 0.15, 0.6], ValueConverters::toPrimaryChromaticities($list));
    }

    /**
     * Validates legal YCbCr subsampling values per EXIF 3.0 §4.6.5.1.12.
     *
     * Legal values are: [2,1] (YCbCr4:2:2) and [2,2] (YCbCr4:2:0).
     * Other values are reserved.
     */
    #[Test]
    public function acceptsLegalYCbCrSubSamplingValues(): void
    {
        // EXIF 3.0 §4.6.5.1.12 defines only [2,1] and [2,2] as legal YCbCr subsampling values
        self::assertSame([2, 1], ValueConverters::ycbcrSubSamplingToPair('2 1'));
        self::assertSame([2, 2], ValueConverters::ycbcrSubSamplingToPair('2 2'));

        // Test with different delimiters (comma, semicolon)
        self::assertSame([2, 1], ValueConverters::ycbcrSubSamplingToPair('2,1'));
        self::assertSame([2, 2], ValueConverters::ycbcrSubSamplingToPair('2;2'));
    }

    /**
     * Rejects illegal YCbCr subsampling values per EXIF 3.0 §4.6.5.1.12.
     *
     * Only [2,1] and [2,2] are defined; all other values are reserved.
     */
    #[Test]
    public function rejectsIllegalYCbCrSubSamplingValues(): void
    {
        // Reserved values per EXIF 3.0 §4.6.5.1.12
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('4 1'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('4 2'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('4 4'));

        // Invalid horizontal/vertical combinations
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('1 1'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('1 2'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('2 3'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('3 1'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('3 2'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('3 3'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('4 3'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('5 1'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('8 8'));

        // Edge cases
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('0 0'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('0 1'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('1 0'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('-1 2'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('2 -1'));

        // Invalid formats
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair(''));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair(null));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('invalid'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('2'));
        self::assertNull(ValueConverters::ycbcrSubSamplingToPair('2 2 2'));
    }

    #[Test]
    public function serialisesMatrices(): void
    {
        $matrix = new ExifRationalList([
            new ExifRational(1, 1),
            new ExifRational(1, 2),
            new ExifRational(1, 4),
        ]);

        self::assertSame('[1.0,0.5,0.25]', ValueConverters::dngMatrixToString($matrix));
    }

    #[Test]
    public function convertsWhitePointAndEnums(): void
    {
        $whitePoint = new ExifRationalList([
            new ExifRational(3127, 10000),
            new ExifRational(3290, 10000),
        ]);

        self::assertSame([0.3127, 0.329], ValueConverters::toWhitePoint($whitePoint));
        self::assertSame(
            ResolutionUnit::INCHES,
            ValueConverters::toEnumOrNull(ResolutionUnit::class, (string) ResolutionUnit::INCHES->value),
        );
        self::assertNull(ValueConverters::toEnumOrNull(ResolutionUnit::class, 99));
        self::assertNull(ValueConverters::toEnumOrNull(ResolutionUnit::class, null));
    }

    #[Test]
    public function calculatesFieldOfViewAndHyperfocalMetrics(): void
    {
        $cropFactor = ValueConverters::calcCropFactor(75, 50.0);
        self::assertEqualsWithDelta(1.5, $cropFactor, 1e-12);

        $circleOfConfusion = ValueConverters::calcCircleOfConfusionMm($cropFactor);
        self::assertEqualsWithDelta(0.02, $circleOfConfusion, 1e-12);
        self::assertEqualsWithDelta(0.03, ValueConverters::calcCircleOfConfusionMm(null), 1e-12);
        self::assertNull(ValueConverters::calcCircleOfConfusionMm(0.0));

        $hyperfocal = ValueConverters::calcHyperfocalM(50.0, 8.0, $circleOfConfusion);
        self::assertEqualsWithDelta(15.675, $hyperfocal, 1e-12);

        self::assertEqualsWithDelta(32.179788109672, ValueConverters::calcFovDeg(75, $cropFactor, 50.0), 1e-12);
        self::assertEqualsWithDelta(26.991466561592, ValueConverters::calcHorizontalFovDeg(75, $cropFactor, 50.0), 1e-12);
        self::assertEqualsWithDelta(18.180553841645, ValueConverters::calcVerticalFovDeg(75, $cropFactor, 50.0), 1e-12);
        self::assertEqualsWithDelta(10.0, ValueConverters::calcEv100(1.0 / 1024.0, 1.0, 100), 1e-12);
    }

    #[Test]
    #[DataProvider('opticalDatasetProvider')]
    public function calculatesOpticalMetricsAcrossSensorFormats(
        int $focalLength35mm,
        float $focalLengthMm,
        float $fNumber,
        float $expectedCropFactor,
        float $expectedCircleOfConfusion,
        float $expectedHyperfocal,
        float $expectedFovDiagonal,
        float $expectedFovHorizontal,
        float $expectedFovVertical,
    ): void {
        $cropFactor = ValueConverters::calcCropFactor($focalLength35mm, $focalLengthMm);
        self::assertEqualsWithDelta($expectedCropFactor, $cropFactor, 1e-9);

        $circleOfConfusion = ValueConverters::calcCircleOfConfusionMm($cropFactor);
        self::assertEqualsWithDelta($expectedCircleOfConfusion, $circleOfConfusion, 1e-9);

        $hyperfocal = ValueConverters::calcHyperfocalM($focalLengthMm, $fNumber, $circleOfConfusion);
        self::assertEqualsWithDelta($expectedHyperfocal, $hyperfocal, 1e-6);

        self::assertEqualsWithDelta(
            $expectedFovDiagonal,
            ValueConverters::calcFovDeg($focalLength35mm, $cropFactor, $focalLengthMm),
            1e-6,
        );
        self::assertEqualsWithDelta(
            $expectedFovHorizontal,
            ValueConverters::calcHorizontalFovDeg($focalLength35mm, $cropFactor, $focalLengthMm),
            1e-6,
        );
        self::assertEqualsWithDelta(
            $expectedFovVertical,
            ValueConverters::calcVerticalFovDeg($focalLength35mm, $cropFactor, $focalLengthMm),
            1e-6,
        );
    }

    /**
     * @return iterable<string, array{
     *     focalLength35mm: int,
     *     focalLengthMm: float,
     *     fNumber: float,
     *     expectedCropFactor: float,
     *     expectedCircleOfConfusion: float,
     *     expectedHyperfocal: float,
     *     expectedFovDiagonal: float,
     *     expectedFovHorizontal: float,
     *     expectedFovVertical: float,
     * }>
     */
    public static function opticalDatasetProvider(): iterable
    {
        yield 'aps-c crop sensor' => [
            'focalLength35mm'           => 75,
            'focalLengthMm'             => 50.0,
            'fNumber'                   => 8.0,
            'expectedCropFactor'        => 1.5,
            'expectedCircleOfConfusion' => 0.02,
            'expectedHyperfocal'        => 15.675,
            'expectedFovDiagonal'       => 32.179788109672,
            'expectedFovHorizontal'     => 26.991466561592,
            'expectedFovVertical'       => 18.180553841645,
        ];

        yield 'aps-c wide angle' => [
            'focalLength35mm'           => 52,
            'focalLengthMm'             => 35.0,
            'fNumber'                   => 5.6,
            'expectedCropFactor'        => 1.4857142857142858,
            'expectedCircleOfConfusion' => 0.02019230769230769,
            'expectedHyperfocal'        => 10.868333333333334,
            'expectedFovDiagonal'       => 45.17707757599993,
            'expectedFovHorizontal'     => 38.18698400097122,
            'expectedFovVertical'       => 25.989233583833013,
        ];

        yield 'full frame portrait' => [
            'focalLength35mm'           => 85,
            'focalLengthMm'             => 85.0,
            'fNumber'                   => 2.0,
            'expectedCropFactor'        => 1.0,
            'expectedCircleOfConfusion' => 0.03,
            'expectedHyperfocal'        => 120.50166666666667,
            'expectedFovDiagonal'       => 28.558322254800274,
            'expectedFovHorizontal'     => 23.91316848629826,
            'expectedFovVertical'       => 16.071421421069587,
        ];

        yield 'micro four thirds normal' => [
            'focalLength35mm'           => 50,
            'focalLengthMm'             => 25.0,
            'fNumber'                   => 4.0,
            'expectedCropFactor'        => 2.0,
            'expectedCircleOfConfusion' => 0.015,
            'expectedHyperfocal'        => 10.441666666666666,
            'expectedFovDiagonal'       => 46.793003343996574,
            'expectedFovHorizontal'     => 39.59775270904986,
            'expectedFovVertical'       => 26.991466561591626,
        ];
    }

    private function buildSpatialFrequencyResponsePayload(): string
    {
        $columns = 3;
        $rows    = 2;

        $payload = pack('n', $columns) . pack('n', $rows);
        $payload .= "10lp/mm\0";
        $payload .= "20lp/mm\0";
        $payload .= "40lp/mm\0";
        $payload .= "Luminance\0";
        $payload .= "Chrominance\0";

        $payload .= $this->packSrational(90, 100);
        $payload .= $this->packSrational(75, 100);
        $payload .= $this->packSrational(60, 100);
        $payload .= $this->packSrational(85, 100);
        $payload .= $this->packSrational(70, 100);
        $payload .= $this->packSrational(55, 100);

        return $payload;
    }

    private function packSrational(int $numerator, int $denominator): string
    {
        return pack('N', $numerator) . pack('N', $denominator);
    }

    /**
     * Tests conversion of EXIF 3.0 acceleration triplets to float vectors.
     *
     * EXIF 3.0 §4.6.6 Acceleration (0x9404): SRATIONAL triplet for 3D acceleration vector.
     */
    #[Test]
    public function convertsSrationalTripletToFloatVector(): void
    {
        $list = new ExifRationalList([
            new ExifRational(50, 100),   // 0.5 m/s²
            new ExifRational(-20, 100),  // -0.2 m/s²
            new ExifRational(980, 100),  // 9.8 m/s²
        ]);

        $result = ValueConverters::srationalTripletToFloatVector($list);

        self::assertIsArray($result);
        self::assertCount(3, $result);
        self::assertEqualsWithDelta(0.5, $result[0], 0.001);
        self::assertEqualsWithDelta(-0.2, $result[1], 0.001);
        self::assertEqualsWithDelta(9.8, $result[2], 0.001);
    }

    /**
     * Tests that triplet conversion handles zero acceleration components.
     */
    #[Test]
    public function convertsSrationalTripletWithZeroComponents(): void
    {
        $list = new ExifRationalList([
            new ExifRational(0, 100),
            new ExifRational(0, 100),
            new ExifRational(981, 100),  // Near gravity
        ]);

        $result = ValueConverters::srationalTripletToFloatVector($list);

        self::assertIsArray($result);
        self::assertEqualsWithDelta(0.0, $result[0], 0.001);
        self::assertEqualsWithDelta(0.0, $result[1], 0.001);
        self::assertEqualsWithDelta(9.81, $result[2], 0.001);
    }

    /**
     * Tests that triplet conversion rejects lists with wrong component count.
     */
    #[Test]
    public function rejectsSrationalListWithWrongComponentCount(): void
    {
        $listWithTwo = new ExifRationalList([
            new ExifRational(10, 100),
            new ExifRational(20, 100),
        ]);

        self::assertNull(ValueConverters::srationalTripletToFloatVector($listWithTwo));

        $listWithFour = new ExifRationalList([
            new ExifRational(10, 100),
            new ExifRational(20, 100),
            new ExifRational(30, 100),
            new ExifRational(40, 100),
        ]);

        self::assertNull(ValueConverters::srationalTripletToFloatVector($listWithFour));
    }

    /**
     * Tests that triplet conversion rejects lists with zero denominators.
     */
    #[Test]
    public function rejectsSrationalTripletWithZeroDenominator(): void
    {
        $list = new ExifRationalList([
            new ExifRational(50, 100),
            new ExifRational(20, 0),     // Invalid: division by zero
            new ExifRational(980, 100),
        ]);

        $result = ValueConverters::srationalTripletToFloatVector($list);

        self::assertNull($result);
    }

    /**
     * Tests conversion with large acceleration values.
     */
    #[Test]
    public function convertsSrationalTripletWithLargeValues(): void
    {
        // Example: High-speed collision or extreme motion
        $list = new ExifRationalList([
            new ExifRational(500000, 1000),  // 500 m/s²
            new ExifRational(-200000, 1000), // -200 m/s²
            new ExifRational(100000, 1000),  // 100 m/s²
        ]);

        $result = ValueConverters::srationalTripletToFloatVector($list);

        self::assertIsArray($result);
        self::assertEqualsWithDelta(500.0, $result[0], 0.001);
        self::assertEqualsWithDelta(-200.0, $result[1], 0.001);
        self::assertEqualsWithDelta(100.0, $result[2], 0.001);
    }

    /**
     * Tests conversion with negative values in all components.
     */
    #[Test]
    public function convertsSrationalTripletWithAllNegativeValues(): void
    {
        $list = new ExifRationalList([
            new ExifRational(-30, 100),
            new ExifRational(-50, 100),
            new ExifRational(-20, 100),
        ]);

        $result = ValueConverters::srationalTripletToFloatVector($list);

        self::assertIsArray($result);
        self::assertEqualsWithDelta(-0.3, $result[0], 0.001);
        self::assertEqualsWithDelta(-0.5, $result[1], 0.001);
        self::assertEqualsWithDelta(-0.2, $result[2], 0.001);
    }
}
