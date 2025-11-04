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
final class ExifConvenience
{
    /**
     * Returns the capture timestamp value from the capture metadata.
     */
    public static function captureDateTime(Capture $capture): ?DateTimeImmutable
    {
        return $capture->dateTime;
    }

    /**
     * Formats the capture timestamp as a string using the supplied format.
     */
    public static function captureDateTimeString(Capture $capture, string $format = DATE_ATOM): ?string
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
    public static function cameraDescription(Camera $camera, ?Lens $lens = null): ?string
    {
        $make  = self::normalise($camera->make);
        $model = self::normalise($camera->model);

        $cameraLabel = null;

        if ($make !== null && $model !== null) {
            $cameraLabel = self::startsWithCaseInsensitive($model, $make) ? $model : $make . ' ' . $model;
        } elseif ($make !== null || $model !== null) {
            $cameraLabel = $make ?? $model;
        }

        $lensLabel = self::normalise($lens?->lensModel);

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
    public static function exposureSummary(Exposure $exposure, ?Lens $lens = null, ?Derived $derived = null): ?string
    {
        $parts = [];

        $seconds = $exposure->exposureTimeSec;
        if ($seconds !== null) {
            $parts[] = self::formatExposureTime($seconds);
        }

        $fNumber = $exposure->fNumber;
        if ($fNumber !== null) {
            $parts[] = self::formatFNumber($fNumber);
        }

        $iso = $exposure->iso;
        if ($iso !== null) {
            $parts[] = self::formatIso($iso);
        }

        $focalLength = $lens?->focalLengthMm;
        if ($focalLength !== null) {
            $parts[] = self::formatFocalLength($focalLength);
        }

        $equivalent = $derived?->equivalent35mm;
        if ($equivalent !== null && !self::containsEquivalent($parts, $equivalent)) {
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
    public static function imageDimensions(Image $image): ?string
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
    public static function gpsString(Gps $gps, int $precision = 6, bool $includeAltitude = false): ?string
    {
        $latitude  = $gps->latitude;
        $longitude = $gps->longitude;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        $latitudeRef  = self::resolveLatitudeRef($gps, $latitude);
        $longitudeRef = self::resolveLongitudeRef($gps, $longitude);

        $latValue = self::formatCoordinate(abs($latitude), $precision) . '° ' . $latitudeRef;
        $lonValue = self::formatCoordinate(abs($longitude), $precision) . '° ' . $longitudeRef;

        $result = $latValue . ', ' . $lonValue;

        if ($includeAltitude) {
            $altitude = self::resolveAltitude($gps);
            if ($altitude !== null) {
                $result .= ' (' . self::formatNumber($altitude, 1) . ' m)';
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
    public static function toArray(
        Camera $camera,
        Lens $lens,
        Image $image,
        Capture $capture,
        Exposure $exposure,
        Gps $gps,
    ): array {
        $capturedAt = $capture->dateTime;

        return [
            'make'        => self::normalise($camera->make),
            'model'       => self::normalise($camera->model),
            'lens'        => self::normalise($lens->lensModel),
            'orientation' => $image->orientation?->value,
            'captured_at' => $capturedAt?->format(DATE_ATOM),
            'exposure_s'  => $exposure->exposureTimeSec,
            'fnumber'     => $exposure->fNumber,
            'focal_mm'    => $lens->focalLengthMm,
            'iso'         => $exposure->iso,
            'gps_lat'     => $gps->latitude,
            'gps_lon'     => $gps->longitude,
            'gps_alt'     => self::resolveAltitude($gps),
        ];
    }

    /**
     * Checks if the parts array contains an equivalent focal length string.
     *
     * @param list<string> $parts      Array of lens descriptor parts.
     * @param int          $equivalent Equivalent focal length value to search for.
     *
     * @return bool True if the equivalent focal length is found.
     */
    private static function containsEquivalent(array $parts, int $equivalent): bool
    {
        $needle = sprintf('%d mm eq', $equivalent);

        return array_any($parts, fn ($part): bool => strcasecmp($part, $needle) === 0);
    }

    private static function formatExposureTime(float $seconds): string
    {
        if ($seconds <= 0.0) {
            return self::formatNumber($seconds, 3) . ' s';
        }

        if ($seconds >= 1.0) {
            $formatted = self::formatNumber($seconds, $seconds < 10.0 ? 2 : 1);

            return $formatted . ' s';
        }

        $denominator = (int) round(1.0 / $seconds);
        if ($denominator > 0) {
            $approximation = 1.0 / $denominator;
            if (abs($approximation - $seconds) < 0.0001) {
                return '1/' . $denominator . ' s';
            }
        }

        return self::formatNumber($seconds, 3) . ' s';
    }

    private static function formatFNumber(float $fNumber): string
    {
        return 'f/' . self::formatNumber($fNumber, 1);
    }

    private static function formatIso(int $iso): string
    {
        return 'ISO ' . $iso;
    }

    private static function formatFocalLength(float $focalLength): string
    {
        return self::formatNumber($focalLength, 1) . ' mm';
    }

    private static function formatCoordinate(float $value, int $precision): string
    {
        if ($precision < 0) {
            $precision = 0;
        }

        $format = '%.' . $precision . 'f';

        return sprintf($format, $value);
    }

    private static function formatNumber(float $value, int $precision): string
    {
        $precision = max(0, $precision);
        $format    = '%.' . $precision . 'f';

        $formatted = sprintf($format, $value);

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalised = trim($value);

        return $normalised === '' ? null : $normalised;
    }

    private static function startsWithCaseInsensitive(string $haystack, string $needle): bool
    {
        $needleLength = strlen($needle);
        if ($needleLength === 0) {
            return true;
        }

        return strncmp(strtolower($haystack), strtolower($needle), $needleLength) === 0;
    }

    private static function resolveLatitudeRef(Gps $gps, float $latitude): string
    {
        $ref = $gps->latitudeRef;

        return $ref ?? ($latitude >= 0.0 ? 'N' : 'S');
    }

    private static function resolveLongitudeRef(Gps $gps, float $longitude): string
    {
        $ref = $gps->longitudeRef;

        return $ref ?? ($longitude >= 0.0 ? 'E' : 'W');
    }

    private static function resolveAltitude(Gps $gps): ?float
    {
        $altitude = $gps->altitude;
        if ($altitude === null) {
            return null;
        }

        $reference = $gps->altitudeRef;
        if ($reference === 1) {
            return -$altitude;
        }

        return $altitude;
    }
}
