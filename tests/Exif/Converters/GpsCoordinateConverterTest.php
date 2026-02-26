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
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\ValidatesGpsRef;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises GpsCoordinateConverter::dmsToFloat directly, verifying DMS-to-decimal
 * conversion, null handling, component validation, and geographic range checks.
 *
 * @internal
 */
#[CoversClass(GpsCoordinateConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesTrait(ValidatesGpsRef::class)]
final class GpsCoordinateConverterTest extends TestCase
{
    private GpsCoordinateConverter $converter;

    protected function setUp(): void
    {
        $numericConverter  = new NumericConverter();
        $rationalConverter = new RationalConverter($numericConverter);

        $this->converter = new GpsCoordinateConverter($rationalConverter, $numericConverter);
    }

    /**
     * Verifies that valid DMS values with N/S/E/W references produce correct decimal degrees.
     */
    #[Test]
    #[DataProvider('provideValidDmsConversions')]
    public function convertsValidDmsToFloat(string $ref, ExifRationalList $val, float $expected): void
    {
        $result = $this->converter->dmsToFloat($ref, $val);

        self::assertNotNull($result);
        self::assertEqualsWithDelta($expected, $result, 0.000001);
    }

    /**
     * @return iterable<string, array{0: string, 1: ExifRationalList, 2: float}>
     */
    public static function provideValidDmsConversions(): iterable
    {
        // 52°31'12.0" = 52 + 31/60 + 12/3600 = 52.52
        $dms = new ExifRationalList([
            new ExifRational(52, 1),
            new ExifRational(31, 1),
            new ExifRational(12000, 1000),
        ]);

        yield 'north latitude' => ['N', $dms, 52.52];
        yield 'south latitude' => ['S', $dms, -52.52];

        // 13°24'17.82" = 13 + 24/60 + 17.82/3600 = 13.404950
        $dmsLon = new ExifRationalList([
            new ExifRational(13, 1),
            new ExifRational(24, 1),
            new ExifRational(17820, 1000),
        ]);

        yield 'east longitude' => ['E', $dmsLon, 13.404950];
        yield 'west longitude' => ['W', $dmsLon, -13.404950];
    }

    /**
     * Verifies that null inputs produce null output.
     */
    #[Test]
    #[DataProvider('provideNullInputs')]
    public function returnsNullForNullInputs(?string $ref, ?ExifRationalList $val): void
    {
        self::assertNull($this->converter->dmsToFloat($ref, $val));
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?ExifRationalList}>
     */
    public static function provideNullInputs(): iterable
    {
        yield 'both null' => [null, null];

        yield 'null val with non-null ref' => [
            'N',
            null,
        ];
    }

    /**
     * Rejects DMS triplets containing a negative component.
     */
    #[Test]
    public function rejectsNegativeDmsComponent(): void
    {
        $val = new ExifRationalList([
            new ExifRational(-12, 1),
            new ExifRational(34, 1),
            new ExifRational(56, 1),
        ]);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1467);

        $this->converter->dmsToFloat('N', $val);
    }

    /**
     * Tolerates DMS triplets where minutes are >= 60 by carrying over into degrees.
     */
    #[Test]
    public function toleratesMinutesAtOrAbove60(): void
    {
        // 12° 75' 0" → carry: 13° 15' 0" = 13.25
        $val = new ExifRationalList([
            new ExifRational(12, 1),
            new ExifRational(75, 1),
            new ExifRational(0, 1),
        ]);

        $result = $this->converter->dmsToFloat('N', $val);

        self::assertNotNull($result);
        self::assertEqualsWithDelta(13.25, $result, 0.000001);
    }

    /**
     * Tolerates DMS triplets where seconds are >= 60 by carrying over into minutes.
     */
    #[Test]
    public function toleratesSecondsAtOrAbove60(): void
    {
        // 12° 30' 75" → carry: 12° 31' 15" = 12 + 31/60 + 15/3600 = 12.520833...
        $val = new ExifRationalList([
            new ExifRational(12, 1),
            new ExifRational(30, 1),
            new ExifRational(75, 1),
        ]);

        $result = $this->converter->dmsToFloat('E', $val);

        self::assertNotNull($result);
        self::assertEqualsWithDelta(12.520833, $result, 0.000001);
    }

    /**
     * Tolerates latitude values outside the nominal EXIF range and preserves raw values.
     *
     * @param list<ExifRational> $rationals
     */
    #[Test]
    #[DataProvider('provideOutOfRangeLatitudes')]
    public function toleratesLatitudeOutOfRange(string $ref, array $rationals, float $expected): void
    {
        $val    = new ExifRationalList($rationals);
        $result = $this->converter->dmsToFloat($ref, $val);

        self::assertNotNull($result);
        self::assertEqualsWithDelta($expected, $result, 0.000001);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<ExifRational>, 2: float}>
     */
    public static function provideOutOfRangeLatitudes(): iterable
    {
        yield 'north above 90' => [
            'N',
            [new ExifRational(91, 1), new ExifRational(0, 1), new ExifRational(0, 1)],
            91.0,
        ];

        yield 'south above 90' => [
            'S',
            [new ExifRational(91, 1), new ExifRational(0, 1), new ExifRational(0, 1)],
            -91.0,
        ];
    }

    /**
     * Tolerates longitude values outside the nominal EXIF range and preserves raw values.
     *
     * @param list<ExifRational> $rationals
     */
    #[Test]
    #[DataProvider('provideOutOfRangeLongitudes')]
    public function toleratesLongitudeOutOfRange(string $ref, array $rationals, float $expected): void
    {
        $val    = new ExifRationalList($rationals);
        $result = $this->converter->dmsToFloat($ref, $val);

        self::assertNotNull($result);
        self::assertEqualsWithDelta($expected, $result, 0.000001);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<ExifRational>, 2: float}>
     */
    public static function provideOutOfRangeLongitudes(): iterable
    {
        yield 'east above 180' => [
            'E',
            [new ExifRational(181, 1), new ExifRational(0, 1), new ExifRational(0, 1)],
            181.0,
        ];

        yield 'west above 180' => [
            'W',
            [new ExifRational(181, 1), new ExifRational(0, 1), new ExifRational(0, 1)],
            -181.0,
        ];
    }

    /**
     * Returns null when the DMS list does not contain exactly 3 components.
     *
     * @param list<ExifRational> $rationals
     */
    #[Test]
    #[DataProvider('provideWrongComponentCounts')]
    public function returnsNullForWrongComponentCount(array $rationals): void
    {
        $val = new ExifRationalList($rationals);

        self::assertNull($this->converter->dmsToFloat('N', $val));
    }

    /**
     * @return iterable<string, array{0: list<ExifRational>}>
     */
    public static function provideWrongComponentCounts(): iterable
    {
        yield '2 components' => [
            [new ExifRational(52, 1), new ExifRational(31, 1)],
        ];

        yield '4 components' => [
            [new ExifRational(52, 1), new ExifRational(31, 1), new ExifRational(12, 1), new ExifRational(0, 1)],
        ];

        yield '1 component' => [
            [new ExifRational(52, 1)],
        ];
    }
}
