<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

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
     * Verifies that $parsedExif->bitsPerSample() equals 8.
     *
     * @see EXIF 3.0 §4.6.5.1.3: BitsPerSample default is 8 8 8 (RGB)
     *
     * @return void
     */
    #[Test]
    public function bitsPerSampleReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(8, $parsedExif->bitsPerSample());
    }

    /**
     * Verifies that $parsedExif->samplesPerPixel() equals 3.
     *
     * @see EXIF 3.0 §4.6.5.1.7: SamplesPerPixel defaults to 3 for RGB/YCbCr
     *
     * @return void
     */
    #[Test]
    public function samplesPerPixelReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(3, $parsedExif->samplesPerPixel());
    }

    /**
     * Verifies that $parsedExif->compression() equals Compression::UNCOMPRESSED.
     *
     * @see TIFF 6.0 §8: Compression default is 1 (no compression)
     *
     * @return void
     */
    #[Test]
    public function compressionReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(Compression::UNCOMPRESSED, $parsedExif->compression());
    }

    /**
     * Verifies that $parsedExif->orientation() equals Orientation::TOP_LEFT.
     *
     * @see TIFF 6.0 §8: Orientation default is 1 (top-left)
     * @see EXIF 3.0 §4.6.5.1.6: Orientation default is 1
     *
     * @return void
     */
    #[Test]
    public function orientationReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(Orientation::TOP_LEFT, $parsedExif->orientation());
    }

    /**
     * Verifies that $parsedExif->planarConfiguration() equals PlanarConfiguration::CHUNKY.
     *
     * @see TIFF 6.0 §8: PlanarConfiguration default is 1 (chunky format)
     * @see EXIF 3.0 §4.6.5.1.10: PlanarConfiguration default is 1
     *
     * @return void
     */
    #[Test]
    public function planarConfigurationReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(PlanarConfiguration::CHUNKY, $parsedExif->planarConfiguration());
    }

    /**
     * Verifies that $parsedExif->resolutionUnit() equals ResolutionUnit::INCHES.
     *
     * @see TIFF 6.0 §8: ResolutionUnit default is 2 (inches)
     * @see EXIF 3.0 §4.6.5.1.11: ResolutionUnit default is 2
     *
     * @return void
     */
    #[Test]
    public function resolutionUnitReturnsDefaultWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(ResolutionUnit::INCHES, $parsedExif->resolutionUnit());
    }

    /**
     * Verifies that $parsedExif->ycbcrPositioning() equals YCbCrPositioning::CENTERED.
     *
     * @see EXIF 3.0 §4.6.5.1.13: Default value is 1 (centered) if missing
     *
     * @return void
     */
    #[Test]
    public function ycbcrPositioningDefaultsToCenteredWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(YCbCrPositioning::CENTERED, $parsedExif->ycbcrPositioning());
    }

    /**
     * Verifies that $parsedExif->referenceBlackWhite() equals [0.0, 255.0, 0.0, 255.0, 0.0, 255.0].
     *
     * @return void
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
     * Verifies that $parsedExif->referenceBlackWhite() equals [0.0, 255.0, 128.0, 128.0, 128.0, 128.0].
     *
     * @return void
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
     * Verifies that $parsedExif->referenceBlackWhite() is null.
     *
     * @return void
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

    /**
     * Verifies that $parsedExif->transferFunction() is null.
     *
     * @return void
     */
    #[Test]
    public function transferFunctionRequiresCompleteLut(): void
    {
        $incomplete = new Ifd([
            ExifTag::TRANSFER_FUNCTION => new IfdEntry(ExifTag::TRANSFER_FUNCTION, 3, 6, [0, 1, 2, 3, 4, 5]),
        ]);

        $parsedExif = new ParsedExif($incomplete, null, null, null, null);

        self::assertNull($parsedExif->transferFunction());

        $table = range(0, 767);

        $complete = new Ifd([
            ExifTag::TRANSFER_FUNCTION => new IfdEntry(ExifTag::TRANSFER_FUNCTION, 3, count($table), $table),
        ]);

        $parsedExif = new ParsedExif($complete, null, null, null, null);

        self::assertSame($table, $parsedExif->transferFunction());
    }

    /**
     * Verifies that $parsedExif->ycbcrCoefficients() equals [0.299, 0.587, 0.114].
     *
     * @return void
     */
    #[Test]
    public function ycbcrCoefficientsDefaultToAnnexDWhenMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::YCBCR->value,
            ),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame([0.299, 0.587, 0.114], $parsedExif->ycbcrCoefficients());

        $rgbIfd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::RGB->value,
            ),
        ]);

        $parsedExif = new ParsedExif($rgbIfd, null, null, null, null);

        self::assertNull($parsedExif->ycbcrCoefficients());
    }
}
