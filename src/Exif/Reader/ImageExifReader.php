<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Text\JisTextDecoder;
use MagicSunday\ImageMeta\Exif\Text\UndefinedTextMarker;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;

use function array_fill;
use function array_find;
use function count;
use function in_array;
use function is_array;
use function ord;
use function preg_match;
use function strlen;
use function substr;
use function substr_count;
use function trim;

/**
 * Reads image-property metadata from EXIF IFDs.
 *
 * Covers camera/lens identification, image dimensions, orientation, colour space,
 * compression, resolution, photometric interpretation, YCbCr, DNG tags, document
 * metadata, user comment decoding, components configuration and EXIF version fields.
 */
final readonly class ImageExifReader
{
    /**
     * @param IfdValueReader  $reader       Value reader for IFD tag extraction.
     * @param ValueConverters $converters   Value converter facade for EXIF type normalization.
     * @param Ifd             $ifd0         Root IFD of the TIFF structure.
     * @param Ifd|null        $exifIfd      Sub IFD containing EXIF-specific tags.
     * @param string          $exifProfile  Derived EXIF capability profile identifier.
     * @param FallbackIfdSet  $fallbackIfds Fallback IFD set for secondary metadata lookup.
     */
    public function __construct(
        private IfdValueReader $reader,
        private ValueConverters $converters,
        private Ifd $ifd0,
        private ?Ifd $exifIfd,
        private string $exifProfile,
        private FallbackIfdSet $fallbackIfds,
    ) {
    }

    // ========================================================================
    // Camera / lens identification
    // ========================================================================

    /**
     * Returns the camera manufacturer string if present.
     *
     * EXIF 3.0 §4.6.5.4.2 (Make) stores the free-form manufacturer identifier
     * as ASCII or UTF-8 including the terminating NUL.
     *
     * @return string|null
     */
    public function cameraMake(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::MAKE);
    }

    /**
     * Returns the camera model string if present.
     *
     * EXIF 3.0 §4.6.5.4.3 (Model) defines the model name or number as an ASCII
     * or UTF-8 string with the NUL terminator counted in the tag length.
     *
     * @return string|null
     */
    public function cameraModel(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::MODEL);
    }

    /**
     * Returns the lens model string if present.
     *
     * EXIF 3.0 §4.6.6.9.6 stores the lens model as an ASCII or UTF-8 string.
     *
     * @return string|null
     */
    public function lensModel(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::LENS_MODEL);
    }

    /**
     * Returns the lens manufacturer string if present.
     *
     * EXIF 3.0 §4.6.6.9.5 records LensMake as an ASCII or UTF-8 identifier and
     * expects it to remain stable once captured.
     *
     * @return string|null
     */
    public function lensMake(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::LENS_MAKE);
    }

    /**
     * Returns the camera owner name if present.
     *
     * EXIF 3.0 §4.6.6.9.2 allows ASCII or UTF-8 text for CameraOwnerName and
     * expects Artist to be populated alongside it.
     *
     * @return string|null
     */
    public function ownerName(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::CAMERA_OWNER_NAME);
    }

    /**
     * Returns the camera body serial number if present.
     *
     * EXIF 3.0 §4.6.6.9.3 stores the camera body serial as an ASCII string.
     *
     * @return string|null
     */
    public function bodySerialNumber(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::BODY_SERIAL_NUMBER);
    }

    /**
     * Returns the lens serial number if present.
     *
     * EXIF 3.0 §4.6.6.9.7 defines LensSerialNumber as a free-form ASCII value
     * that should remain stable across edits.
     *
     * @return string|null
     */
    public function lensSerialNumber(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::LENS_SERIAL_NUMBER);
    }

    /**
     * Returns the lens specification describing focal and aperture range.
     *
     * EXIF 3.0 §4.6.6.9.4 stores four RATIONALs: minimum
     * focal length, maximum focal length, minimum F-number at the minimum focal
     * length, and minimum F-number at the maximum focal length. Unknown
     * apertures are recorded as 0/0.
     *
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public function lensSpecification(): ?array
    {
        $values = $this->reader->rationalList($this->exifIfd, ExifTag::LENS_SPECIFICATION);

        if (!is_array($values) || count($values) !== 4) {
            return null;
        }

        return [
            $values[0],
            $values[1],
            $values[2],
            $values[3],
        ];
    }

    /**
     * Returns the DNG camera serial number from IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, CameraSerialNumber): ASCII, NUL-terminated.
     */
    public function cameraSerialNumber(): ?string
    {
        return $this->reader->str($this->ifd0, DngTag::CAMERA_SERIAL_NUMBER);
    }

    /**
     * Returns the non-localized unique DNG camera model from IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, UniqueCameraModel): ASCII, NUL-terminated.
     */
    public function uniqueCameraModel(): ?string
    {
        return $this->reader->str($this->ifd0, DngTag::UNIQUE_CAMERA_MODEL);
    }

    /**
     * Returns the localized DNG camera model from IFD0.
     *
     * DNG 1.7.1.0 (DNG Tags, LocalizedCameraModel): ASCII or BYTE, NUL-terminated UTF-8.
     * Default: same as UniqueCameraModel when absent.
     */
    public function localizedCameraModel(): ?string
    {
        return $this->reader->str($this->ifd0, DngTag::LOCALIZED_CAMERA_MODEL)
            ?? $this->reader->str($this->ifd0, DngTag::UNIQUE_CAMERA_MODEL);
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
     *
     * @return int|null
     */
    public function imageWidth(): ?int
    {
        $explicitlyUncompressed = $this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry
            && $this->compression() === Compression::UNCOMPRESSED;

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
     *
     * @return int|null
     */
    public function imageHeight(): ?int
    {
        $explicitlyUncompressed = $this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry
            && $this->compression() === Compression::UNCOMPRESSED;

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
     *
     * @return Orientation
     */
    public function orientation(): Orientation
    {
        $rawOrientation = $this->reader->enumValue($this->ifd0, ExifTag::ORIENTATION);

        // Normalises numeric-string encodings emitted by some cameras.
        $orientation = Orientation::fromExifValue($rawOrientation);

        // TIFF 6.0 §8: Default is 1 (top-left) when tag is not present
        return $orientation ?? Orientation::TOP_LEFT;
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
    // Colour space
    // ========================================================================

    /**
     * Returns the colour space enumeration.
     *
     * EXIF 3.0 §4.6.6.2.1 states ColorSpace is always recorded when an
     * ExifIFD is present. When the tag is absent despite ExifIFD existing,
     * sRGB is assumed per the most common real-world usage. An explicitly
     * present but unrecognized value still returns null.
     */
    public function colorSpace(): ?ColorSpace
    {
        $value = $this->reader->enumValue($this->exifIfd, ExifTag::COLOR_SPACE);
        $space = ColorSpace::fromExifValue($value);

        if ($space instanceof ColorSpace) {
            return $space;
        }

        // Tag present with unrecognized value -> null (don't override)
        if ($this->exifIfd?->get(ExifTag::COLOR_SPACE) instanceof IfdEntry) {
            return null;
        }

        // EXIF 3.0 §4.6.6.2.1: ColorSpace is required when ExifIFD exists.
        // Default to sRGB for non-conformant files that omit the tag.
        return $this->exifIfd instanceof Ifd ? ColorSpace::SRGB : null;
    }

    /**
     * Returns the derived EXIF capability profile identifier.
     */
    public function exifProfile(): string
    {
        return $this->exifProfile;
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
     *
     * @return Compression|null
     */
    public function compression(): ?Compression
    {
        if (!$this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry) {
            return Compression::UNCOMPRESSED;
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
        $resolution = $this->reader->rational($this->ifd0, ExifTag::X_RESOLUTION);

        if ($resolution !== null) {
            return $resolution;
        }

        // JPEG context (no Compression tag) -> EXIF default 72 dpi
        return $this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry ? null : 72.0;
    }

    /**
     * Returns the vertical resolution value expressed in the resolution unit.
     *
     * Defaults to XResolution when absent per EXIF 3.0 §4.6.5.1.9.
     * Returns null in TIFF context when both tags are absent.
     */
    public function yResolution(): ?float
    {
        $resolution = $this->reader->rational($this->ifd0, ExifTag::Y_RESOLUTION);

        if ($resolution !== null) {
            return $resolution;
        }

        return $this->xResolution();
    }

    /**
     * Returns the resolution unit enum for the reported X/Y resolution values.
     *
     * EXIF 3.0 §4.6.5.1.11 and TIFF 6.0 §8 specify default value 2 (inches) when
     * not present.
     *
     * @return ResolutionUnit
     */
    public function resolutionUnit(): ResolutionUnit
    {
        $value = $this->reader->enumValue($this->ifd0, ExifTag::RESOLUTION_UNIT);
        $unit  = ResolutionUnit::fromExifValue($value);

        // TIFF 6.0 §8: Default is 2 (INCHES) when tag is not present
        return $unit ?? ResolutionUnit::INCHES;
    }

    // ========================================================================
    // Photometric interpretation
    // ========================================================================

    /**
     * Returns the photometric interpretation enum.
     */
    public function photometric(): ?Photometric
    {
        $value = $this->reader->enumValue($this->ifd0, ExifTag::PHOTOMETRIC_INTERPRETATION);

        return Photometric::fromExifValue($value);
    }

    // ========================================================================
    // Planar configuration
    // ========================================================================

    /**
     * Returns the planar configuration enum.
     *
     * TIFF 6.0 §8 specifies default value 1 (chunky format) when not present.
     * EXIF 3.0 §4.6.5.1.10 states JPEG compressed data shall not record
     * this tag because the JPEG marker carries the equivalent information.
     * Returns null when the tag is absent in JPEG context (no Compression tag).
     *
     * @return PlanarConfiguration|null
     */
    public function planarConfiguration(): ?PlanarConfiguration
    {
        $value  = $this->reader->enumValue($this->ifd0, ExifTag::PLANAR_CONFIGURATION);
        $config = PlanarConfiguration::fromExifValue($value);

        if ($config instanceof PlanarConfiguration) {
            return $config;
        }

        // TIFF 6.0 §8: Default is CHUNKY when tag is absent in TIFF context.
        // When Compression is absent (JPEG primary image), do not emit a
        // synthetic TIFF-layout value.
        return $this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry
            ? PlanarConfiguration::CHUNKY
            : null;
    }

    // ========================================================================
    // Samples / bits per sample
    // ========================================================================

    /**
     * Returns the number of samples per pixel.
     *
     * When absent, defaults depend on photometric interpretation:
     * RGB, YCbCr, CIELab -> 3; grayscale/palette/mask -> 1.
     * JPEG context (no Compression tag) defaults to 3 per EXIF 3.0 §4.6.5.1.7;
     * TIFF context defaults to 1 per TIFF 6.0 §8.
     *
     * @return int
     */
    public function samplesPerPixel(): int
    {
        $explicit = $this->reader->int($this->ifd0, ExifTag::SAMPLES_PER_PIXEL);

        if ($explicit !== null) {
            return $explicit;
        }

        $photometric = $this->photometric();

        if ($photometric instanceof Photometric) {
            return match ($photometric) {
                Photometric::RGB, Photometric::YCBCR, Photometric::CIELAB => 3,
                default => 1,
            };
        }

        // JPEG context (no Compression tag) -> EXIF default 3;
        // TIFF context -> TIFF 6.0 default 1.
        return $this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry ? 1 : 3;
    }

    /**
     * Returns the first component of BitsPerSample (convenience scalar).
     *
     * EXIF 3.0 §4.6.5.1.3 states JPEG compressed data shall not record this
     * tag; precision comes from the JPEG SOF marker instead. Returns null in
     * JPEG context (no Compression tag) so callers can fall back to SOF.
     * TIFF 6.0 §8 default is 1 per component; EXIF profile uses 8 for RGB.
     *
     * @return int|null
     */
    public function bitsPerSample(): ?int
    {
        $list = $this->bitsPerSampleList();

        return $list !== null ? $list[0] : null;
    }

    /**
     * Returns the per-sample BitsPerSample vector.
     *
     * TIFF 6.0 defines BitsPerSample as a per-component field whose count
     * matches SamplesPerPixel. Returns null in JPEG context (no Compression
     * tag) so callers can fall back to SOF precision.
     *
     * @return list<int>|null
     */
    public function bitsPerSampleList(): ?array
    {
        $values = $this->reader->numericList($this->ifd0, ExifTag::BITS_PER_SAMPLE);

        if ($values !== null) {
            return $values;
        }

        // JPEG context: let caller use SOF precision fallback
        if (!$this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry) {
            return null;
        }

        // TIFF context: default 8 per component, replicated per SamplesPerPixel
        $spp = $this->samplesPerPixel();

        return array_fill(0, $spp, 8);
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
    // YCbCr
    // ========================================================================

    /**
     * Returns the YCbCr conversion coefficients when provided.
     *
     * EXIF 3.0 §4.6.5.3.4 defines three rational coefficients for RGB->YCbCr
     * conversion, defaulting to Annex D values when the tag is absent.
     *
     * @return array{0:float,1:float,2:float}|null
     */
    public function ycbcrCoefficients(): ?array
    {
        $value = $this->reader->normalisedValue($this->ifd0, ExifTag::YCBCR_COEFFICIENTS);

        if ($value instanceof ExifNumericList) {
            $coeffs = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $coeffs[] = (float) $component->toInt('YCbCrCoefficients');
                } else {
                    $coeffs[] = (float) $component;
                }
            }

            return count($coeffs) === 3 ? $coeffs : null;
        }

        if ($value instanceof ExifRationalList) {
            $coeffs = [];
            foreach ($value->values as $component) {
                $float = $this->converters->rationalToFloat($component);
                if ($float === null) {
                    return null;
                }

                $coeffs[] = $float;
            }

            return count($coeffs) === 3 ? $coeffs : null;
        }

        return $this->defaultYCbCrCoefficients();
    }

    /**
     * Returns the YCbCr subsampling factors.
     *
     * EXIF 3.0 §4.6.5.1.12 defines only [2,1] (YCbCr4:2:2) and [2,2]
     * (YCbCr4:2:0) as legal values. The tag shall not be recorded for JPEG
     * compressed data because the JPEG marker stream already encodes the
     * sampling factors.
     *
     * TIFF 6.0 §21 defines the default as [2,2] for YCbCr images when the
     * tag is absent. In JPEG context (no Compression tag) we return null so
     * SOF-derived subsampling can be used by the caller.
     *
     * @return array{0:int,1:int}|null
     */
    public function ycbcrSubSampling(): ?array
    {
        $values = $this->reader->numericList($this->ifd0, ExifTag::YCBCR_SUB_SAMPLING);

        if ($values !== null) {
            if (count($values) === 2) {
                return $this->validateYcbcrPair($values[0], $values[1]);
            }

            return null;
        }

        $raw = $this->reader->rawString($this->ifd0, ExifTag::YCBCR_SUB_SAMPLING);

        if ($raw !== null) {
            $pair = $this->converters->ycbcrSubSamplingToPair($raw);

            return $pair !== null ? $this->validateYcbcrPair($pair[0], $pair[1]) : null;
        }

        // TIFF 6.0 §21: Default [2,2] for YCbCr in TIFF context.
        // In JPEG context, let SOF-derived subsampling take precedence.
        if (
            $this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry
            && $this->photometric() === Photometric::YCBCR
        ) {
            return [2, 2];
        }

        return null;
    }

    /**
     * Returns the YCbCr positioning enum describing the chroma siting.
     *
     * EXIF 3.0 §4.6.5.1.13 defines the default as centered, but the tag
     * is only semantically applicable when the photometric interpretation
     * is YCbCr. Non-YCbCr images return null when the tag is absent.
     */
    public function ycbcrPositioning(): ?YCbCrPositioning
    {
        $rawValue = $this->reader->value($this->ifd0, ExifTag::YCBCR_POSITIONING);

        if ($rawValue === null) {
            return $this->photometric() === Photometric::YCBCR
                ? YCbCrPositioning::CENTERED
                : null;
        }

        $value = $this->reader->normaliseEnumScalar($rawValue);

        return YCbCrPositioning::fromExifValue($value);
    }

    /**
     * Returns the reference black and white point values as floating point numbers.
     *
     * EXIF 3.0 §4.6.5.3.5 describes defaults when the colour space is declared.
     *
     * @return list<float>|null
     */
    public function referenceBlackWhite(): ?array
    {
        $values = $this->reader->rationalList($this->ifd0, ExifTag::REFERENCE_BLACK_WHITE);

        if ($values !== null) {
            return $this->normaliseReferenceBlackWhite($values);
        }

        return $this->defaultReferenceBlackWhite();
    }

    // ========================================================================
    // White point / primary chromaticities
    // ========================================================================

    /**
     * Returns the normalized white point coordinates.
     *
     * EXIF 3.0 §4.6.5.3.2 (WhitePoint) encodes the chromaticity of the white
     * point as exactly two rational values (X,Y).
     *
     * @return array{0:float,1:float}|null
     */
    public function whitePoint(): ?array
    {
        $value = $this->reader->value($this->ifd0, ExifTag::WHITE_POINT);

        return $value instanceof ExifRationalList || $value instanceof ExifNumericList
            ? $this->converters->toWhitePoint($value)
            : null;
    }

    /**
     * Returns the primary chromaticities ordered as R,G,B.
     *
     * EXIF 3.0 §4.6.5.3.3 (PrimaryChromaticities) defines three rational pairs
     * (RedX, RedY, GreenX, GreenY, BlueX, BlueY).
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public function primaryChromaticities(): ?array
    {
        $value = $this->reader->value($this->ifd0, ExifTag::PRIMARY_CHROMATICITIES);

        return $value instanceof ExifRationalList || $value instanceof ExifNumericList
            ? $this->converters->toPrimaryChromaticities($value)
            : null;
    }

    // ========================================================================
    // DNG tags
    // ========================================================================

    /**
     * Returns the DNG version encoded in IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, DNGVersion): BYTE[4], required in IFD0.
     */
    public function dngVersion(): ?string
    {
        return $this->reader->dngVersionTag($this->ifd0, DngTag::DNG_VERSION);
    }

    /**
     * Returns the backward-compatibility DNG version encoded in IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, DNGBackwardVersion): BYTE[4], required in IFD0.
     */
    public function dngBackwardVersion(): ?string
    {
        return $this->reader->dngVersionTag($this->ifd0, DngTag::DNG_BACKWARD_VERSION);
    }

    /**
     * Returns the DNG profile name from IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, ProfileName): ASCII or UTF-8.
     */
    public function dngProfileName(): ?string
    {
        return $this->reader->str($this->ifd0, DngTag::PROFILE_NAME);
    }

    /**
     * Returns the primary DNG calibration illuminant identifier.
     *
     * DNG 1.7.1.0 (DNG Tags, CalibrationIlluminant1): SHORT.
     */
    public function dngCalibrationIlluminant1(): ?int
    {
        return $this->reader->int($this->ifd0, DngTag::CALIBRATION_ILLUMINANT_1);
    }

    /**
     * Returns the secondary DNG calibration illuminant identifier.
     *
     * DNG 1.7.1.0 (DNG Tags, CalibrationIlluminant2): SHORT.
     */
    public function dngCalibrationIlluminant2(): ?int
    {
        return $this->reader->int($this->ifd0, DngTag::CALIBRATION_ILLUMINANT_2);
    }

    /**
     * Returns the primary DNG color matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, ColorMatrix1): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngColorMatrix1(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::COLOR_MATRIX_1);
    }

    /**
     * Returns the secondary DNG color matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, ColorMatrix2): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngColorMatrix2(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::COLOR_MATRIX_2);
    }

    /**
     * Returns the primary DNG camera calibration matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, CameraCalibration1): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngCameraCalibration1(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::CAMERA_CALIBRATION_1);
    }

    /**
     * Returns the secondary DNG camera calibration matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, CameraCalibration2): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngCameraCalibration2(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::CAMERA_CALIBRATION_2);
    }

    /**
     * Returns the primary DNG forward matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, ForwardMatrix1): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngForwardMatrix1(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::FORWARD_MATRIX_1);
    }

    /**
     * Returns the secondary DNG forward matrix as a list of floats.
     *
     * DNG 1.7.1.0 (DNG Tags, ForwardMatrix2): SRATIONAL.
     *
     * @return list<float>|null
     */
    public function dngForwardMatrix2(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::FORWARD_MATRIX_2);
    }

    /**
     * Returns the DNG as-shot neutral white balance coordinates.
     *
     * DNG 1.7.1.0 (DNG Tags, AsShotNeutral): RATIONAL or SHORT.
     *
     * @return list<float>|null
     */
    public function dngAsShotNeutral(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::AS_SHOT_NEUTRAL);
    }

    /**
     * Returns the DNG as-shot white point chromaticity coordinates.
     *
     * DNG 1.7.1.0 (DNG Tags, AsShotWhiteXY): RATIONAL.
     *
     * @return list<float>|null
     */
    public function dngAsShotWhiteXY(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::AS_SHOT_WHITE_XY);
    }

    /**
     * Returns the DNG baseline exposure offset value.
     *
     * DNG 1.7.1.0 (DNG Tags, BaselineExposure): SRATIONAL.
     */
    public function dngBaselineExposure(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::BASELINE_EXPOSURE);
    }

    /**
     * Returns the DNG baseline noise level estimate.
     *
     * DNG 1.7.1.0 (DNG Tags, BaselineNoise): RATIONAL.
     */
    public function dngBaselineNoise(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::BASELINE_NOISE);
    }

    /**
     * Returns the DNG baseline sharpness estimate.
     *
     * DNG 1.7.1.0 (DNG Tags, BaselineSharpness): RATIONAL.
     */
    public function dngBaselineSharpness(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::BASELINE_SHARPNESS);
    }

    /**
     * Returns the DNG linear response limit for the sensor.
     *
     * DNG 1.7.1.0 (DNG Tags, LinearResponseLimit): RATIONAL.
     */
    public function dngLinearResponseLimit(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::LINEAR_RESPONSE_LIMIT);
    }

    /**
     * Returns the DNG linearization lookup table.
     *
     * DNG 1.7.1.0 (DNG Tags, LinearizationTable): SHORT.
     *
     * @return list<int>|null
     */
    public function linearizationTable(): ?array
    {
        return $this->reader->numericList($this->ifd0, DngTag::LINEARIZATION_TABLE);
    }

    /**
     * Returns the DNG analog balance values per color channel.
     *
     * DNG 1.7.1.0 (DNG Tags, AnalogBalance): RATIONAL.
     *
     * @return list<float>|null
     */
    public function analogBalance(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::ANALOG_BALANCE);
    }

    /**
     * Returns the DNG anti-aliasing strength applied during capture.
     *
     * DNG 1.7.1.0 (DNG Tags, AntiAliasStrength): RATIONAL.
     */
    public function antiAliasStrength(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::ANTI_ALIAS_STRENGTH);
    }

    /**
     * Returns the DNG shadow scale parameter for tone mapping.
     *
     * DNG 1.7.1.0 (DNG Tags, ShadowScale): RATIONAL.
     */
    public function shadowScale(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::SHADOW_SCALE);
    }

    /**
     * Returns the DNG best quality scale factor for rendering.
     *
     * DNG 1.7.1.0 (DNG Tags, BestQualityScale): RATIONAL.
     */
    public function bestQualityScale(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::BEST_QUALITY_SCALE);
    }

    /**
     * Returns the DNG baseline exposure offset adjustment.
     *
     * DNG 1.7.1.0 (DNG Tags, BaselineExposureOffset): SRATIONAL.
     */
    public function baselineExposureOffset(): ?float
    {
        return $this->reader->rational($this->ifd0, DngTag::BASELINE_EXPOSURE_OFFSET);
    }

    /**
     * Returns the DNG profile tone curve data.
     *
     * DNG 1.7.1.0 (DNG Tags, ProfileToneCurve): FLOAT.
     *
     * @return list<float>|null
     */
    public function profileToneCurve(): ?array
    {
        return $this->reader->rationalList($this->ifd0, DngTag::PROFILE_TONE_CURVE);
    }

    // ========================================================================
    // Document metadata
    // ========================================================================

    /**
     * Returns the software or firmware identifier reported by the image source.
     *
     * EXIF 3.0 §4.6.5.4.4 (Software) recommends recording the generating software
     * name and version in ASCII or UTF-8 with the terminating NUL accounted for in the count.
     */
    public function software(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::SOFTWARE);
    }

    /**
     * Returns the document name preferring EXIF 3.0 tags with XP fallbacks.
     */
    public function documentName(): ?string
    {
        $candidates = [
            [$this->ifd0, TiffTag::DOCUMENT_NAME],
            [$this->exifIfd, TiffTag::DOCUMENT_NAME],
            [$this->ifd0, ExifTag::IMAGE_TITLE],
            [$this->exifIfd, ExifTag::IMAGE_TITLE],
        ];

        foreach ($candidates as [$ifd, $tag]) {
            $value = $this->reader->str($ifd, $tag);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Returns the copyright notice string when present.
     *
     * EXIF 3.0 §4.6.5.4.7 represents empty or blank-filled copyright fields as unknown values.
     */
    public function copyright(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::COPYRIGHT);
    }

    /**
     * Returns the artist tag value when present.
     *
     * EXIF 3.0 §4.6.5.4.6 requires Artist to be populated alongside
     * CameraOwnerName, Photographer, or ImageEditor. The closest available
     * attribution is returned when the primary tag is missing.
     */
    public function artist(): ?string
    {
        $artist = $this->reader->str($this->ifd0, ExifTag::ARTIST);

        if ($artist !== null) {
            return $artist;
        }

        return array_find(
            [
                $this->reader->str($this->exifIfd, ExifTag::CAMERA_OWNER_NAME),
                $this->reader->str($this->ifd0, ExifTag::PHOTOGRAPHER),
                $this->reader->str($this->exifIfd, ExifTag::PHOTOGRAPHER),
                $this->reader->str($this->ifd0, ExifTag::IMAGE_EDITOR),
                $this->reader->str($this->exifIfd, ExifTag::IMAGE_EDITOR),
            ],
            static fn (?string $value): bool => $value !== null,
        );
    }

    /**
     * Returns the photographer name if present.
     *
     * EXIF 3.0 §4.6.6.9.9 recommends keeping the photographer attribution stable
     * and recording Artist alongside it.
     */
    public function photographer(): ?string
    {
        $value = $this->reader->str($this->ifd0, ExifTag::PHOTOGRAPHER);

        if ($value !== null) {
            return $value;
        }

        $value = $this->reader->str($this->exifIfd, ExifTag::PHOTOGRAPHER);

        return $value ?? $this->reader->str(
            $this->ifd0,
            ExifTag::ARTIST
        );
    }

    /**
     * Returns the image editor attribution if present.
     *
     * EXIF 3.0 §4.6.6.9.10 captures the primary editor name and expects Artist to
     * be recorded when this tag is present.
     */
    public function imageEditor(): ?string
    {
        $value = $this->reader->str($this->ifd0, ExifTag::IMAGE_EDITOR);

        return $value ?? $this->reader->str(
            $this->exifIfd,
            ExifTag::IMAGE_EDITOR
        );
    }

    // ========================================================================
    // Image description / title
    // ========================================================================

    /**
     * Returns the optional image title string.
     *
     * EXIF 3.0 §4.6.6.9.8 allows ASCII or UTF-8 text for ImageTitle and
     * treats blank fields as unknown.
     */
    public function imageTitle(): ?string
    {
        $value = $this->reader->str($this->ifd0, ExifTag::IMAGE_TITLE);

        if ($value !== null) {
            return $value;
        }

        $value = $this->reader->str($this->exifIfd, ExifTag::IMAGE_TITLE);

        return $value ?? $this->reader->str(
            $this->ifd0,
            ExifTag::IMAGE_DESCRIPTION
        );
    }

    /**
     * Returns the EXIF image description when available.
     *
     * EXIF 3.0 §4.6.5.4.1 (ImageDescription) defines a free-form ASCII or UTF-8
     * description of the image content with the NUL terminator included in the stored count.
     */
    public function imageDescription(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::IMAGE_DESCRIPTION);
    }

    /**
     * Returns the image unique identifier if present.
     *
     * EXIF 3.0 §4.6.6.9.1 records a 128-bit UUID in
     * hexadecimal ASCII with a fixed count of 33 (including the terminator).
     * Version 4 UUIDs are recommended and the value should remain immutable.
     *
     * @return string|null
     */
    public function imageUniqueId(): ?string
    {
        $value = $this->reader->str($this->exifIfd, ExifTag::IMAGE_UNIQUE_ID);

        // EXIF 3.0 §4.6.6.9.1: ImageUniqueID is a 128-bit UUID encoded as
        // 32 hexadecimal ASCII characters. Reject non-conformant values.
        if (($value === null) || (preg_match('/\A[0-9a-fA-F]{32}\z/', $value) !== 1)) {
            return null;
        }

        return $value;
    }

    // ========================================================================
    // User comment
    // ========================================================================

    /**
     * Returns the user comment string after decoding the EXIF prefix.
     *
     * EXIF 3.0 §4.6.6.4.2 defines the multicode-compatible prefix (see §4.6.4) that annotates
     * the UserComment character code.
     */
    public function userComment(): ?string
    {
        $raw = $this->rawUserComment();

        return $raw !== null ? $this->decodeUserComment($raw) : null;
    }

    /**
     * Returns the encoding declared in the EXIF user comment prefix.
     *
     * EXIF 3.0 §4.6.4 requires UNDEFINED text fields to include an 8-byte
     * character code area. Payloads shorter than 8 bytes are non-conformant.
     */
    public function userCommentEncoding(): ?string
    {
        $raw = $this->rawUserComment();
        if ($raw === null || strlen($raw) < 8) {
            return null;
        }

        $prefix            = substr($raw, 0, 8);
        $canonicalEncoding = $this->canonicalUserCommentMarker($prefix);

        if ($canonicalEncoding === '') {
            return null;
        }

        $content    = substr($raw, 8);
        $hasContent = trim($content, "\0 ") !== '';

        return $hasContent ? $canonicalEncoding : null;
    }

    /**
     * Provides the declared user comment encoding falling back to content inference
     * when the 8-byte prefix is present but denotes UNDEFINED encoding.
     *
     * EXIF 3.0 §4.6.4 requires the 8-byte character code area to be present.
     */
    public function userCommentEncodingBestEffort(): ?string
    {
        $encoding = $this->userCommentEncoding();
        if ($encoding !== null) {
            return $encoding;
        }

        $raw = $this->rawUserComment();
        if ($raw === null || strlen($raw) < 8) {
            return null;
        }

        $prefix            = substr($raw, 0, 8);
        $canonicalEncoding = $this->canonicalUserCommentMarker($prefix);

        if ($canonicalEncoding === '') {
            return null;
        }

        $content = substr($raw, 8);

        return $this->inferUserCommentEncoding($content);
    }

    // ========================================================================
    // Components configuration
    // ========================================================================

    /**
     * Returns the components configuration array when present.
     *
     * EXIF 3.0 §4.6.6.3.3 describes the four-byte component order for compressed image data.
     *
     * @return list<int>|null
     */
    public function componentsConfiguration(): ?array
    {
        $value = $this->reader->componentsInput($this->exifIfd, ExifTag::COMPONENTS_CONFIGURATION);

        return $this->converters->componentsConfiguration($value);
    }

    /**
     * Returns the component configuration labels in human readable form.
     *
     * EXIF 3.0 §4.6.6.3.3 documents the channel identifiers for compressed data streams.
     *
     * @return list<string>|null
     */
    public function componentsConfigurationLabels(): ?array
    {
        $value = $this->reader->componentsInput($this->exifIfd, ExifTag::COMPONENTS_CONFIGURATION);

        return $this->converters->componentsConfigurationLabels($value);
    }

    /**
     * Returns the component configuration as a formatted string.
     */
    public function componentsConfigurationDescription(): ?string
    {
        $value = $this->reader->componentsInput($this->exifIfd, ExifTag::COMPONENTS_CONFIGURATION);

        return $this->converters->componentsConfigurationDescription($value);
    }

    // ========================================================================
    // EXIF version / FlashPix version
    // ========================================================================

    /**
     * Returns the normalised EXIF version string when present.
     *
     * EXIF 3.0 §4.6.6.1.1 (ExifVersion) treats a missing tag as non-conformance.
     */
    public function exifVersion(): ?string
    {
        $rawVersion = $this->reader->rawString($this->exifIfd, ExifTag::EXIF_VERSION);

        return $this->converters->toExifVersion($rawVersion);
    }

    /**
     * Returns the normalised FlashPix version string when present.
     *
     * EXIF 3.0 §4.6.6.1.2 (FlashpixVersion) limits this field to four ASCII digits.
     */
    public function flashpixVersion(): ?string
    {
        $value = $this->reader->rawString($this->exifIfd, ExifTag::FLASHPIX_VERSION);

        if ($value === null) {
            return '1.00';
        }

        return $this->converters->toExifVersion($value);
    }

    /**
     * Returns the gamma correction value when provided.
     *
     * EXIF 3.0 §4.6.6.2.2 (Gamma)
     */
    public function gamma(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::GAMMA);
    }

    // ========================================================================
    // Private helpers
    // ========================================================================

    /**
     * Retrieves the raw user comment value from primary and fallback directories.
     */
    private function rawUserComment(): ?string
    {
        $raw = $this->reader->rawString($this->exifIfd, ExifTag::USER_COMMENT);
        if ($raw !== null) {
            return $raw;
        }

        foreach ($this->fallbackIfds->resolve(includeIfd0: true) as $ifd) {
            $candidate = $this->reader->rawString($ifd, ExifTag::USER_COMMENT);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Decodes EXIF user comment strings with encoding prefixes.
     *
     * EXIF 3.0 §4.6.4 requires UNDEFINED text fields to include an 8-byte
     * character code area. Payloads shorter than 8 bytes are non-conformant
     * and are rejected. An unrecognised prefix is also rejected.
     */
    private function decodeUserComment(string $raw): ?string
    {
        if (strlen($raw) < 8) {
            return null;
        }

        $prefix            = substr($raw, 0, 8);
        $canonicalEncoding = $this->canonicalUserCommentMarker($prefix);

        if ($canonicalEncoding === '') {
            return null;
        }

        $content   = substr($raw, 8);
        $sanitized = trim($content, "\0 ");

        if ($sanitized === '') {
            return null;
        }

        return match ($canonicalEncoding) {
            'UNICODE' => $this->decodeUnicodeUserComment($content),
            'JIS'     => $this->decodeJisComment($sanitized),
            default   => $sanitized,
        };
    }

    /**
     * Normalises known EXIF user comment markers to their canonical identifiers.
     */
    private function canonicalUserCommentMarker(string $prefix): string
    {
        return UndefinedTextMarker::canonicalMarkerFromPrefix($prefix);
    }

    /**
     * Decodes UNICODE-marker user comments using EXIF 3.0 UTF-8 semantics.
     *
     * Compatibility policy:
     * - EXIF 3.0 `UNICODE\0`: decode as UTF-8.
     * - Legacy fallback: when UTF-8 validation fails, accept BOM-tagged UTF-16
     *   payloads for older EXIF 2.x ecosystem files.
     */
    private function decodeUnicodeUserComment(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        if (preg_match('//u', $content) === 1) {
            $trimmed = trim($content, "\0 ");

            return $trimmed === '' ? null : $trimmed;
        }

        return $this->reader->decodeLegacyUnicodeFromBom($content);
    }

    /**
     * Decodes a JIS-marker user comment using ISO-2022-JP/JIS strategy.
     */
    private function decodeJisComment(string $content): ?string
    {
        return JisTextDecoder::decode($content);
    }

    /**
     * Infers the most likely user comment encoding based on the raw payload.
     */
    private function inferUserCommentEncoding(string $content): ?string
    {
        $trimmed = trim($content, "\0 ");
        if ($trimmed === '') {
            return null;
        }

        if ($this->looksLikeUtf16($content)) {
            return 'UNICODE';
        }

        if ($this->looksPrintableAscii($trimmed)) {
            return 'ASCII';
        }

        return 'UNDEFINED';
    }

    /**
     * Checks whether the payload is limited to printable ASCII characters.
     */
    private function looksPrintableAscii(string $content): bool
    {
        $length = strlen($content);
        for ($i = 0; $i < $length; ++$i) {
            $byte = ord($content[$i]);
            if ($byte < 0x20 && !in_array($byte, [0x09, 0x0A, 0x0D], true)) {
                return false;
            }

            if ($byte > 0x7E) {
                return false;
            }
        }

        return true;
    }

    /**
     * Heuristically determines whether the payload resembles UTF-16 text.
     */
    private function looksLikeUtf16(string $content): bool
    {
        $length = strlen($content);
        if ($length < 2) {
            return false;
        }

        $bom = substr($content, 0, 2);
        if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") {
            return true;
        }

        $nullCount = substr_count($content, "\x00");
        if ($nullCount < 2) {
            return false;
        }

        $sampleLength = min($length, 32);
        $sample       = substr($content, 0, $sampleLength);

        $nullsOnEven = 0;
        $nullsOnOdd  = 0;

        $sampleSize = strlen($sample);
        for ($i = 0; $i < $sampleSize; ++$i) {
            if ($sample[$i] === "\x00") {
                if (($i % 2) === 0) {
                    ++$nullsOnEven;
                } else {
                    ++$nullsOnOdd;
                }
            }
        }

        if ($nullsOnEven === 0 && $nullsOnOdd === 0) {
            return false;
        }

        if ($nullsOnEven === 0 || $nullsOnOdd === 0) {
            return true;
        }

        if ($nullCount <= 2) {
            return false;
        }

        return $nullCount >= (int) ($length / 4);
    }

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
                Compression::JPEG,
                Compression::JPEG_NEW_STYLE,
                Compression::LOSSY_JPEG,
                Compression::JPEG_2000,
            ],
            true
        );
    }

    /**
     * Validates a YCbCrSubSampling pair against the EXIF 3.0 §4.6.5.1.12 allowed values.
     *
     * Only [2,1] (YCbCr4:2:2) and [2,2] (YCbCr4:2:0) are defined by the spec.
     *
     * @return array{0:int,1:int}|null
     */
    private function validateYcbcrPair(int $horiz, int $vert): ?array
    {
        if ($horiz === 2 && ($vert === 1 || $vert === 2)) {
            return [$horiz, $vert];
        }

        return null;
    }

    /**
     * Applies EXIF 3.0 §4.6.5.3.4 defaults for missing YCbCrCoefficients.
     *
     * Annex D recommends the ITU-R BT.601 coefficients when no matrix is
     * specified: [0.299, 0.587, 0.114].
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function defaultYCbCrCoefficients(): ?array
    {
        if ($this->photometric() !== Photometric::YCBCR) {
            return null;
        }

        return [0.299, 0.587, 0.114];
    }

    /**
     * Applies EXIF 3.0 §4.6.5.3.5 defaults when ReferenceBlackWhite is absent.
     *
     * Defaults are only valid when the colour space is explicitly defined and
     * the photometric interpretation is RGB or YCbCr.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     */
    private function defaultReferenceBlackWhite(): ?array
    {
        $photometric = $this->photometric();
        $colorSpace  = $this->colorSpace();

        if ((!$photometric instanceof Photometric) || (!$colorSpace instanceof ColorSpace) || ($colorSpace === ColorSpace::UNCALIBRATED)) {
            return null;
        }

        return match ($photometric) {
            Photometric::RGB   => [0.0, 255.0, 0.0, 255.0, 0.0, 255.0],
            Photometric::YCBCR => [0.0, 255.0, 128.0, 128.0, 128.0, 128.0],
            default            => null,
        };
    }

    /**
     * Normalises a reference black and white array to six components.
     *
     * EXIF 3.0 §4.6.5.3.5 requires six rational values representing the
     * black and white points for each channel.
     *
     * @param list<float> $values
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     */
    private function normaliseReferenceBlackWhite(array $values): ?array
    {
        if (count($values) !== 6) {
            return null;
        }

        return [
            0 => $values[0],
            1 => $values[1],
            2 => $values[2],
            3 => $values[3],
            4 => $values[4],
            5 => $values[5],
        ];
    }
}
