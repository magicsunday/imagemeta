<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;

use function in_array;
use function mb_check_encoding;
use function ord;
use function rtrim;

/**
 * Decodes tag payload primitives that can be handled independently of parser state.
 */
final readonly class ExifTagDecoder
{
    /**
     * Decodes TIFF/EXIF ASCII payloads and validates 7-bit / UTF-8 conformance rules.
     *
     * @param int       $tag      Tag identifier.
     * @param int       $count    Declared value count.
     * @param string    $bytes    Raw payload bytes.
     * @param list<int> $utf8Tags EXIF 3.0 tags that allow UTF-8 text.
     *
     * @return string
     */
    public function decodeAscii(int $tag, int $count, string $bytes, array $utf8Tags): string
    {
        // EXIF 3.0 §4.6.2 / TIFF 6.0 §2 require NUL termination, but many
        // legacy cameras omit the terminator.  Accept as-is when missing.

        $firstHighByteOffset = -1;
        for ($i = 0; $i < $count; ++$i) {
            if (ord($bytes[$i]) > 0x7F) {
                $firstHighByteOffset = $i;

                break;
            }
        }

        if ($firstHighByteOffset >= 0) {
            $text = rtrim($bytes, "\0");

            // EXIF 3.0 §4.6.5.4 designates certain tags as UTF-8 text.
            if (in_array($tag, $utf8Tags, true)) {
                if (!mb_check_encoding($text, 'UTF-8')) {
                    throw new ParseError(
                        'EXIF 3.0 text tag contains malformed UTF-8 per EXIF 3.0 §4.6.5.4.',
                        1459,
                    );
                }

                return $text;
            }

            // TIFF 6.0 §2.2 defines ASCII as 7-bit, but real-world cameras
            // commonly write Latin-1 (ISO-8859-1) or UTF-8 in ASCII fields
            // for accented characters in names and locations.  Strategy: try
            // UTF-8 first (superset of ASCII), fall back to Latin-1 (every
            // byte sequence is valid Latin-1).
            if (mb_check_encoding($text, 'UTF-8')) {
                return $text;
            }

            return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }

        return rtrim($bytes, "\0");
    }
}
