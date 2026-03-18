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
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
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
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
final class ParsedExifDefaultValuesTest extends TestCase
{
    /**
     * Omits BitsPerSample in TIFF context (Compression tag present).
     * Scalar accessor returns 8 (first component of default vector).
     */
    #[Test]
    public function bitsPerSampleReturnsDefaultInTiffContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
        ]);

        self::assertSame(8, $parsedExif->bitsPerSample());
    }

    /**
     * Omits BitsPerSample in JPEG context (no Compression tag).
     * Returns null so SOF precision fallback can apply.
     */
    #[Test]
    public function bitsPerSampleReturnsNullInJpegContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertNull($parsedExif->bitsPerSample());
    }

    /**
     * BitsPerSampleList returns per-component vector in TIFF context.
     * Defaults to [8] per SamplesPerPixel when tag is absent.
     */
    #[Test]
    public function bitsPerSampleListReturnsDefaultVector(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
        ]);

        // Default: SamplesPerPixel=1 for TIFF context without photometric → [8]
        self::assertSame([8], $parsedExif->bitsPerSampleList());
    }

    /**
     * BitsPerSampleList preserves multi-component values.
     */
    #[Test]
    public function bitsPerSampleListPreservesMultipleComponents(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::BITS_PER_SAMPLE => new IfdEntry(ExifTag::BITS_PER_SAMPLE, 3, 3, [8, 10, 8]),
        ]);

        self::assertSame([8, 10, 8], $parsedExif->bitsPerSampleList());
        self::assertSame(8, $parsedExif->bitsPerSample());
    }

    /**
     * BitsPerSampleList returns null in JPEG context.
     */
    #[Test]
    public function bitsPerSampleListReturnsNullInJpegContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertNull($parsedExif->bitsPerSampleList());
    }

    /**
     * Leaves SamplesPerPixel unset in JPEG context (no Compression tag).
     * Confirms the method returns 3 per EXIF 3.0 §4.6.5.1.7.
     */
    #[Test]
    public function samplesPerPixelReturnsThreeInJpegContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertSame(3, $parsedExif->samplesPerPixel());
    }

    /**
     * Leaves SamplesPerPixel unset for a TIFF grayscale image.
     * Confirms the method returns 1 instead of the EXIF default 3.
     */
    #[Test]
    public function samplesPerPixelReturnsOneForGrayscale(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::BlackIsZero->value,
            ),
        ]);

        self::assertSame(1, $parsedExif->samplesPerPixel());
    }

    /**
     * Leaves SamplesPerPixel unset for an RGB photometric image.
     * Confirms the method returns 3 when photometric is RGB.
     */
    #[Test]
    public function samplesPerPixelReturnsThreeForRgb(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::Rgb->value,
            ),
        ]);

        self::assertSame(3, $parsedExif->samplesPerPixel());
    }

    /**
     * Leaves SamplesPerPixel unset in TIFF context without photometric.
     * Confirms the TIFF 6.0 default of 1 is returned.
     */
    #[Test]
    public function samplesPerPixelReturnsOneInTiffContextWithoutPhotometric(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
        ]);

        self::assertSame(1, $parsedExif->samplesPerPixel());
    }

    /**
     * Skips the Compression tag to validate the TIFF default behavior.
     * Ensures ParsedExif returns UNCOMPRESSED when the Compression tag is absent.
     *
     * @see TIFF 6.0 §8: Compression default is 1 (no compression)
     */
    #[Test]
    public function compressionReturnsUncompressedWhenMissing(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertSame(Compression::Uncompressed, $parsedExif->compression());
    }

    /**
     * Compression tag present with JPEG XL code resolves to JPEG_XL enum case.
     *
     * @see DNG 1.7.1.0: Compression 52546 = JPEG XL
     */
    #[Test]
    public function compressionReturnsJpegXlForCode52546(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, 52546),
        ]);

        self::assertSame(Compression::JpegXl, $parsedExif->compression());
    }

    /**
     * Compression tag present with an unsupported code returns null
     * instead of silently falling back to UNCOMPRESSED.
     */
    #[Test]
    public function compressionReturnsNullForUnsupportedPresentCode(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, 99999),
        ]);

        self::assertNull($parsedExif->compression());
    }

    /**
     * Leaves Orientation unset to confirm the default orientation is applied.
     * Verifies the returned enum is TOP_LEFT as specified by TIFF/EXIF.
     *
     * @see TIFF 6.0 §8: Orientation default is 1 (top-left)
     * @see EXIF 3.0 §4.6.5.1.6: Orientation default is 1
     */
    #[Test]
    public function orientationReturnsDefaultWhenMissing(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertSame(Orientation::TopLeft, $parsedExif->orientation());
    }

    /**
     * Omits PlanarConfiguration in TIFF context (Compression tag present).
     * Ensures the returned enum is CHUNKY for interleaved samples.
     *
     * @see TIFF 6.0 §8: PlanarConfiguration default is 1 (chunky format)
     */
    #[Test]
    public function planarConfigurationReturnsChunkyInTiffContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
        ]);

        self::assertSame(PlanarConfiguration::Chunky, $parsedExif->planarConfiguration());
    }

    /**
     * Omits PlanarConfiguration in JPEG context (no Compression tag).
     * Returns null because JPEG markers carry the equivalent information.
     *
     * @see EXIF 3.0 §4.6.5.1.10
     */
    #[Test]
    public function planarConfigurationReturnsNullInJpegContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertNull($parsedExif->planarConfiguration());
    }

    /**
     * Leaves ResolutionUnit unset to exercise the TIFF/EXIF default.
     * Confirms the returned enum is INCHES.
     *
     * @see TIFF 6.0 §8: ResolutionUnit default is 2 (inches)
     * @see EXIF 3.0 §4.6.5.1.11: ResolutionUnit default is 2
     */
    #[Test]
    public function resolutionUnitReturnsDefaultWhenMissing(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertSame(ResolutionUnit::Inches, $parsedExif->resolutionUnit());
    }

    /**
     * Omits XResolution/YResolution in JPEG context (no Compression tag).
     * Confirms 72.0 dpi fallback per EXIF 3.0 §4.6.5.1.8-9.
     */
    #[Test]
    public function resolutionDefaultsTo72InJpegContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertSame(72.0, $parsedExif->xResolution());
        self::assertSame(72.0, $parsedExif->yResolution());
    }

    /**
     * Omits XResolution/YResolution in TIFF context (Compression tag present).
     * TIFF 6.0 defines no default, so null is returned.
     */
    #[Test]
    public function resolutionReturnsNullInTiffContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
        ]);

        self::assertNull($parsedExif->xResolution());
        self::assertNull($parsedExif->yResolution());
    }

    /**
     * Omits YCbCrPositioning when photometric is YCbCr.
     * Verifies the default is CENTERED when the tag is missing.
     *
     * @see EXIF 3.0 §4.6.5.1.13: Default value is 1 (centered) if missing
     */
    #[Test]
    public function ycbcrPositioningDefaultsToCenteredForYcbcr(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::Ycbcr->value,
            ),
        ]);

        self::assertSame(YCbCrPositioning::Centered, $parsedExif->ycbcrPositioning());
    }

    /**
     * Omits YCbCrPositioning when photometric is RGB.
     * Returns null because positioning is only applicable to YCbCr images.
     */
    #[Test]
    public function ycbcrPositioningReturnsNullForNonYcbcr(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::Rgb->value,
            ),
        ]);

        self::assertNull($parsedExif->ycbcrPositioning());
    }

    /**
     * Omits YCbCrSubSampling in TIFF YCbCr context.
     * TIFF 6.0 §21 defines default [2,2] for YCbCr images.
     */
    #[Test]
    public function ycbcrSubSamplingDefaultsInTiffYcbcrContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION                => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::Ycbcr->value,
            ),
        ]);

        self::assertSame([2, 2], $parsedExif->ycbcrSubSampling());
    }

    /**
     * Omits YCbCrSubSampling in JPEG context (no Compression tag).
     * Returns null so SOF-derived subsampling can take precedence.
     */
    #[Test]
    public function ycbcrSubSamplingReturnsNullInJpegContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertNull($parsedExif->ycbcrSubSampling());
    }

    /**
     * Omits YCbCrSubSampling in TIFF RGB context.
     * Returns null because the default only applies to YCbCr images.
     */
    #[Test]
    public function ycbcrSubSamplingReturnsNullForNonYcbcr(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION                => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::Rgb->value,
            ),
        ]);

        self::assertNull($parsedExif->ycbcrSubSampling());
    }

    /**
     * Sets PhotometricInterpretation to RGB with an sRGB color space.
     * Confirms referenceBlackWhite defaults to the RGB black/white levels.
     */
    #[Test]
    public function referenceBlackWhiteDefaultsForRgb(): void
    {
        $parsedExif = $this->parsedExifFromEntries(
            [
                ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                    ExifTag::PHOTOMETRIC_INTERPRETATION,
                    3,
                    1,
                    Photometric::Rgb->value,
                ),
            ],
            [
                ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::Srgb->value),
            ],
        );

        self::assertSame(
            [0.0, 255.0, 0.0, 255.0, 0.0, 255.0],
            $parsedExif->referenceBlackWhite(),
        );
    }

    /**
     * Sets PhotometricInterpretation to YCbCr with an sRGB color space.
     * Ensures the YCbCr reference black/white defaults are applied.
     */
    #[Test]
    public function referenceBlackWhiteDefaultsForYCbCr(): void
    {
        $parsedExif = $this->parsedExifFromEntries(
            [
                ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                    ExifTag::PHOTOMETRIC_INTERPRETATION,
                    3,
                    1,
                    Photometric::Ycbcr->value,
                ),
            ],
            [
                ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::Srgb->value),
            ],
        );

        self::assertSame(
            [0.0, 255.0, 128.0, 128.0, 128.0, 128.0],
            $parsedExif->referenceBlackWhite(),
        );
    }

    /**
     * Uses RGB photometric interpretation but marks the color space as uncalibrated.
     * Verifies referenceBlackWhite is suppressed and returns null in that case.
     */
    #[Test]
    public function referenceBlackWhiteDefaultsAreSuppressedForUncalibratedColorSpace(): void
    {
        $parsedExif = $this->parsedExifFromEntries(
            [
                ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                    ExifTag::PHOTOMETRIC_INTERPRETATION,
                    3,
                    1,
                    Photometric::Rgb->value,
                ),
            ],
            [
                ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::Uncalibrated->value),
            ],
        );

        self::assertNull($parsedExif->referenceBlackWhite());
    }

    /**
     * Feeds an incomplete transfer function LUT to ensure it is rejected.
     * Confirms a full 3×256 entry table is accepted and returned intact.
     */
    #[Test]
    public function transferFunctionRequiresCompleteLut(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::TRANSFER_FUNCTION => new IfdEntry(ExifTag::TRANSFER_FUNCTION, 3, 6, [0, 1, 2, 3, 4, 5]),
        ]);

        self::assertNull($parsedExif->transferFunction());

        $table      = range(0, 767);

        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::TRANSFER_FUNCTION => new IfdEntry(ExifTag::TRANSFER_FUNCTION, 3, count($table), $table),
        ]);

        self::assertSame($table, $parsedExif->transferFunction());
    }

    /**
     * Omits TransferFunction tag in TIFF context.
     * Materializes the NTSC gamma 2.2 default table (768 entries for 8-bit).
     */
    #[Test]
    public function transferFunctionDefaultsToGamma22InTiffContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
        ]);

        $table      = $parsedExif->transferFunction();

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
     */
    #[Test]
    public function transferFunctionReturnsNullInJpegContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertNull($parsedExif->transferFunction());
    }

    /**
     * Omits MinSampleValue — defaults to 0 per TIFF 6.0 §8.
     */
    #[Test]
    public function minSampleValueDefaultsToZero(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertSame(0, $parsedExif->minSampleValue());
    }

    /**
     * Omits MaxSampleValue in TIFF 8-bit context — defaults to 255.
     */
    #[Test]
    public function maxSampleValueDefaultsTo255For8Bit(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
        ]);

        self::assertSame(255, $parsedExif->maxSampleValue());
    }

    /**
     * Omits TransferRange in TIFF 8-bit context.
     * Defaults to [0, 255, 0, 255, 0, 255] per TIFF 6.0 §8.
     */
    #[Test]
    public function transferRangeDefaultsInTiffContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::Uncompressed->value),
        ]);

        $range      = $parsedExif->transferRange();

        self::assertInstanceOf(ExifNumericList::class, $range);
        self::assertSame([0, 255, 0, 255, 0, 255], $range->toArray());
    }

    /**
     * Omits TransferRange in JPEG context — returns null.
     */
    #[Test]
    public function transferRangeReturnsNullInJpegContext(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([]);

        self::assertNull($parsedExif->transferRange());
    }

    /**
     * When YCbCr coefficients are missing, expects Annex D defaults for YCbCr photos.
     * Verifies the method returns null for RGB photometric interpretation.
     */
    #[Test]
    public function ycbcrCoefficientsDefaultToAnnexDWhenMissing(): void
    {
        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::Ycbcr->value,
            ),
        ]);

        self::assertSame([0.299, 0.587, 0.114], $parsedExif->ycbcrCoefficients());

        $parsedExif = $this->parsedExifFromIfd0([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(
                ExifTag::PHOTOMETRIC_INTERPRETATION,
                3,
                1,
                Photometric::Rgb->value,
            ),
        ]);

        self::assertNull($parsedExif->ycbcrCoefficients());
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     */
    private function parsedExifFromIfd0(array $ifd0Entries): ParsedExif
    {
        return new ParsedExif(new Ifd($ifd0Entries), null, null, null, null);
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     * @param array<int, IfdEntry> $exifEntries
     */
    private function parsedExifFromEntries(array $ifd0Entries, array $exifEntries): ParsedExif
    {
        return new ParsedExif(new Ifd($ifd0Entries), new Ifd($exifEntries), null, null, null);
    }
}
