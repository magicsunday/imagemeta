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
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises composite image count decoding from the SourceImageNumberOfCompositeImage tag.
 * It verifies valid two-element counts are returned intact when they meet spec constraints.
 * The tests reject counts that are too small or malformed and return null.
 * This ensures composite metadata is only exposed when the payload is valid.
 */
#[CoversClass(ParsedExif::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
final class ParsedExifCompositeImageTest extends TestCase
{
    /**
     * Provides composite image counts that meet the EXIF constraints.
     * Verifies the parser returns the two-element array when values are valid.
     */
    #[Test]
    public function returnsCountsWhenValuesMeetSpecRequirements(): void
    {
        self::assertSame([6, 4], $this->parseCompositeImageCount([6, 4]));
    }

    /**
     * Uses counts that fall below the minimum required totals.
     * Ensures the parser returns null when the composite counts are invalid.
     */
    #[Test]
    public function returnsNullWhenCountsAreBelowMinimum(): void
    {
        self::assertNull($this->parseCompositeImageCount([1, 0]));
    }

    /**
     * Sets the used count higher than the captured total.
     * Confirms the parser rejects inconsistent composite image counts by returning null.
     */
    #[Test]
    public function returnsNullWhenUsedCountExceedsCapturedTotal(): void
    {
        self::assertNull($this->parseCompositeImageCount([3, 5]));
    }

    /**
     * @param list<int> $values
     *
     * @return list<int>|null
     */
    private function parseCompositeImageCount(array $values): ?array
    {
        $exifIfd = new Ifd([
            ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE,
                TiffConst::TYPE_SHORT,
                2,
                $values,
            ),
        ]);

        return (new ParsedExif(new Ifd([]), $exifIfd, null, null, null))
            ->sourceImageNumberOfCompositeImage();
    }
}
