<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistArray;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistDictionary;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistScalar;
use MagicSunday\ImageMeta\MakerNotes\Apple\BinaryPlistDecoder;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistBinaryReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;
use function count;
use function iconv;
use function implode;
use function pack;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Exercises BinaryPlistDecoder error handling and core decoding paths.
 * It verifies invalid headers, malformed trailers, and corrupted payloads throw ParseError.
 * The suite covers scalar, array, and dictionary node decoding with varied payloads.
 * This ensures binary plist decoding remains strict and reliable.
 *
 * @internal
 */
#[CoversClass(BinaryPlistDecoder::class)]
#[UsesClass(ApplePlistArray::class)]
#[UsesClass(ApplePlistDictionary::class)]
#[UsesClass(ApplePlistScalar::class)]
#[UsesClass(PlistBinaryReader::class)]
final class BinaryPlistDecoderTest extends TestCase
{
    private function decodeSingleObject(string $objectBytes): ApplePlistScalar|ApplePlistArray|ApplePlistDictionary
    {
        $decoder = new BinaryPlistDecoder();

        return $decoder->decode($this->buildPlistWithSingleObject($objectBytes));
    }

    /**
     * Passes an empty payload to the binary plist decoder.
     * Ensures a ParseError is raised for the missing header.
     */
    #[Test]
    public function decodeThrowsOnEmptyPayload(): void
    {
        $decoder = new BinaryPlistDecoder();
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('must not be empty');
        $decoder->decode('');
    }

    /**
     * Supplies a non-bplist string as input.
     * Verifies the decoder rejects unsupported formats with ParseError.
     */
    #[Test]
    public function decodeThrowsOnUnsupportedFormat(): void
    {
        $decoder = new BinaryPlistDecoder();
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported property list format');
        $decoder->decode('not-a-plist');
    }

    /**
     * Corrupts the trailer so the offset table is reported before the header.
     * Ensures the decoder rejects the invalid offset table position.
     */
    #[Test]
    public function decodeRejectsOffsetTableBeforeHeader(): void
    {
        $decoder = new BinaryPlistDecoder();

        $object  = chr(0x50 | 0x01) . 'A';
        $payload = $this->buildPlistWithSingleObject($object);

        // Replace the trailer's offset-table start with an invalid value before the header.
        $payload = substr($payload, 0, -8) . $this->packUint64BE(0);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('offset table offset is invalid');
        $decoder->decode($payload);
    }

    /**
     * Manipulates the trailer so the offset table overlaps the plist trailer.
     * Verifies the decoder detects the bounds violation and throws ParseError.
     */
    #[Test]
    public function decodeRejectsOffsetTableOverlappingTrailer(): void
    {
        $decoder = new BinaryPlistDecoder();

        $object  = chr(0x50 | 0x01) . 'A';
        $payload = $this->buildPlistWithSingleObject($object);

        // Increase the reported object count so the offset table would overlap the trailer.
        $trailerOffset  = strlen($payload) - 32;
        $trailer        = substr($payload, $trailerOffset);
        $invalidTrailer = substr($trailer, 0, 8)
            . $this->packUint64BE(2)
            . substr($trailer, 16);
        $payload = substr($payload, 0, $trailerOffset) . $invalidTrailer;

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('offset table exceeds payload bounds');
        $decoder->decode($payload);
    }

    /**
     * Corrupts the offset table entry to point beyond the object table range.
     * Ensures the decoder reports the invalid object offset with ParseError.
     */
    #[Test]
    public function decodeRejectsObjectOffsetOutsideTableRange(): void
    {
        $decoder = new BinaryPlistDecoder();

        $object  = chr(0x50 | 0x01) . 'A';
        $payload = $this->buildPlistWithSingleObject($object);

        $offsetTableStart = strlen($payload) - 32 - 1; // single 1-byte entry
        $payload          = substr($payload, 0, $offsetTableStart)
            . chr($offsetTableStart + 10)
            . substr($payload, $offsetTableStart + 1);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('outside of the object table range');
        $decoder->decode($payload);
    }

    /**
     * Sets the top object index to a value that exceeds the object count.
     * Confirms the decoder rejects the out-of-range top-level index.
     */
    #[Test]
    public function decodeRejectsTopObjectIndexOutOfRange(): void
    {
        $decoder = new BinaryPlistDecoder();

        $object  = chr(0x50 | 0x01) . 'A';
        $payload = $this->buildPlistWithSingleObject($object);

        // Set top object index to 1 while only a single object exists.
        $trailerOffset  = strlen($payload) - 32;
        $trailer        = substr($payload, $trailerOffset);
        $invalidTrailer = substr($trailer, 0, 16)
            . $this->packUint64BE(1)
            . substr($trailer, 24);
        $payload = substr($payload, 0, $trailerOffset) . $invalidTrailer;

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Top level object index is out of range');
        $decoder->decode($payload);
    }

    /**
     * Encodes a short ASCII string as a single object plist.
     * Ensures the decoder returns an ApplePlistScalar with the string value.
     */
    #[Test]
    public function decodeAsciiString(): void
    {
        // Single ASCII string "Hi" (type=5, len=2) → marker 0x52 + payload
        $object = chr(0x50 | 0x02) . 'Hi';
        $result = $this->decodeSingleObject($object);
        self::assertInstanceOf(ApplePlistScalar::class, $result);
        self::assertSame('Hi', $result->value());
    }

    /**
     * Encodes a 1-byte integer object in a minimal plist.
     * Verifies the decoder returns an ApplePlistScalar with the integer value.
     */
    #[Test]
    public function decodeInteger(): void
    {
        // Integer 42 with 1-byte payload: marker 0x10 + payload 0x2A
        $object = chr(0x10) . chr(0x2A);
        $result = $this->decodeSingleObject($object);
        self::assertInstanceOf(ApplePlistScalar::class, $result);
        self::assertSame(42, $result->value());
    }

    /**
     * Encodes a date value with zero seconds since the 2001-01-01 epoch.
     * Confirms the decoder returns the correct ISO-8601 timestamp.
     */
    #[Test]
    public function decodeDateEpoch(): void
    {
        // Date mit 8-Byte double Sekunden seit 2001-01-01, hier 0.0
        $object = chr(0x30 | 0x03) . pack('E', 0.0); // marker 0x33
        $result = $this->decodeSingleObject($object);
        self::assertInstanceOf(ApplePlistScalar::class, $result);
        // Keine Mikrosekunden in der Ausgabe bei .000000
        self::assertSame('2001-01-01T00:00:00+00:00', $result->value());
    }

    /**
     * Encodes a date value with +60 seconds relative to the 2001-01-01 epoch.
     * Ensures the decoded timestamp reflects the expected minute offset.
     */
    #[Test]
    public function decodeDateNonZeroSeconds(): void
    {
        // +60.0 Sekunden nach 2001-01-01T00:00:00Z -> 00:01:00 (ohne Mikrosekunden)
        $object = chr(0x30 | 0x03) . pack('E', 60.0);
        $result = $this->decodeSingleObject($object);
        self::assertInstanceOf(ApplePlistScalar::class, $result);
        self::assertSame('2001-01-01T00:01:00+00:00', $result->value());
    }

    /**
     * Encodes a date value with -60 seconds relative to the 2001-01-01 epoch.
     * Verifies the decoder returns a timestamp before the epoch.
     */
    #[Test]
    public function decodeDateNegativeSeconds(): void
    {
        // -60.0 Sekunden relativ zu 2001-01-01T00:00:00Z => 2000-12-31T23:59:00Z
        $object = chr(0x30 | 0x03) . pack('E', -60.0);
        $result = $this->decodeSingleObject($object);
        self::assertInstanceOf(ApplePlistScalar::class, $result);
        self::assertSame('2000-12-31T23:59:00+00:00', $result->value());
    }

    /**
     * Encodes a date value with sub-second precision after the epoch.
     * Confirms the decoder preserves microseconds in the output string.
     */
    #[Test]
    public function decodeDateSubSecondAfterEpoch(): void
    {
        // +0.5 Sekunden nach 2001-01-01T00:00:00Z -> 00:00:00.500000 (Mikrosekunden bleiben erhalten)
        $object = chr(0x30 | 0x03) . pack('E', 0.5);
        $result = $this->decodeSingleObject($object);
        self::assertInstanceOf(ApplePlistScalar::class, $result);
        self::assertSame('2001-01-01T00:00:00.500000+00:00', $result->value());
    }

    /**
     * Encodes a date value with sub-second precision before the epoch.
     * Ensures the decoder returns a timestamp with preserved microseconds.
     */
    #[Test]
    public function decodeDateSubSecondBeforeEpoch(): void
    {
        // -0.5 Sekunden vor 2001-01-01T00:00:00Z -> 2000-12-31T23:59:59.500000 (Mikrosekunden bleiben erhalten)
        $object = chr(0x30 | 0x03) . pack('E', -0.5);
        $result = $this->decodeSingleObject($object);
        self::assertInstanceOf(ApplePlistScalar::class, $result);
        self::assertSame('2000-12-31T23:59:59.500000+00:00', $result->value());
    }

    /**
     * Encodes a UTF-16BE string payload in a binary plist.
     * Confirms the decoder converts it to a Unicode PHP string.
     */
    #[Test]
    public function decodeUnicodeString(): void
    {
        // Unicode string "Ä" (U+00C4) in UTF-16BE => 0x00 0xC4, length (chars) = 1
        $utf16  = iconv('UTF-8', 'UTF-16BE', 'Ä');
        $object = chr(0x60 | 0x01) . $utf16;
        $result = $this->decodeSingleObject($object);
        self::assertInstanceOf(ApplePlistScalar::class, $result);
        self::assertSame('Ä', $result->value());
    }

    /**
     * Encodes a UID value larger than the native PHP integer range.
     * Ensures the decoder returns the UID as a string representation.
     */
    #[Test]
    public function decodeUidLargerThanPhpIntSize(): void
    {
        // 9-byte UID: 0x01 00 00 00 00 00 00 00 00  => 2^64 = 18446744073709551616
        $uidBytes = "\x01" . str_repeat("\x00", 8);
        $marker   = 0x80 | (9 - 1); // size-1 in info nibble
        $object   = chr($marker) . $uidBytes;

        $result = $this->decodeSingleObject($object);

        self::assertInstanceOf(ApplePlistScalar::class, $result);
        self::assertSame('18446744073709551616', $result->value());
    }

    /**
     * Builds an array object referencing an integer and an ASCII string.
     * Verifies the decoder returns an ApplePlistArray with ordered elements.
     */
    #[Test]
    public function decodeArrayOfValues(): void
    {
        $decoder = new BinaryPlistDecoder();

        // Objects:
        // 0: Array [ ref 1, ref 2 ]
        // 1: Integer 1
        // 2: ASCII "Hi"
        $int1  = chr(0x10) . chr(0x01);
        $ascii = chr(0x50 | 0x02) . 'Hi';
        $array = $this->buildArrayObject(
            [1, 2]
        ); // objectRefSize=1

        $payload = $this->buildPlistWithObjects([$array, $int1, $ascii], 0);

        $result = $decoder->decode($payload);
        self::assertInstanceOf(ApplePlistArray::class, $result);

        $values = $result->values();
        self::assertCount(2, $values);

        self::assertInstanceOf(ApplePlistScalar::class, $values[0]);
        self::assertSame(1, $values[0]->value());

        self::assertInstanceOf(ApplePlistScalar::class, $values[1]);
        self::assertSame('Hi', $values[1]->value());
    }

    /**
     * Builds a dictionary object with two string keys and integer values.
     * Ensures the decoder returns an ApplePlistDictionary with the expected entries.
     */
    #[Test]
    public function decodeDictionaryWithTwoEntries(): void
    {
        $decoder = new BinaryPlistDecoder();

        // Objects:
        // 0: Dict { "A": 1, "B": 2 }
        // 1: Key "A"
        // 2: Key "B"
        // 3: Int 1
        // 4: Int 2
        $keyA = chr(0x50 | 0x01) . 'A';
        $keyB = chr(0x50 | 0x01) . 'B';
        $int1 = chr(0x10) . chr(0x01);
        $int2 = chr(0x10) . chr(0x02);
        $dict = $this->buildDictionaryObject(
            [
                1,
                2,
            ],
            [
                3,
                4,
            ]
        ); // objectRefSize=1

        $payload = $this->buildPlistWithObjects([$dict, $keyA, $keyB, $int1, $int2], 0);

        $result = $decoder->decode($payload);
        self::assertInstanceOf(ApplePlistDictionary::class, $result);

        $map = $result->entries();
        self::assertArrayHasKey('A', $map);
        self::assertArrayHasKey('B', $map);

        self::assertInstanceOf(ApplePlistScalar::class, $map['A']);
        self::assertSame(1, $map['A']->value());

        self::assertInstanceOf(ApplePlistScalar::class, $map['B']);
        self::assertSame(2, $map['B']->value());
    }

    // -------------------------
    // Helpers to build test plists
    // -------------------------
    /**
     * Build a minimal valid binary plist with exactly one object as top-level.
     * The single offset table entry points to the object right after the header.
     *
     * Layout:
     *   [0..7]   header ("bplist00")
     *   [8..x]   single object bytes
     *   [x+1]    offset table (1 byte)
     *   [tail]   32-byte trailer
     *
     * @param string $objectBytes The serialized object bytes.
     */
    private function buildPlistWithSingleObject(string $objectBytes): string
    {
        $header        = 'bplist00';
        $objectOffset  = strlen($header);                  // always 8
        $offsetTblPos  = $objectOffset + strlen($objectBytes);
        $offsetIntSize = 1;                                 // 1 byte offsets
        $objectRefSize = 1;                                 // 1 byte refs
        $numObjects    = 1;
        $topObjectIdx  = 0;

        // Offset table with one entry: start of object (8)
        $offsetTable = chr($objectOffset);

        // Trailer (32 bytes)
        $trailer = str_repeat("\x00", 6)
            . chr($offsetIntSize)
            . chr($objectRefSize)
            . $this->packUint64BE($numObjects)
            . $this->packUint64BE($topObjectIdx)
            . $this->packUint64BE($offsetTblPos);

        return $header . $objectBytes . $offsetTable . $trailer;
    }

    /**
     * Build a plist with multiple objects; offsets are computed sequentially.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param array<int,string> $objects  Object bytes in object-index order.
     * @param int               $topIndex Index of the top-level object.
     */
    private function buildPlistWithObjects(array $objects, int $topIndex): string
    {
        $header = 'bplist00';

        $offsets = [];
        $cursor  = strlen($header);

        foreach ($objects as $bytes) {
            $offsets[] = $cursor;
            $cursor += strlen($bytes);
        }

        $offsetTableStart = $cursor;

        // We only need 1-byte offsets for these tiny payloads.
        $offsetIntSize = 1;
        $objectRefSize = 1;
        $numObjects    = count($objects);

        $offsetTable = '';

        foreach ($offsets as $off) {
            // Tests are designed to keep offsets < 256
            $offsetTable .= chr($off);
        }

        $trailer = str_repeat("\x00", 6)
            . chr($offsetIntSize)
            . chr($objectRefSize)
            . $this->packUint64BE($numObjects)
            . $this->packUint64BE($topIndex)
            . $this->packUint64BE($offsetTableStart);

        return $header . implode('', $objects) . $offsetTable . $trailer;
    }

    /**
     * Detects circular reference: array at object 0 that references itself.
     */
    #[Test]
    public function rejectsCircularReference(): void
    {
        // Object 0 is an array referencing object 0 (itself)
        $array = $this->buildArrayObject([0]);
        $plist = $this->buildPlistWithObjects([$array], 0);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1953);
        $this->expectExceptionMessage('Circular reference');

        (new BinaryPlistDecoder())->decode($plist);
    }

    /**
     * Detects recursion depth exceeding the limit of 64.
     */
    #[Test]
    public function rejectsDeepNesting(): void
    {
        // Build 65 array objects: each references the next, the last references a scalar
        $objects = [];

        for ($i = 0; $i < 65; ++$i) {
            $objects[] = $this->buildArrayObject([$i + 1]);
        }

        // Object 65 is a simple integer (value 42)
        $objects[] = "\x10\x2A";

        $plist = $this->buildPlistWithObjects($objects, 0);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1954);
        $this->expectExceptionMessage('Recursion depth');

        (new BinaryPlistDecoder())->decode($plist);
    }

    /**
     * Build an Array object with inline count (<= 15) and 1-byte references.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param list<int> $refIndices Object indices referenced by the array.
     */
    private function buildArrayObject(array $refIndices): string
    {
        $count  = count($refIndices);
        $marker = 0xA0 | $count;
        $bytes  = chr($marker);

        foreach ($refIndices as $idx) {
            $bytes .= chr($idx); // objectRefSize == 1 in tests
        }

        return $bytes;
    }

    /**
     * Build a Dictionary object with inline count (<= 15) and 1-byte references.
     * Layout after marker: [keyRefs...][valueRefs...].
     *
     * @param list<int> $keyRefIndices
     * @param list<int> $valueRefIndices
     */
    private function buildDictionaryObject(array $keyRefIndices, array $valueRefIndices): string
    {
        $count  = count($keyRefIndices);
        $marker = 0xD0 | $count;
        $bytes  = chr($marker);

        foreach ($keyRefIndices as $idx) {
            $bytes .= chr($idx);
        }

        foreach ($valueRefIndices as $idx) {
            $bytes .= chr($idx);
        }

        return $bytes;
    }

    /**
     * Tolerates padding bytes between the offset table and the trailer.
     */
    #[Test]
    public function toleratesPaddingBetweenOffsetTableAndTrailer(): void
    {
        $header       = 'bplist00';
        $objectBytes  = "\x08"; // boolean false
        $objectOffset = strlen($header); // 8
        $offsetTable  = chr($objectOffset); // 1 byte: offset to object 0

        $padding = "\xFF\xFF"; // 2 bytes of padding

        $offsetTableStart = $objectOffset + strlen($objectBytes); // 9
        $trailer          = str_repeat("\x00", 6)
            . chr(1) // offsetIntSize
            . chr(1) // objectRefSize
            . $this->packUint64BE(1) // numObjects
            . $this->packUint64BE(0) // topObjectIndex
            . $this->packUint64BE($offsetTableStart);

        $plist = $header . $objectBytes . $offsetTable . $padding . $trailer;

        $this->expectNotToPerformAssertions();

        (new BinaryPlistDecoder())->decode($plist);
    }

    /**
     * Pack an unsigned 64-bit integer (big-endian) using portable formats.
     * This checks the behavior for the specific inputs used in the test.
     */
    private function packUint64BE(int $value): string
    {
        $high = ($value >> 32) & 0xFFFFFFFF;
        $low  = $value & 0xFFFFFFFF;

        return pack('NN', $high, $low);
    }
}
