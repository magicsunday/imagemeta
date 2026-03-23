<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;

use function in_array;

/**
 * Reads physical image structure and layout metadata from EXIF IFDs.
 *
 * Covers image dimensions, orientation, compression, resolution, strip layout,
 * and JPEG interchange format fields.
 */
final readonly class ImageStructureExifReader
{
    /**
     * @param IfdValueReader $reader  Value reader for IFD tag extraction.
     * @param Ifd            $ifd0    Root IFD of the TIFF structure.
     * @param Ifd|null       $exifIfd Sub IFD containing EXIF-specific tags.
     */
    public function __construct(
        private IfdValueReader $reader,
        private Ifd $ifd0,
        private ?Ifd $exifIfd,
    ) {
    }

    // ========================================================================
    // Image dimensions
    // ========================================================================
    /**
     * Returns the image width, preferring the compressed-specific EXIF tag when applicable.
     *
     * Prefers PixelXDimension from the Exif IFD when present (EXIF 3.0
     * §4.6.6.3.1), falling back to ImageWidth from IFD0 (TIFF 6.0 §8).
     *
     * PixelXDimension is skipped only when the Compression tag is
     * explicitly set to UNCOMPRESSED. When the tag is absent (valid for
     * JPEG primary images per EXIF 3.0 §4.6.5.1.4), PixelXDimension
     * takes priority so the defaulted UNCOMPRESSED value does not
     * suppress dimension tags that are actually present.
     */
    public function imageWidth(): ?int
    {
        $explicitlyUncompressed = $this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry
            && ($this->compression() === Compression::Uncompressed);

        if (!$explicitlyUncompressed) {
            $pixelWidth = $this->reader->int($this->exifIfd, ExifTag::PIXEL_X_DIMENSION);

            if ($pixelWidth !== null) {
                return $pixelWidth;
            }
        }

        return $this->reader->int($this->ifd0, ExifTag::IMAGE_WIDTH);
    }

    /**
     * Returns the image height, preferring the compressed-specific EXIF tag when applicable.
     *
     * Prefers PixelYDimension from the Exif IFD when present (EXIF 3.0
     * §4.6.6.3.2), falling back to ImageLength from IFD0 (TIFF 6.0 §8).
     */
    public function imageHeight(): ?int
    {
        $explicitlyUncompressed = $this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry
            && ($this->compression() === Compression::Uncompressed);

        if (!$explicitlyUncompressed) {
            $pixelHeight = $this->reader->int($this->exifIfd, ExifTag::PIXEL_Y_DIMENSION);

            if ($pixelHeight !== null) {
                return $pixelHeight;
            }
        }

        return $this->reader->int($this->ifd0, ExifTag::IMAGE_LENGTH);
    }

    /**
     * Alias for imageHeight() using exact EXIF tag name.
     * EXIF 3.0 §4.6.5.1.2 ImageLength — Tag 0x0101, type SHORT or LONG, count 1; no default; not used for JPEG compressed data.
     *
     * @return int|null Image height in pixels
     */
    public function imageLength(): ?int
    {
        return $this->imageHeight();
    }

    /**
     * Alias for imageWidth() using exact EXIF tag name.
     * EXIF 3.0 §4.6.3 Tag Support Levels, Table 9 — Tag 0xA002 PixelXDimension.
     *
     * @return int|null Image width in pixels
     */
    public function pixelXDimension(): ?int
    {
        return $this->imageWidth();
    }

    /**
     * Alias for imageHeight() using exact EXIF tag name.
     * EXIF 3.0 §4.6.3 Tag Support Levels, Table 9 — Tag 0xA003 PixelYDimension.
     *
     * @return int|null Image height in pixels
     */
    public function pixelYDimension(): ?int
    {
        return $this->imageHeight();
    }

    // ========================================================================
    // Orientation
    // ========================================================================
    /**
     * Returns the EXIF orientation enumeration.
     *
     * TIFF 6.0 §8 and EXIF 3.0 §4.6.5.1.6 specify default value 1 (top-left) when not present.
     */
    public function orientation(): Orientation
    {
        // Normalizes numeric-string encodings emitted by some cameras.
        // TIFF 6.0 §8: Default is 1 (top-left) when tag is not present
        return Orientation::fromExifValue(
            $this->reader->enumValue($this->ifd0, ExifTag::ORIENTATION)
        ) ?? Orientation::TopLeft;
    }

    /**
     * Returns the orientation as a human-readable rotation description.
     *
     * EXIF 3.0 §4.6.5.1.6 defines eight orientation states. This method
     * returns descriptions like "Rotate 180", "Rotate 90 CW", or
     * "Mirror horizontal" as commonly displayed by ExifTool.
     */
    public function orientationDescription(): string
    {
        return $this->orientation()->rotationDescription();
    }

    // ========================================================================
    // Compression (primary image)
    // ========================================================================
    /**
     * Returns the compression method enum for the primary image.
     *
     * EXIF 3.0 §4.6.5.1.4 omits the Compression tag for primary JPEG images.
     * TIFF 6.0 §8 specifies default value 1 (no compression) when not present
     * in TIFF image data.
     *
     * Returns UNCOMPRESSED when the tag is absent (TIFF default), the resolved
     * enum case when the tag value is recognised, or null when the tag is
     * present but carries an unsupported code.
     */
    public function compression(): ?Compression
    {
        if (!$this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry) {
            return Compression::Uncompressed;
        }

        $value = $this->reader->enumValue($this->ifd0, ExifTag::COMPRESSION);

        return Compression::fromExifValue($value);
    }

    /**
     * Returns the compressed bits per pixel ratio.
     *
     * EXIF 3.0 §4.6.6.3.4 defines this rational value for compressed imagery to indicate
     * the effective compression mode.
     */
    public function compressedBitsPerPixel(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::COMPRESSED_BITS_PER_PIXEL);
    }

    // ========================================================================
    // Resolution
    // ========================================================================

    /**
     * Returns the horizontal resolution value expressed in the resolution unit.
     *
     * EXIF 3.0 §4.6.5.1.8 defaults to 72 dpi for JPEG primary images.
     * TIFF 6.0 defines no default; returns null in TIFF context.
     */
    public function xResolution(): ?float
    {
        return $this->reader->rational($this->ifd0, ExifTag::X_RESOLUTION)
            ?? ($this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry ? null : 72.0);
    }

    /**
     * Returns the vertical resolution value expressed in the resolution unit.
     *
     * Defaults to XResolution when absent per EXIF 3.0 §4.6.5.1.9.
     * Returns null in TIFF context when both tags are absent.
     */
    public function yResolution(): ?float
    {
        return $this->reader->rational($this->ifd0, ExifTag::Y_RESOLUTION) ?? $this->xResolution();
    }

    /**
     * Returns the resolution unit enum for the reported X/Y resolution values.
     *
     * EXIF 3.0 §4.6.5.1.11 and TIFF 6.0 §8 specify default value 2 (inches) when
     * not present.
     */
    public function resolutionUnit(): ResolutionUnit
    {
        // TIFF 6.0 §8: Default is 2 (INCHES) when tag is not present
        return ResolutionUnit::fromExifValue(
            $this->reader->enumValue($this->ifd0, ExifTag::RESOLUTION_UNIT)
        ) ?? ResolutionUnit::Inches;
    }

    // ========================================================================
    // Strip layout
    // ========================================================================

    /**
     * Returns the rows per strip value when the image data is organized in strips.
     *
     * EXIF 3.0 §4.6.5.2.2 defines RowsPerStrip for strip-based images and
     * requires the tag to be omitted for JPEG-compressed primary images.
     */
    public function rowsPerStrip(): ?int
    {
        if ($this->isJpegCompression($this->compression())) {
            return null;
        }

        // TIFF 6.0 §8: default is 2^32-1 (entire image in one strip).
        return $this->reader->int($this->ifd0, ExifTag::ROWS_PER_STRIP) ?? 4294967295;
    }

    /**
     * Returns the strip offsets defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.2.1 defines StripOffsets for strip-based image storage and
     * requires the tag to be omitted for JPEG-compressed primary images.
     * For thumbnail strip offsets, use thumbnailStripOffsets().
     *
     * @return list<int>|null
     */
    public function stripOffsets(): ?array
    {
        if ($this->isJpegCompression($this->compression())) {
            return null;
        }

        return $this->reader->numericList($this->ifd0, ExifTag::STRIP_OFFSETS);
    }

    /**
     * Returns the strip byte counts for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.2.3 defines StripByteCounts for strip-based image storage and
     * requires the tag to be omitted for JPEG-compressed primary images.
     * For thumbnail strip byte counts, use thumbnailStripByteCounts().
     *
     * @return list<int>|null
     */
    public function stripByteCounts(): ?array
    {
        if ($this->isJpegCompression($this->compression())) {
            return null;
        }

        return $this->reader->numericList($this->ifd0, ExifTag::STRIP_BYTE_COUNTS);
    }

    // ========================================================================
    // JPEG interchange format (IFD0)
    // ========================================================================

    /**
     * Returns the JPEG interchange format offset for legacy thumbnails.
     *
     * EXIF 3.0 §4.6.5.2.4 notes that this tag shall not be recorded for primary
     * images encoded with JPEG compression.
     */
    public function jpegInterchangeFormat(): ?int
    {
        if ($this->isJpegCompression($this->compression())) {
            return null;
        }

        return $this->reader->int($this->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT);
    }

    /**
     * Returns the JPEG interchange format length for legacy thumbnails.
     *
     * EXIF 3.0 §4.6.5.2.4 notes that this tag shall not be recorded for primary
     * images encoded with JPEG compression.
     */
    public function jpegInterchangeFormatLength(): ?int
    {
        if ($this->isJpegCompression($this->compression())) {
            return null;
        }

        return $this->reader->int($this->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);
    }

    // ========================================================================
    // Private helpers
    // ========================================================================

    /**
     * Determines whether strip-based metadata shall be omitted for JPEG-encoded payloads.
     */
    private function isJpegCompression(?Compression $compression): bool
    {
        if (!$compression instanceof Compression) {
            return false;
        }

        return in_array(
            $compression,
            [
                Compression::Jpeg,
                Compression::JpegNewStyle,
                Compression::LossyJpeg,
                Compression::Jpeg2000,
            ],
            true
        );
    }
}
