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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

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
    public function toEnumOrNullReturnsNullForNonNumericString(): void
    {
        self::assertNull($this->converter->toEnumOrNull(Orientation::class, 'invalid'));
    }

    /**
     * MakerNoteSafety returns false for integer 0.
     */
    #[Test]
    public function makerNoteSafetyReturnsFalseForZero(): void
    {
        self::assertFalse($this->converter->makerNoteSafety(0));
    }

    /**
     * MakerNoteSafety returns true for integer 1.
     */
    #[Test]
    public function makerNoteSafetyReturnsTrueForOne(): void
    {
        self::assertTrue($this->converter->makerNoteSafety(1));
    }

    /**
     * MakerNoteSafety returns null for values outside the 0/1 domain.
     */
    #[Test]
    public function makerNoteSafetyReturnsNullForOutOfRange(): void
    {
        self::assertNull($this->converter->makerNoteSafety(2));
    }

    /**
     * MakerNoteSafety returns null for null input.
     */
    #[Test]
    public function makerNoteSafetyReturnsNullForNull(): void
    {
        self::assertNull($this->converter->makerNoteSafety(null));
    }

    /**
     * MakerNoteSafety handles float values that are whole numbers.
     */
    #[Test]
    public function makerNoteSafetyAcceptsWholeFloat(): void
    {
        self::assertTrue($this->converter->makerNoteSafety(1.0));
    }

    /**
     * MakerNoteSafety rejects fractional float values.
     */
    #[Test]
    public function makerNoteSafetyRejectsNonWholeFloat(): void
    {
        self::assertNull($this->converter->makerNoteSafety(0.5));
    }

    /**
     * MakerNoteSafety accepts numeric string "1".
     */
    #[Test]
    public function makerNoteSafetyAcceptsNumericString(): void
    {
        self::assertTrue($this->converter->makerNoteSafety('1'));
    }

    /**
     * MakerNoteSafety rejects non-digit string.
     */
    #[Test]
    public function makerNoteSafetyRejectsNonDigitString(): void
    {
        self::assertNull($this->converter->makerNoteSafety('abc'));
    }

    /**
     * MakerNoteSafety extracts first value from ExifNumericList.
     */
    #[Test]
    public function makerNoteSafetyExtractsFromNumericList(): void
    {
        self::assertFalse($this->converter->makerNoteSafety(new ExifNumericList([0])));
    }

    /**
     * MakerNoteSafety handles ExifRational input.
     */
    #[Test]
    public function makerNoteSafetyHandlesRational(): void
    {
        self::assertTrue($this->converter->makerNoteSafety(new ExifRational(1, 1)));
    }

    /**
     * MakerNoteSafety handles ExifRational with zero denominator.
     */
    #[Test]
    public function makerNoteSafetyHandlesZeroDenominatorRational(): void
    {
        self::assertNull($this->converter->makerNoteSafety(new ExifRational(1, 0)));
    }
}
