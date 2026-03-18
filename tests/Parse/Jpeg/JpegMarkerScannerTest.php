<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegMarkerScanner;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function strlen;

/**
 * Exercises low-level JPEG marker navigation, segment length validation,
 * and payload reading in JpegMarkerScanner.
 *
 * ITU-T T.81 section B.1.1.2 defines the marker segment structure decoded here.
 *
 * @internal
 */
#[CoversClass(JpegMarkerScanner::class)]
#[UsesClass(Stream::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(JpegParserConfig::class)]
final class JpegMarkerScannerTest extends TestCase
{
    /**
     * Finds a standard JPEG marker and returns its code and byte offset.
     */
    #[Test]
    public function findsNextMarkerAndReturnsCodeWithOffset(): void
    {
        // FF E1 at byte 0 is an APP1 marker
        $data    = "\xFF\xE1";
        $scanner = $this->createScanner($data);

        [$code, $offset] = $scanner->nextMarkerWithOffset();

        self::assertSame(0xE1, $code);
        self::assertSame(0, $offset);
    }

    /**
     * Skips fill bytes (0xFF padding) before the actual marker code.
     *
     * ITU-T T.81 section B.1.1.2 allows any number of 0xFF fill bytes
     * between the marker introducer and the marker code.
     */
    #[Test]
    public function skipsFillBytesBeforeMarkerCode(): void
    {
        // FF FF FF DB: three 0xFF bytes before the DQT marker code
        $data    = "\xFF\xFF\xFF\xDB";
        $scanner = $this->createScanner($data);

        [$code, $offset] = $scanner->nextMarkerWithOffset();

        self::assertSame(0xDB, $code);
        self::assertSame(0, $offset);
    }

    /**
     * Skips stuffed bytes (0xFF00) when intervening bytes are allowed.
     *
     * In entropy-coded data after SOS, byte-stuffed 0xFF00 sequences must
     * be silently skipped during marker scanning.
     */
    #[Test]
    public function skipsStuffedBytesWhenInterveningBytesAllowed(): void
    {
        // FF 00 (stuffed byte) then FF D9 (EOI marker)
        $data    = "\xFF\x00\xFF\xD9";
        $scanner = $this->createScanner($data);

        [$code, $offset] = $scanner->nextMarkerWithOffset();

        self::assertSame(0xD9, $code);
        self::assertSame(2, $offset);
    }

    /**
     * Throws ParseError for stuffed bytes when intervening bytes are not allowed.
     */
    #[Test]
    public function throwsForStuffedByteWhenNotAllowed(): void
    {
        $data    = "\xFF\x00";
        $scanner = $this->createScanner($data);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2056);

        $scanner->nextMarkerWithOffset(false);
    }

    /**
     * Throws ParseError for non-marker bytes when intervening bytes are not allowed.
     */
    #[Test]
    public function throwsForNonMarkerByteWhenNotAllowed(): void
    {
        $data    = "\x42\xFF\xD9";
        $scanner = $this->createScanner($data);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1505);

        $scanner->nextMarkerWithOffset(false);
    }

    /**
     * Throws BoundsError when the stream ends mid-marker (after 0xFF introducer).
     */
    #[Test]
    public function throwsWhenStreamEndsMidMarker(): void
    {
        // Only the marker introducer with no code byte following
        $data    = "\xFF";
        $scanner = $this->createScanner($data);

        $this->expectException(BoundsError::class);

        $scanner->nextMarkerWithOffset();
    }

    /**
     * Reads a valid segment length from a two-byte big-endian field.
     */
    #[Test]
    public function readsValidSegmentLength(): void
    {
        // Two-byte length field: 0x0010 = 16
        $data    = pack('n', 16);
        $scanner = $this->createScanner($data);

        $length = $scanner->readSegmentLength(0xE1, 0, false);

        self::assertSame(16, $length);
    }

    /**
     * Throws ParseError when segment length is less than 2.
     */
    #[Test]
    public function throwsWhenSegmentLengthIsTooSmall(): void
    {
        $data    = pack('n', 1);
        $scanner = $this->createScanner($data);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1265);

        $scanner->readSegmentLength(0xE1, 0, false);
    }

    /**
     * Enforces maximum APP segment size when enabled.
     */
    #[Test]
    public function throwsWhenAppSegmentExceedsMaxPayload(): void
    {
        // Length field = 65535 (payload = 65533 bytes), config limit = 100
        $data    = pack('n', 65535);
        $scanner = $this->createScanner($data, new JpegParserConfig(maxAppSegmentSize: 100));

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1266);

        $scanner->readSegmentLength(0xE1, 0, true);
    }

    /**
     * Reads segment payload bytes successfully.
     */
    #[Test]
    public function readsSegmentPayloadSuccessfully(): void
    {
        $data    = 'HelloWorld';
        $scanner = $this->createScanner($data);

        $payload = $scanner->readSegmentPayload(0xE1, 0, 5);

        self::assertSame('Hello', $payload);
    }

    /**
     * Returns empty string when payload length is zero.
     */
    #[Test]
    public function returnsEmptyStringForZeroLengthPayload(): void
    {
        $scanner = $this->createScanner('');

        $payload = $scanner->readSegmentPayload(0xE1, 0, 0);

        self::assertSame('', $payload);
    }

    /**
     * Throws ParseError when the payload is truncated.
     */
    #[Test]
    public function throwsWhenPayloadIsTruncated(): void
    {
        $data    = 'Hi';
        $scanner = $this->createScanner($data);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1267);

        $scanner->readSegmentPayload(0xE1, 0, 100);
    }

    /**
     * Skips non-marker bytes before the actual marker when allowed.
     */
    #[Test]
    public function skipsInterveningNonMarkerBytes(): void
    {
        // Garbage bytes followed by an APP1 marker
        $data    = "\x01\x02\x03\xFF\xE1";
        $scanner = $this->createScanner($data);

        [$code, $offset] = $scanner->nextMarkerWithOffset(true);

        self::assertSame(0xE1, $code);
        self::assertSame(3, $offset);
    }

    private function createScanner(string $data, ?JpegParserConfig $config = null): JpegMarkerScanner
    {
        $fh = fopen('php://memory', 'rb+');
        self::assertIsResource($fh);

        fwrite($fh, $data);
        rewind($fh);

        $stream = new Stream($fh, strlen($data));

        return new JpegMarkerScanner($stream, $config ?? new JpegParserConfig());
    }
}
