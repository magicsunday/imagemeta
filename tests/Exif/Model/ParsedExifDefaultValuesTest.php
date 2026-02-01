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
 * Exercises TIFF/EXIF default tag values when specific fields are absent.
 * It verifies defaults for BitsPerSample, SamplesPerPixel, Compression, and other baseline tags.
 * The suite checks that enum-backed defaults map to the expected values.
 * This keeps ParsedExif consistent with the default rules defined in the specs.
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifDefaultValuesTest extends TestCase
{
    /**
     * Omits BitsPerSample from the IFD to exercise the default path.
     * Verifies ParsedExif returns the TIFF/EXIF default of 8 bits per sample.
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
     * Leaves SamplesPerPixel unset so the default is applied.
     * Confirms the method returns 3 for the standard RGB/YCbCr case.
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
     * Skips the Compression tag to validate the TIFF default behavior.
     * Ensures ParsedExif returns the UNCOMPRESSED enum value.
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
     * Leaves Orientation unset to confirm the default orientation is applied.
     * Verifies the returned enum is TOP_LEFT as specified by TIFF/EXIF.
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
     * Omits PlanarConfiguration so ParsedExif must use the default layout.
     * Ensures the returned enum is CHUNKY for interleaved samples.
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
     * Leaves ResolutionUnit unset to exercise the TIFF/EXIF default.
     * Confirms the returned enum is INCHES.
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
     * Omits YCbCrPositioning from the IFD to trigger the default selection.
     * Verifies the default is CENTERED when the tag is missing.
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
     * Sets PhotometricInterpretation to RGB with an sRGB color space.
     * Confirms referenceBlackWhite defaults to the RGB black/white levels.
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
     * Sets PhotometricInterpretation to YCbCr with an sRGB color space.
     * Ensures the YCbCr reference black/white defaults are applied.
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
     * Uses RGB photometric interpretation but marks the color space as uncalibrated.
     * Verifies referenceBlackWhite is suppressed and returns null in that case.
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
     * Feeds an incomplete transfer function LUT to ensure it is rejected.
     * Confirms a full 3×256 entry table is accepted and returned intact.
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
     * When YCbCr coefficients are missing, expects Annex D defaults for YCbCr photos.
     * Verifies the method returns null for RGB photometric interpretation.
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
