<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Convenience;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use Throwable;

use function is_float;
use function is_int;

/**
 * Helper routines that extract frequently-used EXIF values in a safe manner.
 */
final class ExifConvenience
{
    /**
     * Extracts camera identification details from the EXIF document.
     *
     * @param ExifDocument $doc parsed EXIF metadata
     *
     * @return array{make: ?string, model: ?string, lens: ?string} camera details ready for display
     */
    public static function camera(ExifDocument $doc): array
    {
        return [
            'make'  => $doc->cameraMake(),  // EXIF 3.0 §4.6.4; EXIF 2.32 §4.6.4 (Make)
            'model' => $doc->cameraModel(), // EXIF 3.0 §4.6.4; EXIF 2.32 §4.6.4 (Model)
            'lens'  => $doc->lensModel(),   // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (LensModel)
        ];
    }

    /**
     * Normalises the capture timestamp by combining the EXIF datetime and offset.
     *
     * @param ExifDocument $doc parsed EXIF metadata
     *
     * @return DateTimeImmutable|null the capture timestamp or null when unavailable
     */
    public static function captureDateTime(ExifDocument $doc): ?DateTimeImmutable
    {
        try {
            // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 harmonise DateTimeOriginal with OffsetTime*.
            return $doc->captureDateTime();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Returns the GPS coordinates extracted from the EXIF document.
     *
     * @param ExifDocument $doc parsed EXIF metadata
     *
     * @return array{lat: ?float, lon: ?float, alt: ?float} geographic coordinates
     */
    public static function gps(ExifDocument $doc): array
    {
        // GPSLatitude/GPSLongitude/GPSAltitude (EXIF 3.0 §4.6.8; EXIF 2.32 §4.6.8).
        return $doc->gps(); // ['lat'=>?float,'lon'=>?float,'alt'=>?float]
    }

    /**
     * Converts the exposure time rational to seconds.
     *
     * @param ExifDocument $doc parsed EXIF metadata
     *
     * @return float|null exposure duration in seconds
     */
    public static function exposureTime(ExifDocument $doc): ?float
    {
        $entry = self::find($doc, ExifTag::EXPOSURE_TIME); // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (ExposureTime)

        return $entry instanceof IfdEntry
            ? self::rationalEntryToFloat($entry)
            : null;
    }

    /**
     * Retrieves the aperture (f-number) from the EXIF data.
     *
     * @param ExifDocument $doc parsed EXIF metadata
     *
     * @return float|null aperture value
     */
    public static function fNumber(ExifDocument $doc): ?float
    {
        $entry = self::find($doc, ExifTag::F_NUMBER); // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (FNumber)

        return $entry instanceof IfdEntry
            ? self::rationalEntryToFloat($entry)
            : null;
    }

    /**
     * Retrieves the focal length from the EXIF data in millimetres.
     *
     * @param ExifDocument $doc parsed EXIF metadata
     *
     * @return float|null focal length in millimetres
     */
    public static function focalLength(ExifDocument $doc): ?float
    {
        $entry = self::find($doc, ExifTag::FOCAL_LENGTH); // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (FocalLength)

        return $entry instanceof IfdEntry
            ? self::rationalEntryToFloat($entry)
            : null;
    }

    /**
     * Determines the ISO sensitivity from either the modern or legacy tag.
     *
     * @param ExifDocument $doc parsed EXIF metadata
     *
     * @return int|null ISO value when available
     */
    public static function iso(ExifDocument $doc): ?int
    {
        try {
            $iso = $doc->isoBestEffort();
            if ($iso !== null) {
                return $iso;
            }
        } catch (Throwable) {
            // ignore and fall back to manual resolution
        }

        $iso = self::isoFromSensitivityType($doc); // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (SensitivityType)
        if ($iso !== null) {
            return $iso;
        }

        $iso = self::isoFromEntry($doc->exifIfd?->get(ExifTag::ISO_SPEED)); // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (ISOSpeedRatings)
        if ($iso !== null) {
            return $iso;
        }

        $iso = self::isoFromEntry($doc->exifIfd?->get(ExifTag::STANDARD_OUTPUT_SENSITIVITY)); // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (StandardOutputSensitivity)
        if ($iso !== null) {
            return $iso;
        }

        $iso = self::isoFromEntry($doc->exifIfd?->get(ExifTag::RECOMMENDED_EXPOSURE_INDEX)); // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (RecommendedExposureIndex)
        if ($iso !== null) {
            return $iso;
        }

        $iso = self::isoFromEntry($doc->exifIfd?->get(ExifTag::PHOTOGRAPHIC_SENSITIVITY)); // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (PhotographicSensitivity)
        if ($iso !== null) {
            return $iso;
        }

        return self::isoFromEntry($doc->ifd0->get(ExifTag::PHOTOGRAPHIC_SENSITIVITY)); // Legacy fallback retained for EXIF 2.32 §4.6.3 compatibility
    }

    /**
     * Resolves ISO sensitivity using the EXIF 3.x sensitivity type priority rules.
     *
     * EXIF 3.0 §4.6.3 formalises the SensitivityType-driven priority order retained from EXIF 2.32 §4.6.3.
     */
    private static function isoFromSensitivityType(ExifDocument $doc): ?int
    {
        $type = self::isoFromEntry($doc->exifIfd?->get(ExifTag::SENSITIVITY_TYPE)); // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (SensitivityType)
        if ($type === null) {
            return null;
        }

        foreach (self::sensitivityTagPriority($type) as $tag) {
            $iso = self::isoFromEntry($doc->exifIfd?->get($tag));
            if ($iso !== null) {
                return $iso;
            }
        }

        return null;
    }

    /**
     * Converts various EXIF value representations into an ISO integer.
     */
    private static function isoFromEntry(?IfdEntry $entry): ?int
    {
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $value = $entry->value;

        if ($value instanceof UInt64) {
            $intValue = self::uint64ToInt($value, 'ISO sensitivity');

            if ($intValue === null) {
                return null;
            }

            return $intValue;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;
            if (is_int($first)) {
                return $first;
            }

            if (is_float($first)) {
                return (int) $first;
            }

            $numeric = ValueConverters::rationalToFloat($value);
            if ($numeric !== null) {
                return (int) $numeric;
            }

            return null;
        }

        if ($value instanceof ExifRational || $value instanceof ExifRationalList) {
            $float = ValueConverters::rationalToFloat($value);
            if ($float !== null) {
                return (int) $float;
            }

            return null;
        }

        return null;
    }

    /**
     * Maps sensitivity type enumerations to ISO tag priorities.
     *
     * @return list<int>
     */
    private static function sensitivityTagPriority(int $type): array
    {
        // Mapping derived from the EXIF SensitivityType table (EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3).
        return match ($type) {
            1       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY],
            2       => [ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            3       => [ExifTag::ISO_SPEED],
            4       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY, ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            5       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY, ExifTag::ISO_SPEED],
            6       => [ExifTag::RECOMMENDED_EXPOSURE_INDEX, ExifTag::ISO_SPEED],
            7       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY, ExifTag::RECOMMENDED_EXPOSURE_INDEX, ExifTag::ISO_SPEED],
            default => [],
        };
    }

    /**
     * Provides a flattened associative representation of common EXIF values.
     *
     * @param ExifDocument $doc parsed EXIF metadata
     *
     * @return array{
     *     make:?string,
     *     model:?string,
     *     lens:?string,
     *     orientation:?int,
     *     captured_at:?string,
     *     exposure_s:?float,
     *     fnumber:?float,
     *     focal_mm:?float,
     *     iso:?int,
     *     gps_lat:?float,
     *     gps_lon:?float,
     *     gps_alt:?float
     * } normalised metadata values
     */
    public static function toArray(ExifDocument $doc): array
    {
        $dt  = self::captureDateTime($doc);
        $gps = $doc->gps();

        return [
            'make'  => $doc->cameraMake(),  // EXIF 3.0 §4.6.4; EXIF 2.32 §4.6.4 (Make)
            'model' => $doc->cameraModel(), // EXIF 3.0 §4.6.4; EXIF 2.32 §4.6.4 (Model)
            'lens'  => $doc->lensModel(),   // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (LensModel)
            // Expose the numeric orientation code to preserve the legacy convenience contract.
            'orientation' => $doc->orientation()?->value,
            'captured_at' => $dt?->format(DATE_ATOM),
            'exposure_s'  => self::exposureTime($doc), // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (ExposureTime)
            'fnumber'     => self::fNumber($doc),      // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (FNumber)
            'focal_mm'    => self::focalLength($doc),  // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (FocalLength)
            'iso'         => self::iso($doc),          // EXIF 3.0 §4.6.3; EXIF 2.32 §4.6.3 (ISO values)
            'gps_lat'     => $gps['lat'],              // EXIF 3.0 §4.6.8; EXIF 2.32 §4.6.8 (GPSLatitude)
            'gps_lon'     => $gps['lon'],              // EXIF 3.0 §4.6.8; EXIF 2.32 §4.6.8 (GPSLongitude)
            'gps_alt'     => $gps['alt'],              // EXIF 3.0 §4.6.8; EXIF 2.32 §4.6.8 (GPSAltitude)
        ];
    }

    /**
     * Finds a tag either within the EXIF IFD or as a fallback in IFD0.
     *
     * @param ExifDocument $doc parsed EXIF metadata
     * @param int          $tag tag identifier to search for
     *
     * @return IfdEntry|null matching tag entry when present
     */
    private static function find(ExifDocument $doc, int $tag): ?IfdEntry
    {
        $exifEntry = $doc->exifIfd?->get($tag);
        if ($exifEntry instanceof IfdEntry) {
            return $exifEntry;
        }

        return $doc->ifd0->get($tag);
    }

    /**
     * Converts a rational-oriented EXIF entry into a float value.
     */
    private static function rationalEntryToFloat(IfdEntry $entry): ?float
    {
        $value = $entry->value;

        if ($value instanceof UInt64) {
            $intValue = self::uint64ToInt($value, 'EXIF rational entry');

            if ($intValue === null) {
                return null;
            }

            $value = $intValue;
        }

        return ValueConverters::rationalToFloat($value);
    }

    /**
     * Converts an unsigned 64-bit value into an int when it fits the signed range.
     */
    private static function uint64ToInt(UInt64 $value, string $context): ?int
    {
        if (!$value->fitsSignedInt()) {
            return null;
        }

        return $value->toInt($context);
    }
}
