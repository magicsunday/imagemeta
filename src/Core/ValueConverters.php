<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use BackedEnum;
use DateTimeZone;
use Exception;
use JsonException;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters as ExifValueConverters;
use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\FlashInfo;
use Throwable;
use UnitEnum;

use function array_filter;
use function array_slice;
use function array_values;
use function atan;
use function count;
use function ctype_digit;
use function ctype_print;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function json_encode;
use function log;
use function pow;
use function rad2deg;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use function trim;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

/**
 * Collection of helper methods that translate raw metadata values into domain specific scalars.
 */
final readonly class ValueConverters
{
    private const FULL_FRAME_WIDTH_MM = 36.0;
    private const FULL_FRAME_HEIGHT_MM = 24.0;
    private const FULL_FRAME_DIAGONAL_MM = 43.2666153056;
    private const FULL_FRAME_CIRCLE_OF_CONFUSION_MM = 0.029;

    /**
     * Converts a rational or numeric EXIF representation into a floating point value.
     *
     * @param int|float|array<int, int|float|string>|ExifRational|ExifRationalList|ExifNumericList|null $value Raw value to convert.
     */
    public static function rationalToFloat(int|float|array|ExifRational|ExifRationalList|ExifNumericList|null $value): ?float
    {
        if (is_array($value)) {
            $components = array_values($value);
            if (!isset($components[0], $components[1])) {
                return null;
            }

            $numerator   = self::normaliseNumericComponent($components[0]);
            $denominator = self::normaliseNumericComponent($components[1]);

            if ($numerator === null || $denominator === null || $denominator === 0.0) {
                return null;
            }

            return $numerator / $denominator;
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
            default => $value,
        };
    }

    /**
     * Parses an ISO 8601 offset into a DateTimeZone instance.
     */
    public static function parseOffset(?string $offset): ?DateTimeZone
    {
        if ($offset === null) {
            return null;
        }

        $trimmed = trim($offset);
        if ($trimmed === '') {
            return null;
        }

        if ($trimmed === 'Z') {
            $trimmed = '+00:00';
        }

        if ($trimmed[0] === '+' || $trimmed[0] === '-') {
            $sign = $trimmed[0];
            $body = substr($trimmed, 1);
            $body = str_replace(':', '', $body);

            if ($body === '' || !ctype_digit($body)) {
                return null;
            }

            $length = strlen($body);
            if ($length <= 2) {
                $hours   = (int) $body;
                $minutes = 0;
            } elseif ($length === 3) {
                $hours   = (int) $body[0];
                $minutes = (int) substr($body, 1, 2);
            } elseif ($length === 4) {
                $hours   = (int) substr($body, 0, 2);
                $minutes = (int) substr($body, 2, 2);
            } else {
                return null;
            }

            if ($hours > 14 || $minutes >= 60 || ($hours === 14 && $minutes !== 0)) {
                return null;
            }

            $trimmed = sprintf('%s%02d:%02d', $sign, $hours, $minutes);
        }

        try {
            return new DateTimeZone($trimmed);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Normalises EXIF subject area representations into a rectangle map.
     *
     * @param array<int, int|float> $values Subject area values as extracted from metadata.
     *
     * @return array{x:?int,y:?int,w:?int,h:?int}|null
     */
    public static function subjectAreaToRect(array $values): ?array
    {
        $values = array_values($values);
        $count  = count($values);

        if ($count >= 4) {
            if (!is_numeric($values[0]) || !is_numeric($values[1]) || !is_numeric($values[2]) || !is_numeric($values[3])) {
                return null;
            }

            return [
                'x' => (int) $values[0],
                'y' => (int) $values[1],
                'w' => (int) $values[2],
                'h' => (int) $values[3],
            ];
        }

        if ($count === 3) {
            if (!is_numeric($values[0]) || !is_numeric($values[1]) || !is_numeric($values[2])) {
                return null;
            }

            $radius = (int) $values[2];

            if ($radius < 0) {
                return null;
            }

            return [
                'x' => (int) $values[0] - $radius,
                'y' => (int) $values[1] - $radius,
                'w' => $radius * 2,
                'h' => $radius * 2,
            ];
        }

        if ($count === 2) {
            if (!is_numeric($values[0]) || !is_numeric($values[1])) {
                return null;
            }

            return ['x' => (int) $values[0], 'y' => (int) $values[1], 'w' => null, 'h' => null];
        }

        return null;
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
        $hMm      = $fSquared / ($fNumber * $circleOfConfusionMm) + $focalLengthMm;

        return $hMm / 1000.0;
    }

    /**
     * Calculates the crop factor from focal lengths.
     */
    public static function calcCropFactor(?int $focalLength35mm, ?float $focalLengthMm): ?float
    {
        if ($focalLength35mm === null || $focalLength35mm <= 0 || $focalLengthMm === null || $focalLengthMm <= 0.0) {
            return null;
        }

        return (float) $focalLength35mm / $focalLengthMm;
    }

    /**
     * Calculates the circle of confusion in millimetres based on the crop factor.
     */
    public static function calcCircleOfConfusionMm(?float $cropFactor): ?float
    {
        if ($cropFactor === null) {
            return self::FULL_FRAME_CIRCLE_OF_CONFUSION_MM;
        }

        if ($cropFactor <= 0.0) {
            return null;
        }

        return self::FULL_FRAME_CIRCLE_OF_CONFUSION_MM / $cropFactor;
    }

    /**
     * Approximates the diagonal field of view in degrees.
     *
     * The result reflects the diagonal angle of view of the recorded frame.
     */
    public static function calcFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        $fov = self::calcFovFromSensorDimension(
            self::FULL_FRAME_DIAGONAL_MM,
            $focalLengthMm,
            $focalLength35mm,
            $cropFactor,
        );

        if ($fov !== null) {
            return $fov;
        }

        if ($cropFactor !== null && $cropFactor > 0.0) {
            // Assume a 50mm lens equivalent on full frame to derive an estimate when only the crop factor is known.
            $equivalent = 50.0 * $cropFactor;

            return rad2deg(2.0 * atan(self::FULL_FRAME_DIAGONAL_MM / (2.0 * $equivalent)));
        }

        return null;
    }

    /**
     * Approximates the horizontal field of view in degrees.
     */
    public static function calcHorizontalFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        return self::calcFovFromSensorDimension(
            self::FULL_FRAME_WIDTH_MM,
            $focalLengthMm,
            $focalLength35mm,
            $cropFactor,
        );
    }

    /**
     * Approximates the vertical field of view in degrees.
     */
    public static function calcVerticalFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        return self::calcFovFromSensorDimension(
            self::FULL_FRAME_HEIGHT_MM,
            $focalLengthMm,
            $focalLength35mm,
            $cropFactor,
        );
    }

    /**
     * Calculates the field of view for the supplied sensor dimension.
     */
    private static function calcFovFromSensorDimension(
        float $fullFrameDimensionMm,
        ?float $focalLengthMm,
        ?int $focalLength35mm,
        ?float $cropFactor
    ): ?float {
        if ($focalLengthMm !== null && $focalLengthMm > 0.0 && $cropFactor !== null && $cropFactor > 0.0) {
            $sensorDimension = $fullFrameDimensionMm / $cropFactor;

            return rad2deg(2.0 * atan($sensorDimension / (2.0 * $focalLengthMm)));
        }

        if ($focalLength35mm !== null && $focalLength35mm > 0) {
            return rad2deg(2.0 * atan($fullFrameDimensionMm / (2.0 * (float) $focalLength35mm)));
        }

        return null;
    }

    /**
     * Normalises a raw EXIF version byte string into a dotted decimal representation.
     */
    public static function toExifVersion(?string $bytes): ?string
    {
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $trimmed = trim($bytes, "\0");
        if ($trimmed === '') {
            return null;
        }

        if (ctype_digit($trimmed) && strlen($trimmed) === 4) {
            $known = [
                '0100',
                '0110',
                '0210',
                '0220',
                '0221',
                '0230',
                '0231',
                '0232',
                '0300',
            ];

            if (in_array($trimmed, $known, true)) {
                $major = (int) substr($trimmed, 0, 2);
                $minor = substr($trimmed, 2, 2);

                return sprintf('%d.%s', $major, $minor);
            }

            return null;
        }

        if (ctype_print($trimmed)) {
            return $trimmed;
        }

        return null;
    }

    /**
     * Converts a textual YCbCr subsampling representation into integer pairs.
     *
     * @return array{0:int,1:int}|null
     */
    public static function ycbcrSubSamplingToPair(?string $val): ?array
    {
        if ($val === null || $val === '') {
            return null;
        }

        $parts = array_values(array_filter(
            explode(' ', str_replace([',', ';'], ' ', $val)),
            static fn (string $part): bool => $part !== '',
        ));
        if (count($parts) !== 2) {
            return null;
        }

        if (!is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return null;
        }

        return [(int) $parts[0], (int) $parts[1]];
    }

    /**
     * Attempts to map a raw value to a backed enum instance.
     *
     * @template T of BackedEnum
     *
     * @param class-string<T> $enumClass
     * @param int|string|null $raw
     *
     * @return T|null
     */
    public static function toEnumOrNull(string $enumClass, int|string|null $raw): ?UnitEnum
    {
        if ($raw === null) {
            return null;
        }

        if ($raw === '') {
            return null;
        }

        $value = $raw;
        if (is_string($raw) && ctype_digit($raw)) {
            $value = (int) $raw;
        }

        /** @var class-string<T> $enumClass */
        try {
            return $enumClass::from($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Converts a rational pair into a white point array.
     *
     * @param array<int, mixed>|ExifRationalList|ExifNumericList|null $rational
     *
     * @return array{0:float,1:float}|null
     */
    public static function toWhitePoint(ExifRationalList|ExifNumericList|array|null $rational): ?array
    {
        if ($rational === null) {
            return null;
        }

        if ($rational instanceof ExifRationalList || $rational instanceof ExifNumericList) {
            $values = $rational->values;
        } else {
            $values = [];
            foreach (array_values($rational) as $component) {
                if (is_array($component)) {
                    /** @var array<int, int|float|string> $pair */
                    $pair     = array_values($component);
                    $values[] = $pair;
                } elseif (is_int($component) || is_float($component)) {
                    $values[] = $component;
                } elseif (is_string($component)) {
                    if (!is_numeric($component)) {
                        return null;
                    }

                    $values[] = (float) $component;
                } else {
                    return null;
                }
            }
        }

        /** @var list<array<int, int|float|string>|int|float|ExifRational> $values */
        if (count($values) < 2) {
            return null;
        }

        $x = self::rationalToFloat($values[0]);
        $y = self::rationalToFloat($values[1]);

        return $x !== null && $y !== null ? [$x, $y] : null;
    }

    /**
     * Converts a rational list into primary chromaticity coordinates.
     *
     * @param array<int, mixed>|ExifRationalList|ExifNumericList|null $rational
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public static function toPrimaryChromaticities(ExifRationalList|ExifNumericList|array|null $rational): ?array
    {
        if ($rational === null) {
            return null;
        }

        if ($rational instanceof ExifRationalList || $rational instanceof ExifNumericList) {
            $values = $rational->values;
        } else {
            $values = [];
            foreach (array_values($rational) as $component) {
                if (is_array($component)) {
                    /** @var array<int, int|float|string> $pair */
                    $pair     = array_values($component);
                    $values[] = $pair;
                } elseif (is_int($component) || is_float($component)) {
                    $values[] = $component;
                } elseif (is_string($component)) {
                    if (!is_numeric($component)) {
                        return null;
                    }

                    $values[] = (float) $component;
                } else {
                    return null;
                }
            }
        }

        /** @var list<array<int, int|float|string>|int|float|ExifRational> $values */
        if (count($values) < 6) {
            return null;
        }

        $result = [];
        foreach (array_slice($values, 0, 6) as $component) {
            $float = self::rationalToFloat($component);
            if ($float === null) {
                return null;
            }

            $result[] = $float;
        }

        /** @var array{0:float,1:float,2:float,3:float,4:float,5:float} $result */
        return $result;
    }

    /**
     * Serialises a DNG matrix or CFA pattern into a reproducible string representation.
     *
     * @param array<int, mixed>|ExifRationalList|ExifNumericList|null $matrix
     */
    public static function dngMatrixToString(ExifRationalList|ExifNumericList|array|null $matrix): ?string
    {
        if ($matrix === null) {
            return null;
        }

        if ($matrix instanceof ExifRationalList || $matrix instanceof ExifNumericList) {
            $raw = $matrix->values;
        } else {
            $raw = [];
            foreach ($matrix as $component) {
                if (is_array($component)) {
                    /** @var array<int, int|float|string> $pair */
                    $pair  = array_values($component);
                    $raw[] = $pair;
                    continue;
                }

                if (is_int($component) || is_float($component)) {
                    $raw[] = $component;
                    continue;
                }

                if (is_string($component)) {
                    if (!is_numeric($component)) {
                        return null;
                    }

                    $raw[] = (float) $component;
                    continue;
                }

                return null;
            }
        }

        if ($raw === []) {
            return null;
        }

        /** @var list<array<int, int|float|string>|int|float|ExifRational> $raw */
        $values = [];
        foreach ($raw as $component) {
            $float = self::rationalToFloat($component);
            if ($float === null) {
                return null;
            }

            $values[] = $float;
        }

        try {
            return json_encode($values, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return implode(',', $values);
        }
    }

    /**
     * Normalises a numeric component from a rational pair.
     *
     * @param int|float|string $component
     */
    private static function normaliseNumericComponent(int|float|string $component): ?float
    {
        if (is_int($component) || is_float($component)) {
            return (float) $component;
        }

        if (!is_numeric($component)) {
            return null;
        }

        return (float) $component;
    }
}
