<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\SensorDataReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
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
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(SensorDataReader::class)]
#[UsesClass(ValueConverters::class)]
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
