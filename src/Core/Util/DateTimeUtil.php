<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core\Util;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;

use function preg_match;
use function preg_replace;
use function trim;

/**
 * Shared ISO 8601 date parsing utility.
 */
final class DateTimeUtil
{
    /**
     * Parses an ISO 8601 date-time string into a DateTimeImmutable.
     *
     * Accepts subsets from YYYY through YYYY-MM-DDThh:mm:ss.sTZD.
     * Returns null on invalid/empty input.
     *
     * @param string|null       $value      ISO 8601 date-time string.
     * @param DateTimeZone|null $fallbackTz Fallback timezone when the value carries no zone.
     */
    public static function parseIso8601(?string $value, ?DateTimeZone $fallbackTz = null): ?DateTimeImmutable
    {
        if (($value === null) || ($value === '')) {
            return null;
        }

        // XMP Date value type: ISO 8601 subset (YYYY through YYYY-MM-DDThh:mm:ss.sTZD)
        if (preg_match('/^\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}(:?\d{2})?)?)?)?)?$/', $value) !== 1) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $fallbackTz ?? new DateTimeZone('UTC'));
        } catch (DateMalformedStringException) {
            return null;
        }
    }

    /**
     * Parses a RIFF date string into a DateTimeImmutable.
     *
     * RIFF dates appear in various formats depending on camera/software:
     * - C ctime: "Mon Dec 15 15:19:38 2014"
     * - ISO-like: "2002-12-16 15:35:01"
     * - EXIF: "2024:03:15 10:30:00"
     *
     * ExifTool RIFF.pm — ConvertRIFFDate().
     *
     * @param string|null $value Raw date string from RIFF INFO or EXIF chunks.
     */
    public static function parseRiffDate(?string $value): ?DateTimeImmutable
    {
        if (($value === null) || ($value === '')) {
            return null;
        }

        $trimmed = trim($value);

        // Already EXIF format — delegate to ISO parser after converting colons to dashes
        $normalized = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $trimmed);

        if ($normalized !== null) {
            $parsed = self::parseIso8601($normalized);

            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        // C ctime format: "Mon Dec 15 15:19:38 2014" or "Wen Jul  5 10:46:25 2017"
        // Some cameras write non-standard day abbreviations (Wen, Thr, etc.),
        // so strip the day-of-week prefix and parse month+day+time+year only.
        if (preg_match('/^[A-Za-z]{3}\s+([A-Za-z]{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+\d{4})/', $trimmed, $matches) === 1) {
            $withoutDay = $matches[1];

            $parsed = DateTimeImmutable::createFromFormat('M d H:i:s Y', $withoutDay);

            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }

            // Single-digit day with space padding: "Jul  5 10:46:25 2017"
            $parsed = DateTimeImmutable::createFromFormat('M  d H:i:s Y', $withoutDay);

            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        // ISO-like with dashes: "2002-12-16 15:35:01"
        try {
            return new DateTimeImmutable($trimmed);
        } catch (DateMalformedStringException) {
            return null;
        }
    }

    /**
     * Prevents instantiation of the utility class.
     */
    private function __construct()
    {
    }
}
