<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the ExifRational value object for zero-denominator conversion edge cases.
 */
#[CoversClass(ExifRational::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(NumericConverter::class)]
final class ExifRationalTest extends TestCase
{
    /**
     * A RATIONAL with a zero denominator must produce null instead of
     * throwing a DivisionByZeroError during conversion.
     */
    #[Test]
    public function itReturnsNullForRationalWithZeroDenominator(): void
    {
        $rational  = new ExifRational(72, 0);
        $converter = new RationalConverter(new NumericConverter());

        self::assertNull($converter->toFloat($rational));
    }

    /**
     * An SRATIONAL with a zero denominator must produce null instead of
     * throwing a DivisionByZeroError during conversion.
     */
    #[Test]
    public function itReturnsNullForSrationalWithZeroDenominator(): void
    {
        $rational  = new ExifRational(-72, 0);
        $converter = new RationalConverter(new NumericConverter());

        self::assertNull($converter->toFloat($rational));
    }
}
