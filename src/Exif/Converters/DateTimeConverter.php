<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use DateInvalidTimeZoneException;
use DateTimeZone;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;

use function is_string;
use function preg_match;
use function sprintf;
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
     * Normalizes EXIF offset time values to a canonical "+HH:MM" representation.
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

        if (preg_match('/^[+-]\d{2}:\d{2}$/', $trimmed) !== 1) {
            return null;
        }

        $hours   = (int) substr($trimmed, 1, 2);
        $minutes = (int) substr($trimmed, 4, 2);

        if ($hours > 14 || $minutes >= 60 || ($hours === 14 && $minutes !== 0)) {
            return null;
        }

        try {
            return new DateTimeZone($trimmed);
        } catch (DateInvalidTimeZoneException) {
            // EXIF timezone offsets may be malformed; return null to let callers use fallbacks.
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
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^[+-]\d{2}:\d{2}$/', $trimmed) !== 1) {
            return null;
        }

        $sign    = $trimmed[0] === '-' ? -1 : 1;
        $hours   = (int) substr($trimmed, 1, 2);
        $minutes = (int) substr($trimmed, 4, 2);

        if ($minutes >= 60) {
            return null;
        }

        // EXIF 3.0 §4.6.6.6.3-§4.6.6.6.5: maximum absolute offset is 14:00.
        if ($hours > 14 || ($hours === 14 && $minutes !== 0)) {
            return null;
        }

        return [
            'sign'    => $sign,
            'hours'   => $hours,
            'minutes' => $minutes,
        ];
    }
}
