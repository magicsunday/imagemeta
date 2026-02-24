<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Text\JisTextDecoder;
use MagicSunday\ImageMeta\Exif\Text\UndefinedTextMarker;
use MagicSunday\ImageMeta\Value\Enum\CharacterEncoding;

use function abs;
use function checkdate;
use function count;
use function floor;
use function iconv;
use function is_string;
use function preg_match;
use function round;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use function trim;

/**
 * Assembles GPS timestamps from date and time components and decodes GPS
 * UNDEFINED text fields (EXIF 3.0 §4.6.7.1.8, §4.6.7.1.28–§4.6.7.1.30).
 */
final readonly class GpsTimestampConverter
{
    /**
     * @param RationalConverter $rationalConverter Dependency for rational conversions.
     * @param StringConverter   $stringConverter   Dependency for string sanitisation.
     */
    public function __construct(
        private RationalConverter $rationalConverter,
        private StringConverter $stringConverter,
    ) {
    }

    /**
     * Extracts date, time, processing method and area information from a GPS IFD.
     *
     * @return array{
     *     date: ?string,
     *     date_raw: ?string,
     *     time: ?string,
     *     timestamp: ?DateTimeImmutable,
     *     processing_method: ?string,
     *     area_information: ?string,
     * }
     */
    public function extractFromIfd(Ifd $gps): array
    {
        $dateEntry    = $gps->get(ExifTag::GPS_DATE_STAMP);
        $timeEntry    = $gps->get(ExifTag::GPS_TIME_STAMP);
        $processEntry = $gps->get(ExifTag::GPS_PROCESSING_METHOD);
        $areaEntry    = $gps->get(ExifTag::GPS_AREA_INFORMATION);

        $dateParts = $this->normalizeDate($dateEntry?->value);
        if (($dateEntry instanceof IfdEntry) && ($dateParts['normalized'] === null)) {
            throw new ParseError(
                sprintf(
                    'GPSDateStamp "%s" is not a valid UTC calendar date per EXIF 3.0 §4.6.7.1.30.',
                    $dateParts['raw'] ?? '',
                ),
                1465,
            );
        }

        $timeParts = $timeEntry instanceof IfdEntry && $timeEntry->value instanceof ExifRationalList
            ? $this->parseTime($timeEntry->value)
            : null;

        if (($timeEntry instanceof IfdEntry) && ($timeParts === null)) {
            throw new ParseError(
                'GPSTimeStamp is outside valid UTC ranges (hour 0..23, minute 0..59, second >=0 and <60) per EXIF 3.0 §4.6.7.1.8.',
                1466,
            );
        }

        return [
            'date'              => $dateParts['normalized'],
            'date_raw'          => $dateParts['raw'],
            'time'              => $this->formatTime($timeParts),
            'timestamp'         => $this->combineDateTime($dateParts['normalized'], $timeParts),
            'processing_method' => $this->decodeUndefinedString($processEntry?->value),
            'area_information'  => $this->decodeUndefinedString($areaEntry?->value),
        ];
    }

    /**
     * Normalises a GPS date stamp into an ISO 8601 calendar date.
     *
     * EXIF 3.0 §4.6.8 (GPSDateStamp): the value is a "YYYY:MM:DD" ASCII string in UTC.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw value.
     *
     * @return array{normalized: ?string, raw: ?string}
     */
    private function normalizeDate(
        string|int|float|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): array {
        $raw = is_string($value) ? $value : null;
        if (!is_string($value)) {
            return [
                'normalized' => null,
                'raw'        => $raw,
            ];
        }

        $clean = trim(str_replace("\0", '', $value));
        if ($clean === '') {
            return [
                'normalized' => null,
                'raw'        => $raw,
            ];
        }

        if (preg_match('/^\d{4}:\d{2}:\d{2}$/', $clean) !== 1) {
            return [
                'normalized' => null,
                'raw'        => $raw,
            ];
        }

        $year  = (int) substr($clean, 0, 4);
        $month = (int) substr($clean, 5, 2);
        $day   = (int) substr($clean, 8, 2);
        if (!checkdate($month, $day, $year)) {
            return [
                'normalized' => null,
                'raw'        => $raw,
            ];
        }

        return [
            'normalized' => str_replace(':', '-', $clean),
            'raw'        => $raw,
        ];
    }

    /**
     * Extracts hour, minute and second components from a GPS time stamp list.
     *
     * EXIF 3.0 §4.6.8 (GPSTimeStamp): a three-element rational list representing UTC hours,
     * minutes and seconds.
     *
     * @return array{hours:int, minutes:int, seconds:float}|null
     */
    private function parseTime(ExifRationalList $value): ?array
    {
        if (count($value->values) !== 3) {
            return null;
        }

        $hours   = $this->rationalConverter->toFloat($value->values[0]);
        $minutes = $this->rationalConverter->toFloat($value->values[1]);
        $seconds = $this->rationalConverter->toFloat($value->values[2]);

        if ($hours === null || $minutes === null || $seconds === null) {
            return null;
        }

        if (!$this->isWholeNumber($hours) || !$this->isWholeNumber($minutes)) {
            return null;
        }

        $hoursInt   = (int) $hours;
        $minutesInt = (int) $minutes;

        if (($hoursInt < 0) || ($hoursInt > 23)) {
            return null;
        }

        if (($minutesInt < 0) || ($minutesInt > 59)) {
            return null;
        }

        // Leap seconds are not accepted; EXIF GPS timestamps are restricted to [0, 60).
        if (($seconds < 0.0) || ($seconds >= 60.0)) {
            return null;
        }

        return [
            'hours'   => $hoursInt,
            'minutes' => $minutesInt,
            'seconds' => $seconds,
        ];
    }

    /**
     * Formats GPS time components into a human readable HH:MM:SS(.ffffff) string.
     *
     * @param array{hours:int, minutes:int, seconds:float}|null $timeParts
     */
    private function formatTime(?array $timeParts): ?string
    {
        if ($timeParts === null) {
            return null;
        }

        $secondsFloat = $timeParts['seconds'];
        $secondsInt   = (int) floor($secondsFloat);
        $fraction     = $secondsFloat - $secondsInt;
        $microseconds = (int) round($fraction * 1_000_000);

        if ($microseconds >= 1_000_000) {
            ++$secondsInt;
            $microseconds -= 1_000_000;
        }

        $time = sprintf('%02d:%02d:%02d', $timeParts['hours'], $timeParts['minutes'], $secondsInt);

        if ($microseconds > 0) {
            $micro = rtrim(sprintf('%06d', $microseconds), '0');
            if ($micro === '') {
                $micro = '0';
            }

            $time .= '.' . $micro;
        }

        return $time;
    }

    /**
     * Combines a GPS date and time into a UTC timestamp.
     *
     * @param string|null                                       $date
     * @param array{hours:int, minutes:int, seconds:float}|null $timeParts
     */
    private function combineDateTime(?string $date, ?array $timeParts): ?DateTimeImmutable
    {
        if ($date === null || $timeParts === null) {
            return null;
        }

        $secondsFloat = $timeParts['seconds'];
        $secondsInt   = (int) floor($secondsFloat);
        $fraction     = $secondsFloat - $secondsInt;
        $microseconds = (int) round($fraction * 1_000_000);

        if ($microseconds >= 1_000_000) {
            ++$secondsInt;
            $microseconds -= 1_000_000;
        }

        $timeString = sprintf('%02d:%02d:%02d', $timeParts['hours'], $timeParts['minutes'], $secondsInt);
        $format     = 'Y-m-d H:i:s';

        if ($microseconds > 0) {
            $timeString .= sprintf('.%06d', $microseconds);
            $format .= '.u';
        }

        $dateTime = DateTimeImmutable::createFromFormat(
            $format,
            $date . ' ' . $timeString,
            new DateTimeZone('UTC'),
        );

        if ($dateTime === false) {
            return null;
        }

        return $dateTime;
    }

    private function isWholeNumber(float $value): bool
    {
        return abs($value - floor($value)) < 1.0e-9;
    }

    /**
     * EXIF 3.0 §4.6.4 requires UNDEFINED text fields to include an 8-byte
     * character code area. Payloads shorter than 8 bytes or with an
     * unrecognised prefix are rejected.
     */
    private function decodeUndefinedString(
        string|int|float|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        if (!is_string($value) || strlen($value) < 8) {
            return null;
        }

        $prefixBytes = substr($value, 0, 8);
        $payload     = substr($value, 8);
        $marker      = UndefinedTextMarker::canonicalMarkerFromPrefix($prefixBytes);
        if ($marker === '') {
            return null;
        }

        $encoding = UndefinedTextMarker::encodingForMarker($marker);
        if (!$encoding instanceof CharacterEncoding) {
            return null;
        }

        return match ($encoding) {
            CharacterEncoding::Utf8 => $this->decodeUndefinedUtf8($payload),
            CharacterEncoding::Jis  => $this->decodeUndefinedJis($payload),
            CharacterEncoding::Ascii,
            CharacterEncoding::Undefined => $this->stringConverter->sanitize($payload),
            default                      => null,
        };
    }

    /**
     * Decodes EXIF 3.0 UNICODE-marker payloads as UTF-8.
     *
     * For compatibility with older EXIF 2.x ecosystem payloads that used UTF-16
     * under the same marker, BOM-tagged UTF-16 payloads are accepted as fallback.
     */
    private function decodeUndefinedUtf8(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        if (preg_match('//u', $payload) === 1) {
            return $this->decodeUndefinedWithEncoding($payload, CharacterEncoding::Utf8);
        }

        return $this->decodeUndefinedUnicode($payload);
    }

    /**
     * Decodes a UTF-16 encoded undefined GPS string into UTF-8.
     */
    private function decodeUndefinedUnicode(string $payload): ?string
    {
        if (strlen($payload) < 2) {
            return null;
        }

        $byteOrderMark = substr($payload, 0, 2);
        $content       = substr($payload, 2);

        $encoding = match ($byteOrderMark) {
            "\xFF\xFE" => CharacterEncoding::Utf16le,
            "\xFE\xFF" => CharacterEncoding::Utf16be,
            default    => null,
        };

        // No TIFF byte order context is available in this converter path.
        // Without an explicit BOM, decoding is rejected to avoid byte-order ambiguity.
        if (($encoding === null) || ($content === '') || (strlen($content) % 2 !== 0)) {
            return null;
        }

        return $this->decodeUndefinedWithEncoding($content, $encoding);
    }

    /**
     * Decodes a Shift-JIS encoded undefined GPS string into UTF-8.
     */
    private function decodeUndefinedJis(string $payload): ?string
    {
        return $this->decodeUndefinedWithEncoding($payload, CharacterEncoding::Jis);
    }

    /**
     * Decodes a GPS undefined payload with the selected source encoding.
     */
    private function decodeUndefinedWithEncoding(string $payload, CharacterEncoding $sourceEncoding): ?string
    {
        if ($payload === '') {
            return null;
        }

        $decoded = match ($sourceEncoding) {
            CharacterEncoding::Jis  => JisTextDecoder::decode($payload),
            CharacterEncoding::Utf8 => $payload,
            default                 => @iconv($sourceEncoding->value, CharacterEncoding::Utf8->value, $payload),
        };

        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        return $this->stringConverter->sanitize($decoded);
    }
}
