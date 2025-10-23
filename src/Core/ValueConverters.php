<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use DateTimeZone;
use Exception;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters as ExifValueConverters;
use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\FlashInfo;

use function array_values;
use function atan;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function log;
use function pow;
use function rad2deg;

/**
 * Collection of helper methods that translate raw metadata values into domain specific scalars.
 */
final readonly class ValueConverters
{
    /**
     * Converts a rational or numeric EXIF representation into a floating point value.
     *
     * @param int|float|array<int, int|float>|ExifRational|ExifRationalList|ExifNumericList|null $value Raw value to convert.
     */
    public static function rationalToFloat(int|float|array|ExifRational|ExifRationalList|ExifNumericList|null $value): ?float
    {
        if (is_array($value)) {
            $components = array_values($value);
            if (isset($components[0], $components[1])) {
                $numerator   = $components[0];
                $denominator = $components[1];

                if ((is_int($numerator) || is_float($numerator)) && (is_int($denominator) || is_float($denominator))) {
                    if ((float) $denominator === 0.0) {
                        return null;
                    }

                    return (float) $numerator / (float) $denominator;
                }
            }
        }

        return ExifValueConverters::rationalToFloat($value);
    }

    /**
     * Converts an APEX aperture value to an f-number.
     */
    public static function apexToFNumber(float $apex): float
    {
        return pow(2.0, $apex / 2.0);
    }

    /**
     * Decodes the EXIF flash bit field into a structured value object.
     */
    public static function flashFromShort(int $bits): FlashInfo
    {
        return new FlashInfo(
            fired: (bool) ($bits & 0x01),
            mode: FlashMode::fromFlashBits($bits),
            returnDetection: FlashReturn::fromFlashBits($bits),
            functionPresence: FlashFunction::fromFlashBits($bits),
            redEyeReduction: (bool) ($bits & 0x40),
        );
    }

    /**
     * Converts EXIF GPS speed values into metres per second.
     */
    public static function gpsSpeedToMs(float $value, string $ref): float
    {
        return match ($ref) {
            'K', 'k' => $value * 1000.0 / 3600.0,
            'M', 'm' => $value * 1609.344 / 3600.0,
            'N', 'n' => $value * 1852.0 / 3600.0,
            default  => $value,
        };
    }

    /**
     * Parses an ISO 8601 offset into a DateTimeZone instance.
     */
    public static function parseOffset(?string $offset): ?DateTimeZone
    {
        if ($offset === null || $offset === '') {
            return null;
        }

        try {
            return new DateTimeZone($offset);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Normalises EXIF subject area representations into a rectangle map.
     *
     * @param array<int, int|float> $values Subject area values as extracted from metadata.
     *
     * @return array{x:?int,y:?int,w:?int,h:?int}
     */
    public static function subjectAreaToRect(array $values): array
    {
        $values = array_values($values);

        if (count($values) >= 4) {
            return [
                'x' => (int) $values[0],
                'y' => (int) $values[1],
                'w' => (int) $values[2],
                'h' => (int) $values[3],
            ];
        }

        if (count($values) === 3) {
            $radius = (int) $values[2];

            return [
                'x' => (int) $values[0] - $radius,
                'y' => (int) $values[1] - $radius,
                'w' => $radius * 2,
                'h' => $radius * 2,
            ];
        }

        return ['x' => null, 'y' => null, 'w' => null, 'h' => null];
    }

    /**
     * Calculates the exposure value normalised to ISO 100.
     */
    public static function calcEv100(?float $exposureTimeSec, ?float $fNumber, ?int $iso): ?float
    {
        if ($exposureTimeSec === null || $exposureTimeSec <= 0.0 || $fNumber === null || $fNumber <= 0.0 || $iso === null || $iso <= 0) {
            return null;
        }

        $ev = (pow($fNumber, 2.0) / $exposureTimeSec) * (100.0 / $iso);

        return log($ev, 2.0);
    }

    /**
     * Calculates the hyperfocal distance in metres using the thin lens approximation.
     */
    public static function calcHyperfocalM(?float $focalLengthMm, ?float $fNumber, ?float $circleOfConfusionMm): ?float
    {
        if ($focalLengthMm === null || $focalLengthMm <= 0.0 || $fNumber === null || $fNumber <= 0.0 || $circleOfConfusionMm === null || $circleOfConfusionMm <= 0.0) {
            return null;
        }

        $fSquared = $focalLengthMm * $focalLengthMm;
        $hMm      = ($fSquared) / ($fNumber * $circleOfConfusionMm) + $focalLengthMm;

        return $hMm / 1000.0;
    }

    /**
     * Approximates the diagonal field of view in degrees.
     */
    public static function calcFovDeg(?int $focalLength35mm, ?float $cropFactor): ?float
    {
        if ($focalLength35mm !== null && $focalLength35mm > 0) {
            // 35mm full frame diagonal is approximately 43.2666153056 mm.
            return rad2deg(2.0 * atan(43.2666153056 / (2.0 * (float) $focalLength35mm)));
        }

        if ($cropFactor !== null && $cropFactor > 0.0) {
            // Assume a 50mm lens equivalent on full frame to derive an estimate.
            $equivalent = 50.0 * $cropFactor;

            return rad2deg(2.0 * atan(43.2666153056 / (2.0 * $equivalent)));
        }

        return null;
    }
}
