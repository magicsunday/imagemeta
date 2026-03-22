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
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises basic EXIF string tags exposed by ParsedExif.
 * It verifies trimming of null padding for ImageDescription, Make, and Model fields.
 * The suite checks that common IFD0 ASCII tags map to the expected getters.
 * This ensures baseline camera metadata remains clean and user-facing.
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
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
final class ParsedExifBasicTagsTest extends TestCase
{
    /**
     * Provides an ASCII ImageDescription that includes null padding.
     * Verifies imageDescription trims trailing nulls to return the clean text.
     */
    #[Test]
    public function imageDescriptionTrimsNullPadding(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::IMAGE_DESCRIPTION => new IfdEntry(
                ExifTag::IMAGE_DESCRIPTION,
                TiffConst::TYPE_ASCII,
                22,
                "1988 company picnic\0\0",
            ),
        ]);

        self::assertSame('1988 company picnic', $parsedExif->imageDescription());
    }

    /**
     * Supplies MAKE and MODEL strings with trailing null bytes.
     * Ensures cameraMake() and cameraModel() return trimmed human-readable values.
     */
    #[Test]
    public function cameraMakeAndModelReturnStrings(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::MAKE  => new IfdEntry(ExifTag::MAKE, TiffConst::TYPE_ASCII, 8, "Magic\0"),
            ExifTag::MODEL => new IfdEntry(ExifTag::MODEL, TiffConst::TYPE_ASCII, 13, "PhotonPro\0\0"),
        ]);

        self::assertSame('Magic', $parsedExif->cameraMake());
        self::assertSame('PhotonPro', $parsedExif->cameraModel());
    }

    /**
     * Uses a SOFTWARE tag that contains only a null terminator.
     * Confirms software() returns null rather than an empty string.
     */
    #[Test]
    public function softwareReturnsNullForEmptyString(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::SOFTWARE => new IfdEntry(
                ExifTag::SOFTWARE,
                TiffConst::TYPE_ASCII,
                1,
                "\0",
            ),
        ]);

        self::assertNull($parsedExif->software());
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     */
    private function parsedExifFromIfd0(array $ifd0Entries): ParsedExif
    {
        return new ParsedExif(new Ifd($ifd0Entries), null, null, null, null);
    }
}
