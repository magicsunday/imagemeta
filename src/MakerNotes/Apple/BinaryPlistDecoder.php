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
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function array_any;
use function array_key_exists;
use function iconv;
use function intdiv;
use function is_string;
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
    private readonly PlistBinaryReader $reader;

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

    /**
     * Offset table with byte offsets for each object in the payload.
     *
     * @var list<int>
     */
    private array $offsetTable = [];

    /** @var int Number of bytes used for object references inside arrays/dictionaries */
    private int $objectRefSize = 0;

    /** @var int Index of the top-level object in the offset table */
    private int $topObjectIndex = 0;

    /** @var int Total number of objects inside the payload */
    private int $objectCount = 0;

    private const int MAX_RECURSION_DEPTH = 64;

    private int $recursionDepth = 0;

    /** @var array<int, bool> */
    private array $visiting = [];

    public function __construct()
    {
        $this->reader = new PlistBinaryReader();
    }

    /**
     * Decodes the supplied binary property list and returns the top level value.
     *
     * @param string $data Raw payload that contains a 'bplist00' block (possibly with preamble).
     *
     * @throws ParseError If the payload is empty, malformed, or inconsistent.
     */
    public function decode(string $data): ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
    {
        if ($data === '') {
            throw new ParseError('The property list data must not be empty.', 1034);
        }

        $signatureOffset = strpos($data, 'bplist00');
        if ($signatureOffset === false) {
            throw new ParseError('Unsupported property list format.', 1035);
        }

        // Some maker notes prepend arbitrary bytes before the actual bplist,
        // so we normalize by cutting to the signature position.
        $data = substr($data, $signatureOffset);

        // 8 bytes header + minimal object + 1 entry offset table + 32 bytes trailer
        PayloadGuard::ensureMinimumLength($data, 40, 'Property list payload', 1036);

        $this->reader->load($data);
        $this->offsetTable    = [];
        $this->objectRefSize  = 0;
        $this->objectCount    = 0;
        $this->visiting       = [];
        $this->recursionDepth = 0;
        $this->decodeTrailer();

        if ($this->offsetTable === []) {
            throw new ParseError('The property list does not contain any objects.', 1037);
        }

        $topIndex = $this->topObjectIndex;
        if ($topIndex < 0 || $topIndex >= $this->objectCount) {
            throw new ParseError('Top level object index is out of range.', 1038);
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
        $trailer = substr($this->reader->data(), -32);
        if (strlen($trailer) !== 32) {
            throw new ParseError('Invalid property list trailer.', 1039);
        }

        $offsetIntSize    = ord($trailer[6]);
        $objectRefSize    = ord($trailer[7]);
        $numObjects       = $this->reader->readUint64($trailer, 8);
        $topObject        = $this->reader->readUint64($trailer, 16);
        $offsetTableStart = $this->reader->readUint64($trailer, 24);

        if ($offsetIntSize < 1 || $objectRefSize < 1) {
            throw new ParseError('Invalid property list integer sizing.', 1040);
        }

        if ($numObjects < 1) {
            throw new ParseError('The property list does not contain any objects.', 1041);
        }

        if ($numObjects > PHP_INT_MAX) {
            throw new ParseError('The property list contains too many objects.', 1042);
        }

        if ($offsetTableStart >= $this->reader->length()) {
            throw new ParseError('The offset table is located outside of the payload.', 1043);
        }

        if ($topObject > PHP_INT_MAX) {
            throw new ParseError('The top level object index exceeds platform limits.', 1044);
        }

        $trailerStart = $this->reader->length() - 32;

        if ($offsetTableStart < 8) {
            throw new ParseError('The offset table offset is invalid.', 1045);
        }

        if ($numObjects > intdiv(PHP_INT_MAX, $offsetIntSize)) {
            throw new ParseError('The offset table size exceeds platform limits.', 1046);
        }

        $offsetTableBytes = $numObjects * $offsetIntSize;
        $offsetTableEnd   = $offsetTableStart + $offsetTableBytes;

        if ($offsetTableEnd > $trailerStart) {
            throw new ParseError('The offset table exceeds payload bounds.', 1047);
        }

        if ($objectRefSize < 8) {
            $maxReferences = 1 << ($objectRefSize * 8);
            if ($maxReferences <= $numObjects) {
                throw new ParseError('Object reference size is insufficient for object count.', 1049);
            }
        }

        if ($offsetIntSize < 8) {
            $maxOffset = 1 << ($offsetIntSize * 8);
            if ($maxOffset <= $offsetTableStart) {
                throw new ParseError('Offset integer size cannot represent object positions.', 1050);
            }
        }

        if ($topObject >= $numObjects) {
            throw new ParseError('Top level object index is out of range.', 1051);
        }

        $this->objectRefSize = $objectRefSize;
        $this->objectCount   = $numObjects;

        // Build offsets from the offset table region.
        $entries         = [];
        $cursor          = $offsetTableStart;
        $maxObjectOffset = $offsetTableStart - 1;
        for ($idx = 0; $idx < $numObjects; ++$idx) {
            $offset = $this->reader->readUint($cursor, $offsetIntSize);
            if ($offset < 8 || $offset > $maxObjectOffset) {
                throw new ParseError('Object offset is outside of the object table range.', 1052);
            }

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
     * @throws ParseError
     */
    private function parseObject(int $index): ApplePlistArray|ApplePlistDictionary|ApplePlistScalar
    {
        if ($index < 0 || $index >= $this->objectCount) {
            throw new ParseError('The property list object reference is invalid.', 1053);
        }

        if (!array_key_exists($index, $this->offsetTable)) {
            throw new ParseError('The property list object reference is invalid.', 1054);
        }

        if (array_key_exists($index, $this->visiting)) {
            throw new ParseError(sprintf('Circular reference detected at object index %d.', $index), 1953);
        }

        if ($this->recursionDepth >= self::MAX_RECURSION_DEPTH) {
            throw new ParseError(sprintf('Recursion depth exceeds limit of %d.', self::MAX_RECURSION_DEPTH), 1954);
        }

        $offset = $this->offsetTable[$index];
        if ($offset >= $this->reader->length()) {
            throw new ParseError('The property list object offset is invalid.', 1055);
        }

        $marker = ord($this->reader->data()[$offset]);
        $type   = $marker >> 4;
        $info   = $marker & self::MARKER_INFO_MASK;

        $this->visiting[$index] = true;
        ++$this->recursionDepth;

        try {
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
                default                      => throw new ParseError('Unsupported property list object type.', 1056),
            };
        } finally {
            unset($this->visiting[$index]);
            --$this->recursionDepth;
        }
    }

    /**
     * Wrap a scalar value in the ApplePlistScalar container.
     *
     * @param BinaryPlistScalar $value Scalar value to wrap.
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
            default                  => throw new ParseError('Unsupported simple property list object.', 1057),
        };

        return $this->wrapScalar($value);
    }

    /**
     * Parse an integer object.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble encoding the size exponent.
     *
     * @throws ParseError
     */
    private function parseInteger(int $offset, int $info): int
    {
        $size = 1 << $info;
        if ($size === 0) {
            throw new ParseError('Integer object without payload.', 1058);
        }

        if ($size > PHP_INT_SIZE) {
            throw new ParseError(sprintf('Integer object size %d exceeds platform int size.', $size), 1955);
        }

        return $this->reader->readUint($offset + 1, $size);
    }

    /**
     * Parse a real (float/double) object.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble encoding the size exponent (4 or 8 bytes).
     *
     * @throws ParseError
     */
    private function parseReal(int $offset, int $info): float
    {
        $size = 1 << $info;

        if ($size === 4) {
            $bytes = substr($this->reader->data(), $offset + 1, 4);
            if (strlen($bytes) !== 4) {
                throw new ParseError('Incomplete real payload.', 1059);
            }

            return Unpack::float('G', $bytes, 'plist float32');
        }

        if ($size === 8) {
            $bytes = substr($this->reader->data(), $offset + 1, 8);
            if (strlen($bytes) !== 8) {
                throw new ParseError('Incomplete double payload.', 1062);
            }

            return Unpack::float('E', $bytes, 'plist float64');
        }

        throw new ParseError('Unsupported floating point width.', 1065);
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
            throw new ParseError('Date objects must use eight byte payloads.', 1066);
        }

        $payload = substr($this->reader->data(), $offset + 1, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete date payload.', 1067);
        }

        $seconds = Unpack::float('E', $payload, 'plist date');

        // Seconds since 2001-01-01T00:00:00Z
        $totalSeconds = 978307200 + $seconds;

        $timestamp = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%.6F', $totalSeconds),
            new DateTimeZone('UTC')
        );

        if (!$timestamp instanceof DateTimeImmutable) {
            throw new ParseError('Failed to decode date payload.', 1070);
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
        [$size, $header] = $this->reader->readLength($offset, $info);

        $payload = substr($this->reader->data(), $offset + $header, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete data payload.', 1071);
        }

        return $payload;
    }

    /**
     * Parse an ASCII string.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (length or extended length marker).
     *
     * @throws ParseError
     */
    private function parseAscii(int $offset, int $info): string
    {
        [$size, $header] = $this->reader->readLength($offset, $info);

        $payload = substr($this->reader->data(), $offset + $header, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete ASCII string payload.', 1072);
        }

        return $payload;
    }

    /**
     * Parse a UTF-16BE string.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (character count or extended length marker).
     *
     * @throws ParseError
     */
    private function parseUnicode(int $offset, int $info): string
    {
        [$size, $header] = $this->reader->readLength($offset, $info);

        if ($size > intdiv(PHP_INT_MAX, 2)) {
            throw new ParseError('Unicode string character count would overflow byte length.', 1956);
        }

        $byteLength = $size * 2;
        $payload    = substr($this->reader->data(), $offset + $header, $byteLength);
        if (strlen($payload) !== $byteLength) {
            throw new ParseError('Incomplete Unicode string payload.', 1073);
        }

        $decoded = iconv('UTF-16BE', 'UTF-8', $payload);
        if ($decoded === false) {
            throw new ParseError('Failed to decode Unicode string payload.', 1074);
        }

        return $decoded;
    }

    /**
     * Parse a UTF-8 string (non-standard but observed in the wild).
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (length or extended length marker).
     *
     * @throws ParseError
     */
    private function parseUtf8(int $offset, int $info): string
    {
        [$size, $header] = $this->reader->readLength($offset, $info);

        $payload = substr($this->reader->data(), $offset + $header, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete UTF-8 string payload.', 1075);
        }

        // Normalizes/validates UTF-8 input
        $decoded = iconv('UTF-8', 'UTF-8', $payload);
        if ($decoded === false) {
            throw new ParseError('Failed to decode UTF-8 string payload.', 1076);
        }

        return $decoded;
    }

    /**
     * Parse a UID object. Returns int if it fits PHP int, otherwise a decimal string.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (length or extended length marker).
     *
     * @throws ParseError
     */
    private function parseUid(int $offset, int $info): int|string
    {
        [$lengthValue, $header] = $this->reader->readLength($offset, $info);

        // UID special-case: if info != EXTENDED, the info nibble encodes "size - 1"
        if ($info === self::MARKER_INFO_EXTENDED) {
            $size = $lengthValue;
        } else {
            $size   = $lengthValue + 1;
            $header = 1;
        }

        if ($size < 1) {
            throw new ParseError('UID objects must contain at least one byte.', 1077);
        }

        $payloadOffset = $offset + $header;
        if (($payloadOffset + $size) > $this->reader->length()) {
            throw new ParseError('Incomplete UID payload.', 1078);
        }

        if ($size <= PHP_INT_SIZE) {
            $value = $this->reader->readUint($payloadOffset, $size);

            // Handle platform-dependent overflow edge case:
            if ($size === PHP_INT_SIZE && $value < 0) {
                return sprintf('%u', $value);
            }

            return $value;
        }

        $payload = substr($this->reader->data(), $payloadOffset, $size);
        if (strlen($payload) !== $size) {
            throw new ParseError('Incomplete UID payload.', 1079);
        }

        return $this->reader->convertUidPayloadToDecimalString($payload);
    }

    /**
     * Parse an array object.
     *
     * @param int $offset Byte offset in payload.
     * @param int $info   Info nibble (count or extended length marker).
     *
     * @throws ParseError
     */
    private function parseArray(int $offset, int $info): ApplePlistArray
    {
        [$count, $header] = $this->reader->readLength($offset, $info);
        if ($count === 0) {
            return new ApplePlistArray([]);
        }

        if ($count > intdiv(PHP_INT_MAX, $this->objectRefSize)) {
            throw new ParseError('Array element count would overflow reference byte length.', 1957);
        }

        $refsOffset = $offset + $header;
        $bytes      = $count * $this->objectRefSize;
        if (($refsOffset + $bytes) > $this->reader->length()) {
            throw new ParseError('Array references exceed payload bounds.', 1080);
        }

        /** @var BinaryPlistArray $result */
        $result = [];
        for ($idx = 0; $idx < $count; ++$idx) {
            $reference = $this->reader->readUint($refsOffset + ($idx * $this->objectRefSize), $this->objectRefSize);
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
     * @throws ParseError
     */
    private function parseDictionary(int $offset, int $info): ApplePlistDictionary
    {
        [$count, $header] = $this->reader->readLength($offset, $info);
        if ($count === 0) {
            return new ApplePlistDictionary([]);
        }

        if ($count > intdiv(PHP_INT_MAX, $this->objectRefSize * 2)) {
            throw new ParseError('Dictionary element count would overflow reference byte length.', 1958);
        }

        $refsOffset = $offset + $header;
        $bytes      = $count * $this->objectRefSize * 2;
        if (($refsOffset + $bytes) > $this->reader->length()) {
            throw new ParseError('Dictionary references exceed payload bounds.', 1081);
        }

        /** @var list<ApplePlistValueInterface> $keys */
        $keys = [];
        /** @var list<ApplePlistValueInterface> $values */
        $values = [];

        for ($idx = 0; $idx < $count; ++$idx) {
            $keyRef = $this->reader->readUint($refsOffset + ($idx * $this->objectRefSize), $this->objectRefSize);
            $valRef = $this->reader->readUint(
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
            throw new ParseError('Dictionary keys must be strings.', 1082);
        }

        /** @var array<string, ApplePlistValueInterface> $entries */
        $entries = [];
        for ($idx = 0; $idx < $count; ++$idx) {
            /** @var ApplePlistScalar $key */
            $key      = $keys[$idx];
            $keyValue = $key->value();

            if (!is_string($keyValue)) {
                throw new ParseError('Dictionary key must be a string value.', 1083);
            }

            $entries[$keyValue] = $values[$idx];
        }

        return new ApplePlistDictionary($entries);
    }
}
