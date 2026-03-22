<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\DateTimeConverter;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\ExifFlash;
use MagicSunday\ImageMeta\Exif\Converters\FlashConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\PhotoCalculator;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\Converters\SubjectAreaConverter;
use MagicSunday\ImageMeta\Exif\Converters\ValidatesGpsRef;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Text\JisTextDecoder;
use MagicSunday\ImageMeta\Exif\Text\UndefinedTextMarker;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\FlashInfo;
use MagicSunday\ImageMeta\Value\SubjectArea;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function chr;
use function iconv;
use function pack;
use function strlen;
use function substr;

/**
 * Exercises ValueConverters for transforming raw EXIF values into normalized forms.
 * It covers rational-to-float conversion, flash bit-field decoding, and date parsing.
 * The suite validates enum conversions and list handling for representative tags.
 * This ensures converter helpers remain consistent for ValueFactory consumption.
 *
 * @internal
 */
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(FlashInfo::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifFlash::class)]
#[CoversClass(ValueConverters::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(DateTimeConverter::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(FlashConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(PhotoCalculator::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(SubjectAreaConverter::class)]
#[UsesTrait(ValidatesGpsRef::class)]
#[UsesClass(JisTextDecoder::class)]
#[UsesClass(UndefinedTextMarker::class)]
#[UsesClass(GpsAltitudeRef::class)]
#[UsesClass(SubjectArea::class)]
final class ValueConvertersTest extends TestCase
{
    private ValueConverters $converters;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converters = new ValueConverters();
    }

    /**
     * Converts rational values (including lists) to floats.
     * It validates the transformation using representative inputs.
     *
     * @param ExifRational|ExifRationalList $value    The rational value to convert.
     * @param float                         $expected The expected float representation.
     */
    #[Test]
    #[DataProvider('provideValidRationals')]
    public function convertsRationalPairsToFloat(ExifRational|ExifRationalList $value, float $expected): void
    {
        self::assertSame($expected, $this->converters->rationalToFloat($value));
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
     * Casts scalar numeric inputs to floats.
     * It validates the transformation using representative inputs.
     *
     * @param int|float $value    The scalar input value.
     * @param float     $expected The expected float representation.
     */
    #[Test]
    #[DataProvider('provideScalarInputs')]
    public function convertsScalarsToFloat(int|float $value, float $expected): void
    {
        self::assertSame($expected, $this->converters->rationalToFloat($value));
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
     * Returns null for invalid or unsupported rational inputs.
     * It verifies the error path and guardrail handling.
     *
     * @param ExifRational|ExifNumericList|string|null $value The invalid rational input to convert.
     */
    #[Test]
    #[DataProvider('provideInvalidInputs')]
    public function returnsNullForInvalidRationalInputs(ExifRational|ExifNumericList|string|null $value): void
    {
        self::assertNull($this->converters->rationalToFloat($value));
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
     * Converts denominators -1 and 0xFFFFFFFF numerically in generic rational contexts.
     * It verifies EXIF unknown sentinels are not applied globally by the generic converter.
     */
    #[Test]
    public function convertsSignedAndUnsignedUnknownSentinelDenominatorsGenerically(): void
    {
        self::assertSame(-1.0, $this->converters->rationalToFloat(new ExifRational(1, -1)));
        self::assertEqualsWithDelta(
            1.0 / 4294967295.0,
            $this->converters->rationalToFloat(new ExifRational(1, 0xFFFFFFFF)),
            1.0e-18,
        );
    }

    /**
     * Converts APEX values to f-numbers and returns null for invalid inputs.
     * It validates the transformation using representative inputs.
     *
     * @param ExifRational|string $value    The APEX encoded value.
     * @param float|null          $expected The expected f-number.
     */
    #[Test]
    #[DataProvider('provideApexValues')]
    public function convertsApexValuesToFNumber(ExifRational|string $value, ?float $expected): void
    {
        $result = $this->converters->apexToFNumber($value);

        if ($expected === null) {
            self::assertNull($result);

            return;
        }

        self::assertNotNull($result);
        self::assertEqualsWithDelta($expected, $result, 0.000001);
    }

    /**
     * Converts APEX shutter speed values to seconds and rejects invalid rationals.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function convertsApexShutterSpeedToSeconds(): void
    {
        self::assertEqualsWithDelta(1 / 128, $this->converters->apexShutterSpeedToSeconds(new ExifRational(7, 1)), 0.0000001);
        self::assertEqualsWithDelta(1.0, $this->converters->apexShutterSpeedToSeconds(new ExifRational(0, 1)), 0.0000001);
        self::assertEqualsWithDelta(1 / 60, $this->converters->apexShutterSpeedToSeconds(new ExifRational(59, 10)), 0.0001);
        self::assertNull($this->converters->apexShutterSpeedToSeconds(new ExifRational(1, 0)));
    }

    /**
     * Formats components configuration values into labels and descriptions.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function formatsComponentsConfiguration(): void
    {
        $values = new ExifNumericList([1, 2, 3, 0]);

        self::assertSame(['Y', 'Cb', 'Cr', '-'], $this->converters->componentsConfigurationLabels($values));
        self::assertSame('Y Cb Cr -', $this->converters->componentsConfigurationDescription($values));
        self::assertNull($this->converters->componentsConfigurationLabels(new ExifNumericList([])));
    }

    /**
     * Normalizes components configuration payloads and rejects empty inputs.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function normalizesComponentsConfigurationPayloads(): void
    {
        $values = new ExifNumericList([1, 2, 3, 0]);

        self::assertSame([1, 2, 3, 0], $this->converters->componentsConfiguration($values));
        self::assertSame([4, 5], $this->converters->componentsConfiguration([4, 5]));
        self::assertNull($this->converters->componentsConfiguration(new ExifNumericList([])));
        self::assertNull($this->converters->componentsConfiguration(null));
    }

    /**
     * Returns null when components configuration contains reserved identifiers.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function returnsNullForReservedComponentIdentifiers(): void
    {
        $values = new ExifNumericList([1, 2, 7, 0]);

        self::assertNull($this->converters->componentsConfigurationLabels($values));
        self::assertNull($this->converters->componentsConfigurationDescription($values));
    }

    /**
     * Normalizes maker note safety flags to booleans.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function convertsMakerNoteSafetyFlags(): void
    {
        self::assertTrue($this->converters->makerNoteSafety(1));
        self::assertFalse($this->converters->makerNoteSafety(0));
        self::assertTrue($this->converters->makerNoteSafety(new ExifNumericList([1])));
        self::assertNull($this->converters->makerNoteSafety(new ExifNumericList([])));
        self::assertNull($this->converters->makerNoteSafety('invalid'));
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
     * Converts GPS speed values to metres per second based on the reference.
     * It validates the transformation using representative inputs.
     *
     * @param string|null         $ref      The speed reference.
     * @param ExifRational|string $value    The raw speed value.
     * @param float|null          $expected The expected metres per second.
     */
    #[Test]
    #[DataProvider('provideGpsSpeedValues')]
    public function convertsGpsSpeedToMetresPerSecond(?string $ref, ExifRational|string $value, ?float $expected): void
    {
        $result = $this->converters->gpsSpeedToMs($ref, $value);

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
     * Parses flash bitfields into a FlashInfo value object.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function convertsFlashShortToValueObject(): void
    {
        $info = $this->converters->flashFromShort(new ExifNumericList([63]));

        self::assertInstanceOf(FlashInfo::class, $info);
        self::assertTrue($info->fired);
        self::assertSame(FlashMode::Auto, $info->mode);
        self::assertSame(FlashReturn::ReturnDetected, $info->returnDetection);
        self::assertSame(FlashFunction::Absent, $info->functionPresence);
        self::assertFalse($info->redEyeReduction);
    }

    /**
     * Returns null when flash inputs are invalid or unsupported.
     * It verifies the error path and guardrail handling.
     */
    #[Test]
    public function returnsNullForInvalidFlashValue(): void
    {
        self::assertNull($this->converters->flashFromShort(new ExifRational(1, 0)));
        self::assertNull($this->converters->flashFromShort('invalid'));
    }

    /**
     * Normalizes offset strings into canonical ±HH:MM values.
     * It validates the transformation using representative inputs.
     *
     * @param int|float|string|ExifRational|ExifRationalList|null $value    The raw offset representation.
     * @param string|null                                         $expected The expected canonical offset string.
     */
    #[Test]
    #[DataProvider('provideOffsetStrings')]
    public function normalizesOffsetStrings(int|float|string|ExifRational|ExifRationalList|null $value, ?string $expected): void
    {
        self::assertSame($expected, $this->converters->parseOffsetString($value));
    }

    /**
     * @return iterable<string, array{int|float|string|ExifRational|ExifRationalList|null, string|null}>
     */
    public static function provideOffsetStrings(): iterable
    {
        yield 'already normalized' => ['+01:30', '+01:30'];
        yield 'missing sign with colon' => ['05:45', null];
        yield 'compact digits' => ['0530', null];
        yield 'decimal hours' => ['-5.5', null];
        yield 'utc prefix' => ['UTC-4', null];
        yield 'zulu designator' => ['Z', null];
        yield 'maximum offset' => ['+14:00', '+14:00'];
        yield 'positive above maximum' => ['+14:30', null];
        yield 'negative above maximum' => ['-14:30', null];
        yield 'single digit hour with sign' => ['+5:30', null];
        yield 'no minutes' => ['14', null];
        yield 'minute overflow' => ['+01:75', null];
        yield 'invalid string' => ['abc', null];
        yield 'out of range' => ['+15:00', null];
    }

    /**
     * Extracts GPS coordinates with positive altitude.
     * It exercises the scenario described by the test name.
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

        $result = $this->converters->gpsFromIfd($gps);

        self::assertEqualsWithDelta(51.5, $result['lat'], 0.000001);
        self::assertEqualsWithDelta(0.125, $result['lon'], 0.000001);
        self::assertEqualsWithDelta(45.0, $result['alt'], 0.000001);
    }

    /**
     * Applies hemisphere and altitude sign rules for GPS coordinates.
     * It exercises the scenario described by the test name.
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

        $result = $this->converters->gpsFromIfd($gps);

        self::assertEqualsWithDelta(-33.255, $result['lat'], 0.000001);
        self::assertEqualsWithDelta(-70.75, $result['lon'], 0.000001);
        self::assertEqualsWithDelta(-125.0, $result['alt'], 0.000001);
    }

    /**
     * Extracts GPS coordinates when altitude is missing.
     * It exercises the scenario described by the test name.
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

        $result = $this->converters->gpsFromIfd($gps);

        self::assertEqualsWithDelta(10.0, $result['lat'], 0.000001);
        self::assertEqualsWithDelta(20.5, $result['lon'], 0.000001);
        self::assertNull($result['alt']);
    }

    /**
     * Accepts numeric list components for GPS coordinate conversion.
     * It exercises the scenario described by the test name.
     */
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

        $result = $this->converters->gpsFromIfd($gps);

        self::assertEqualsWithDelta(40.504166, $result['lat'] ?? 0.0, 1e-6);
        self::assertEqualsWithDelta(7.758333, $result['lon'] ?? 0.0, 1e-6);
        self::assertEqualsWithDelta(250.0, $result['alt'] ?? 0.0, 1e-6);
    }

    /**
     * Accepts string altitude references and applies sign accordingly.
     * It confirms optional fields are accepted without errors.
     */
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

        $result = $this->converters->gpsFromIfd($gps);

        self::assertEqualsWithDelta(-33.0, $result['lat'] ?? 0.0, 1e-6);
        self::assertEqualsWithDelta(18.5, $result['lon'] ?? 0.0, 1e-6);
        self::assertEqualsWithDelta(-120.0, $result['alt'] ?? 0.0, 1e-6);
        self::assertSame(1, $result['alt_ref']);
    }

    /**
     * Returns null latitude when GPS seconds contain invalid rationals.
     * It verifies the error path and guardrail handling.
     */
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

        $result = $this->converters->gpsFromIfd($gps);

        self::assertNull($result['lat']);
        self::assertEqualsWithDelta(56.0, $result['lon'] ?? 0.0, 1e-6);
    }

    /**
     * Extracts extended GPS metadata, including timestamps and distances.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function extractsExtendedGpsMetadata(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_VERSION_ID   => new IfdEntry(ExifTag::GPS_VERSION_ID, 2, 9, '2.4.0.0' . chr(0)),
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
            ExifTag::GPS_SPEED_REF         => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 2, 'K'),
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
            ExifTag::GPS_DIFFERENTIAL        => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, 1),
            ExifTag::GPS_H_POSITIONING_ERROR => new IfdEntry(ExifTag::GPS_H_POSITIONING_ERROR, 5, 1, new ExifRational(15, 10)),
        ]);

        $result = $this->converters->gpsFromIfd($gps);

        self::assertEqualsWithDelta(51.5, $result['lat'], 0.000001);
        self::assertEqualsWithDelta(8.5, $result['lon'], 0.000001);
        self::assertEqualsWithDelta(150.0, $result['alt'], 0.000001);
        self::assertSame('2.4.0.0', $result['version']);
        self::assertSame('2.4.0.0' . chr(0), $result['version_raw']);
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

        self::assertSame(1, $result['differential']);
        self::assertEqualsWithDelta(1.5, $result['h_positioning_error'], 0.000001);
    }

    /**
     * Decodes GPS undefined strings using UNICODE and JIS prefixes.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function decodesGpsUndefinedStringsWithEncodings(): void
    {
        $unicodePayload = "UNICODE\0測位方式\0";
        $jisContent     = iconv('UTF-8', 'ISO-2022-JP', '東京');
        self::assertIsString($jisContent);

        $jisPayload = "JIS\0\0\0\0\0" . $jisContent . "\0";

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

        $result = $this->converters->gpsFromIfd($gps);

        self::assertSame('測位方式', $result['processing_method']);
        self::assertSame('東京', $result['area_information']);
    }

    /**
     * Rejects Shift-JIS payloads when the EXIF marker declares JIS semantics.
     */
    #[Test]
    public function rejectsGpsUndefinedJisPayloadEncodedAsShiftJis(): void
    {
        $shiftJisPayload = "JIS\0\0\0\0\0" . pack('C*', 0x93, 0x8C, 0x8B, 0x9E) . "\0";

        $gps = new Ifd([
            ExifTag::GPS_AREA_INFORMATION => new IfdEntry(
                ExifTag::GPS_AREA_INFORMATION,
                7,
                strlen($shiftJisPayload),
                $shiftJisPayload,
            ),
        ]);

        $result = $this->converters->gpsFromIfd($gps);

        self::assertNull($result['area_information']);
    }

    /**
     * Rejects malformed JIS payloads for GPS undefined strings.
     */
    #[Test]
    public function rejectsMalformedGpsUndefinedJisPayload(): void
    {
        $malformedPayload = "JIS\0\0\0\0\0\x1B\x24\x42\x24";

        $gps = new Ifd([
            ExifTag::GPS_AREA_INFORMATION => new IfdEntry(
                ExifTag::GPS_AREA_INFORMATION,
                7,
                strlen($malformedPayload),
                $malformedPayload,
            ),
        ]);

        $result = $this->converters->gpsFromIfd($gps);

        self::assertNull($result['area_information']);
    }

    /**
     * Decodes UTF-16BE GPS undefined strings when the payload carries a BE BOM.
     */
    #[Test]
    public function decodesGpsUndefinedUnicodeBigEndianWithBom(): void
    {
        $unicodePayload = "UNICODE\0\xFE\xFF" . pack('n*', 0x6E2C, 0x4F4D, 0x65B9, 0x5F0F) . "\0\0";

        $gps = new Ifd([
            ExifTag::GPS_PROCESSING_METHOD => new IfdEntry(
                ExifTag::GPS_PROCESSING_METHOD,
                7,
                strlen($unicodePayload),
                $unicodePayload,
            ),
        ]);

        $result = $this->converters->gpsFromIfd($gps);

        self::assertSame('測位方式', $result['processing_method']);
    }

    /**
     * Rejects malformed UTF-16 GPS undefined payloads without lossy salvage.
     */
    #[Test]
    public function rejectsMalformedGpsUndefinedUnicodePayload(): void
    {
        $malformedPayload = "UNICODE\0\xC3\x28";

        $gps = new Ifd([
            ExifTag::GPS_PROCESSING_METHOD => new IfdEntry(
                ExifTag::GPS_PROCESSING_METHOD,
                7,
                strlen($malformedPayload),
                $malformedPayload,
            ),
        ]);

        $result = $this->converters->gpsFromIfd($gps);

        self::assertNull($result['processing_method']);
    }

    /**
     * Rejects malformed UTF-16 GPS undefined payloads with odd-length content.
     */
    #[Test]
    public function rejectsGpsUndefinedUnicodePayloadWithOddLengthUtf16Bom(): void
    {
        $malformedPayload = "UNICODE\0\xFE\xFF\x00A\x00";

        $gps = new Ifd([
            ExifTag::GPS_PROCESSING_METHOD => new IfdEntry(
                ExifTag::GPS_PROCESSING_METHOD,
                7,
                strlen($malformedPayload),
                $malformedPayload,
            ),
        ]);

        $result = $this->converters->gpsFromIfd($gps);

        self::assertNull($result['processing_method']);
    }

    /**
     * Returns null when decoded GPS undefined strings are empty.
     * It ensures missing or invalid inputs yield no value.
     */
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

        $result = $this->converters->gpsFromIfd($gps);

        self::assertNull($result['processing_method']);
    }

    /**
     * Formats GPS version values from numeric lists.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function formatsGpsVersionFromNumericList(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_VERSION_ID => new IfdEntry(ExifTag::GPS_VERSION_ID, 1, 4, [2, 4, 0, 0]),
        ]);

        $result = $this->converters->gpsFromIfd($gps);

        self::assertSame('2.4.0.0', $result['version']);
        self::assertNull($result['version_raw']);
    }

    /**
     * Defaults the GPS version when the tag is missing.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function defaultsGpsVersionWhenEntryMissing(): void
    {
        $gps = new Ifd([]);

        $result = $this->converters->gpsFromIfd($gps);

        self::assertSame('2.4.0.0', $result['version']);
        self::assertNull($result['version_raw']);
    }

    /**
     * Defaults the GPS version when the payload is empty.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function defaultsGpsVersionWhenStringPayloadEmpty(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_VERSION_ID => new IfdEntry(ExifTag::GPS_VERSION_ID, 2, 4, "\0\0\0\0"),
        ]);

        $result = $this->converters->gpsFromIfd($gps);

        self::assertSame('2.4.0.0', $result['version']);
        self::assertSame("\0\0\0\0", $result['version_raw']);
    }

    /**
     * Decodes spatial frequency response labels.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function decodeSpatialFrequencyResponseReturnsLabels(): void
    {
        $payload = pack('n', 1) . pack('n', 1);
        $payload .= "Alpha\0";
        $payload .= "Beta\0";
        $payload .= $this->packSrational(1, 1);

        $result = $this->converters->decodeSpatialFrequencyResponse($payload);

        self::assertNotNull($result);
        self::assertSame(['Alpha'], $result['labels']['columns']);
        self::assertSame(['Beta'], $result['labels']['rows']);
    }

    /**
     * Decodes spatial frequency response tables and values.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function decodeSpatialFrequencyResponseParsesTable(): void
    {
        $payload = $this->buildSpatialFrequencyResponsePayload();

        $result = $this->converters->decodeSpatialFrequencyResponse($payload);

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

    /**
     * Rejects truncated spatial frequency response payloads.
     * It verifies the error path and guardrail handling.
     */
    #[Test]
    public function decodeSpatialFrequencyResponseRejectsInvalidPayload(): void
    {
        $payload = substr($this->buildSpatialFrequencyResponsePayload(), 0, 8);

        self::assertNull($this->converters->decodeSpatialFrequencyResponse($payload));
    }

    /**
     * Supports array rationals and numeric APEX values.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function convertsRationalsAndApexValues(): void
    {
        self::assertSame(0.5, $this->converters->rationalToFloat([1, 2]));
        self::assertSame(2.8284271247461903, $this->converters->apexToFNumber(3.0));
    }

    /**
     * Normalizes EXIF version strings and parses flash bitfields.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function normalizesExifVersionAndFlash(): void
    {
        self::assertSame('1.00', $this->converters->toExifVersion('0100'));
        self::assertSame('2.00', $this->converters->toExifVersion('0200'));
        self::assertSame('2.20', $this->converters->toExifVersion('0220'));
        self::assertSame('2.31', $this->converters->toExifVersion('0231'));
        self::assertSame('3.00', $this->converters->toExifVersion('0300'));
        self::assertNull($this->converters->toExifVersion("0100\0\0"));
        self::assertNull($this->converters->toExifVersion('Exif'));
        self::assertNull($this->converters->toExifVersion('0240'));
        self::assertNull($this->converters->toExifVersion("\x01\x02\x03\x04"));

        $flash = $this->converters->flashFromShort(0x59);
        self::assertInstanceOf(FlashInfo::class, $flash);
        self::assertTrue($flash->fired);
        self::assertSame(FlashMode::Auto, $flash->mode);
        self::assertSame(FlashReturn::NoStrobeDetection, $flash->returnDetection);
    }

    /**
     * Normalizes offsets and converts subject area arrays to rectangles.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function normalizesOffsetsAndSubjectAreas(): void
    {
        self::assertSame('+01:00', $this->converters->parseOffset('+01:00')?->getName());
        self::assertSame('-05:30', $this->converters->parseOffset('-05:30')?->getName());
        self::assertSame('+14:00', $this->converters->parseOffset('+14:00')?->getName());
        self::assertNull($this->converters->parseOffset('UTC'));
        self::assertNull($this->converters->parseOffset('GMT'));
        self::assertNull($this->converters->parseOffset('Europe/Berlin'));
        self::assertNull($this->converters->parseOffset('Z'));
        self::assertNull($this->converters->parseOffset('+0100'));
        self::assertNull($this->converters->parseOffset('+1'));
        self::assertNull($this->converters->parseOffset('+9:00'));
        self::assertNull($this->converters->parseOffset('+1401'));
        self::assertNull($this->converters->parseOffset('+15:00'));
        self::assertNull($this->converters->parseOffset('+01:61'));

        self::assertSame(
            ['x' => 10, 'y' => 20, 'w' => null, 'h' => null],
            $this->converters->subjectAreaToRect([10, 20]),
        );
        self::assertSame(
            ['x' => 100, 'y' => 120, 'w' => 25, 'h' => 25],
            $this->converters->subjectAreaToRect([100, 120, 25]),
        );
        self::assertSame(
            ['x' => 10, 'y' => 20, 'w' => 30, 'h' => 40],
            $this->converters->subjectAreaToRect([10, 20, 30, 40]),
        );
        self::assertNull($this->converters->subjectAreaToRect([10]));
        self::assertNull($this->converters->subjectAreaToRect([10, 20, -5]));
        self::assertNull($this->converters->subjectAreaToRect(['a', 'b']));
        self::assertNull($this->converters->subjectAreaToRect([1, 2, 3, 4, 5]));
    }

    /**
     * Parses YCbCr subsampling pairs and chromaticities.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function parsesSamplingAndChromaticities(): void
    {
        self::assertSame([2, 2], $this->converters->ycbcrSubSamplingToPair('2 2'));

        $list = new ExifRationalList([
            new ExifRational(6400, 10000),
            new ExifRational(3300, 10000),
            new ExifRational(3000, 10000),
            new ExifRational(6000, 10000),
            new ExifRational(1500, 10000),
            new ExifRational(6000, 10000),
        ]);

        self::assertSame([0.64, 0.33, 0.3, 0.6, 0.15, 0.6], $this->converters->toPrimaryChromaticities($list));
    }

    /**
     * Accepts legal YCbCr subsampling values with multiple delimiters.
     * It confirms optional fields are accepted without errors.
     */
    #[Test]
    public function acceptsLegalYCbCrSubSamplingValues(): void
    {
        // EXIF 3.0 §4.6.5.1.12 defines only [2,1] and [2,2] as legal YCbCr subsampling values
        self::assertSame([2, 1], $this->converters->ycbcrSubSamplingToPair('2 1'));
        self::assertSame([2, 2], $this->converters->ycbcrSubSamplingToPair('2 2'));

        // Test with different delimiters (comma, semicolon)
        self::assertSame([2, 1], $this->converters->ycbcrSubSamplingToPair('2,1'));
        self::assertSame([2, 2], $this->converters->ycbcrSubSamplingToPair('2;2'));
    }

    /**
     * Rejects illegal YCbCr subsampling values and malformed inputs.
     * It verifies the error path and guardrail handling.
     */
    #[Test]
    public function rejectsIllegalYCbCrSubSamplingValues(): void
    {
        // Reserved values per EXIF 3.0 §4.6.5.1.12
        self::assertNull($this->converters->ycbcrSubSamplingToPair('4 1'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('4 2'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('4 4'));

        // Invalid horizontal/vertical combinations
        self::assertNull($this->converters->ycbcrSubSamplingToPair('1 1'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('1 2'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('2 3'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('3 1'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('3 2'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('3 3'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('4 3'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('5 1'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('8 8'));

        // Edge cases
        self::assertNull($this->converters->ycbcrSubSamplingToPair('0 0'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('0 1'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('1 0'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('-1 2'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('2 -1'));

        // Invalid formats
        self::assertNull($this->converters->ycbcrSubSamplingToPair(''));
        self::assertNull($this->converters->ycbcrSubSamplingToPair(null));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('invalid'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('2'));
        self::assertNull($this->converters->ycbcrSubSamplingToPair('2 2 2'));
    }

    /**
     * Serialises DNG matrices into string form.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function serialisesMatrices(): void
    {
        $matrix = new ExifRationalList([
            new ExifRational(1, 1),
            new ExifRational(1, 2),
            new ExifRational(1, 4),
        ]);

        self::assertSame('[1.0,0.5,0.25]', $this->converters->dngMatrixToString($matrix));
    }

    /**
     * Converts white point values and maps enums from scalar inputs.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function convertsWhitePointAndEnums(): void
    {
        $whitePoint = new ExifRationalList([
            new ExifRational(3127, 10000),
            new ExifRational(3290, 10000),
        ]);

        self::assertSame([0.3127, 0.329], $this->converters->toWhitePoint($whitePoint));
        self::assertSame(
            ResolutionUnit::Inches,
            $this->converters->toEnumOrNull(ResolutionUnit::class, (string) ResolutionUnit::Inches->value),
        );
        self::assertNull($this->converters->toEnumOrNull(ResolutionUnit::class, 99));
        self::assertNull($this->converters->toEnumOrNull(ResolutionUnit::class, null));
    }

    /**
     * Rejects invalid white point and chromaticity lengths.
     * It verifies the error path and guardrail handling.
     */
    #[Test]
    public function rejectsInvalidWhitePointAndChromaticityLengths(): void
    {
        $whitePoint = new ExifRationalList([
            new ExifRational(1, 2),
            new ExifRational(1, 2),
            new ExifRational(1, 2),
        ]);

        self::assertNull($this->converters->toWhitePoint($whitePoint));

        $chromaticities = new ExifRationalList([
            new ExifRational(1, 1),
            new ExifRational(1, 1),
            new ExifRational(1, 1),
            new ExifRational(1, 1),
        ]);

        self::assertNull($this->converters->toPrimaryChromaticities($chromaticities));
    }

    /**
     * Calculates crop factor, circle of confusion, and field-of-view metrics.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function calculatesFieldOfViewAndHyperfocalMetrics(): void
    {
        $cropFactor = $this->converters->calcCropFactor(75, 50.0);
        self::assertEqualsWithDelta(1.5, $cropFactor, 1e-12);

        $circleOfConfusion = $this->converters->calcCircleOfConfusionMm($cropFactor);
        self::assertEqualsWithDelta(0.02, $circleOfConfusion, 1e-12);
        self::assertEqualsWithDelta(0.03, $this->converters->calcCircleOfConfusionMm(null), 1e-12);
        self::assertNull($this->converters->calcCircleOfConfusionMm(0.0));

        $hyperfocal = $this->converters->calcHyperfocalM(50.0, 8.0, $circleOfConfusion);
        self::assertEqualsWithDelta(15.675, $hyperfocal, 1e-12);

        self::assertEqualsWithDelta(32.179788109672, $this->converters->calcFovDeg(75, $cropFactor, 50.0), 1e-12);
        self::assertEqualsWithDelta(26.991466561592, $this->converters->calcHorizontalFovDeg(75, $cropFactor, 50.0), 1e-12);
        self::assertEqualsWithDelta(18.180553841645, $this->converters->calcVerticalFovDeg(75, $cropFactor, 50.0), 1e-12);
        self::assertEqualsWithDelta(10.0, $this->converters->calcEv100(1.0 / 1024.0, 1.0, 100), 1e-12);
    }

    /**
     * Calculates optical metrics across multiple sensor formats.
     * It validates the transformation using representative inputs.
     */
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
        $cropFactor = $this->converters->calcCropFactor($focalLength35mm, $focalLengthMm);
        self::assertEqualsWithDelta($expectedCropFactor, $cropFactor, 1e-9);

        $circleOfConfusion = $this->converters->calcCircleOfConfusionMm($cropFactor);
        self::assertEqualsWithDelta($expectedCircleOfConfusion, $circleOfConfusion, 1e-9);

        $hyperfocal = $this->converters->calcHyperfocalM($focalLengthMm, $fNumber, $circleOfConfusion);
        self::assertEqualsWithDelta($expectedHyperfocal, $hyperfocal, 1e-6);

        self::assertEqualsWithDelta(
            $expectedFovDiagonal,
            $this->converters->calcFovDeg($focalLength35mm, $cropFactor, $focalLengthMm),
            1e-6,
        );
        self::assertEqualsWithDelta(
            $expectedFovHorizontal,
            $this->converters->calcHorizontalFovDeg($focalLength35mm, $cropFactor, $focalLengthMm),
            1e-6,
        );
        self::assertEqualsWithDelta(
            $expectedFovVertical,
            $this->converters->calcVerticalFovDeg($focalLength35mm, $cropFactor, $focalLengthMm),
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

        return $payload . $this->packSrational(55, 100);
    }

    private function packSrational(int $numerator, int $denominator): string
    {
        return pack('N', $numerator) . pack('N', $denominator);
    }

    /**
     * Converts signed rational triplets to float vectors.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function convertsSrationalTripletToFloatVector(): void
    {
        $list = new ExifRationalList([
            new ExifRational(50, 100),   // 0.5 m/s²
            new ExifRational(-20, 100),  // -0.2 m/s²
            new ExifRational(980, 100),  // 9.8 m/s²
        ]);

        $result = $this->converters->srationalTripletToFloatVector($list);

        self::assertIsArray($result);
        self::assertEqualsWithDelta(0.5, $result[0], 0.001);
        self::assertEqualsWithDelta(-0.2, $result[1], 0.001);
        self::assertEqualsWithDelta(9.8, $result[2], 0.001);
    }

    /**
     * Converts triplets with zero components to float vectors.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function convertsSrationalTripletWithZeroComponents(): void
    {
        $list = new ExifRationalList([
            new ExifRational(0, 100),
            new ExifRational(0, 100),
            new ExifRational(981, 100),  // Near gravity
        ]);

        $result = $this->converters->srationalTripletToFloatVector($list);

        self::assertIsArray($result);
        self::assertEqualsWithDelta(0.0, $result[0], 0.001);
        self::assertEqualsWithDelta(0.0, $result[1], 0.001);
        self::assertEqualsWithDelta(9.81, $result[2], 0.001);
    }

    /**
     * Converts triplets that include denominator 0xFFFFFFFF numerically in generic context.
     */
    #[Test]
    public function convertsSrationalTripletWithUnsignedSentinelDenominator(): void
    {
        $list = new ExifRationalList([
            new ExifRational(10, 100),
            new ExifRational(20, 0xFFFFFFFF),
            new ExifRational(-10, 100),
        ]);

        $result = $this->converters->srationalTripletToFloatVector($list);

        self::assertIsArray($result);
        self::assertEqualsWithDelta(0.1, $result[0], 0.001);
        self::assertEqualsWithDelta(20.0 / 4294967295.0, $result[1], 1.0e-15);
        self::assertEqualsWithDelta(-0.1, $result[2], 0.001);
    }

    /**
     * Rejects srational triplets with invalid component counts.
     * It verifies the error path and guardrail handling.
     */
    #[Test]
    public function rejectsSrationalListWithWrongComponentCount(): void
    {
        $listWithTwo = new ExifRationalList([
            new ExifRational(10, 100),
            new ExifRational(20, 100),
        ]);

        self::assertNull($this->converters->srationalTripletToFloatVector($listWithTwo));

        $listWithFour = new ExifRationalList([
            new ExifRational(10, 100),
            new ExifRational(20, 100),
            new ExifRational(30, 100),
            new ExifRational(40, 100),
        ]);

        self::assertNull($this->converters->srationalTripletToFloatVector($listWithFour));
    }

    /**
     * Returns null when any triplet component has a zero denominator.
     * It verifies the error path and guardrail handling.
     */
    #[Test]
    public function rejectsSrationalTripletWithZeroDenominator(): void
    {
        $list = new ExifRationalList([
            new ExifRational(50, 100),
            new ExifRational(20, 0),     // Invalid: division by zero
            new ExifRational(980, 100),
        ]);

        $result = $this->converters->srationalTripletToFloatVector($list);

        self::assertNull($result);
    }

    /**
     * Converts triplets with large magnitude values to floats.
     * It validates the transformation using representative inputs.
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

        $result = $this->converters->srationalTripletToFloatVector($list);

        self::assertIsArray($result);
        self::assertEqualsWithDelta(500.0, $result[0], 0.001);
        self::assertEqualsWithDelta(-200.0, $result[1], 0.001);
        self::assertEqualsWithDelta(100.0, $result[2], 0.001);
    }

    /**
     * Converts triplets with all negative values to floats.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function convertsSrationalTripletWithAllNegativeValues(): void
    {
        $list = new ExifRationalList([
            new ExifRational(-30, 100),
            new ExifRational(-50, 100),
            new ExifRational(-20, 100),
        ]);

        $result = $this->converters->srationalTripletToFloatVector($list);

        self::assertIsArray($result);
        self::assertEqualsWithDelta(-0.3, $result[0], 0.001);
        self::assertEqualsWithDelta(-0.5, $result[1], 0.001);
        self::assertEqualsWithDelta(-0.2, $result[2], 0.001);
    }

    /**
     * Formats exposure times into human-readable strings.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    #[DataProvider('provideExposureTimeValues')]
    public function formatsExposureTime(?float $seconds, ?string $expected): void
    {
        self::assertSame($expected, $this->converters->formatExposureTime($seconds));
    }

    /**
     * @return iterable<string, array{?float, ?string}>
     */
    public static function provideExposureTimeValues(): iterable
    {
        yield 'null input' => [null, null];
        yield 'zero' => [0.0, null];
        yield 'negative' => [-0.1, null];
        yield '1/50 second' => [0.02, '1/50'];
        yield '1/20 second' => [0.05, '1/20'];
        yield '1/100 second' => [0.01, '1/100'];
        yield '1/1000 second' => [0.001, '1/1000'];
        yield '1/4 second' => [0.25, '1/4'];
        yield '0.5 second' => [0.5, '0.5'];
        yield '1 second' => [1.0, '1'];
        yield '2 seconds' => [2.0, '2'];
        yield '1.5 seconds' => [1.5, '1.5'];
    }

    /**
     * Formats shutter speed strings from APEX values.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function formatsShutterSpeedFromApex(): void
    {
        // APEX value 4.32 => 2^(-4.32) ≈ 0.05 seconds => "1/20"
        $apexValue = new ExifRational(64736, 14979);
        $formatted = $this->converters->formatShutterSpeedFromApex($apexValue);

        self::assertSame('1/20', $formatted);

        // APEX value 6.64 => 2^(-6.64) ≈ 0.01 seconds => "1/100"
        $apexValue2 = new ExifRational(664, 100);
        $formatted2 = $this->converters->formatShutterSpeedFromApex($apexValue2);

        self::assertSame('1/100', $formatted2);
    }

    /**
     * Formats aperture strings from APEX values.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function formatsApertureFromApex(): void
    {
        // APEX value 1.85 => 2^(1.85/2) ≈ 1.9 => "f/1.9"
        $apexValue = new ExifRational(16384, 8847);
        $formatted = $this->converters->formatApertureFromApex($apexValue);

        self::assertSame('f/1.9', $formatted);

        // APEX value 3.0 => 2^(3/2) ≈ 2.83 => "f/2.8"
        $apexValue2 = new ExifRational(3, 1);
        $formatted2 = $this->converters->formatApertureFromApex($apexValue2);

        self::assertSame('f/2.8', $formatted2);
    }

    /**
     * Formats brightness values into display strings.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    #[DataProvider('provideBrightnessValues')]
    public function formatsBrightnessValue(ExifRational|float|null $value, ?string $expected): void
    {
        self::assertSame($expected, $this->converters->formatBrightnessValue($value));
    }

    /**
     * Keeps EXIF unknown brightness sentinels mapped to null in the tag-specific formatter.
     */
    #[Test]
    public function treatsUnknownBrightnessDenominatorsAsNull(): void
    {
        self::assertNull($this->converters->formatBrightnessValue(new ExifRational(1, -1)));
        self::assertNull($this->converters->formatBrightnessValue(new ExifRational(1, 0xFFFFFFFF)));
    }

    /**
     * @return iterable<string, array{ExifRational|float|null, ?string}>
     */
    public static function provideBrightnessValues(): iterable
    {
        yield 'null input' => [null, null];
        yield 'positive rational' => [new ExifRational(530, 100), '5.3'];
        yield 'negative rational' => [new ExifRational(-221, 100), '-2.21'];
        yield 'integer value' => [new ExifRational(5, 1), '5'];
        yield 'zero value' => [new ExifRational(0, 1), '0'];
        yield 'positive float' => [3.5, '3.5'];
        yield 'negative float' => [-2.21, '-2.21'];
    }
}
