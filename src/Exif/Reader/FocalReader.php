<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Exif\ExifConst;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Value\CfaPattern;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\FileSource;

use function array_slice;
use function count;
use function is_float;
use function is_int;
use function is_string;
use function ord;

/**
 * Reads focal length, focal plane, CFA pattern, file source and
 * interoperability metadata from EXIF IFDs.
 *
 * EXIF 3.0 §4.6.6.7 and §4.6.8.1 define the tags decoded by this reader.
 */
final readonly class FocalReader
{
    /**
     * @param IfdValueReader $reader     Value reader for IFD tag extraction.
     * @param Ifd|null       $exifIfd    Sub IFD containing EXIF-specific tags.
     * @param Ifd            $ifd0       Root IFD of the TIFF structure.
     * @param Ifd|null       $interopIfd Sub IFD containing interoperability tags.
     */
    public function __construct(
        private IfdValueReader $reader,
        private ?Ifd $exifIfd,
        private Ifd $ifd0,
        private ?Ifd $interopIfd,
    ) {
    }

    // ========================================================================
    // Focal length / focal plane
    // ========================================================================
    /**
     * Returns the focal length in millimetres if available.
     *
     * EXIF 3.0 §4.6.6.7.23 (FocalLength)
     */
    public function focalLengthMm(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::FOCAL_LENGTH);
    }

    /**
     * Returns the focal length in 35mm equivalent if available.
     */
    public function focalLength35Mm(): ?int
    {
        return $this->reader->int($this->exifIfd, ExifTag::FOCAL_LENGTH_IN_35MM_FILM);
    }

    /**
     * Returns the focal plane X resolution.
     *
     * EXIF 3.0 §4.6.6.7.26 defines this as the number of pixels in the image
     * width per {@see ExifTag::FOCAL_PLANE_RESOLUTION_UNIT} on the camera
     * focal plane. The value refers to the primary image rather than the
     * physical sensor grid.
     */
    public function focalPlaneXResolution(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::FOCAL_PLANE_X_RESOLUTION);
    }

    /**
     * Returns the focal plane Y resolution.
     *
     * EXIF 3.0 §4.6.6.7.27 records the number of pixels in the image height per
     * {@see ExifTag::FOCAL_PLANE_RESOLUTION_UNIT} on the camera focal plane,
     * aligned with the primary image output.
     */
    public function focalPlaneYResolution(): ?float
    {
        return $this->reader->rational($this->exifIfd, ExifTag::FOCAL_PLANE_Y_RESOLUTION);
    }

    /**
     * Returns the focal plane resolution unit.
     *
     * EXIF 3.0 §4.6.6.7.28 reuses the {@see ResolutionUnit} scale for focal
     * plane resolution values.
     */
    public function focalPlaneResolutionUnit(): int
    {
        // EXIF 3.0 §4.6.6.7.28: default is 2 (inches).
        return $this->reader->int($this->exifIfd, ExifTag::FOCAL_PLANE_RESOLUTION_UNIT) ?? 2;
    }

    // ========================================================================
    // CFA pattern
    // ========================================================================

    /**
     * Returns the CFA pattern layout when available.
     *
     * EXIF 3.0 §4.6.6.7.34 defines the payload as two SHORT repeat units followed by m×n
     * component identifiers describing the colour filter array.
     */
    public function cfaPattern(): ?CfaPattern
    {
        $components                = $this->reader->numericList($this->exifIfd, ExifTag::CFA_PATTERN);

        if ($components === null || count($components) < 3) {
            return null;
        }

        $horizontalRepeatPixelUnit = $components[0];
        $verticalRepeatPixelUnit   = $components[1];

        return CfaPattern::fromComponents($horizontalRepeatPixelUnit, $verticalRepeatPixelUnit, array_slice($components, 2));
    }

    /**
     * Returns the CFA pattern as colour enums when possible.
     *
     * @return list<CfaPatternColor>|null
     */
    public function cfaPatternColors(): ?array
    {
        return $this->cfaPattern()?->colors;
    }

    // ========================================================================
    // File source / interoperability
    // ========================================================================

    /**
     * Returns the EXIF file source enum when provided.
     *
     * EXIF 3.0 §4.6.6.7.32 (FileSource)
     */
    public function fileSource(): ?FileSource
    {
        foreach ([$this->exifIfd, $this->ifd0] as $ifd) {
            if (!$ifd instanceof Ifd) {
                continue;
            }

            $value = $this->reader->value($ifd, ExifTag::FILE_SOURCE);

            if ($value instanceof ExifNumericList) {
                $first = $value->values[0] ?? null;

                if (is_int($first) || is_float($first)) {
                    return FileSource::fromExifValue((int) $first);
                }

                continue;
            }

            if (is_int($value) || is_float($value)) {
                return FileSource::fromExifValue((int) $value);
            }

            if (is_string($value) && ($value !== '')) {
                return FileSource::fromExifValue(ord($value[0]));
            }
        }

        // EXIF 3.0 §4.6.6.7.32: default is 3 (DSC).
        return FileSource::fromExifValue(3);
    }

    /**
     * Returns the interoperability index string when recorded.
     *
     * EXIF 3.0 §4.6.8.1.1: ASCII[4] including terminating NUL.
     */
    public function interopIndex(): ?string
    {
        $entry = $this->interopIfd?->get(ExifTag::INTEROPERABILITY_INDEX);

        if (!$entry instanceof IfdEntry) {
            return null;
        }

        if ($entry->type !== ExifConst::TYPE_ASCII || $entry->count !== 4) {
            return null;
        }

        return $this->reader->str($this->interopIfd, ExifTag::INTEROPERABILITY_INDEX);
    }
}
