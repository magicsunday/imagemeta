<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;

use function ctype_digit;
use function is_string;
use function str_contains;
use function str_replace;
use function strlen;
use function substr;
use function trim;

/**
 * Converts and sanitizes EXIF string values.
 *
 * EXIF strings often contain null padding and extraneous whitespace that
 * must be normalized for consistent downstream processing.
 */
final readonly class StringConverter
{
    /**
     * Normalises ASCII-like EXIF strings by trimming whitespace and null padding.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw value.
     */
    public function sanitize(
        string|int|float|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $clean = trim(str_replace("\0", '', $value));

        return $clean === '' ? null : $clean;
    }

    /**
     * Normalises a raw EXIF version byte string into a dotted decimal representation.
     *
     * EXIF 3.0 §4.6.6.1.1 (ExifVersion) requires the field to contain exactly four ASCII digits
     * without a terminating null byte.
     */
    public function toExifVersion(?string $bytes): ?string
    {
        if ($bytes === null || $bytes === '') {
            return null;
        }

        if (str_contains($bytes, "\0")) {
            return null;
        }

        $trimmed = trim($bytes, " \t\n\r");
        if ($trimmed === '') {
            return null;
        }

        if (strlen($trimmed) !== 4) {
            return null;
        }

        if (!ctype_digit($trimmed)) {
            return null;
        }

        $known = [
            '0100',
            '0110',
            '0200',
            '0210',
            '0220',
            '0221',
            '0230',
            '0231',
            '0232',
            '0300',
        ];

        if (!in_array($trimmed, $known, true)) {
            return null;
        }

        $major = (int) substr($trimmed, 0, 2);
        $minor = substr($trimmed, 2, 2);

        return sprintf('%d.%s', $major, $minor);
    }
}
