<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\Core\ParseError;

use function array_key_exists;
use function chr;
use function iconv;
use function intdiv;
use function is_array;
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
 */
final class BinaryPlistDecoder
{
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
     * @return array<int|string, array<int|string, mixed>|bool|float|int|string|null>|bool|float|int|string|null
     */
    public function decode(string $data): array|string|int|float|bool|null
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
     * @return array<int|string, array<int|string, mixed>|bool|float|int|string|null>|bool|float|int|string|null
     */
    private function parseObject(int $index): array|string|int|float|bool|null
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
        $info   = $marker & 0x0F;

        return match ($type) {
            0x0     => $this->parseSimple($info),
            0x1     => $this->parseInteger($offset, $info),
            0x2     => $this->parseReal($offset, $info),
            0x4     => $this->parseData($offset, $info),
            0x5     => $this->parseAscii($offset, $info),
            0x6     => $this->parseUnicode($offset, $info),
            0x8     => $this->parseUid($offset, $info),
            0xA     => $this->parseArray($offset, $info),
            0xD     => $this->parseDictionary($offset, $info),
            default => throw new ParseError('Unsupported property list object type.'),
        };
    }

    private function parseSimple(int $info): ?bool
    {
        return match ($info) {
            0x0     => null,
            0x8     => false,
            0x9     => true,
            default => throw new ParseError('Unsupported simple property list object.'),
        };
    }

    private function parseInteger(int $offset, int $info): int
    {
        $size = 1 << $info;
        if ($size === 0) {
            throw new ParseError('Integer object without payload.');
        }

        $value = $this->readUint($offset + 1, $size);

        return $value;
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
            if (!is_array($value) || !array_key_exists('float', $value)) {
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
            if (!is_array($value) || !array_key_exists('float', $value)) {
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

        if ($info === 0x0F) {
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
        $value = '0';
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
            $digit = ord($decimal[$idx]) - 48;
            $product = ($digit * $multiplier) + $carry;
            $result = chr(($product % 10) + 48) . $result;
            $carry  = intdiv($product, 10);
        }

        while ($carry > 0) {
            $result = chr(($carry % 10) + 48) . $result;
            $carry  = intdiv($carry, 10);
        }

        $trimmed = ltrim($result, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * @return list<array<int|string, mixed>|bool|float|int|string|null>
     */
    private function parseArray(int $offset, int $info): array
    {
        [$count, $header] = $this->readLength($offset, $info);
        if ($count === 0) {
            return [];
        }

        $refsOffset = $offset + $header;
        $bytes      = $count * $this->objectRefSize;
        if ($refsOffset + $bytes > $this->length) {
            throw new ParseError('Array references exceed payload bounds.');
        }

        $result = [];
        for ($idx = 0; $idx < $count; ++$idx) {
            $reference = $this->readUint($refsOffset + ($idx * $this->objectRefSize), $this->objectRefSize);
            $result[]  = $this->parseObject($reference);
        }

        return $result;
    }

    /**
     * @return array<string, array<int|string, mixed>|bool|float|int|string|null>
     */
    private function parseDictionary(int $offset, int $info): array
    {
        [$count, $header] = $this->readLength($offset, $info);
        if ($count === 0) {
            return [];
        }

        $refsOffset = $offset + $header;
        $bytes      = $count * $this->objectRefSize * 2;
        if ($refsOffset + $bytes > $this->length) {
            throw new ParseError('Dictionary references exceed payload bounds.');
        }

        $keys   = [];
        $values = [];
        for ($idx = 0; $idx < $count; ++$idx) {
            $keyRef = $this->readUint($refsOffset + ($idx * $this->objectRefSize), $this->objectRefSize);
            $valRef = $this->readUint(
                $refsOffset + ($count * $this->objectRefSize) + ($idx * $this->objectRefSize),
                $this->objectRefSize
            );

            $keys[]   = $this->parseObject($keyRef);
            $values[] = $this->parseObject($valRef);
        }

        $result = [];
        foreach ($keys as $idx => $key) {
            if (!is_string($key)) {
                throw new ParseError('Dictionary keys must be strings.');
            }

            $result[$key] = $values[$idx];
        }

        return $result;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function readLength(int $offset, int $info): array
    {
        if ($info !== 0x0F) {
            return [$info, 1];
        }

        $sizeMarkerOffset = $offset + 1;
        if ($sizeMarkerOffset >= $this->length) {
            throw new ParseError('The property list size marker exceeds the payload.');
        }

        $marker = ord($this->data[$sizeMarkerOffset]);
        $type   = $marker >> 4;
        $info   = $marker & 0x0F;
        if ($type !== 0x1) {
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
        if (!is_array($parts) || !array_key_exists('high', $parts) || !array_key_exists('low', $parts)) {
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
