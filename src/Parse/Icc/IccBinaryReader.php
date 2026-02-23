<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Icc;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function array_key_exists;
use function function_exists;
use function iconv;
use function is_array;
use function is_int;
use function mb_convert_encoding;
use function sprintf;
use function strlen;
use function unpack;

/**
 * Low-level binary I/O and encoding conversion for ICC profile decoding.
 *
 * ICC.1:2022 defines several fixed-width numeric types (uInt32Number, uInt16Number,
 * s15Fixed16Number) and UTF-16BE text encoding used across header fields and tag payloads.
 */
final class IccBinaryReader
{
    /**
     * Converts exactly four bytes into an unsigned big-endian integer.
     *
     * @param string $bytes Raw bytes to interpret as a big-endian integer.
     *
     * @return int Parsed unsigned integer value.
     */
    public function uInt32Be(string $bytes): int
    {
        if (strlen($bytes) !== 4) {
            throw new ParseError(
                sprintf('ICC uInt32 field truncated: expected 4 bytes, got %d', strlen($bytes)),
                1124,
            );
        }

        return Unpack::int('N', $bytes, 'ICC profile uInt32');
    }

    /**
     * Converts exactly two bytes into an unsigned big-endian integer.
     *
     * @param string $bytes Raw bytes to interpret as a big-endian integer.
     *
     * @return int Parsed unsigned integer value.
     */
    public function uInt16Be(string $bytes): int
    {
        if (strlen($bytes) !== 2) {
            throw new ParseError(
                sprintf('ICC uInt16 field truncated: expected 2 bytes, got %d', strlen($bytes)),
                1125,
            );
        }

        return Unpack::int('n', $bytes, 'ICC profile uInt16');
    }

    /**
     * Parses an s15Fixed16Number from tag data at the given offset.
     *
     * ICC.1:2022 §4.6: s15Fixed16Number is a signed 32-bit fixed-point number
     * with 16 fractional bits. The value is calculated as: raw_value / 65536.0
     *
     * @param string $data   Raw tag data.
     * @param int    $offset Byte offset within the data.
     *
     * @return float Parsed fixed-point value as a float.
     */
    public function s15Fixed16(string $data, int $offset): float
    {
        $bytes = substr($data, $offset, 4);
        if (strlen($bytes) < 4) {
            return 0.0;
        }

        // Unpack as unsigned 32-bit big-endian
        $unpacked = @unpack('Nvalue', $bytes);
        if (!is_array($unpacked) || !array_key_exists('value', $unpacked)) {
            return 0.0;
        }

        $unsigned = $unpacked['value'];
        if (!is_int($unsigned)) {
            return 0.0;
        }

        // Convert to signed if necessary (two's complement)
        $signed = $unsigned >= 0x80000000
            ? $unsigned - 0x100000000
            : $unsigned;

        // Convert fixed-point to float (16 fractional bits)
        return $signed / 65536.0;
    }

    /**
     * Converts a UTF-16BE encoded string to UTF-8 when possible.
     *
     * @param string $data Raw UTF-16BE encoded bytes.
     *
     * @return string|null Converted UTF-8 string or null when conversion fails.
     */
    public function decodeUtf16Be(string $data): ?string
    {
        if ($data === '') {
            return null;
        }

        // ICC.1:2022 §10.13: UTF-16BE must consist of complete code units.
        if ((strlen($data) % 2) !== 0) {
            throw new ParseError('Odd-length UTF-16BE payload in ICC mluc record', 1123);
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-16BE');
        }

        if (function_exists('iconv')) {
            // Strict conversion without //IGNORE to reject malformed sequences.
            $converted = iconv('UTF-16BE', 'UTF-8', $data);

            return $converted === false ? null : $converted;
        }

        // No Imagick fallback; return null when no pure-PHP conversion is available
        return null;
    }
}
