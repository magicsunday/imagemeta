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
 * Covers ParsedExif accessors for EXIF IFD pointer tags.
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
     * Verifies each pointer accessor returns the configured offset.
     *
     * @return void
     */
    #[Test]
    public function pointerAccessorsReturnConfiguredOffsets(): void
    {
        $ifd0 = new Ifd([
            ExifTag::EXIF_IFD_POINTER => new IfdEntry(ExifTag::EXIF_IFD_POINTER, 4, 1, 128),
            ExifTag::GPS_IFD_POINTER  => new IfdEntry(ExifTag::GPS_IFD_POINTER, 4, 1, 256),
        ]);

        $exifIfd = new Ifd([
            ExifTag::INTEROPERABILITY_IFD_POINTER => new IfdEntry(ExifTag::INTEROPERABILITY_IFD_POINTER, 4, 1, 384),
        ]);

        $parsedExif = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(128, $parsedExif->exifIfdPointer());
        self::assertSame(256, $parsedExif->gpsIfdPointer());
        self::assertSame(384, $parsedExif->interoperabilityIfdPointer());
    }

    /**
     * Uses EXIF data without pointer tags.
     * Verifies pointer accessors return null when tags are absent.
     *
     * @return void
     */
    #[Test]
    public function pointerAccessorsReturnNullWhenTagsAreAbsent(): void
    {
        $parsedExif = new ParsedExif(new Ifd([]), null, null, null, null);

        self::assertNull($parsedExif->exifIfdPointer());
        self::assertNull($parsedExif->gpsIfdPointer());
        self::assertNull($parsedExif->interoperabilityIfdPointer());
    }
}
