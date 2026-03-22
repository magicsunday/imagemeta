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
use MagicSunday\ImageMeta\Exif\Reader\FocalReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\CfaPattern;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * Exercises CFA pattern decoding from EXIF numeric lists into CfaPattern values.
 * It validates repeat unit sizes and the conversion to CfaPatternColor enums.
 * The tests cover valid pattern payloads and confirm grid ordering is preserved.
 * This ensures sensor mosaic metadata is represented consistently.
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
#[UsesClass(FocalReader::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(CfaPattern::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class ParsedExifCfaPatternTest extends TestCase
{
    /**
     * Provides a CFA pattern list with 2x2 repeat units and four color values.
     * Verifies ParsedExif returns a CfaPattern with correct repeat units and grid ordering.
     */
    #[Test]
    public function parsesCfaPatternWithRepeatUnits(): void
    {
        $pattern = $this->parseCfaPattern([2, 2, 0, 1, 2, 1]);

        self::assertInstanceOf(CfaPattern::class, $pattern);
        self::assertSame(2, $pattern->horizontalRepeatPixelUnit);
        self::assertSame(2, $pattern->verticalRepeatPixelUnit);
        self::assertSame([
            [CfaPatternColor::Red, CfaPatternColor::Green],
            [CfaPatternColor::Blue, CfaPatternColor::Green],
        ], $pattern->grid());
    }

    /**
     * Supplies a CFA pattern list that lacks enough entries to fill the grid.
     * Ensures the parser returns null when the pattern data is incomplete.
     */
    #[Test]
    public function returnsNullWhenPatternIsIncomplete(): void
    {
        self::assertNull($this->parseCfaPattern([2, 2, 0, 1]));
    }

    /**
     * @param list<int> $values
     */
    private function parseCfaPattern(array $values): ?CfaPattern
    {
        $entry = new IfdEntry(
            ExifTag::CFA_PATTERN,
            7,
            count($values),
            new ExifNumericList($values),
        );

        $exifIfd    = new Ifd([ExifTag::CFA_PATTERN => $entry]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        return $parsedExif->cfaPattern();
    }
}
