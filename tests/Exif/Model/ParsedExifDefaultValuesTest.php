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
use function round;

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
     * Omits BitsPerSample in TIFF context (Compression tag present).
     * Verifies the EXIF default of 8 is returned.
     *
     * @see EXIF 3.0 §4.6.5.1.3: BitsPerSample default is 8 8 8 (RGB)
     *
     * @return void
     */
    #[Test]
    public function bitsPerSampleReturnsDefaultInTiffContext(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::UNCOMPRESSED->value),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(8, $parsedExif->bitsPerSample());
    }

    /**
     * Omits BitsPerSample in JPEG context (no Compression tag).
     * Returns null so SOF precision fallback can apply.
     *
     * @see EXIF 3.0 §4.6.5.1.3: JPEG data shall not record BitsPerSample
     *
     * @return void
     */
    #[Test]
    public function bitsPerSampleReturnsNullInJpegContext(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->bitsPerSample());
    }

    /**
     * Leaves SamplesPerPixel unset in JPEG context (no Compression tag).
     * Confirms the method returns 3 per EXIF 3.0 §4.6.5.1.7.
     *
     * @return void
     */
    #[Test]
    public function samplesPerPixelReturnsThreeInJpegContext(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(3, $parsedExif->samplesPerPixel());
    }

    /**
     * Leaves SamplesPerPixel unset for a TIFF grayscale image.
     * Confirms the method returns 1 instead of the EXIF default 3.
     *
     * @return void
     */
    #[Test]
    public function samplesPerPixelReturnsOneForGrayscale(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::BLACK_IS_ZERO->value,
            ),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(1, $parsedExif->samplesPerPixel());
    }

    /**
     * Leaves SamplesPerPixel unset for an RGB photometric image.
     * Confirms the method returns 3 when photometric is RGB.
     *
     * @return void
     */
    #[Test]
    public function samplesPerPixelReturnsThreeForRgb(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::RGB->value,
            ),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(3, $parsedExif->samplesPerPixel());
    }

    /**
     * Leaves SamplesPerPixel unset in TIFF context without photometric.
     * Confirms the TIFF 6.0 default of 1 is returned.
     *
     * @return void
     */
    #[Test]
    public function samplesPerPixelReturnsOneInTiffContextWithoutPhotometric(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::UNCOMPRESSED->value),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(1, $parsedExif->samplesPerPixel());
    }

    /**
     * Skips the Compression tag to validate the TIFF default behavior.
     * Ensures ParsedExif returns UNCOMPRESSED when the Compression tag is absent.
     *
     * @see TIFF 6.0 §8: Compression default is 1 (no compression)
     *
     * @return void
     */
    #[Test]
    public function compressionReturnsUncompressedWhenMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(Compression::UNCOMPRESSED, $parsedExif->compression());
    }

    /**
     * Compression tag present with JPEG XL code resolves to JPEG_XL enum case.
     *
     * @see DNG 1.7.1.0: Compression 52546 = JPEG XL
     */
    #[Test]
    public function compressionReturnsJpegXlForCode52546(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, 52546),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(Compression::JPEG_XL, $parsedExif->compression());
    }

    /**
     * Compression tag present with an unsupported code returns null
     * instead of silently falling back to UNCOMPRESSED.
     */
    #[Test]
    public function compressionReturnsNullForUnsupportedPresentCode(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, 99999),
        ]);

        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->compression());
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
     * Omits PlanarConfiguration in TIFF context (Compression tag present).
     * Ensures the returned enum is CHUNKY for interleaved samples.
     *
     * @see TIFF 6.0 §8: PlanarConfiguration default is 1 (chunky format)
     *
     * @return void
     */
    #[Test]
    public function planarConfigurationReturnsChunkyInTiffContext(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::UNCOMPRESSED->value),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(PlanarConfiguration::CHUNKY, $parsedExif->planarConfiguration());
    }

    /**
     * Omits PlanarConfiguration in JPEG context (no Compression tag).
     * Returns null because JPEG markers carry the equivalent information.
     *
     * @see EXIF 3.0 §4.6.5.1.10
     *
     * @return void
     */
    #[Test]
    public function planarConfigurationReturnsNullInJpegContext(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->planarConfiguration());
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
     * Omits XResolution/YResolution in JPEG context (no Compression tag).
     * Confirms 72.0 dpi fallback per EXIF 3.0 §4.6.5.1.8-9.
     *
     * @return void
     */
    #[Test]
    public function resolutionDefaultsTo72InJpegContext(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(72.0, $parsedExif->xResolution());
        self::assertSame(72.0, $parsedExif->yResolution());
    }

    /**
     * Omits XResolution/YResolution in TIFF context (Compression tag present).
     * TIFF 6.0 defines no default, so null is returned.
     *
     * @return void
     */
    #[Test]
    public function resolutionReturnsNullInTiffContext(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::UNCOMPRESSED->value),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->xResolution());
        self::assertNull($parsedExif->yResolution());
    }

    /**
     * Omits YCbCrPositioning when photometric is YCbCr.
     * Verifies the default is CENTERED when the tag is missing.
     *
     * @see EXIF 3.0 §4.6.5.1.13: Default value is 1 (centered) if missing
     *
     * @return void
     */
    #[Test]
    public function ycbcrPositioningDefaultsToCenteredForYcbcr(): void
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

        self::assertSame(YCbCrPositioning::CENTERED, $parsedExif->ycbcrPositioning());
    }

    /**
     * Omits YCbCrPositioning when photometric is RGB.
     * Returns null because positioning is only applicable to YCbCr images.
     *
     * @return void
     */
    #[Test]
    public function ycbcrPositioningReturnsNullForNonYcbcr(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::RGB->value,
            ),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->ycbcrPositioning());
    }

    /**
     * Omits YCbCrSubSampling in TIFF YCbCr context.
     * TIFF 6.0 §21 defines default [2,2] for YCbCr images.
     *
     * @return void
     */
    #[Test]
    public function ycbcrSubSamplingDefaultsInTiffYcbcrContext(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION                => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::UNCOMPRESSED->value),
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::YCBCR->value,
            ),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame([2, 2], $parsedExif->ycbcrSubSampling());
    }

    /**
     * Omits YCbCrSubSampling in JPEG context (no Compression tag).
     * Returns null so SOF-derived subsampling can take precedence.
     *
     * @return void
     */
    #[Test]
    public function ycbcrSubSamplingReturnsNullInJpegContext(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->ycbcrSubSampling());
    }

    /**
     * Omits YCbCrSubSampling in TIFF RGB context.
     * Returns null because the default only applies to YCbCr images.
     *
     * @return void
     */
    #[Test]
    public function ycbcrSubSamplingReturnsNullForNonYcbcr(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION                => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::UNCOMPRESSED->value),
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::RGB->value,
            ),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->ycbcrSubSampling());
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
     * Omits TransferFunction tag in TIFF context.
     * Materializes the NTSC gamma 2.2 default table (768 entries for 8-bit).
     *
     * @return void
     */
    #[Test]
    public function transferFunctionDefaultsToGamma22InTiffContext(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::UNCOMPRESSED->value),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        $table = $parsedExif->transferFunction();

        self::assertNotNull($table);
        self::assertCount(768, $table);
        self::assertSame(0, $table[0]);
        self::assertSame(65535, $table[255]);
        // Verify midpoint matches gamma 2.2 curve
        self::assertSame((int) round((128 / 255) ** 2.2 * 65535), $table[128]);
    }

    /**
     * Omits TransferFunction tag in JPEG context.
     * Returns null so no synthetic table is emitted.
     *
     * @return void
     */
    #[Test]
    public function transferFunctionReturnsNullInJpegContext(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->transferFunction());
    }

    /**
     * Omits MinSampleValue — defaults to 0 per TIFF 6.0 §8.
     *
     * @return void
     */
    #[Test]
    public function minSampleValueDefaultsToZero(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(0, $parsedExif->minSampleValue());
    }

    /**
     * Omits MaxSampleValue in TIFF 8-bit context — defaults to 255.
     *
     * @return void
     */
    #[Test]
    public function maxSampleValueDefaultsTo255For8Bit(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::UNCOMPRESSED->value),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertSame(255, $parsedExif->maxSampleValue());
    }

    /**
     * Omits TransferRange in TIFF 8-bit context.
     * Defaults to [0, 255, 0, 255, 0, 255] per TIFF 6.0 §8.
     *
     * @return void
     */
    #[Test]
    public function transferRangeDefaultsInTiffContext(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::UNCOMPRESSED->value),
        ]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        $range = $parsedExif->transferRange();

        self::assertInstanceOf(ExifNumericList::class, $range);
        self::assertSame([0, 255, 0, 255, 0, 255], $range->toArray());
    }

    /**
     * Omits TransferRange in JPEG context — returns null.
     *
     * @return void
     */
    #[Test]
    public function transferRangeReturnsNullInJpegContext(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->transferRange());
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
