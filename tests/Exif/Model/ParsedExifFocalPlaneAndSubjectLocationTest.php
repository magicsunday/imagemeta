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
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\FocalReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\SceneModeReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises focal plane resolution and subject location tag decoding.
 * It verifies rational values are converted to floats and units are exposed correctly.
 * The suite covers subject location arrays and ensures missing tags yield nulls.
 * This keeps camera geometry metadata reliable for downstream calculations.
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
#[UsesClass(ExifRational::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(FocalReader::class)]
#[UsesClass(SceneModeReader::class)]
#[UsesClass(ValueConverters::class)]
final class ParsedExifFocalPlaneAndSubjectLocationTest extends TestCase
{
    /**
     * Provides focal plane X/Y resolution rationals and a resolution unit.
     * Confirms the rational values are converted to floats and the unit is exposed.
     */
    #[Test]
    public function focalPlaneResolutionConvertsRationals(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([
            ExifTag::FOCAL_PLANE_X_RESOLUTION => new IfdEntry(
                ExifTag::FOCAL_PLANE_X_RESOLUTION,
                TiffConst::TYPE_RATIONAL,
                1,
                [6000, 2],
            ),
            ExifTag::FOCAL_PLANE_Y_RESOLUTION => new IfdEntry(
                ExifTag::FOCAL_PLANE_Y_RESOLUTION,
                TiffConst::TYPE_RATIONAL,
                1,
                [5900, 2],
            ),
            ExifTag::FOCAL_PLANE_RESOLUTION_UNIT => new IfdEntry(
                ExifTag::FOCAL_PLANE_RESOLUTION_UNIT,
                TiffConst::TYPE_SHORT,
                1,
                3,
            ),
        ]);

        self::assertSame(3000.0, $parsedExif->focalPlaneXResolution());
        self::assertSame(2950.0, $parsedExif->focalPlaneYResolution());
        self::assertSame(3, $parsedExif->focalPlaneResolutionUnit());
    }

    /**
     * Provides a valid two-coordinate subject location and an invalid one-value entry.
     * Ensures the parser returns coordinates only when both values are present.
     */
    #[Test]
    public function subjectLocationRequiresTwoCoordinates(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([
            ExifTag::SUBJECT_LOCATION => new IfdEntry(
                ExifTag::SUBJECT_LOCATION,
                TiffConst::TYPE_SHORT,
                2,
                [1200, 800],
            ),
        ]);

        self::assertSame([1200, 800], $parsedExif->subjectLocation());

        $parsedInvalid = $this->parsedExifFromExifEntries([
            ExifTag::SUBJECT_LOCATION => new IfdEntry(
                ExifTag::SUBJECT_LOCATION,
                TiffConst::TYPE_SHORT,
                1,
                [42],
            ),
        ]);

        self::assertNull($parsedInvalid->subjectLocation());
    }

    /**
     * @param array<int, IfdEntry> $exifEntries
     */
    private function parsedExifFromExifEntries(array $exifEntries): ParsedExif
    {
        return new ParsedExif(new Ifd([]), new Ifd($exifEntries), null, null, null);
    }
}
