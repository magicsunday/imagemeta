<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\ValidatesGpsRef;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises GpsUnitConverter for altitude, speed and destination distance
 * extraction and unit conversions per EXIF 3.0 §4.6.7.1.6–§4.6.7.1.27.
 *
 * @internal
 */
#[CoversClass(GpsUnitConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(GpsAltitudeRef::class)]
#[UsesTrait(ValidatesGpsRef::class)]
final class GpsUnitConverterTest extends TestCase
{
    private GpsUnitConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $numericConverter  = new NumericConverter();
        $rationalConverter = new RationalConverter($numericConverter);

        $this->converter   = new GpsUnitConverter($rationalConverter);
    }

    // ── Altitude ──────────────────────────────────────────────────────

    /**
     * Verifies altitude extraction with ref 0 (above ellipsoidal surface).
     */
    #[Test]
    public function extractsAltitudeAboveEllipsoidal(): void
    {
        $gps    = new Ifd([
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(1500, 10),
            ),
        ]);

        $result = $this->converter->extractFromIfd($gps);

        self::assertSame(0, $result['alt_ref']);
        self::assertEqualsWithDelta(150.0, $result['alt'], 0.0001);
    }

    /**
     * Verifies altitude is negated when ref indicates below (ref=1).
     */
    #[Test]
    public function extractsAltitudeBelowEllipsoidal(): void
    {
        $gps    = new Ifd([
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 1),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(500, 10),
            ),
        ]);

        $result = $this->converter->extractFromIfd($gps);

        self::assertSame(1, $result['alt_ref']);
        self::assertEqualsWithDelta(-50.0, $result['alt'], 0.0001);
    }

    /**
     * Verifies that altitude defaults ref to 0 when only altitude tag is present.
     */
    #[Test]
    public function defaultsAltitudeRefToZeroWhenMissing(): void
    {
        $gps    = new Ifd([
            ExifTag::GPS_ALTITUDE => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(100, 1),
            ),
        ]);

        $result = $this->converter->extractFromIfd($gps);

        self::assertSame(0, $result['alt_ref']);
        self::assertEqualsWithDelta(100.0, $result['alt'], 0.0001);
    }

    /**
     * Tolerates negative altitude values by using absolute magnitude.
     */
    #[Test]
    public function toleratesNegativeAltitudeValue(): void
    {
        $gps    = new Ifd([
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(
                ExifTag::GPS_ALTITUDE,
                5,
                1,
                new ExifRational(-50, 1),
            ),
        ]);

        $result = $this->converter->extractFromIfd($gps);

        self::assertEqualsWithDelta(50.0, $result['alt'], 0.0001);
    }

    // ── Speed ─────────────────────────────────────────────────────────

    /**
     * Verifies speed conversion from km/h to m/s.
     */
    #[Test]
    #[DataProvider('provideSpeedConversions')]
    public function convertsSpeedToMs(string $ref, float $input, float $expectedMs): void
    {
        $gps    = new Ifd([
            ExifTag::GPS_SPEED_REF => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 2, $ref),
            ExifTag::GPS_SPEED     => new IfdEntry(
                ExifTag::GPS_SPEED,
                5,
                1,
                new ExifRational((int) ($input * 1000), 1000),
            ),
        ]);

        $result = $this->converter->extractFromIfd($gps);

        self::assertNotNull($result['speed_ms']);
        self::assertEqualsWithDelta($expectedMs, $result['speed_ms'], 0.001);
        self::assertSame($ref, $result['speed_ref']);
    }

    /**
     * @return iterable<string, array{0: string, 1: float, 2: float}>
     */
    public static function provideSpeedConversions(): iterable
    {
        // 36 km/h = 10 m/s
        yield 'km/h to m/s' => ['K', 36.0, 10.0];

        // 100 mph * 0.44704 = 44.704 m/s
        yield 'mph to m/s' => ['M', 100.0, 44.704];

        // 10 knots * 0.5144444... = 5.1444... m/s
        yield 'knots to m/s' => ['N', 10.0, 5.14444];
    }

    /**
     * Verifies that speed defaults ref to K when only speed value tag is present.
     */
    #[Test]
    public function defaultsSpeedRefToKWhenMissing(): void
    {
        $gps    = new Ifd([
            ExifTag::GPS_SPEED => new IfdEntry(
                ExifTag::GPS_SPEED,
                5,
                1,
                new ExifRational(36000, 1000),
            ),
        ]);

        $result = $this->converter->extractFromIfd($gps);

        self::assertSame('K', $result['speed_ref']);
        self::assertNotNull($result['speed_ms']);
        self::assertEqualsWithDelta(10.0, $result['speed_ms'], 0.001);
    }

    // ── Destination distance ──────────────────────────────────────────

    /**
     * Verifies destination distance conversion to metres.
     */
    #[Test]
    #[DataProvider('provideDistanceConversions')]
    public function convertsDestDistanceToMetres(string $ref, float $input, float $expectedM): void
    {
        $gps    = new Ifd([
            ExifTag::GPS_DEST_DISTANCE_REF => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 2, $ref),
            ExifTag::GPS_DEST_DISTANCE     => new IfdEntry(
                ExifTag::GPS_DEST_DISTANCE,
                5,
                1,
                new ExifRational((int) ($input * 1000), 1000),
            ),
        ]);

        $result = $this->converter->extractFromIfd($gps);

        self::assertNotNull($result['dest_distance_m']);
        self::assertEqualsWithDelta($expectedM, $result['dest_distance_m'], 0.01);
        self::assertSame($ref, $result['dest_distance_ref']);
    }

    /**
     * @return iterable<string, array{0: string, 1: float, 2: float}>
     */
    public static function provideDistanceConversions(): iterable
    {
        // 5 km = 5000 m
        yield 'km to metres' => ['K', 5.0, 5000.0];

        // 1 mile = 1609.344 m
        yield 'miles to metres' => ['M', 1.0, 1609.344];

        // 1 nautical mile = 1852 m
        yield 'nautical miles to metres' => ['N', 1.0, 1852.0];
    }

    /**
     * Verifies that destination distance defaults ref to K when only value tag is present.
     */
    #[Test]
    public function defaultsDestDistanceRefToKWhenMissing(): void
    {
        $gps    = new Ifd([
            ExifTag::GPS_DEST_DISTANCE => new IfdEntry(
                ExifTag::GPS_DEST_DISTANCE,
                5,
                1,
                new ExifRational(5000, 1000),
            ),
        ]);

        $result = $this->converter->extractFromIfd($gps);

        self::assertSame('K', $result['dest_distance_ref']);
        self::assertNotNull($result['dest_distance_m']);
        self::assertEqualsWithDelta(5000.0, $result['dest_distance_m'], 0.01);
    }

    // ── Empty IFD ─────────────────────────────────────────────────────

    /**
     * Verifies that an empty IFD produces all-null results.
     */
    #[Test]
    public function returnsAllNullsForEmptyIfd(): void
    {
        $result = $this->converter->extractFromIfd(new Ifd([]));

        self::assertNull($result['alt_ref']);
        self::assertNull($result['alt']);
        self::assertNull($result['speed_ref']);
        self::assertNull($result['speed_ms']);
        self::assertNull($result['speed_original_ref']);
        self::assertNull($result['speed_original']);
        self::assertNull($result['dest_distance_ref']);
        self::assertNull($result['dest_distance_m']);
        self::assertNull($result['dest_distance_original_ref']);
        self::assertNull($result['dest_distance_original']);
    }

    // ── normalizeAltitudeRef ──────────────────────────────────────────

    /**
     * Verifies normalizeAltitudeRef accepts valid integer values 0-3.
     */
    #[Test]
    #[DataProvider('provideValidAltitudeRefs')]
    public function normalizesValidAltitudeRef(int|float|string $input, int $expected): void
    {
        self::assertSame($expected, $this->converter->normalizeAltitudeRef($input));
    }

    /**
     * @return iterable<string, array{0: int|float|string, 1: int}>
     */
    public static function provideValidAltitudeRefs(): iterable
    {
        yield 'int 0' => [0, 0];
        yield 'int 1' => [1, 1];
        yield 'int 2' => [2, 2];
        yield 'int 3' => [3, 3];
        yield 'float 0.0' => [0.0, 0];
        yield 'string 2' => ['2', 2];
    }

    /**
     * Verifies normalizeAltitudeRef rejects out-of-range or invalid values.
     */
    #[Test]
    #[DataProvider('provideInvalidAltitudeRefs')]
    public function rejectsInvalidAltitudeRef(int|float|string|null $input): void
    {
        self::assertNull($this->converter->normalizeAltitudeRef($input));
    }

    /**
     * @return iterable<string, array{0: int|float|string|null}>
     */
    public static function provideInvalidAltitudeRefs(): iterable
    {
        yield 'negative' => [-1];
        yield 'above 3' => [4];
        yield 'null' => [null];
        yield 'float 1.5' => [1.5];
        yield 'empty string' => [''];
        yield 'non-numeric string' => ['abc'];
        yield 'float string' => ['1.5'];
    }

    /**
     * Verifies normalizeAltitudeRef unwraps ExifNumericList.
     */
    #[Test]
    public function normalizesAltitudeRefFromNumericList(): void
    {
        $list = new ExifNumericList([1]);

        self::assertSame(1, $this->converter->normalizeAltitudeRef($list));
    }

    /**
     * Verifies normalizeAltitudeRef unwraps ExifRationalList.
     */
    #[Test]
    public function normalizesAltitudeRefFromRationalList(): void
    {
        $list = new ExifRationalList([new ExifRational(2, 1)]);

        self::assertSame(2, $this->converter->normalizeAltitudeRef($list));
    }

    /**
     * Verifies normalizeAltitudeRef unwraps a single ExifRational.
     */
    #[Test]
    public function normalizesAltitudeRefFromRational(): void
    {
        self::assertSame(0, $this->converter->normalizeAltitudeRef(new ExifRational(0, 1)));
    }

    // ── speedToMs / distanceToMetres standalone ───────────────────────

    /**
     * Verifies speedToMs returns null for null ref.
     */
    #[Test]
    public function speedToMsReturnsNullForNullRef(): void
    {
        self::assertNull($this->converter->speedToMs(null, 100.0));
    }

    /**
     * Verifies speedToMs returns null for unsupported ref.
     */
    #[Test]
    public function speedToMsReturnsNullForUnsupportedRef(): void
    {
        self::assertNull($this->converter->speedToMs('X', 100.0));
    }

    /**
     * Verifies distanceToMetres returns null for null ref.
     */
    #[Test]
    public function distanceToMetresReturnsNullForNullRef(): void
    {
        self::assertNull($this->converter->distanceToMetres(null, 100.0));
    }

    /**
     * Verifies distanceToMetres returns null for unsupported ref.
     */
    #[Test]
    public function distanceToMetresReturnsNullForUnsupportedRef(): void
    {
        self::assertNull($this->converter->distanceToMetres('X', 100.0));
    }
}
