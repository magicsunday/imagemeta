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
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises composite image count decoding from the SourceImageNumberOfCompositeImage tag.
 * It verifies valid two-element counts are returned intact when they meet spec constraints.
 * The tests reject counts that are too small or malformed and return null.
 * This ensures composite metadata is only exposed when the payload is valid.
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifCompositeImageTest extends TestCase
{
    /**
     * Provides composite image counts that meet the EXIF constraints.
     * Verifies the parser returns the two-element array when values are valid.
     *
     * @return void
     */
    #[Test]
    public function returnsCountsWhenValuesMeetSpecRequirements(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE,
                TiffConst::TYPE_SHORT,
                2,
                [6, 4],
            ),
        ]);

        $parsedExif = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame([6, 4], $parsedExif->sourceImageNumberOfCompositeImage());
    }

    /**
     * Uses counts that fall below the minimum required totals.
     * Ensures the parser returns null when the composite counts are invalid.
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenCountsAreBelowMinimum(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE,
                TiffConst::TYPE_SHORT,
                2,
                [1, 0],
            ),
        ]);

        $parsedExif = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertNull($parsedExif->sourceImageNumberOfCompositeImage());
    }

    /**
     * Sets the used count higher than the captured total.
     * Confirms the parser rejects inconsistent composite image counts by returning null.
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenUsedCountExceedsCapturedTotal(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE,
                TiffConst::TYPE_SHORT,
                2,
                [3, 5],
            ),
        ]);

        $parsedExif = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertNull($parsedExif->sourceImageNumberOfCompositeImage());
    }
}
