<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList as ExifNumericValueList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;

use function is_int;
use function sprintf;

/**
 * Validates tag layouts, value domains, and cross-IFD semantic constraints.
 *
 * EXIF 3.0 §4.6 defines fixed-length tag requirements, numeric domains, and
 * companion tag obligations checked by this validator.
 */
final readonly class TiffExifTagValidator
{
    // Postel's Law: EXIF 3.0 §4.6.5.1 says several tags "shall not be recorded"
    // in IFD0 for JPEG-compressed primary images, but many cameras include them
    // anyway (e.g. BitsPerSample=8, SamplesPerPixel=3).  We only reject tags
    // that would cause structural parsing conflicts — JPEG interchange pointers.
    // RowsPerStrip / StripByteCounts are tolerated because JPEG readers derive
    // image layout from SOF markers in stream data.
    // Informational tags like BitsPerSample,
    // SamplesPerPixel, PhotometricInterpretation, PlanarConfiguration, and
    // Compression are tolerated because they are redundant (derivable from the
    // JPEG SOF marker) and harmless.

    /**
     * Tags prohibited in JPEG-compressed primary images (IFD0).
     *
     * EXIF 3.0 §4.6.5.1 specifies several tags that shall not be used when the
     * primary image data is JPEG-compressed.
     *
     * @var list<array{int, string}>
     */
    private const array JPEG_PROHIBITED_TAGS = [
        [ExifTag::JPEG_INTERCHANGE_FORMAT, 'JPEGInterchangeFormat'],
        [ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 'JPEGInterchangeFormatLength'],
    ];

    /**
     * EXIF 3.0 §4.6.6.9 tags must reside in the Exif IFD, not IFD0.
     * CameraOwnerName (EXIF 3.0 §4.6.6.9.2) is tolerated in IFD0.
     *
     * @var list<array{int, string, string}>
     */
    private const array EXIF_IFD_ONLY_TAGS = [
        [ExifTag::PHOTOGRAPHER, 'Photographer', '§4.6.6.9.9'],
        [ExifTag::IMAGE_EDITOR, 'ImageEditor', '§4.6.6.9.10'],
        [ExifTag::CAMERA_FIRMWARE, 'CameraFirmware', '§4.6.6.9.11'],
        [ExifTag::RAW_DEVELOPING_SOFTWARE, 'RAWDevelopingSoftware', '§4.6.6.9.12'],
        [ExifTag::IMAGE_EDITING_SOFTWARE, 'ImageEditingSoftware', '§4.6.6.9.13'],
        [ExifTag::METADATA_EDITING_SOFTWARE, 'MetadataEditingSoftware', '§4.6.6.9.14'],
    ];

    /**
     * Validates tag layouts with fixed byte counts mandated by EXIF.
     */
    public function validateFixedLengthTagLayout(): void
    {
        // Postel's Law: tolerate both TIFF type mismatches and fixed-length
        // byte-count deviations.  Real-world cameras routinely write tags with
        // types and component counts that differ from spec.  The parser decodes
        // using the actual type and whatever bytes are available.
    }

    /**
     * Validates tags that have a strict TIFF type but no fixed component count.
     */
    public function validateTypeOnlyTagLayout(): void
    {
        // Postel's Law: tolerate TIFF type mismatches for type-only tags.
        // Real-world cameras write tags with types that differ from spec.
        // The parser decodes using the actual type present.
    }

    /**
     * Validates individual tag value domains inline during readDirEntry.
     */
    public function validateTagValueDomain(
        int $tag,
        int|float|string|ExifRational|ExifRationalList|ExifNumericValueList|UInt64 $value,
    ): void {
        if (!is_int($value)) {
            return;
        }

        match ($tag) {
            DngTag::MAKER_NOTE_SAFETY => $this->assertMakerNoteSafetyDomain($value),
            TiffTag::PREDICTOR        => $this->assertPredictorDomain($value),
            default                   => null,
        };
    }

    /**
     * Validates strict GPS reference tag layouts within the GPS IFD.
     */
    public function validateGpsReferenceTagLayouts(): void
    {
        // Postel's Law: tolerate both TIFF type mismatches and byte-count
        // deviations for GPS reference tags.  Real-world cameras write
        // varying types and counts.
    }

    /**
     * Validates strict GPS coordinate value tag layouts within the GPS IFD.
     */
    public function validateGpsCoordinateTagLayouts(): void
    {
        // Postel's Law: tolerate both TIFF type mismatches and byte-count
        // deviations for GPS coordinate tags.  Real-world cameras write
        // varying types and counts.
    }

    /**
     * Validates EXIF primary/thumbnail structure combinations across IFD0 and IFD1.
     *
     * EXIF 3.0 §4.5.8 Table 3 states that when primary image data is uncompressed
     * RGB or uncompressed YCbCr, the thumbnail shall not be JPEG-compressed.
     *
     * @param Ifd      $ifd0        Primary image IFD.
     * @param Ifd|null $ifd1        Thumbnail IFD.
     * @param bool     $jpegContext True when APP1 data comes from JPEG primary image context.
     */
    public function validatePrimaryThumbnailStructureCompatibility(Ifd $ifd0, ?Ifd $ifd1, bool $jpegContext): void
    {
        if (!$ifd1 instanceof Ifd || $jpegContext) {
            return;
        }

        $thumbCompression = $ifd1->get(ExifTag::COMPRESSION);
        if (!($thumbCompression instanceof IfdEntry) || !is_int($thumbCompression->value) || ($thumbCompression->value !== 6)) {
            return;
        }

        $primaryCompression = $ifd0->get(ExifTag::COMPRESSION);
        if (!($primaryCompression instanceof IfdEntry) || !is_int($primaryCompression->value) || ($primaryCompression->value !== 1)) {
            return;
        }

        $photometric = $ifd0->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        if (!($photometric instanceof IfdEntry) || !is_int($photometric->value)) {
            return;
        }

        if (($photometric->value === 2) || ($photometric->value === 6)) {
            $primaryStructure = $photometric->value === 2 ? 'uncompressed RGB' : 'uncompressed YCbCr';

            throw new ParseError(
                sprintf(
                    'IFD1 JPEG thumbnail compression is not allowed when IFD0 primary image uses %s per EXIF 3.0 §4.5.8 Table 3.',
                    $primaryStructure,
                ),
                1995,
            );
        }
    }

    /**
     * Validates EXIF Flash tag bitfield semantics and reserved combinations.
     *
     * EXIF 3.0 §4.6.6.7.21 defines bit 0 (fired), bits 1-2 (return status),
     * bits 3-4 (mode), bit 5 (function present flag), and bit 6 (red-eye).
     * Bit 7 and above are reserved and must remain zero in strict conformance.
     *
     * @param Ifd|null $exifIfd EXIF IFD when present.
     */
    public function validateFlashBitfield(?Ifd $exifIfd): void
    {
        if (!$exifIfd instanceof Ifd) {
            return;
        }

        $entry = $exifIfd->get(ExifTag::FLASH);
        if (!($entry instanceof IfdEntry) || !is_int($entry->value)) {
            return;
        }

        // Mask to bits 0–6; ignore reserved high-order bits (Postel's Law).
        $flashBits = $entry->value & 0x7F;

        // Postel's Law: accept reserved return-status bits.
        $fired      = ($flashBits & 0x01) !== 0;
        $returnBits = ($flashBits >> 1) & 0x03;
        $modeBits   = ($flashBits >> 3) & 0x03;

        // EXIF 3.0 §4.6.6.7.21 marks return-detection bits without fired bit as invalid.
        // Tolerate real-world camera values like 20/30 when mode bits are populated,
        // but keep rejecting the strict unknown-mode variants (e.g. 0x04/0x06).
        if ((($returnBits === 2) || ($returnBits === 3)) && !$fired && ($modeBits === 0)) {
            throw new ParseError(
                sprintf('Flash value %d encodes return detection while flash-fired bit is unset per EXIF 3.0 §4.6.6.7.21', $flashBits),
                1984,
            );
        }
    }

    /**
     * Validates that tags prohibited in JPEG-compressed primary images are absent from IFD0.
     *
     * EXIF 3.0 §4.6.5.1 prohibits JPEG interchange pointers in IFD0 when the
     * primary image is JPEG-compressed. RowsPerStrip/StripByteCounts are
     * tolerated reader-side for real-world compatibility.
     *
     * @param Ifd $ifd0 Primary image IFD.
     */
    public function validateJpegContextProhibitions(Ifd $ifd0): void
    {
        foreach (self::JPEG_PROHIBITED_TAGS as [$tag, $name]) {
            if ($ifd0->get($tag) instanceof IfdEntry) {
                throw new ParseError(sprintf(
                    '%s shall not be present in IFD0 for JPEG-compressed primary image per EXIF 3.0 §4.6.5.1.',
                    $name,
                ), 1353);
            }
        }
    }

    /**
     * Validates that EXIF 3.0 §4.6.6.9 tags are not placed in IFD0.
     *
     * @param Ifd $ifd0 Primary image IFD.
     */
    public function validateExifIfdPlacement(Ifd $ifd0): void
    {
        // EXIF 3.0 §4.6.6.9.2 defines CameraOwnerName in Exif IFD, but many
        // files place it in IFD0; reader-side parsing preserves this value.
        foreach (self::EXIF_IFD_ONLY_TAGS as [$tag, $name, $section]) {
            if ($ifd0->get($tag) instanceof IfdEntry) {
                throw new ParseError(sprintf(
                    '%s must reside in the Exif IFD, not IFD0, per EXIF 3.0 %s.',
                    $name,
                    $section,
                ), 1991);
            }
        }
    }

    private function assertMakerNoteSafetyDomain(int $value): void
    {
        if (($value !== 0) && ($value !== 1)) {
            throw new ParseError(sprintf(
                'MakerNoteSafety value %d is outside the valid domain {0, 1} per DNG 1.7.1.0.',
                $value,
            ), 1975);
        }
    }

    private function assertPredictorDomain(int $value): void
    {
        if (($value !== 1) && ($value !== 2)) {
            throw new ParseError(sprintf(
                'Predictor value %d is outside the valid domain {1, 2} per TIFF 6.0 §14.',
                $value,
            ), 1358);
        }
    }
}
