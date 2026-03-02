<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core\Util;

use MagicSunday\ImageMeta\Core\Util\ProtobufDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;

/**
 * Tests the ProtobufDecoder utility for decoding Protocol Buffers wire format.
 */
#[CoversClass(ProtobufDecoder::class)]
final class ProtobufDecoderTest extends TestCase
{
    #[Test]
    public function readVarintDecodesSingleByte(): void
    {
        // varint 1 = 0x01
        $result = ProtobufDecoder::readVarint("\x01", 0);

        self::assertNotNull($result);
        self::assertSame(1, $result[0]);
        self::assertSame(1, $result[1]);
    }

    #[Test]
    public function readVarintDecodesMultiByte(): void
    {
        // varint 300 = 0xAC 0x02
        $result = ProtobufDecoder::readVarint("\xAC\x02", 0);

        self::assertNotNull($result);
        self::assertSame(300, $result[0]);
        self::assertSame(2, $result[1]);
    }

    #[Test]
    public function readVarintReturnsNullOnTruncatedInput(): void
    {
        // High bit set but no continuation byte
        $result = ProtobufDecoder::readVarint("\x80", 0);

        self::assertNull($result);
    }

    #[Test]
    public function readVarintRespectsOffset(): void
    {
        // Two bytes of padding, then varint 42 (0x2A)
        $result = ProtobufDecoder::readVarint("\x00\x00\x2A", 2);

        self::assertNotNull($result);
        self::assertSame(42, $result[0]);
        self::assertSame(1, $result[1]);
    }

    #[Test]
    public function readVarintReturnsNullOnEmptyInput(): void
    {
        self::assertNull(ProtobufDecoder::readVarint('', 0));
    }

    #[Test]
    public function parseFieldsDecodesVarintField(): void
    {
        // field 1, wire type 0 (varint), value = 150
        // tag byte: (1 << 3) | 0 = 0x08
        // varint 150: 0x96 0x01
        $data = "\x08\x96\x01";

        $fields = ProtobufDecoder::parseFields($data);

        self::assertCount(1, $fields);
        self::assertSame(1, $fields[0]['field']);
        self::assertSame(0, $fields[0]['wireType']);
        self::assertSame(150, $fields[0]['value']);
    }

    #[Test]
    public function parseFieldsDecodesFixed32Field(): void
    {
        // field 2, wire type 5 (fixed32)
        // tag byte: (2 << 3) | 5 = 0x15
        $floatBytes = pack('g', 29.97);
        $data       = "\x15" . $floatBytes;

        $fields = ProtobufDecoder::parseFields($data);

        self::assertCount(1, $fields);
        self::assertSame(2, $fields[0]['field']);
        self::assertSame(5, $fields[0]['wireType']);
        self::assertSame($floatBytes, $fields[0]['value']);
    }

    #[Test]
    public function parseFieldsDecodesFixed64Field(): void
    {
        // field 3, wire type 1 (fixed64)
        // tag byte: (3 << 3) | 1 = 0x19
        $doubleBytes = pack('e', 0.894425);
        $data        = "\x19" . $doubleBytes;

        $fields = ProtobufDecoder::parseFields($data);

        self::assertCount(1, $fields);
        self::assertSame(3, $fields[0]['field']);
        self::assertSame(1, $fields[0]['wireType']);
        self::assertSame($doubleBytes, $fields[0]['value']);
    }

    #[Test]
    public function parseFieldsDecodesLengthDelimitedField(): void
    {
        // field 4, wire type 2 (length-delimited), value = "DJI FC8671"
        // tag byte: (4 << 3) | 2 = 0x22
        // length: 10 = 0x0A
        $data = "\x22\x0ADJI FC8671";

        $fields = ProtobufDecoder::parseFields($data);

        self::assertCount(1, $fields);
        self::assertSame(4, $fields[0]['field']);
        self::assertSame(2, $fields[0]['wireType']);
        self::assertSame('DJI FC8671', $fields[0]['value']);
    }

    #[Test]
    public function parseFieldsDecodesMultipleFields(): void
    {
        // field 4 (string "DJI") + field 5 (fixed32 float) + field 6 (varint 100)
        $data = "\x22\x03DJI"          // field 4, wire 2, len 3
              . "\x2D" . pack('g', 30.0) // field 5, wire 5, fixed32
              . "\x30\x64";             // field 6, wire 0, varint 100

        $fields = ProtobufDecoder::parseFields($data);

        self::assertCount(3, $fields);
        self::assertSame('DJI', $fields[0]['value']);
        self::assertSame(5, $fields[1]['field']);
        self::assertSame(100, $fields[2]['value']);
    }

    #[Test]
    public function parseFieldsReturnsEmptyForEmptyInput(): void
    {
        self::assertSame([], ProtobufDecoder::parseFields(''));
    }

    #[Test]
    public function parseFieldsStopsAtFieldNumberZero(): void
    {
        // field 0 is invalid in protobuf — parser should stop
        $data = "\x00";

        self::assertSame([], ProtobufDecoder::parseFields($data));
    }

    #[Test]
    public function parseFieldsStopsAtExcessiveFieldNumber(): void
    {
        // field 501 is beyond our heuristic limit
        // tag byte: (501 << 3) | 0 = 4008 → varint: 0xA8 0x1F
        $data = "\xA8\x1F\x01"; // field 501, wire 0, value 1

        self::assertSame([], ProtobufDecoder::parseFields($data));
    }

    #[Test]
    public function parseFieldsRespectsEndBoundary(): void
    {
        // Two fields but limit to parsing only first
        $data = "\x08\x01" // field 1, varint 1
              . "\x10\x02"; // field 2, varint 2

        $fields = ProtobufDecoder::parseFields($data, 0, 2);

        self::assertCount(1, $fields);
        self::assertSame(1, $fields[0]['value']);
    }

    #[Test]
    public function fieldsCoverBytesReturnsTrueForValidProtobuf(): void
    {
        $data   = "\x08\x01\x10\x02"; // two varint fields
        $fields = ProtobufDecoder::parseFields($data);

        self::assertTrue(ProtobufDecoder::fieldsCoverBytes($fields));
    }

    #[Test]
    public function fieldsCoverBytesReturnsFalseForEmptyFields(): void
    {
        self::assertFalse(ProtobufDecoder::fieldsCoverBytes([]));
    }

    #[Test]
    public function fieldsCoverBytesReturnsFalseForSingleField(): void
    {
        $fields = [['field' => 1, 'wireType' => 0, 'value' => 1]];

        self::assertFalse(ProtobufDecoder::fieldsCoverBytes($fields));
    }

    #[Test]
    public function fieldsCoverBytesReturnsFalseForExcessiveFieldNumbers(): void
    {
        $fields = [
            ['field' => 1, 'wireType' => 0, 'value' => 1],
            ['field' => 501, 'wireType' => 0, 'value' => 2],
        ];

        self::assertFalse(ProtobufDecoder::fieldsCoverBytes($fields));
    }
}
