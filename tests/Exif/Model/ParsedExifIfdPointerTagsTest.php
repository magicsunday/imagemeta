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
 * Covers ParsedExif IFD pointer tag storage via the underlying Ifd entries.
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
final class ParsedExifIfdPointerTagsTest extends TestCase
{
    /**
     * Provides all EXIF pointer tags with valid scalar offsets.
     * Verifies each pointer value is accessible through the underlying Ifd entries.
     */
    #[Test]
    public function pointerEntriesReturnConfiguredOffsets(): void
    {
        $ifd0 = new Ifd([
            ExifTag::EXIF_IFD_POINTER => new IfdEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, 128),
            ExifTag::GPS_IFD_POINTER  => new IfdEntry(ExifTag::GPS_IFD_POINTER, 4, 1, 256),
        ]);

        $exifIfd = new Ifd([
            ExifTag::INTEROPERABILITY_IFD_POINTER => new IfdEntry(ExifTag::INTEROPERABILITY_IFD_POINTER, 4, 1, 384),
        ]);

        $parsedExif = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(128, $parsedExif->ifd0->get(ExifTag::EXIF_IFD_POINTER)?->value);
        self::assertSame(256, $parsedExif->ifd0->get(ExifTag::GPS_IFD_POINTER)?->value);
        self::assertSame(384, $parsedExif->exifIfd?->get(ExifTag::INTEROPERABILITY_IFD_POINTER)?->value);
    }

    /**
     * Uses EXIF data without pointer tags.
     * Verifies pointer entries return null when tags are absent.
     */
    #[Test]
    public function pointerEntriesReturnNullWhenTagsAreAbsent(): void
    {
        $parsedExif = new ParsedExif(new Ifd([]), null, null, null, null);

        self::assertNull($parsedExif->ifd0->get(ExifTag::EXIF_IFD_POINTER));
        self::assertNull($parsedExif->ifd0->get(ExifTag::GPS_IFD_POINTER));
        self::assertNull($parsedExif->exifIfd?->get(ExifTag::INTEROPERABILITY_IFD_POINTER));
    }
}
