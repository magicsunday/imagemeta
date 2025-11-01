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
use function iconv;
use function intdiv;
use function is_float;
use function is_int;
use function is_string;
use function ltrim;
use function ord;
use function sprintf;
use function strlen;
use function strpos;
use function substr;

/**
 * Minimal decoder for Apple's binary property list format used inside maker notes.
 *
 * @phpstan-type BinaryPlistScalar bool|float|int|string|null
 * @phpstan-type BinaryPlistValue ApplePlistScalar|ApplePlistArray|ApplePlistDictionary
 * @phpstan-type BinaryPlistArray list<BinaryPlistValue>
 * @phpstan-type BinaryPlistDictionary array<string, BinaryPlistValue>
 */
final class BinaryPlistDecoder
{
    private const int MARKER_TYPE_SIMPLE = 0;

    private const int MARKER_TYPE_INTEGER = 1;

    private const int MARKER_TYPE_REAL = 2;

    private const int MARKER_TYPE_DATA = 4;

    private const int MARKER_TYPE_ASCII = 5;

    private const int MARKER_TYPE_UNICODE = 6;

    private const int MARKER_TYPE_UID = 8;

    private const int MARKER_TYPE_ARRAY = 10;

    private const int MARKER_TYPE_DICTIONARY = 13;

    private const int MARKER_SIMPLE_NULL = 0;

    private const int MARKER_SIMPLE_FALSE = 8;

    private const int MARKER_SIMPLE_TRUE = 9;

    private const int MARKER_INFO_EXTENDED = 0x0F;

    private const int MARKER_INFO_MASK = BitMask::LOW_NIBBLE;

    private string $data = '';

    /**
     * @var list<int>
     */
    private array $offsetTable = [];

    private int $objectRefSize = 0;

    private int $length = 0;

    private int $topObjectIndex = 0;

    /**
     * Decodes the supplied binary property list and returns the top level value.
     *
     * @phpstan-return BinaryPlistValue
     */
    public function decode(string $data): ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
    {
        if ($data === '') {
            throw new ParseError('The property list data must not be empty.');
        }

        $signatureOffset = strpos($data, 'bplist00');
        if ($signatureOffset === false) {
            throw new ParseError('Unsupported property list format.');
        }

        $data = substr($data, $signatureOffset);

        if (strlen($data) < 40) {
            throw new ParseError('The property list payload is too small.');
        }

        $this->data          = $data;
        $this->length        = strlen($data);
        $this->offsetTable   = [];
        $this->objectRefSize = 0;
        $this->decodeTrailer();

        if ($this->offsetTable === []) {
            throw new ParseError('The property list does not contain any objects.');
        }

        $topIndex = $this->topObjectIndex;
        if ($topIndex < 0) {
            throw new ParseError('Missing top level object index.');
        }

        return $this->parseObject($topIndex);
    }

    /**
     * Parses the property list trailer to configure offsets and reference sizing.
     */
    private function decodeTrailer(): void
    {
        $trailer = substr($this->data, -32);
        if (strlen($trailer) !== 32) {
            throw new ParseError('Invalid property list trailer.');
        }

        $offsetIntSize    = ord($trailer[6]);
        $objectRefSize    = ord($trailer[7]);
        $numObjects       = $this->readUint64($trailer, 8);
        $topObject        = $this->readUint64($trailer, 16);
        $offsetTableStart = $this->readUint64($trailer, 24);

        if ($offsetIntSize < 1 || $objectRefSize < 1) {
            throw new ParseError('Invalid property list integer sizing.');
        }

        if ($numObjects < 1) {
            throw new ParseError('The property list does not contain any objects.');
        }

        if ($offsetTableStart >= $this->length) {
            throw new ParseError('The offset table is located outside of the payload.');
        }

        $this->objectRefSize = $objectRefSize;

        $entries = [];
        $cursor  = $offsetTableStart;
        for ($idx = 0; $idx < $numObjects; ++$idx) {
            $offset    = $this->readUint($cursor, $offsetIntSize);
            $entries[] = $offset;
            $cursor += $offsetIntSize;
        }

        $this->offsetTable    = $entries;
        $this->topObjectIndex = $topObject;
    }

    /**
     * @phpstan-return BinaryPlistValue
     */
    private function parseObject(int $index): ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
    {
        if (!array_key_exists($index, $this->offsetTable)) {
            throw new ParseError('The property list object reference is invalid.');
        }

        $offset = $this->offsetTable[$index];
        if ($offset >= $this->length) {
            throw new ParseError('The property list object offset is invalid.');
        }

        $marker = ord($this->data[$offset]);
        $type   = $marker >> 4;
        $info   = $marker & self::MARKER_INFO_MASK;

        return match ($type) {
            self::MARKER_TYPE_SIMPLE     => $this->parseSimple($info),
            self::MARKER_TYPE_INTEGER    => $this->wrapScalar($this->parseInteger($offset, $info)),
            self::MARKER_TYPE_REAL       => $this->wrapScalar($this->parseReal($offset, $info)),
            self::MARKER_TYPE_DATA       => $this->wrapScalar($this->parseData($offset, $info)),
            self::MARKER_TYPE_ASCII      => $this->wrapScalar($this->parseAscii($offset, $info)),
            self::MARKER_TYPE_UNICODE    => $this->wrapScalar($this->parseUnicode($offset, $info)),
            self::MARKER_TYPE_UID        => $this->wrapScalar($this->parseUid($offset, $info)),
            self::MARKER_TYPE_ARRAY      => $this->parseArray($offset, $info),
            self::MARKER_TYPE_DICTIONARY => $this->parseDictionary($offset, $info),
            default                      => throw new ParseError('Unsupported property list object type.'),
        };
    }

    /**
     * @param BinaryPlistScalar $value
     *
     * @return ApplePlistScalar
     */
    private function wrapScalar(bool|float|int|string|null $value): ApplePlistScalar
    {
        return new ApplePlistScalar($value);
    }

    private function parseSimple(int $info): ApplePlistScalar
    {
        $value = match ($info) {
            self::MARKER_SIMPLE_NULL  => null,
            self::MARKER_SIMPLE_FALSE => false,
            self::MARKER_SIMPLE_TRUE  => true,
            default                   => throw new ParseError('Unsupported simple property list object.'),
        };

        return new ApplePlistScalar($value);
    }

    private function parseInteger(int $offset, int $info): int
    {
        $size = 1 << $info;
        if ($size === 0) {
            throw new ParseError('Integer object without payload.');
        }

        return $this->readUint($offset + 1, $size);
    }

    private function parseReal(int $offset, int $info): float
    {
        $size = 1 << $info;
        if ($size === 4) {
            $bytes = substr($this->data, $offset + 1, 4);
            if (strlen($bytes) !== 4) {
                throw new ParseError('Incomplete real payload.');
            }

            $value = unpack('Gfloat', $bytes);
            if ($value === false || !array_key_exists('float', $value)) {
                throw new ParseError('Failed to decode floating point value.');
            }

            $float = $value['float'];
            if (!is_float($float) && !is_int($float)) {
                throw new ParseError('Failed to decode floating point value.');
            }

            return (float) $float;
        }

        if ($size === 8) {
            $bytes = substr($this->data, $offset + 1, 8);
            if (strlen($bytes) !== 8) {
                throw new ParseError('Incomplete double payload.');
            }

            $value = unpack('Efloat', $bytes);
            if ($value === false || !array_key_exists('float', $value)) {
                throw new ParseError('Failed to decode floating point value.');
            }

            $float = $value['float'];
            if (!is_float($float) && !is_int($float)) {
                throw new ParseError('Failed to decode floating point value.');
            }

            return (float) $float;
        }

        throw new ParseError('Unsupported floating point width.');
    }

    private function parseData(int $offset, int $info): string
    {
        [$size, $header] = $this->readLength($offset, $info);

        $payload = substr($this->data, $offset + $header, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete data payload.');
        }

        return $payload;
    }

    private function parseAscii(int $offset, int $info): string
    {
        [$size, $header] = $this->readLength($offset, $info);

        $payload = substr($this->data, $offset + $header, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete ASCII string payload.');
        }

        return $payload;
    }

    private function parseUnicode(int $offset, int $info): string
    {
        [$size, $header] = $this->readLength($offset, $info);

        $byteLength = $size * 2;
        $payload    = substr($this->data, $offset + $header, $byteLength);
        if (strlen($payload) !== $byteLength) {
            throw new ParseError('Incomplete Unicode string payload.');
        }

        $decoded = iconv('UTF-16BE', 'UTF-8', $payload);
        if ($decoded === false) {
            throw new ParseError('Failed to decode Unicode string payload.');
        }

        return $decoded;
    }

    private function parseUid(int $offset, int $info): int|string
    {
        [$lengthValue, $header] = $this->readLength($offset, $info);

        if ($info === self::MARKER_INFO_EXTENDED) {
            $size = $lengthValue;
        } else {
            $size   = $lengthValue + 1;
            $header = 1;
        }

        if ($size < 1) {
            throw new ParseError('UID objects must contain at least one byte.');
        }

        $payloadOffset = $offset + $header;
        if ($payloadOffset + $size > $this->length) {
            throw new ParseError('Incomplete UID payload.');
        }

        if ($size <= PHP_INT_SIZE) {
            $value = $this->readUint($payloadOffset, $size);

            if ($size === PHP_INT_SIZE && $value < 0) {
                return sprintf('%u', $value);
            }

            return $value;
        }

        $payload = substr($this->data, $payloadOffset, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete UID payload.');
        }

        return $this->convertUidPayloadToDecimalString($payload);
    }

    private function convertUidPayloadToDecimalString(string $payload): string
    {
        $value  = '0';
        $length = strlen($payload);
        for ($idx = 0; $idx < $length; ++$idx) {
            $value = $this->multiplyAndAddDecimalString($value, 256, ord($payload[$idx]));
        }

        return $value;
    }

    private function multiplyAndAddDecimalString(string $decimal, int $multiplier, int $addend): string
    {
        $carry  = $addend;
        $result = '';

        for ($idx = strlen($decimal) - 1; $idx >= 0; --$idx) {
            $digit   = ord($decimal[$idx]) - 48;
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

    /**
     * @phpstan-return ApplePlistArray
     */
    private function parseArray(int $offset, int $info): ApplePlistArray
    {
        [$count, $header] = $this->readLength($offset, $info);
        if ($count === 0) {
            return new ApplePlistArray([]);
        }

        $refsOffset = $offset + $header;
        $bytes      = $count * $this->objectRefSize;
        if ($refsOffset + $bytes > $this->length) {
            throw new ParseError('Array references exceed payload bounds.');
        }

        /** @var BinaryPlistArray $result */
        $result = [];
        for ($idx = 0; $idx < $count; ++$idx) {
            $reference = $this->readUint($refsOffset + ($idx * $this->objectRefSize), $this->objectRefSize);
            $result[]  = $this->parseObject($reference);
        }

        return new ApplePlistArray($result);
    }

    /**
     * @phpstan-return ApplePlistDictionary
     */
    private function parseDictionary(int $offset, int $info): ApplePlistDictionary
    {
        [$count, $header] = $this->readLength($offset, $info);
        if ($count === 0) {
            return new ApplePlistDictionary([]);
        }

        $refsOffset = $offset + $header;
        $bytes      = $count * $this->objectRefSize * 2;
        if ($refsOffset + $bytes > $this->length) {
            throw new ParseError('Dictionary references exceed payload bounds.');
        }

        /** @var list<ApplePlistValue> $keys */
        $keys = [];

        /** @var list<ApplePlistValue> $values */
        $values = [];
        for ($idx = 0; $idx < $count; ++$idx) {
            $keyRef = $this->readUint($refsOffset + ($idx * $this->objectRefSize), $this->objectRefSize);
            $valRef = $this->readUint(
                $refsOffset + ($count * $this->objectRefSize) + ($idx * $this->objectRefSize),
                $this->objectRefSize,
            );

            $keys[]   = $this->parseObject($keyRef);
            $values[] = $this->parseObject($valRef);
        }

        /** @var BinaryPlistDictionary $entries */
        $entries = [];
        foreach ($keys as $idx => $key) {
            if (!$key instanceof ApplePlistScalar || !is_string($key->value())) {
                throw new ParseError('Dictionary keys must be strings.');
            }

            $entries[$key->value()] = $values[$idx];
        }

        return new ApplePlistDictionary($entries);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function readLength(int $offset, int $info): array
    {
        if ($info !== self::MARKER_INFO_EXTENDED) {
            return [$info, 1];
        }

        $sizeMarkerOffset = $offset + 1;
        if ($sizeMarkerOffset >= $this->length) {
            throw new ParseError('The property list size marker exceeds the payload.');
        }

        $marker = ord($this->data[$sizeMarkerOffset]);
        $type   = $marker >> 4;
        $info   = $marker & self::MARKER_INFO_MASK;
        if ($type !== self::MARKER_TYPE_INTEGER) {
            throw new ParseError('Size marker does not encode an integer.');
        }

        $sizeBytes = 1 << $info;
        $value     = $this->readUint($sizeMarkerOffset + 1, $sizeBytes);

        return [$value, 2 + $sizeBytes];
    }

    private function readUint(int $offset, int $length): int
    {
        if ($length < 1) {
            throw new ParseError('Cannot read zero length integers.');
        }

        if ($offset < 0 || $offset + $length > $this->length) {
            throw new ParseError('Attempted to read outside of the payload.');
        }

        $value = 0;
        for ($idx = 0; $idx < $length; ++$idx) {
            $value = ($value << 8) | ord($this->data[$offset + $idx]);
        }

        return $value;
    }

    private function readUint64(string $data, int $offset): int
    {
        $slice = substr($data, $offset, 8);
        if (strlen($slice) !== 8) {
            throw new ParseError('Failed to read 64-bit integer.');
        }

        $parts = unpack('Nhigh/Nlow', $slice);
        if ($parts === false || !array_key_exists('high', $parts) || !array_key_exists('low', $parts)) {
            throw new ParseError('Failed to unpack 64-bit integer.');
        }

        $rawHigh = $parts['high'];
        $rawLow  = $parts['low'];

        if (!is_int($rawHigh) || !is_int($rawLow)) {
            throw new ParseError('Unexpected 64-bit integer components.');
        }

        return ($rawHigh << 32) | $rawLow;
    }
}
