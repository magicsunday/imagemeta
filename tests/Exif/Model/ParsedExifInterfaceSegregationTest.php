<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifGpsDataInterface;
use MagicSunday\ImageMeta\Exif\Model\ExifIfd0DataInterface;
use MagicSunday\ImageMeta\Exif\Model\ExifIfd1DataInterface;
use MagicSunday\ImageMeta\Exif\Model\ExifInteropDataInterface;
use MagicSunday\ImageMeta\Exif\Model\ExifSubIfdDataInterface;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
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
 * Verifies interface segregation contracts implemented by ParsedExif.
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
final class ParsedExifInterfaceSegregationTest extends TestCase
{
    /**
     * Creates a ParsedExif instance with minimal IFD data.
     * Verifies all EXIF area interfaces are implemented for BC-safe consumption.
     */
    #[Test]
    public function parsedExifImplementsAllSegmentInterfaces(): void
    {
        $parsedExif = new ParsedExif(new Ifd([]), null, null, null, null);
        $interfaces = class_implements($parsedExif);

        self::assertContains(ExifIfd0DataInterface::class, $interfaces);
        self::assertContains(ExifIfd1DataInterface::class, $interfaces);
        self::assertContains(ExifSubIfdDataInterface::class, $interfaces);
        self::assertContains(ExifGpsDataInterface::class, $interfaces);
        self::assertContains(ExifInteropDataInterface::class, $interfaces);
    }
}
