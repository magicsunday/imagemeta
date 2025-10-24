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
        self::assertSame('2.20', ValueConverters::toExifVersion('0220'));
        self::assertSame('2.31', ValueConverters::toExifVersion('0231'));
        self::assertSame('3.00', ValueConverters::toExifVersion('0300'));
        self::assertSame('Exif', ValueConverters::toExifVersion("Exif\0\0"));
        self::assertNull(ValueConverters::toExifVersion('0240'));
        self::assertNull(ValueConverters::toExifVersion("\x01\x02\x03\x04"));
        $flash = ValueConverters::flashFromShort(0x59);
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
