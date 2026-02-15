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
use function sprintf;

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
        if (($count > 0) && ($bytes[$count - 1] !== "\0")) {
            throw new ParseError(
                'ASCII values must be NUL-terminated and include the terminator in count per EXIF 3.0 §4.6.2; TIFF 6.0 §2.',
                1329,
            );
        }

        $firstHighByteOffset = -1;
        for ($i = 0; $i < $count; ++$i) {
            if (ord($bytes[$i]) > 0x7F) {
                $firstHighByteOffset = $i;

                break;
            }
        }

        if ($firstHighByteOffset >= 0) {
            if (in_array($tag, $utf8Tags, true)) {
                $text = rtrim($bytes, "\0");

                if (!mb_check_encoding($text, 'UTF-8')) {
                    throw new ParseError(
                        'EXIF 3.0 text tag contains malformed UTF-8 per EXIF 3.0 §4.6.5.4.',
                        1459,
                    );
                }

                return $text;
            }

            throw new ParseError(sprintf(
                'ASCII value contains non-7-bit byte 0x%02X at offset %d per TIFF 6.0 §2.2.',
                ord($bytes[$firstHighByteOffset]),
                $firstHighByteOffset,
            ), 1330);
        }

        return rtrim($bytes, "\0");
    }
}
