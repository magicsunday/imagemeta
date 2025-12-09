<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedExif::class)]
final class ParsedExifColorSpaceAndGammaTest extends TestCase
{
    #[Test]
    public function colorSpaceIsNullForReservedValues(): void
    {
        $exifIfd = new Ifd([
            ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, 2),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->colorSpace());
    }

    #[Test]
    public function gammaReturnsRationalValue(): void
    {
        $exifIfd = new Ifd([
            ExifTag::GAMMA => new IfdEntry(ExifTag::GAMMA, 5, 1, [22, 10]),
        ]);

        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertSame(2.2, $parsedExif->gamma());
    }

    #[Test]
    public function gammaReturnsNullWhenMissing(): void
    {
        $parsedExif = new ParsedExif(new Ifd([]), new Ifd([]), null, null, null);

        self::assertNull($parsedExif->gamma());
    }
}
