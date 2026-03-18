<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises string-based EXIF tags with placeholders and fallbacks.
 * It checks that blank DateTime fields are treated as unknown values.
 * The suite verifies artist/camera owner fallback behavior when primary tags are missing.
 * This keeps human-readable string metadata consistent and predictable.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
final class ParsedExifStringTagsTest extends TestCase
{
    /**
     * Treats the all-blank DateTime placeholder as missing metadata.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function dateTimeTreatsBlankPlaceholderAsUnknown(): void
    {
        $ifd0       = new Ifd([
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
     * Falls back to camera owner tags when the artist tag is absent.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function artistFallsBackToRelatedAttributionTags(): void
    {
        $ifd0       = new Ifd([
            ExifTag::PHOTOGRAPHER => new IfdEntry(
                ExifTag::PHOTOGRAPHER,
                2,
                1,
                'Photographer',
            ),
        ]);

        $exifIfd    = new Ifd([
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
     * Treats a blank-filled copyright string as missing.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function copyrightTreatsBlankFilledFieldAsUnknown(): void
    {
        $ifd0       = new Ifd([
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
