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
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedExif::class)]
final class ParsedExifBasicTagsTest extends TestCase
{
    #[Test]
    public function imageDescriptionTrimsNullPadding(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_DESCRIPTION => new IfdEntry(
                ExifTag::IMAGE_DESCRIPTION,
                TiffConst::TYPE_ASCII,
                22,
                "1988 company picnic\0\0",
            ),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame('1988 company picnic', $parsedExif->imageDescription());
    }

    #[Test]
    public function cameraMakeAndModelReturnStrings(): void
    {
        $ifd0 = new Ifd([
            ExifTag::MAKE  => new IfdEntry(ExifTag::MAKE, TiffConst::TYPE_ASCII, 8, "Magic\0"),
            ExifTag::MODEL => new IfdEntry(ExifTag::MODEL, TiffConst::TYPE_ASCII, 13, "PhotonPro\0\0"),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame('Magic', $parsedExif->cameraMake());
        self::assertSame('PhotonPro', $parsedExif->cameraModel());
    }

    #[Test]
    public function softwareReturnsNullForEmptyString(): void
    {
        $ifd0 = new Ifd([
            ExifTag::SOFTWARE => new IfdEntry(
                ExifTag::SOFTWARE,
                TiffConst::TYPE_ASCII,
                1,
                "\0",
            ),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->software());
    }
}
