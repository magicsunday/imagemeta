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
     * Prevents instantiation of the utility class.
     */
    private function __construct()
    {
    }
}
