<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\ValueConverters;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Core\ValueConverters
 */
final class ValueConvertersTest extends TestCase
{
    #[Test]
    public function convertsRationalsAndApexValues(): void
    {
        self::assertSame(0.5, ValueConverters::rationalToFloat([1, 2]));
        self::assertSame(2.8284271247461903, ValueConverters::apexToFNumber(3.0));
    }

    #[Test]
    public function normalisesExifVersionAndFlash(): void
    {
        self::assertSame('3.00', ValueConverters::toExifVersion('0300'));
        $flash = ValueConverters::flashFromShort(0x59);
        self::assertTrue($flash->fired);
        self::assertSame(FlashMode::AUTO, $flash->mode);
        self::assertSame(FlashReturn::NO_STROBE_DETECTION, $flash->returnDetection);
    }

    #[Test]
    public function parsesSamplingAndChromaticities(): void
    {
        self::assertSame([4, 2], ValueConverters::ycbcrSubSamplingToPair('4 2'));

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
}
