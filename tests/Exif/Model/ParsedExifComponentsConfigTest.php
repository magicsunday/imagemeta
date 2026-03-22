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
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * Exercises ComponentsConfiguration validation in ParsedExif.
 * It validates that only allowed component codes 0–6 are accepted per EXIF 3.0 §4.6.5.1.3.
 * The suite covers valid YCbCr and RGB configurations and rejects out-of-range codes.
 * This ensures component metadata is spec-compliant.
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
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
final class ParsedExifComponentsConfigTest extends TestCase
{
    /**
     * Supplies the standard YCbCr configuration [1,2,3,0].
     * Confirms componentsConfiguration() returns the valid component codes.
     */
    #[Test]
    public function acceptsStandardYcbcrConfiguration(): void
    {
        $parsedExif = $this->parsedExifWithComponents([1, 2, 3, 0]);

        self::assertSame([1, 2, 3, 0], $parsedExif->componentsConfiguration());
    }

    /**
     * Supplies the RGB configuration [4,5,6,0].
     * Confirms componentsConfiguration() returns the valid component codes.
     */
    #[Test]
    public function acceptsRgbConfiguration(): void
    {
        $parsedExif = $this->parsedExifWithComponents([4, 5, 6, 0]);

        self::assertSame([4, 5, 6, 0], $parsedExif->componentsConfiguration());
    }

    /**
     * Supplies a configuration with code 7, which is outside the defined range.
     * Confirms componentsConfiguration() rejects the non-conformant value.
     */
    #[Test]
    public function rejectsCodeAboveSix(): void
    {
        $parsedExif = $this->parsedExifWithComponents([1, 2, 3, 7]);

        self::assertNull($parsedExif->componentsConfiguration());
    }

    /**
     * Supplies a configuration with a negative code.
     * Confirms componentsConfiguration() rejects the non-conformant value.
     */
    #[Test]
    public function rejectsNegativeCode(): void
    {
        $parsedExif = $this->parsedExifWithComponents([1, 2, -1, 0]);

        self::assertNull($parsedExif->componentsConfiguration());
    }

    /**
     * @param list<int> $components
     */
    private function parsedExifWithComponents(array $components): ParsedExif
    {
        $exifIfd = new Ifd([
            ExifTag::COMPONENTS_CONFIGURATION => new IfdEntry(
                ExifTag::COMPONENTS_CONFIGURATION,
                7,
                count($components),
                $components,
            ),
        ]);

        return new ParsedExif(new Ifd([]), $exifIfd, null, null, null);
    }
}
