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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ExifRational value object.
 */
#[CoversClass(ExifRational::class)]
final class ExifRationalTest extends TestCase
{
    /**
     * Verifies that $rational->numerator equals 3.
     *
     * @return void
     */
    #[Test]
    public function constructsWithNumeratorAndDenominator(): void
    {
        $rational = new ExifRational(3, 2);

        self::assertSame(3, $rational->numerator);
        self::assertSame(2, $rational->denominator);
    }

    /**
     * Verifies that $rational->numerator equals 0.
     *
     * @return void
     */
    #[Test]
    public function handlesZeroNumerator(): void
    {
        $rational = new ExifRational(0, 1);

        self::assertSame(0, $rational->numerator);
        self::assertSame(1, $rational->denominator);
    }

    /**
     * Verifies that $rational->numerator equals -3.
     *
     * @return void
     */
    #[Test]
    public function handlesNegativeValues(): void
    {
        $rational = new ExifRational(-3, 2);

        self::assertSame(-3, $rational->numerator);
        self::assertSame(2, $rational->denominator);
    }

    /**
     * Verifies that $rational->numerator equals 10.
     *
     * @return void
     */
    #[Test]
    public function handlesWholeNumbers(): void
    {
        $rational = new ExifRational(10, 1);

        self::assertSame(10, $rational->numerator);
        self::assertSame(1, $rational->denominator);
    }

    /**
     * Verifies that $rational->numerator equals 1.
     *
     * @return void
     */
    #[Test]
    public function handlesLargeDenominator(): void
    {
        $rational = new ExifRational(1, 125);

        self::assertSame(1, $rational->numerator);
        self::assertSame(125, $rational->denominator);
    }
}
