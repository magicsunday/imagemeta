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
final class ParsedExifStringTagsTest extends TestCase
{
    /**
     * Verifies that $parsedExif->dateTime() is null.
     *
     * @return void
     */
    #[Test]
    public function dateTimeTreatsBlankPlaceholderAsUnknown(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME => new IfdEntry(
                ExifTag::DATETIME,
                2,
                20,
                '    :  :  :  :  ',
            ),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->dateTime());
    }

    /**
     * Verifies that $parsedExif->artist() equals 'Camera Owner'.
     *
     * @return void
     */
    #[Test]
    public function artistFallsBackToRelatedAttributionTags(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHER => new IfdEntry(
                ExifTag::PHOTOGRAPHER,
                2,
                1,
                'Photographer',
            ),
        ]);

        $exifIfd = new Ifd([
            ExifTag::CAMERA_OWNER_NAME => new IfdEntry(
                ExifTag::CAMERA_OWNER_NAME,
                2,
                1,
                'Camera Owner',
            ),
            ExifTag::IMAGE_EDITOR => new IfdEntry(
                ExifTag::IMAGE_EDITOR,
                2,
                1,
                'Image Editor',
            ),
        ]);

        $parsedExif = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame('Camera Owner', $parsedExif->artist());
    }

    /**
     * Verifies that $parsedExif->copyright() is null.
     *
     * @return void
     */
    #[Test]
    public function copyrightTreatsBlankFilledFieldAsUnknown(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COPYRIGHT => new IfdEntry(
                ExifTag::COPYRIGHT,
                2,
                20,
                '                    ',
            ),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->copyright());
    }
}
