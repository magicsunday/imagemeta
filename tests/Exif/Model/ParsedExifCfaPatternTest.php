<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
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
use MagicSunday\ImageMeta\Value\CfaPattern;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

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
final class ParsedExifCfaPatternTest extends TestCase
{
    /**
     * Provides a CFA pattern list with 2x2 repeat units and four color values.
     * Verifies ParsedExif returns a CfaPattern with correct repeat units and grid ordering.
     *
     * @return void
     */
    #[Test]
    public function parsesCfaPatternWithRepeatUnits(): void
    {
        $cfaPattern = new IfdEntry(
            ExifTag::CFA_PATTERN,
            7,
            6,
            new ExifNumericList([2, 2, 0, 1, 2, 1]),
        );

        $exifIfd    = new Ifd([ExifTag::CFA_PATTERN => $cfaPattern]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        $pattern = $parsedExif->cfaPattern();

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
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenPatternIsIncomplete(): void
    {
        $cfaPattern = new IfdEntry(
            ExifTag::CFA_PATTERN,
            7,
            4,
            new ExifNumericList([2, 2, 0, 1]),
        );

        $exifIfd    = new Ifd([ExifTag::CFA_PATTERN => $cfaPattern]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->cfaPattern());
    }
}
