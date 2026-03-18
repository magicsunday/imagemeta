<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\ParseError;

use function array_key_exists;
use function chr;
use function intdiv;
use function is_int;
use function ltrim;
use function ord;
use function strlen;
use function substr;

/**
 * Low-level binary I/O helper for Apple binary property list decoding.
 *
 * Encapsulates the raw data buffer and provides methods to read unsigned
 * integers, length headers, and UID decimal conversion from the buffer.
 */
final class PlistBinaryReader
{
    /** @var int Info nibble that signals extended length encoding */
    private const int MARKER_INFO_EXTENDED = 0x0F;

    /** @var int Mask to isolate info nibble */
    private const int MARKER_INFO_MASK     = BitMask::LOW_NIBBLE;

    /** @var string Raw data payload */
    private string $data                   = '';

    /** @var int Length in bytes */
    private int $length                    = 0;

    /**
     * Load a raw data payload into the reader.
     *
     * @param string $data Raw binary data.
     */
    public function load(string $data): void
    {
        $this->data   = $data;
        $this->length = strlen($data);
    }

    /**
     * Return the raw data payload.
     */
    public function data(): string
    {
        return $this->data;
    }

    /**
     * Return the length of the raw data payload in bytes.
     */
    public function length(): int
    {
        return $this->length;
    }

    /**
     * Read an unsigned big-endian integer of length $length starting at $offset.
     *
     * @param int $offset Start offset.
     * @param int $length Number of bytes to read.
     *
     * @throws ParseError
     */
    public function readUint(int $offset, int $length): int
    {
        if ($length < 1) {
            throw new ParseError('Cannot read zero length integers.', 1086);
        }

        if (($offset < 0) || (($offset + $length) > $this->length)) {
            throw new ParseError('Attempted to read outside of the payload.', 1087);
        }

        $value = 0;

        for ($idx = 0; $idx < $length; ++$idx) {
            $value = ($value << 8) | ord($this->data[$offset + $idx]);
        }

        return $value;
    }

    /**
     * Read an unsigned 64-bit big-endian integer from a slice.
     *
     * @param string $data   32-byte trailer slice.
     * @param int    $offset Offset in $data.
     *
     * @throws ParseError
     */
    public function readUint64(string $data, int $offset): int
    {
        $slice   = substr($data, $offset, 8);

        if (strlen($slice) !== 8) {
            throw new ParseError('Failed to read 64-bit integer.', 1088);
        }

        $parts   = unpack('Nhigh/Nlow', $slice);

        if ($parts === false || !array_key_exists('high', $parts) || !array_key_exists('low', $parts)) {
            throw new ParseError('Failed to unpack 64-bit integer.', 1089);
        }

        $rawHigh = $parts['high'];
        $rawLow  = $parts['low'];

        if (!is_int($rawHigh) || !is_int($rawLow)) {
            throw new ParseError('Unexpected 64-bit integer components.', 1090);
        }

        return ($rawHigh << 32) | $rawLow;
    }

    /**
     * Read a length header (inline nibble or extended integer).
     *
     * @param int $offset Object start offset.
     * @param int $info   Info nibble.
     *
     * @return array{0:int,1:int} [length, headerByteCount]
     *
     * @throws ParseError
     */
    public function readLength(int $offset, int $info): array
    {
        if ($info !== self::MARKER_INFO_EXTENDED) {
            return [$info, 1];
        }

        $sizeMarkerOffset = $offset + 1;

        if ($sizeMarkerOffset >= $this->length) {
            throw new ParseError('The property list size marker exceeds the payload.', 1084);
        }

        $marker           = ord($this->data[$sizeMarkerOffset]);
        $type             = $marker >> 4;
        $innerInfo        = $marker & self::MARKER_INFO_MASK;

        if ($type !== PlistMarkerType::Integer->value) {
            throw new ParseError('Size marker does not encode an integer.', 1085);
        }

        $sizeBytes        = 1 << $innerInfo;
        $value            = $this->readUint($sizeMarkerOffset + 1, $sizeBytes);

        return [$value, 2 + $sizeBytes];
    }

    /**
     * Convert an arbitrary-length big-endian byte string to a decimal string.
     *
     * @param string $payload Raw big-endian bytes.
     *
     * @return string Decimal representation.
     */
    public function convertUidPayloadToDecimalString(string $payload): string
    {
        $value  = '0';
        $length = strlen($payload);

        // Multiply-and-add in decimal to avoid GMP/BCMath requirements.
        for ($idx = 0; $idx < $length; ++$idx) {
            $value = $this->multiplyAndAddDecimalString($value, 256, ord($payload[$idx]));
        }

        return $value;
    }

    /**
     * Multiply a base-10 number (as string) by an integer and add another integer (also base-10 result).
     *
     * @param string $decimal    Base-10 integer as string.
     * @param int    $multiplier Multiplier (e.g., 256).
     * @param int    $addend     Addend to add after multiplication.
     *
     * @return string Result in base-10 as string.
     */
    private function multiplyAndAddDecimalString(string $decimal, int $multiplier, int $addend): string
    {
        $carry   = $addend;
        $result  = '';

        for ($idx = strlen($decimal) - 1; $idx >= 0; --$idx) {
            $digit   = ord($decimal[$idx]) - 48; // ASCII '0' => 48
            $product = ($digit * $multiplier) + $carry;
            $result  = chr(($product % 10) + 48) . $result;
            $carry   = intdiv($product, 10);
        }

        while ($carry > 0) {
            $result = chr(($carry % 10) + 48) . $result;
            $carry  = intdiv($carry, 10);
        }

        $trimmed = ltrim($result, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
