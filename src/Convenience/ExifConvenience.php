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
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use Throwable;

use function is_string;
use function strlen;

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
            'make'  => $doc->cameraMake(),
            'model' => $doc->cameraModel(),
            'lens'  => $doc->lensModel(),
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
        $rawDateTime = $doc->dateTimeOriginalRaw(); // "YYYY:MM:DD HH:MM:SS"

        if (!is_string($rawDateTime) || $rawDateTime === '') {
            return null;
        }

        // Ensure that the timestamp contains both a date and a time component.
        if (strlen($rawDateTime) < 19) {
            return null;
        }

        try {
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
        $e = self::find($doc, ExifTag::EXPOSURE_TIME); // ExposureTime (rational)

        return self::rationalToFloat($e?->value);
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
        $e = self::find($doc, ExifTag::F_NUMBER); // FNumber (rational)

        return self::rationalToFloat($e?->value);
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
        $e = self::find($doc, ExifTag::FOCAL_LENGTH); // FocalLength (rational)

        return self::rationalToFloat($e?->value);
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
        // ISO can be ExifIFD tag ExifTag::PHOTOGRAPHIC_SENSITIVITY or the newer ExifTag::ISO_SPEED
        $entry = self::find($doc, ExifTag::ISO_SPEED) ?? self::find($doc, ExifTag::PHOTOGRAPHIC_SENSITIVITY);

        if ($entry === null) {
            return null;
        }

        $value = $entry->value;

        if (is_array($value)) {
            // Some cameras store ISO as a list, keep the first element.
            $value = $value[0] ?? null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Provides a flattened associative representation of common EXIF values.
     *
     * @param ExifDocument $doc parsed EXIF metadata
     *
     * @return array<string, mixed> normalised metadata values
     */
    public static function toArray(ExifDocument $doc): array
    {
        $dt  = self::captureDateTime($doc);
        $gps = $doc->gps();

        return [
            'make'        => $doc->cameraMake(),
            'model'       => $doc->cameraModel(),
            'lens'        => $doc->lensModel(),
            'orientation' => $doc->orientation(),
            'captured_at' => $dt?->format(DATE_ATOM),
            'exposure_s'  => self::exposureTime($doc),
            'fnumber'     => self::fNumber($doc),
            'focal_mm'    => self::focalLength($doc),
            'iso'         => self::iso($doc),
            'gps_lat'     => $gps['lat'],
            'gps_lon'     => $gps['lon'],
            'gps_alt'     => $gps['alt'],
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
        return $doc->exifIfd?->get($tag) ?? $doc->ifd0->get($tag) ?? null;
    }

    /**
     * Converts EXIF rational values (or lists of rationals) into a float.
     *
     * @param mixed $v scalar, rational pair or list of rational pairs from an EXIF tag
     *
     * @return float|null normalised scalar value
     */
    private static function rationalToFloat(mixed $v): ?float
    {
        if (is_array($v)) {
            // [num, den] or list of rationals
            if (count($v) === 2 && is_numeric($v[0] ?? null) && is_numeric($v[1] ?? null) && (int) $v[1] !== 0) {
                return (float) $v[0] / (float) $v[1];
            }
            if (isset($v[0]) && is_array($v[0]) && count($v[0]) === 2) {
                $n = $v[0][0] ?? 0;
                $d = $v[0][1] ?? 1;

                return (int) $d !== 0 ? (float) $n / (float) $d : null;
            }
        }

        return is_int($v) || is_float($v) ? (float) $v : null;
    }
}
