<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Parse\Jpeg\JumbfTransportParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;
use function substr;

/**
 * Exercises APP11 JUMBF transport stream reassembly and XMP packet extraction.
 *
 * EXIF 3.0 section 4.7.5.3 defines the APP11 transport wrapper for JUMBF
 * superboxes carrying annotation metadata.
 *
 * @internal
 */
#[CoversClass(JumbfTransportParser::class)]
#[UsesClass(PayloadGuard::class)]
#[UsesClass(Unpack::class)]
final class JumbfTransportParserTest extends TestCase
{
    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    /**
     * Reassembles a single-chunk JUMBF superbox containing an xml box with XMP payload.
     */
    #[Test]
    public function reassemblesSingleChunkJumbfWithXmpPayload(): void
    {
        $xmpContent = '<x:xmpmeta xmlns:x="adobe:ns:meta/">test</x:xmpmeta>';

        $xmlBoxPayload = self::XMP_SIGNATURE . $xmpContent;
        $xmlBox        = pack('N', strlen($xmlBoxPayload) + 8) . 'xml ' . $xmlBoxPayload;
        $jumbfBox      = pack('N', strlen($xmlBox) + 8) . 'jumb' . $xmlBox;

        $transportPayload = 'JP'                        // CI identifier (4 bytes)
            . "\x00\x00"                                // padding to reach 4-byte CI
            . pack('n', 1)                              // instance number
            . pack('N', 1)                              // sequence number
            . $jumbfBox;

        /** @var list<string> $collectedPackets */
        $collectedPackets = [];
        $parser           = new JumbfTransportParser(function (string $packet) use (&$collectedPackets): void {
            $collectedPackets[] = $packet;
        });

        $parser->handleSegment($transportPayload, 0);
        $parser->finalise();

        self::assertCount(1, $collectedPackets);
        self::assertSame($xmpContent, $collectedPackets[0]);
    }

    /**
     * Reassembles multi-chunk JUMBF superbox from two APP11 segments.
     */
    #[Test]
    public function reassemblesMultiChunkJumbfSuperbox(): void
    {
        $xmpContent = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">data</rdf:RDF>';

        $xmlBoxPayload = $xmpContent;
        $xmlBox        = pack('N', strlen($xmlBoxPayload) + 8) . 'xml ' . $xmlBoxPayload;
        $jumbfBox      = pack('N', strlen($xmlBox) + 8) . 'jumb' . $xmlBox;

        $splitPoint = (int) (strlen($jumbfBox) / 2);
        $chunk1     = substr($jumbfBox, 0, $splitPoint);
        $chunk2     = substr($jumbfBox, $splitPoint);

        $segment1 = 'JP' . "\x00\x00" . pack('n', 1) . pack('N', 1) . $chunk1;
        $segment2 = 'JP' . "\x00\x00" . pack('n', 1) . pack('N', 2) . $chunk2;

        /** @var list<string> $collectedPackets */
        $collectedPackets = [];
        $parser           = new JumbfTransportParser(function (string $packet) use (&$collectedPackets): void {
            $collectedPackets[] = $packet;
        });

        $parser->handleSegment($segment1, 0);
        $parser->handleSegment($segment2, 100);
        $parser->finalise();

        self::assertCount(1, $collectedPackets);
        self::assertSame($xmpContent, $collectedPackets[0]);
    }

    /**
     * Ignores payloads that do not start with "JP" identifier.
     */
    #[Test]
    public function ignoresNonJpPayload(): void
    {
        $payload = 'XX' . "\x00\x00" . pack('n', 1) . pack('N', 1) . 'data';

        /** @var list<string> $collectedPackets */
        $collectedPackets = [];
        $parser           = new JumbfTransportParser(function (string $packet) use (&$collectedPackets): void {
            $collectedPackets[] = $packet;
        });

        $parser->handleSegment($payload, 0);
        $parser->finalise();

        self::assertCount(0, $collectedPackets);
    }

    /**
     * Throws ParseError when the transport header is too short.
     */
    #[Test]
    public function throwsWhenTransportHeaderTooShort(): void
    {
        $payload = 'JP' . "\x00\x00";

        /** @var list<string> $collectedPackets */
        $collectedPackets = [];
        $parser           = new JumbfTransportParser(function (string $packet) use (&$collectedPackets): void {
            $collectedPackets[] = $packet;
        });

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1331);

        $parser->handleSegment($payload, 0);
    }

    /**
     * Throws ParseError when instance number is zero.
     */
    #[Test]
    public function throwsWhenInstanceNumberIsZero(): void
    {
        $payload = 'JP' . "\x00\x00"
            . pack('n', 0)                              // invalid instance number
            . pack('N', 1)
            . 'data';

        /** @var list<string> $collectedPackets */
        $collectedPackets = [];
        $parser           = new JumbfTransportParser(function (string $packet) use (&$collectedPackets): void {
            $collectedPackets[] = $packet;
        });

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1335);

        $parser->handleSegment($payload, 0);
    }

    /**
     * Throws ParseError when sequence number is zero.
     */
    #[Test]
    public function throwsWhenSequenceNumberIsZero(): void
    {
        $payload = 'JP' . "\x00\x00"
            . pack('n', 1)
            . pack('N', 0)                              // invalid sequence number
            . 'data';

        /** @var list<string> $collectedPackets */
        $collectedPackets = [];
        $parser           = new JumbfTransportParser(function (string $packet) use (&$collectedPackets): void {
            $collectedPackets[] = $packet;
        });

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1336);

        $parser->handleSegment($payload, 0);
    }

    /**
     * Throws ParseError for duplicate sequence numbers on the same instance.
     */
    #[Test]
    public function throwsForDuplicateSequenceNumber(): void
    {
        $segment = 'JP' . "\x00\x00" . pack('n', 1) . pack('N', 1) . 'data';

        /** @var list<string> $collectedPackets */
        $collectedPackets = [];
        $parser           = new JumbfTransportParser(function (string $packet) use (&$collectedPackets): void {
            $collectedPackets[] = $packet;
        });

        $parser->handleSegment($segment, 0);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1338);

        $parser->handleSegment($segment, 100);
    }

    /**
     * Throws ParseError when instance metadata is inconsistent across chunks.
     */
    #[Test]
    public function throwsForInconsistentInstanceMetadata(): void
    {
        $segment1 = 'JP' . "\x00\x00" . pack('n', 1) . pack('N', 1) . 'data';
        // Same instance number but different CI identifier
        $segment2 = 'JP' . "\x00\x01" . pack('n', 1) . pack('N', 2) . 'more';

        /** @var list<string> $collectedPackets */
        $collectedPackets = [];
        $parser           = new JumbfTransportParser(function (string $packet) use (&$collectedPackets): void {
            $collectedPackets[] = $packet;
        });

        $parser->handleSegment($segment1, 0);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1337);

        $parser->handleSegment($segment2, 100);
    }

    /**
     * Throws ParseError when a sequence number gap exists during finalisation.
     */
    #[Test]
    public function throwsForMissingSequenceNumberDuringFinalise(): void
    {
        // Provide sequence 1 and 3, but skip 2
        $segment1 = 'JP' . "\x00\x00" . pack('n', 1) . pack('N', 1) . 'aaaa';
        $segment3 = 'JP' . "\x00\x00" . pack('n', 1) . pack('N', 3) . 'cccc';

        /** @var list<string> $collectedPackets */
        $collectedPackets = [];
        $parser           = new JumbfTransportParser(function (string $packet) use (&$collectedPackets): void {
            $collectedPackets[] = $packet;
        });

        $parser->handleSegment($segment1, 0);
        $parser->handleSegment($segment3, 200);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1339);

        $parser->finalise();
    }
}
