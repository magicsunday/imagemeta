<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\ParseError;

use function array_any;
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
 * This class validates structure strictly and maps Foundation objects to simple
 * scalar/array/dictionary wrapper types used by this library.
 *
 * @phpstan-type BinaryPlistScalar bool|float|int|string|null
 * @phpstan-type BinaryPlistValue ApplePlistScalar|ApplePlistArray|ApplePlistDictionary
 * @phpstan-type BinaryPlistArray list<BinaryPlistValue>
 * @phpstan-type BinaryPlistDictionary array<string, BinaryPlistValue>
 */
final class BinaryPlistDecoder
{
    /** @var int Simple marker type */
    private const int MARKER_TYPE_SIMPLE = 0;

    /** @var int Integer marker type */
    private const int MARKER_TYPE_INTEGER = 1;

    /** @var int Real (float/double) marker type */
    private const int MARKER_TYPE_REAL = 2;

    /** @var int Date marker type */
    private const int MARKER_TYPE_DATE = 3;

    /** @var int Data (opaque bytes) marker type */
    private const int MARKER_TYPE_DATA = 4;

    /** @var int ASCII string marker type */
    private const int MARKER_TYPE_ASCII = 5;

    /** @var int UTF-16BE string marker type */
    private const int MARKER_TYPE_UNICODE = 6;

    /** @var int UTF-8 string marker type (non-standard but observed in the wild) */
    private const int MARKER_TYPE_UTF8 = 7;

    /** @var int UID marker type */
    private const int MARKER_TYPE_UID = 8;

    /** @var int Array marker type */
    private const int MARKER_TYPE_ARRAY = 10;

    /** @var int Set marker type (treated as Array) */
    private const int MARKER_TYPE_SET = 11;

    /** @var int Dictionary marker type */
    private const int MARKER_TYPE_DICTIONARY = 13;

    /** @var int Simple: null */
    private const int MARKER_SIMPLE_NULL = 0;

    /** @var int Simple: false */
    private const int MARKER_SIMPLE_FALSE = 8;

    /** @var int Simple: true */
    private const int MARKER_SIMPLE_TRUE = 9;

    /** @var int Simple: URL (Foundation) */
    private const int MARKER_SIMPLE_URL = 12;

    /** @var int Simple: base URL (Foundation) */
    private const int MARKER_SIMPLE_BASE_URL = 13;

    /** @var int Simple: UUID (Foundation) */
    private const int MARKER_SIMPLE_UUID = 14;

    /** @var int Simple: fill byte */
    private const int MARKER_SIMPLE_FILL = 15;

    /** @var int Info nibble that signals extended length encoding */
    private const int MARKER_INFO_EXTENDED = 0x0F;

    /** @var int Mask to isolate info nibble */
    private const int MARKER_INFO_MASK = BitMask::LOW_NIBBLE;

    /** @var string Raw data payload (only valid during decode) */
    private string $data = '';

    /**
     * Offset table with byte offsets for each object in the payload.
     *
     * @var list<int>
     */
    private array $offsetTable = [];

    /** @var int Number of bytes used for object references inside arrays/dictionaries */
    private int $objectRefSize = 0;

    /** @var int Length of in bytes */
    private int $length = 0;

    /** @var int Index of the top-level object in the offset table */
    private int $topObjectIndex = 0;

    /**
     * Decodes the supplied binary property list and returns the top level value.
     *
     * @param string $data Raw payload that contains a 'bplist00' block (possibly with preamble).
     *
     * @return ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
     *
     * @throws ParseError If the payload is empty, malformed, or inconsistent.
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

        // Some maker notes prepend arbitrary bytes before the actual bplist,
        // so we normalize by cutting to the signature position.
        $data = substr($data, $signatureOffset);

        if (strlen($data) < 40) {
            // 8 bytes header + minimal object + 1 entry offset table + 32 bytes trailer
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
     *
     * @throws ParseError
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

        // Build offsets from the offset table region.
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
     * Parse a plist object at the given object table index.
     *
     * @param int $index The object index from the offset table.
     *
     * @return ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
     *
     * @throws ParseError
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
            self::MARKER_TYPE_DATE       => $this->wrapScalar($this->parseDate($offset, $info)),
            self::MARKER_TYPE_DATA       => $this->wrapScalar($this->parseData($offset, $info)),
            self::MARKER_TYPE_ASCII      => $this->wrapScalar($this->parseAscii($offset, $info)),
            self::MARKER_TYPE_UNICODE    => $this->wrapScalar($this->parseUnicode($offset, $info)),
            self::MARKER_TYPE_UTF8       => $this->wrapScalar($this->parseUtf8($offset, $info)),
            self::MARKER_TYPE_UID        => $this->wrapScalar($this->parseUid($offset, $info)),
            self::MARKER_TYPE_ARRAY      => $this->parseArray($offset, $info),
            self::MARKER_TYPE_SET        => $this->parseSet($offset, $info),
            self::MARKER_TYPE_DICTIONARY => $this->parseDictionary($offset, $info),
            default                      => throw new ParseError('Unsupported property list object type.'),
        };
    }

    /**
     * Wrap a scalar value in the ApplePlistScalar container.
     *
     * @param BinaryPlistScalar $value Scalar value to wrap.
     *
     * @return ApplePlistScalar
     */
    private function wrapScalar(bool|float|int|string|null $value): ApplePlistScalar
    {
        return new ApplePlistScalar($value);
    }

    /**
     * Parse "simple" markers (null/boolean/URL/UUID/fill).
     *
     * @param int $info Marker info nibble.
     *
     * @return ApplePlistScalar
     *
     * @throws ParseError
     */
    private function parseSimple(int $info): ApplePlistScalar
    {
        $value = match ($info) {
            // Treat fill byte defensively as null
            self::MARKER_SIMPLE_NULL,
            self::MARKER_SIMPLE_FILL  => null,
            self::MARKER_SIMPLE_FALSE => false,
            self::MARKER_SIMPLE_TRUE  => true,
            // Foundation types we don't model — decode to null to avoid hard failures.
            self::MARKER_SIMPLE_URL,
            self::MARKER_SIMPLE_BASE_URL,
            self::MARKER_SIMPLE_UUID => null,
            default                  => throw new ParseError('Unsupported simple property list object.'),
        };

        return $this->wrapScalar($value);
    }

    /**
     * Parse an integer object.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble encoding the size exponent.
     *
     * @return int
     *
     * @throws ParseError
     */
    private function parseInteger(int $offset, int $info): int
    {
        $size = 1 << $info;
        if ($size === 0) {
            throw new ParseError('Integer object without payload.');
        }

        return $this->readUint($offset + 1, $size);
    }

    /**
     * Parse a real (float/double) object.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble encoding the size exponent (4 or 8 bytes).
     *
     * @return float
     *
     * @throws ParseError
     */
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

    /**
     * Parse a date object (seconds since 2001-01-01T00:00:00Z).
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble encoding the size exponent (must be 3 => 8 bytes).
     *
     * @return string ISO 8601 string with timezone offset, e.g. "2001-01-01T00:00:00+00:00"
     *
     * @throws ParseError
     */
    private function parseDate(int $offset, int $info): string
    {
        $size = 1 << $info;
        if ($size !== 8) {
            throw new ParseError('Date objects must use eight byte payloads.');
        }

        $payload = substr($this->data, $offset + 1, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete date payload.');
        }

        $value = unpack('Eseconds', $payload);
        if ($value === false || !array_key_exists('seconds', $value)) {
            throw new ParseError('Failed to decode date payload.');
        }

        $seconds = $value['seconds'];
        if (!is_float($seconds) && !is_int($seconds)) {
            throw new ParseError('Failed to decode date payload.');
        }

        // Seconds since 2001-01-01T00:00:00Z
        $totalSeconds = 978307200 + (float) $seconds;

        $timestamp = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%.6F', $totalSeconds),
            new DateTimeZone('UTC')
        );

        if (!$timestamp instanceof DateTimeImmutable) {
            throw new ParseError('Failed to decode date payload.');
        }

        $formatted = $timestamp->format('Y-m-d\TH:i:s.uP');
        $timezone  = substr($formatted, -6);
        $main      = substr($formatted, 0, -6);

        if (str_ends_with($main, '.000000')) {
            $main = substr($main, 0, -7);
        }

        return $main . $timezone;
    }

    /**
     * Parse a set object. Treated like an array for practical purposes.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (count or extended length marker).
     *
     * @return ApplePlistArray
     */
    private function parseSet(int $offset, int $info): ApplePlistArray
    {
        return $this->parseArray($offset, $info);
    }

    /**
     * Parse an opaque data object (raw bytes).
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (length or extended length marker).
     *
     * @return string Raw bytes.
     *
     * @throws ParseError
     */
    private function parseData(int $offset, int $info): string
    {
        [$size, $header] = $this->readLength($offset, $info);

        $payload = substr($this->data, $offset + $header, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete data payload.');
        }

        return $payload;
    }

    /**
     * Parse an ASCII string.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (length or extended length marker).
     *
     * @return string
     *
     * @throws ParseError
     */
    private function parseAscii(int $offset, int $info): string
    {
        [$size, $header] = $this->readLength($offset, $info);

        $payload = substr($this->data, $offset + $header, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete ASCII string payload.');
        }

        return $payload;
    }

    /**
     * Parse a UTF-16BE string.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (character count or extended length marker).
     *
     * @return string
     *
     * @throws ParseError
     */
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

    /**
     * Parse a UTF-8 string (non-standard but observed in the wild).
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (length or extended length marker).
     *
     * @return string
     *
     * @throws ParseError
     */
    private function parseUtf8(int $offset, int $info): string
    {
        [$size, $header] = $this->readLength($offset, $info);

        $payload = substr($this->data, $offset + $header, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete UTF-8 string payload.');
        }

        // Normalizes/validates UTF-8 input
        $decoded = iconv('UTF-8', 'UTF-8', $payload);
        if ($decoded === false) {
            throw new ParseError('Failed to decode UTF-8 string payload.');
        }

        return $decoded;
    }

    /**
     * Parse a UID object. Returns int if it fits PHP int, otherwise a decimal string.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (length or extended length marker).
     *
     * @return int|string
     *
     * @throws ParseError
     */
    private function parseUid(int $offset, int $info): int|string
    {
        [$lengthValue, $header] = $this->readLength($offset, $info);

        // UID special-case: if info != EXTENDED, the info nibble encodes "size - 1"
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

            // Handle platform-dependent overflow edge case:
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

    /**
     * Convert an arbitrary-length big-endian byte string to a decimal string.
     *
     * @param string $payload Raw big-endian bytes.
     *
     * @return string Decimal representation.
     */
    private function convertUidPayloadToDecimalString(string $payload): string
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
        $carry  = $addend;
        $result = '';

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

    /**
     * Parse an array object.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (count or extended length marker).
     *
     * @return ApplePlistArray
     *
     * @throws ParseError
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
     * Parse a dictionary object (keys must be strings).
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (count or extended length marker).
     *
     * @return ApplePlistDictionary
     *
     * @throws ParseError
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
                $this->objectRefSize
            );

            $keys[]   = $this->parseObject($keyRef);
            $values[] = $this->parseObject($valRef);
        }

        // Validate that all keys are string scalars (use array_any per guidelines).
        $hasInvalidKey = array_any(
            $keys,
            static fn ($key): bool => !($key instanceof ApplePlistScalar) || !is_string($key->value())
        );

        if ($hasInvalidKey) {
            throw new ParseError('Dictionary keys must be strings.');
        }

        /** @var BinaryPlistDictionary $entries */
        $entries = [];
        for ($idx = 0; $idx < $count; ++$idx) {
            /** @var ApplePlistScalar $key */
            $key                    = $keys[$idx];
            $entries[$key->value()] = $values[$idx];
        }

        return new ApplePlistDictionary($entries);
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
    private function readLength(int $offset, int $info): array
    {
        if ($info !== self::MARKER_INFO_EXTENDED) {
            return [$info, 1];
        }

        $sizeMarkerOffset = $offset + 1;
        if ($sizeMarkerOffset >= $this->length) {
            throw new ParseError('The property list size marker exceeds the payload.');
        }

        $marker    = ord($this->data[$sizeMarkerOffset]);
        $type      = $marker >> 4;
        $innerInfo = $marker & self::MARKER_INFO_MASK;
        if ($type !== self::MARKER_TYPE_INTEGER) {
            throw new ParseError('Size marker does not encode an integer.');
        }

        $sizeBytes = 1 << $innerInfo;
        $value     = $this->readUint($sizeMarkerOffset + 1, $sizeBytes);

        return [$value, 2 + $sizeBytes];
    }

    /**
     * Read an unsigned big-endian integer of length $length starting at $offset.
     *
     * @param int $offset Start offset.
     * @param int $length Number of bytes to read.
     *
     * @return int
     *
     * @throws ParseError
     */
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

    /**
     * Read an unsigned 64-bit big-endian integer from a slice.
     *
     * @param string $data   32-byte trailer slice.
     * @param int    $offset Offset in $data.
     *
     * @return int
     *
     * @throws ParseError
     */
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
