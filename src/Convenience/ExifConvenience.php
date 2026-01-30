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
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Lens;

use function abs;
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
     * Returns the capture timestamp value from the capture metadata.
     */
    public function captureDateTime(Capture $capture): ?DateTimeImmutable
    {
        return $capture->dateTime;
    }

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
        $make  = $this->normalise($camera->make);
        $model = $this->normalise($camera->model);

        $cameraLabel = null;

        if ($make !== null && $model !== null) {
            $cameraLabel = $this->startsWithCaseInsensitive($model, $make) ? $model : $make . ' ' . $model;
        } elseif ($make !== null || $model !== null) {
            $cameraLabel = $make ?? $model;
        }

        $lensLabel = $this->normalise($lens?->lensModel);

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

        $seconds = $exposure->exposureTimeSec;
        if ($seconds !== null) {
            $parts[] = $this->formatExposureTime($seconds);
        }

        $fNumber = $exposure->fNumber;
        if ($fNumber !== null) {
            $parts[] = $this->formatFNumber($fNumber);
        }

        $iso = $exposure->iso;
        if ($iso !== null) {
            $parts[] = $this->formatIso($iso);
        }

        $focalLength = $lens?->focalLengthMm;
        if ($focalLength !== null) {
            $parts[] = $this->formatFocalLength($focalLength);
        }

        $equivalent = $derived?->equivalent35mm;
        if ($equivalent !== null && !$this->containsEquivalent($parts, $equivalent)) {
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
        $latitude  = $gps->latitude;
        $longitude = $gps->longitude;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        $latitudeRef  = $this->resolveLatitudeRef($gps, $latitude);
        $longitudeRef = $this->resolveLongitudeRef($gps, $longitude);

        $latValue = $this->formatCoordinate(abs($latitude), $precision) . '° ' . $latitudeRef;
        $lonValue = $this->formatCoordinate(abs($longitude), $precision) . '° ' . $longitudeRef;

        $result = $latValue . ', ' . $lonValue;

        if ($includeAltitude) {
            $altitude = $this->resolveAltitude($gps);
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
            'make'        => $this->normalise($camera->make),
            'model'       => $this->normalise($camera->model),
            'lens'        => $this->normalise($lens->lensModel),
            'orientation' => $image->orientation?->value,
            'captured_at' => $capturedAt?->format(DATE_ATOM),
            'exposure_s'  => $exposure->exposureTimeSec,
            'fnumber'     => $exposure->fNumber,
            'focal_mm'    => $lens->focalLengthMm,
            'iso'         => $exposure->iso,
            'gps_lat'     => $gps->latitude,
            'gps_lon'     => $gps->longitude,
            'gps_alt'     => $this->resolveAltitude($gps),
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

    private function formatFNumber(float $fNumber): string
    {
        return 'f/' . $this->formatNumber($fNumber, 1);
    }

    private function formatIso(int $iso): string
    {
        return 'ISO ' . $iso;
    }

    private function formatFocalLength(float $focalLength): string
    {
        return $this->formatNumber($focalLength, 1) . ' mm';
    }

    private function formatCoordinate(float $value, int $precision): string
    {
        if ($precision < 0) {
            $precision = 0;
        }

        $format = '%.' . $precision . 'f';

        return sprintf($format, $value);
    }

    private function formatNumber(float $value, int $precision): string
    {
        $precision = max(0, $precision);
        $format    = '%.' . $precision . 'f';

        $formatted = sprintf($format, $value);

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalised = trim($value);

        return $normalised === '' ? null : $normalised;
    }

    private function startsWithCaseInsensitive(string $haystack, string $needle): bool
    {
        $needleLength = strlen($needle);
        if ($needleLength === 0) {
            return true;
        }

        return strncmp(strtolower($haystack), strtolower($needle), $needleLength) === 0;
    }

    private function resolveLatitudeRef(Gps $gps, float $latitude): string
    {
        $ref = $gps->latitudeRef;

        if ($ref instanceof GpsLatLonRef) {
            return $ref->value;
        }

        return $latitude >= 0.0 ? 'N' : 'S';
    }

    private function resolveLongitudeRef(Gps $gps, float $longitude): string
    {
        $ref = $gps->longitudeRef;

        if ($ref instanceof GpsLatLonRef) {
            return $ref->value;
        }

        return $longitude >= 0.0 ? 'E' : 'W';
    }

    private function resolveAltitude(Gps $gps): ?float
    {
        $altitude = $gps->altitude;
        if ($altitude === null) {
            return null;
        }

        $reference = $gps->altitudeRef;

        if ($reference instanceof GpsAltitudeRef && $reference->isBelow()) {
            return -$altitude;
        }

        return $altitude;
    }
}
