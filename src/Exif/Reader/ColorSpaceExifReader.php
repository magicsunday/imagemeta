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
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;

use function array_fill;
use function count;

/**
 * Reads colour space and pixel interpretation metadata from EXIF IFDs.
 *
 * Covers colour space, photometric interpretation, planar configuration,
 * samples/bits per sample, YCbCr properties, white point, chromaticities,
 * components configuration, and gamma.
 */
final readonly class ColorSpaceExifReader
{
    /**
     * @param IfdValueReader  $reader      Value reader for IFD tag extraction.
     * @param ValueConverters $converters  Value converter facade for EXIF type normalization.
     * @param Ifd             $ifd0        Root IFD of the TIFF structure.
     * @param Ifd|null        $exifIfd     Sub IFD containing EXIF-specific tags.
     * @param string          $exifProfile Derived EXIF capability profile identifier.
     */
    public function __construct(
        private IfdValueReader $reader,
        private ValueConverters $converters,
        private Ifd $ifd0,
        private ?Ifd $exifIfd,
        private string $exifProfile,
    ) {
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
    // Gamma
    // ========================================================================

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
