<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TIFF 6.0 and EXIF 3.0 default values in ParsedExif.
 *
 * According to TIFF 6.0 §8 and EXIF 3.0 specifications, several tags have
 * default values when not present in the file. These tests verify that
 * ParsedExif returns the correct defaults.
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifDefaultValuesTest extends TestCase
{
    /**
     * Verifies that bitsPerSample() returns the EXIF 3.0 default value of 8 per
     * component when the tag is not present.
     *
     * @see EXIF 3.0 §4.6.5.1.3: BitsPerSample default is 8 8 8 (RGB)
     * @see EXIF 2.32 §4.6.5.1.3: BitsPerSample default is 8 8 8 (RGB)
     */
    #[Test]
    public function bitsPerSampleReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(8, $parsedExif->bitsPerSample());
    }

    /**
     * Verifies that samplesPerPixel() returns the EXIF 3.0 default value of 3
     * when the tag is not present.
     *
     * @see EXIF 3.0 §4.6.5.1.7: SamplesPerPixel defaults to 3 for RGB/YCbCr
     */
    #[Test]
    public function samplesPerPixelReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(3, $parsedExif->samplesPerPixel());
    }

    /**
     * Verifies that compression() returns the TIFF 6.0 default value of
     * Compression::UNCOMPRESSED when the tag is not present.
     *
     * @see TIFF 6.0 §8: Compression default is 1 (no compression)
     */
    #[Test]
    public function compressionReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(Compression::UNCOMPRESSED, $parsedExif->compression());
    }

    /**
     * Verifies that orientation() returns the TIFF 6.0/EXIF 3.0 default value
     * of Orientation::TOP_LEFT when the tag is not present.
     *
     * @see TIFF 6.0 §8: Orientation default is 1 (top-left)
     * @see EXIF 3.0 §4.6.5.1.6: Orientation default is 1
     */
    #[Test]
    public function orientationReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(Orientation::TOP_LEFT, $parsedExif->orientation());
    }

    /**
     * Verifies that planarConfiguration() returns the TIFF 6.0 default value
     * of PlanarConfiguration::CHUNKY when the tag is not present.
     *
     * @see TIFF 6.0 §8: PlanarConfiguration default is 1 (chunky format)
     */
    #[Test]
    public function planarConfigurationReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(PlanarConfiguration::CHUNKY, $parsedExif->planarConfiguration());
    }

    /**
     * Verifies that resolutionUnit() returns the TIFF 6.0/EXIF 3.0 default value
     * of ResolutionUnit::INCHES when the tag is not present.
     *
     * @see TIFF 6.0 §8: ResolutionUnit default is 2 (inches)
     * @see EXIF 3.0 §4.6.2: ResolutionUnit default is 2
     */
    #[Test]
    public function resolutionUnitReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(ResolutionUnit::INCHES, $parsedExif->resolutionUnit());
    }

    /**
     * Verifies that xResolution() returns the EXIF 3.0 default value of 72 when
     * the tag is not present.
     *
     * @see EXIF 3.0 §4.6.5.1.8: XResolution defaults to 72 when unknown
     */
    #[Test]
    public function xResolutionReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(72.0, $parsedExif->xResolution());
    }

    /**
     * Verifies that yResolution() defaults to the value of xResolution() when
     * not provided separately.
     *
     * @see EXIF 3.0 §4.6.5.1.9: YResolution shall match XResolution
     */
    #[Test]
    public function yResolutionFallsBackToXResolution(): void
    {
        $ifd0 = new Ifd([
            ExifTag::X_RESOLUTION => new IfdEntry(ExifTag::X_RESOLUTION, 5, 1, [300, 1]),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(300.0, $parsedExif->yResolution());
    }

    /**
     * Verifies that ReferenceBlackWhite returns the EXIF 3.0 §4.6.5.3.5 default
     * when the colour space is defined and the photometric interpretation is RGB.
     */
    #[Test]
    public function referenceBlackWhiteDefaultsForRgb(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::RGB->value,
            ),
        ]);

        $exifIfd = new Ifd([
            ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::SRGB->value),
        ]);

        $parsedExif = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(
            [0.0, 255.0, 0.0, 255.0, 0.0, 255.0],
            $parsedExif->referenceBlackWhite(),
        );
    }

    /**
     * Verifies that ReferenceBlackWhite returns the EXIF 3.0 §4.6.5.3.5 default
     * when the colour space is defined and the photometric interpretation is YCbCr.
     */
    #[Test]
    public function referenceBlackWhiteDefaultsForYCbCr(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::YCBCR->value,
            ),
        ]);

        $exifIfd = new Ifd([
            ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::SRGB->value),
        ]);

        $parsedExif = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertSame(
            [0.0, 255.0, 128.0, 128.0, 128.0, 128.0],
            $parsedExif->referenceBlackWhite(),
        );
    }

    /**
     * Ensures no default ReferenceBlackWhite is applied when the colour space
     * is uncalibrated even if the photometric interpretation is RGB.
     */
    #[Test]
    public function referenceBlackWhiteDefaultsAreSuppressedForUncalibratedColorSpace(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::RGB->value,
            ),
        ]);

        $exifIfd = new Ifd([
            ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::UNCALIBRATED->value),
        ]);

        $parsedExif = new ParsedExif($ifd0, $exifIfd, null, null, null);

        self::assertNull($parsedExif->referenceBlackWhite());
    }
}
