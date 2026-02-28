<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Validates EXIF enum and boolean flag conversion for backed enums and maker-note safety.
 * It verifies type-safe conversion of raw EXIF values and graceful degradation for unknowns.
 *
 * @internal
 */
#[CoversClass(EnumConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(ExifNumericList::class)]
final class EnumConverterTest extends TestCase
{
    private EnumConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new EnumConverter(
            new RationalConverter(new NumericConverter()),
        );
    }

    /**
     * Maps a valid integer value to the corresponding enum case.
     */
    #[Test]
    public function toEnumOrNullMapsValidIntToEnum(): void
    {
        $result = $this->converter->toEnumOrNull(Orientation::class, 1);

        self::assertSame(Orientation::TopLeft, $result);
    }

    /**
     * Maps a valid numeric string to the corresponding enum case.
     */
    #[Test]
    public function toEnumOrNullMapsNumericStringToEnum(): void
    {
        $result = $this->converter->toEnumOrNull(Orientation::class, '6');

        self::assertSame(Orientation::RightTop, $result);
    }

    /**
     * Returns null for an invalid enum backing value.
     */
    #[Test]
    public function toEnumOrNullReturnsNullForInvalidValue(): void
    {
        self::assertNull($this->converter->toEnumOrNull(Orientation::class, 99));
    }

    /**
     * Returns null when the input is null.
     */
    #[Test]
    public function toEnumOrNullReturnsNullForNull(): void
    {
        self::assertNull($this->converter->toEnumOrNull(Orientation::class, null));
    }

    /**
     * Returns null for empty string input.
     */
    #[Test]
    public function toEnumOrNullReturnsNullForEmptyString(): void
    {
        self::assertNull($this->converter->toEnumOrNull(Orientation::class, ''));
    }

    /**
     * Returns null for a non-numeric string that is not a valid enum backing.
     */
    #[Test]
    public function toEnumOrNullThrowsTypeErrorForNonNumericString(): void
    {
        $this->expectException(TypeError::class);
        $this->converter->toEnumOrNull(Orientation::class, 'invalid');
    }

    #[Test]
    #[DataProvider('makerNoteSafetyProvider')]
    public function makerNoteSafetyMapsInputsToExpectedValues(
        int|float|string|ExifNumericList|ExifRational|null $input,
        ?bool $expected,
    ): void {
        self::assertSame($expected, $this->converter->makerNoteSafety($input));
    }

    /**
     * @return iterable<string, array{0: int|float|string|ExifNumericList|ExifRational|null, 1: bool|null}>
     */
    public static function makerNoteSafetyProvider(): iterable
    {
        yield 'zero' => [0, false];
        yield 'one' => [1, true];
        yield 'out of range' => [2, null];
        yield 'null' => [null, null];
        yield 'whole float' => [1.0, true];
        yield 'fractional float' => [0.5, null];
        yield 'numeric string' => ['1', true];
        yield 'non-digit string' => ['abc', null];
        yield 'numeric list' => [new ExifNumericList([0]), false];
        yield 'rational' => [new ExifRational(1, 1), true];
        yield 'zero denominator rational' => [new ExifRational(1, 0), null];
    }
}
