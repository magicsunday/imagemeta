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
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Lens;

use function abs;
use function array_any;
use function implode;
use function round;
use function rtrim;
use function sprintf;
use function strcasecmp;
use function strlen;
use function strncmp;
use function strtolower;
use function trim;

use const DATE_ATOM;

/**
 * Presentation helpers that format value objects for UI consumption.
 */
final readonly class ExifConvenience
{
    /**
     * Formats the capture timestamp as a string using the supplied format.
     */
    public function captureDateTimeString(Capture $capture, string $format = DATE_ATOM): ?string
    {
        $dateTime = $capture->dateTime;

        if (!$dateTime instanceof DateTimeImmutable) {
            return null;
        }

        return $dateTime->format($format);
    }

    /**
     * Builds a compact camera and lens description string.
     */
    public function cameraDescription(Camera $camera, ?Lens $lens = null): ?string
    {
        $make  = $this->normalize($camera->make);
        $model = $this->normalize($camera->model);

        $cameraLabel = null;

        if (($make !== null) && ($model !== null)) {
            $cameraLabel = $this->startsWithCaseInsensitive($model, $make) ? $model : $make . ' ' . $model;
        } elseif ($make !== null || $model !== null) {
            $cameraLabel = $make ?? $model;
        }

        $lensLabel = $this->normalize($lens?->lensModel);

        $parts = [];

        if ($cameraLabel !== null) {
            $parts[] = $cameraLabel;
        }

        if ($lensLabel !== null) {
            $parts[] = $lensLabel;
        }

        if ($parts === []) {
            return null;
        }

        return implode(' · ', $parts);
    }

    /**
     * Formats the exposure metadata into a readable summary string.
     */
    public function exposureSummary(Exposure $exposure, ?Lens $lens = null, ?Derived $derived = null): ?string
    {
        $parts = [];

        $seconds = $exposure->settings?->exposureTimeSec;

        if ($seconds !== null) {
            $parts[] = $this->formatExposureTime($seconds);
        }

        $fNumber = $exposure->settings?->fNumber;

        if ($fNumber !== null) {
            $parts[] = $this->formatFNumber($fNumber);
        }

        $iso = $exposure->settings?->iso;

        if ($iso !== null) {
            $parts[] = $this->formatIso($iso);
        }

        $focalLength = $lens?->focalLengthMm;

        if ($focalLength !== null) {
            $parts[] = $this->formatFocalLength($focalLength);
        }

        $equivalent = $derived?->focalLength35Mm;

        if (($equivalent !== null) && !$this->containsEquivalent($parts, $equivalent)) {
            $parts[] = sprintf('%d mm eq', $equivalent);
        }

        if ($parts === []) {
            return null;
        }

        return implode(' · ', $parts);
    }

    /**
     * Formats the image dimensions into a `WIDTH×HEIGHT px` string.
     */
    public function imageDimensions(Image $image): ?string
    {
        $width  = $image->width;
        $height = $image->height;

        if ($width === null || $height === null) {
            return null;
        }

        return sprintf('%d×%d px', $width, $height);
    }

    /**
     * Formats the primary GPS coordinates and optionally altitude.
     */
    public function gpsString(Gps $gps, int $precision = 6, bool $includeAltitude = false): ?string
    {
        $latitude  = $gps->position?->latitude;
        $longitude = $gps->position?->longitude;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        $latitudeRef  = $this->resolveLatitudeRef($gps, $latitude);
        $longitudeRef = $this->resolveLongitudeRef($gps, $longitude);

        $latValue = $this->formatCoordinate(abs($latitude), $precision) . '° ' . $latitudeRef;
        $lonValue = $this->formatCoordinate(abs($longitude), $precision) . '° ' . $longitudeRef;

        $result = $latValue . ', ' . $lonValue;

        if ($includeAltitude) {
            $altitude = $gps->position->altitude;

            if ($altitude !== null) {
                $result .= ' (' . $this->formatNumber($altitude, 1) . ' m)';
            }
        }

        return $result;
    }

    /**
     * Provides a flattened array representation of frequently used values.
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
     * }
     */
    public function toArray(
        Camera $camera,
        Lens $lens,
        Image $image,
        Capture $capture,
        Exposure $exposure,
        Gps $gps,
    ): array {
        $capturedAt = $capture->dateTime;

        return [
            'make'        => $this->normalize($camera->make),
            'model'       => $this->normalize($camera->model),
            'lens'        => $this->normalize($lens->lensModel),
            'orientation' => $image->orientation?->value,
            'captured_at' => $capturedAt?->format(DATE_ATOM),
            'exposure_s'  => $exposure->settings?->exposureTimeSec,
            'fnumber'     => $exposure->settings?->fNumber,
            'focal_mm'    => $lens->focalLengthMm,
            'iso'         => $exposure->settings?->iso,
            'gps_lat'     => $gps->position?->latitudeSigned,
            'gps_lon'     => $gps->position?->longitudeSigned,
            'gps_alt'     => $gps->position?->altitude,
        ];
    }

    /**
     * Checks if the parts array contains an equivalent focal length string.
     *
     * @param list<string|int> $parts      Array of lens descriptor parts.
     * @param int              $equivalent Equivalent focal length value to search for.
     *
     * @return bool True if the equivalent focal length is found.
     */
    private function containsEquivalent(array $parts, int $equivalent): bool
    {
        $needle = sprintf('%d mm eq', $equivalent);

        return array_any($parts, fn ($part): bool => strcasecmp((string) $part, $needle) === 0);
    }

    /**
     * Formats an exposure time value as a display string.
     *
     * @param float $seconds Exposure time in seconds.
     *
     * @return string Formatted exposure time.
     */
    private function formatExposureTime(float $seconds): string
    {
        if ($seconds <= 0.0) {
            return $this->formatNumber($seconds, 3) . ' s';
        }

        if ($seconds >= 1.0) {
            $formatted = $this->formatNumber($seconds, $seconds < 10.0 ? 2 : 1);

            return $formatted . ' s';
        }

        $denominator = (int) round(1.0 / $seconds);

        if ($denominator > 0) {
            $approximation = 1.0 / $denominator;

            if (abs($approximation - $seconds) < 0.0001) {
                return '1/' . $denominator . ' s';
            }
        }

        return $this->formatNumber($seconds, 3) . ' s';
    }

    /**
     * Formats an f-number as a display string.
     *
     * @param float $fNumber F-number value.
     *
     * @return string Formatted f-number.
     */
    private function formatFNumber(float $fNumber): string
    {
        return 'f/' . $this->formatNumber($fNumber, 1);
    }

    /**
     * Formats an ISO speed value as a display string.
     *
     * @param int $iso ISO value.
     *
     * @return string Formatted ISO string.
     */
    private function formatIso(int $iso): string
    {
        return 'ISO ' . $iso;
    }

    /**
     * Formats a focal length value as a display string.
     *
     * @param float $focalLength Focal length in millimetres.
     *
     * @return string Formatted focal length.
     */
    private function formatFocalLength(float $focalLength): string
    {
        return $this->formatNumber($focalLength, 1) . ' mm';
    }

    /**
     * Formats a GPS coordinate with the specified precision.
     *
     * @param float $value     Coordinate value.
     * @param int   $precision Number of decimal places.
     *
     * @return string Formatted coordinate string.
     */
    private function formatCoordinate(float $value, int $precision): string
    {
        if ($precision < 0) {
            $precision = 0;
        }

        $format = '%.' . $precision . 'f';

        return sprintf($format, $value);
    }

    /**
     * Formats a floating point value with a trimmed decimal fraction.
     *
     * @param float $value     Value to format.
     * @param int   $precision Decimal precision.
     *
     * @return string Trimmed formatted number.
     */
    private function formatNumber(float $value, int $precision): string
    {
        $precision = max(0, $precision);
        $format    = '%.' . $precision . 'f';

        $formatted = sprintf($format, $value);

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * Normalizes a string value by trimming whitespace and collapsing empties to null.
     *
     * @param string|null $value Raw string value.
     *
     * @return string|null Normalized string or null when empty.
     */
    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Determines whether a string starts with a prefix, case-insensitive.
     *
     * @param string $haystack Full string to inspect.
     * @param string $needle   Prefix to match.
     *
     * @return bool True when the prefix matches.
     */
    private function startsWithCaseInsensitive(string $haystack, string $needle): bool
    {
        $needleLength = strlen($needle);

        if ($needleLength === 0) {
            return true;
        }

        return strncmp(strtolower($haystack), strtolower($needle), $needleLength) === 0;
    }

    /**
     * Resolves the latitude reference for a GPS coordinate.
     *
     * @param Gps   $gps      GPS data container.
     * @param float $latitude Latitude value used to infer ref when missing.
     *
     * @return string Latitude reference ("N" or "S").
     */
    private function resolveLatitudeRef(Gps $gps, float $latitude): string
    {
        $ref = $gps->position?->latitudeRef;

        if ($ref instanceof GpsLatLonRef) {
            return $ref->value;
        }

        return $latitude >= 0.0 ? 'N' : 'S';
    }

    /**
     * Resolves the longitude reference for a GPS coordinate.
     *
     * @param Gps   $gps       GPS data container.
     * @param float $longitude Longitude value used to infer ref when missing.
     *
     * @return string Longitude reference ("E" or "W").
     */
    private function resolveLongitudeRef(Gps $gps, float $longitude): string
    {
        $ref = $gps->position?->longitudeRef;

        if ($ref instanceof GpsLatLonRef) {
            return $ref->value;
        }

        return $longitude >= 0.0 ? 'E' : 'W';
    }
}
