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
use MagicSunday\ImageMeta\Parse\Jpeg\ExtendedXmpAssembler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_repeat;

/**
 * Exercises the cumulative size limits of the ExtendedXMP assembler.
 */
#[CoversClass(ExtendedXmpAssembler::class)]
#[UsesClass(ParseError::class)]
final class ExtendedXmpAssemblerTest extends TestCase
{
    private const string SIGNATURE = "http://ns.adobe.com/xmp/extension/\0";

    /**
     * Rejects an ExtendedXMP segment whose declared totalLength exceeds the configured limit.
     */
    #[Test]
    public function rejectsTotalLengthExceedingLimit(): void
    {
        $assembler = new ExtendedXmpAssembler(
            32,
            static function (string $packet): void {},
            maxExtendedXmpSize: 100,
        );

        $guid        = str_repeat('A', 32);
        $totalLength = 200;
        $chunkOffset = 0;
        $chunkData   = str_repeat('X', 50);
        $payload     = self::SIGNATURE . $guid . pack('N', $totalLength) . pack('N', $chunkOffset) . $chunkData;

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1946);

        $assembler->handleSegment($payload, 0);
    }

    /**
     * Returns null when xmpNote:HasExtendedXMP is present but the GUID is malformed.
     */
    #[Test]
    public function returnsNullWhenHasExtendedXmpGuidIsMalformed(): void
    {
        $assembler = new ExtendedXmpAssembler(
            32,
            static function (string $packet): void {},
        );

        $packet = '<x:xmpmeta><rdf:RDF xmpNote:HasExtendedXMP="NOT-A-VALID-GUID"/></rdf:RDF></x:xmpmeta>';

        $result = $assembler->extractGuidFromPacket($packet, 0);
        self::assertNull($result);
    }

    /**
     * Rejects ExtendedXMP chunks whose cumulative size exceeds the configured limit
     * even when the declared totalLength passes the initial check.
     *
     * Overlapping chunks can accumulate more raw bytes than totalLength declares;
     * the cumulative guard catches this before finalise() detects the overlap.
     */
    #[Test]
    public function rejectsCumulativeChunkSizeExceedingLimit(): void
    {
        $assembler = new ExtendedXmpAssembler(
            32,
            static function (string $packet): void {},
            maxExtendedXmpSize: 100,
        );

        $guid        = str_repeat('A', 32);
        $totalLength = 100;

        // First chunk: 60 bytes at offset 0 — cumulative 60
        $chunk1  = str_repeat('X', 60);
        $payload = self::SIGNATURE . $guid . pack('N', $totalLength) . pack('N', 0) . $chunk1;
        $assembler->handleSegment($payload, 0);

        // Second chunk: 60 bytes at offset 30 (overlapping) — cumulative 120 > limit 100
        $chunk2  = str_repeat('Y', 60);
        $payload = self::SIGNATURE . $guid . pack('N', $totalLength) . pack('N', 30) . $chunk2;

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1947);

        $assembler->handleSegment($payload, 100);
    }
}
