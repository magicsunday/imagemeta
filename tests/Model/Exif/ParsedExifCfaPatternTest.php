<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Value\CfaPattern;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedExif::class)]
final class ParsedExifCfaPatternTest extends TestCase
{
    #[Test]
    public function parsesCfaPatternWithRepeatUnits(): void
    {
        $cfaPattern = new IfdEntry(
            ExifTag::CFA_PATTERN,
            7,
            6,
            new ExifNumericList([2, 2, 0, 1, 2, 1]),
        );

        $exifIfd   = new Ifd([ExifTag::CFA_PATTERN => $cfaPattern]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        $pattern = $parsedExif->cfaPattern();

        self::assertInstanceOf(CfaPattern::class, $pattern);
        self::assertSame(2, $pattern->horizontalRepeatPixelUnit);
        self::assertSame(2, $pattern->verticalRepeatPixelUnit);
        self::assertSame([
            [CfaPatternColor::RED, CfaPatternColor::GREEN],
            [CfaPatternColor::BLUE, CfaPatternColor::GREEN],
        ], $pattern->grid());
    }

    #[Test]
    public function returnsNullWhenPatternIsIncomplete(): void
    {
        $cfaPattern = new IfdEntry(
            ExifTag::CFA_PATTERN,
            7,
            4,
            new ExifNumericList([2, 2, 0, 1]),
        );

        $exifIfd   = new Ifd([ExifTag::CFA_PATTERN => $cfaPattern]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->cfaPattern());
    }
}
