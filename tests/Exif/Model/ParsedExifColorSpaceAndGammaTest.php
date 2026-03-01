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
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ParsedExif handling of ColorSpace and Gamma tags.
 * It validates that reserved or unknown color space values map to null.
 * The suite verifies rational gamma values are converted to floats.
 * This keeps color and tone metadata consistent for downstream processing.
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
final class ParsedExifColorSpaceAndGammaTest extends TestCase
{
    /**
     * Uses a reserved ColorSpace value that is not defined in the enum.
     * Confirms colorSpace() returns null when the value is not a known enum case.
     */
    #[Test]
    public function colorSpaceIsNullForReservedValues(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([
            ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, 999),
        ]);

        self::assertNull($parsedExif->colorSpace());
    }

    /**
     * Returns AdobeRgb for ColorSpace value 2 (non-standard but universal).
     */
    #[Test]
    public function colorSpaceReturnsAdobeRgb(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([
            ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, 2),
        ]);

        self::assertSame(ColorSpace::AdobeRgb, $parsedExif->colorSpace());
    }

    /**
     * Omits ColorSpace tag when ExifIFD is present.
     * Defaults to sRGB per EXIF 3.0 §4.6.6.2.1 required-tag fallback.
     */
    #[Test]
    public function colorSpaceDefaultsToSrgbWhenAbsentInExifIfd(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([]);

        self::assertSame(ColorSpace::Srgb, $parsedExif->colorSpace());
    }

    /**
     * Omits ExifIFD entirely.
     * Returns null because there is no ExifIFD to carry ColorSpace.
     */
    #[Test]
    public function colorSpaceReturnsNullWithoutExifIfd(): void
    {
        $parsedExif = new ParsedExif(new Ifd([]), null, null, null, null);

        self::assertNull($parsedExif->colorSpace());
    }

    /**
     * Provides a GAMMA tag encoded as a rational pair.
     * Verifies gamma() converts the rational into a floating-point value.
     */
    #[Test]
    public function gammaReturnsRationalValue(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([
            ExifTag::GAMMA => new IfdEntry(ExifTag::GAMMA, 5, 1, [22, 10]),
        ]);

        self::assertSame(2.2, $parsedExif->gamma());
    }

    /**
     * Omits the GAMMA tag from the EXIF IFD.
     * Ensures gamma() returns null when the tag is missing.
     */
    #[Test]
    public function gammaReturnsNullWhenMissing(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([]);

        self::assertNull($parsedExif->gamma());
    }

    /**
     * @param array<int, IfdEntry> $exifEntries
     */
    private function parsedExifFromExifEntries(array $exifEntries): ParsedExif
    {
        return new ParsedExif(new Ifd([]), new Ifd($exifEntries), null, null, null);
    }
}
