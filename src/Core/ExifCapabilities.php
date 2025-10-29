<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use function ctype_digit;
use function preg_replace;
use function rtrim;
use function strlen;
use function trim;

/**
 * Derives EXIF capability profiles from version identifiers defined in
 * EXIF 2.32 §4.6.8 and EXIF 3.0 §4.6.8 (other tags).
 */
final class ExifCapabilities
{
    /**
     * Normalises vendor provided EXIF version identifiers to known capability profile codes.
     * Trims whitespace, removes trailing null bytes and maps digit-only fallbacks so unusual
     * encodings still yield the canonical profile.
     *
     * @param ?string $exifVersion Raw EXIF version string as read from metadata, possibly null or padded.
     *
     * @return string Canonical capability profile identifier or "unknown" when normalisation fails.
     */
    public static function fromVersion(?string $exifVersion): string
    {
        if ($exifVersion === null) {
            return '2.2';
        }

        $trimmed = trim($exifVersion);
        if ($trimmed === '') {
            return '2.2';
        }

        // Remove any trailing null bytes coming from byte aligned EXIF strings.
        $trimmed = rtrim($trimmed, "\0");

        if ($trimmed === '') {
            return '2.2';
        }

        // EXIF 3.0 §4.6.8 and EXIF 2.32 §4.6.8 define the canonical ASCII
        // version identifiers recorded in the ExifVersion tag (0x9000).
        $profile = match ($trimmed) {
            '1.00', '1.0' => '1.0',
            '1.10', '1.1' => '1.1',
            '2.00', '2.0' => '2.0',
            '2.10', '2.1' => '2.1',
            '2.20', '2.2' => '2.2',
            '2.21' => '2.21',
            '2.30', '2.3' => '2.3',
            '2.31' => '2.31',
            '2.32' => '2.32',
            '3.00', '3.0' => '3.0',
            default => null,
        };

        if ($profile !== null) {
            return $profile;
        }

        $digits = preg_replace('/[^0-9]/', '', $trimmed);
        if ($digits === null || $digits === '') {
            $digits = $trimmed;
        }

        if (ctype_digit($digits)) {
            if (strlen($digits) === 3) {
                $digits = '0' . $digits;
            }

            // Numeric encoders frequently drop the dots while keeping the
            // zero-padded digits listed in EXIF 3.0 §4.6.8 / EXIF 2.32 §4.6.8.
            $profile = match ($digits) {
                '0100'  => '1.0',
                '0110'  => '1.1',
                '0200'  => '2.0',
                '0210'  => '2.1',
                '0220'  => '2.2',
                '0221'  => '2.21',
                '0230'  => '2.3',
                '0231'  => '2.31',
                '0232'  => '2.32',
                '0300'  => '3.0',
                default => null,
            };

            if ($profile !== null) {
                return $profile;
            }
        }

        return 'unknown';
    }
}
