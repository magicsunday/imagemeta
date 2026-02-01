<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use DateTimeZone;
use Exception;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;

use function ctype_digit;
use function explode;
use function floor;
use function in_array;
use function is_string;
use function ltrim;
use function preg_match;
use function round;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

/**
 * Converts EXIF date/time and timezone offset values.
 *
 * EXIF 3.0 §4.6.3 (OffsetTime tags) defines the format for timezone offsets.
 */
final readonly class DateTimeConverter
{
    /**
     * Normalises EXIF offset time values to a canonical "+HH:MM" representation.
     *
     * EXIF 3.0 §4.6.3 (OffsetTime tags).
     *
     * @param int|float|string|ExifRational|ExifRationalList|null $value The raw offset value.
     */
    public function parseOffsetString(int|float|string|ExifRational|ExifRationalList|null $value): ?string
    {
        $components = $this->parseOffsetComponents($value);

        if ($components === null) {
            return null;
        }

        $sign = $components['sign'] < 0 ? '-' : '+';

        return sprintf('%s%02d:%02d', $sign, $components['hours'], $components['minutes']);
    }

    /**
     * Parses an ISO 8601 offset into a DateTimeZone instance.
     */
    public function parseOffset(?string $offset): ?DateTimeZone
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
     * Converts an EXIF offset time value to minutes relative to UTC.
     *
     * @param int|float|string|ExifRational|ExifRationalList|null $value The raw offset value.
     */
    public function offsetToMinutes(int|float|string|ExifRational|ExifRationalList|null $value): ?int
    {
        $components = $this->parseOffsetComponents($value);

        if ($components === null) {
            return null;
        }

        $minutes = $components['hours'] * 60 + $components['minutes'];

        return $components['sign'] < 0 ? -$minutes : $minutes;
    }

    /**
     * Parses numeric and textual offset encodings into sign, hour and minute components.
     *
     * @param int|float|string|ExifRational|ExifRationalList|null $value The raw value to parse.
     *
     * @return array{sign:int, hours:int, minutes:int}|null
     */
    public function parseOffsetComponents(int|float|string|ExifRational|ExifRationalList|null $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            if ($first instanceof ExifRational) {
                return $this->parseOffsetComponents($first);
            }

            return null;
        }

        if ($value instanceof ExifRational) {
            if ($value->denominator === 0) {
                return null;
            }

            $value = (float) $value->numerator / (float) $value->denominator;
        }

        $raw = is_string($value) ? trim($value) : (string) $value;
        $raw = str_replace(['−', '–', '—'], '-', $raw);
        $raw = str_replace(['＋'], '+', $raw);

        if ($raw === '') {
            return null;
        }

        $upper = strtoupper($raw);

        if (in_array($upper, ['Z', 'UTC', 'GMT'], true)) {
            return ['sign' => 1, 'hours' => 0, 'minutes' => 0];
        }

        if (str_starts_with($upper, 'UTC') || str_starts_with($upper, 'GMT')) {
            $raw = trim(substr($raw, 3));

            if ($raw === '') {
                return ['sign' => 1, 'hours' => 0, 'minutes' => 0];
            }
        }

        $sign = 1;
        $raw  = ltrim($raw);

        if ($raw === '') {
            return null;
        }

        $firstChar = $raw[0];

        if ($firstChar === '+' || $firstChar === '-') {
            $sign = $firstChar === '-' ? -1 : 1;
            $raw  = substr($raw, 1);
        }

        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $normalized = str_replace([' ', '\t'], '', $raw);
        $normalized = str_replace(',', '.', $normalized);

        if (str_contains($normalized, ':')) {
            $parts = explode(':', $normalized, 3);
            if (count($parts) < 2) {
                return null;
            }

            $hoursPart   = $parts[0];
            $minutesPart = $parts[1];

            if ($hoursPart === '' || $minutesPart === '') {
                return null;
            }

            if (!ctype_digit($hoursPart) || !ctype_digit($minutesPart)) {
                return null;
            }

            $hours   = (int) $hoursPart;
            $minutes = (int) substr($minutesPart, 0, 2);
        } elseif (preg_match('/^\d+(?:\.\d+)?$/', $normalized) === 1) {
            if (str_contains($normalized, '.')) {
                $floatHours = (float) $normalized;
                $hours      = (int) floor(abs($floatHours));
                $minutes    = (int) round((abs($floatHours) - $hours) * 60);
            } else {
                if (!ctype_digit($normalized)) {
                    return null;
                }

                $length = strlen($normalized);

                if ($length <= 2) {
                    $hours   = (int) $normalized;
                    $minutes = 0;
                } else {
                    $hours   = (int) substr($normalized, 0, $length - 2);
                    $minutes = (int) substr($normalized, -2);
                }
            }
        } else {
            return null;
        }

        if ($minutes < 0) {
            return null;
        }

        if ($minutes >= 60) {
            $hours += (int) floor($minutes / 60);
            $minutes %= 60;
        }

        if ($hours > 14) {
            return null;
        }

        return [
            'sign'    => $sign,
            'hours'   => $hours,
            'minutes' => $minutes,
        ];
    }
}
