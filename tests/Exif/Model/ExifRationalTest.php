<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the ExifRational value object with positive, zero, and negative numerators.
 * It verifies that numerator and denominator properties preserve the supplied values.
 * The suite includes whole-number and fractional cases to reflect EXIF tag usage.
 * This ensures rational values remain stable across parsing and value mapping.
 */
#[CoversClass(ExifRational::class)]
final class ExifRationalTest extends TestCase
{
    /**
     * Instantiates ExifRational with a numerator and denominator.
     * Confirms the public properties expose the same values that were supplied.
     */
    #[Test]
    public function constructsWithNumeratorAndDenominator(): void
    {
        $rational = new ExifRational(3, 2);

        self::assertSame(3, $rational->numerator);
        self::assertSame(2, $rational->denominator);
    }

    /**
     * Creates a rational with a zero numerator to represent a zero value.
     * Ensures the numerator stays zero and the denominator remains unchanged.
     */
    #[Test]
    public function handlesZeroNumerator(): void
    {
        $rational = new ExifRational(0, 1);

        self::assertSame(0, $rational->numerator);
        self::assertSame(1, $rational->denominator);
    }

    /**
     * Creates a rational with a negative numerator to represent a negative value.
     * Confirms the sign is preserved and the denominator is stored as provided.
     */
    #[Test]
    public function handlesNegativeValues(): void
    {
        $rational = new ExifRational(-3, 2);

        self::assertSame(-3, $rational->numerator);
        self::assertSame(2, $rational->denominator);
    }

    /**
     * Uses a denominator of one to represent a whole number value.
     * Verifies the value object stores the exact numerator and denominator.
     */
    #[Test]
    public function handlesWholeNumbers(): void
    {
        $rational = new ExifRational(10, 1);

        self::assertSame(10, $rational->numerator);
        self::assertSame(1, $rational->denominator);
    }

    /**
     * Creates a rational with a larger denominator to represent a fine-grained fraction.
     * Confirms the value object does not normalize and keeps the supplied values.
     */
    #[Test]
    public function handlesLargeDenominator(): void
    {
        $rational = new ExifRational(1, 125);

        self::assertSame(1, $rational->numerator);
        self::assertSame(125, $rational->denominator);
    }
}
