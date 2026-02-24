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
use MagicSunday\ImageMeta\Parse\Jpeg\FlashPixStreamAssembler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;
use function iconv;
use function pack;
use function str_repeat;

/**
 * Exercises the cumulative size limit of the FlashPix stream assembler.
 */
#[CoversClass(FlashPixStreamAssembler::class)]
#[UsesClass(ParseError::class)]
final class FlashPixStreamAssemblerTest extends TestCase
{
    /**
     * Rejects FlashPix stream data whose cumulative size exceeds the configured limit.
     */
    #[Test]
    public function rejectsCumulativeStreamSizeExceedingLimit(): void
    {
        $assembler = new FlashPixStreamAssembler(
            maxContentEntries: 10,
            maxStreamSize: 1_000_000,
            maxFlashPixTotalSize: 100,
        );

        // Build a contents-list segment with one stream entry of size 200
        $contentsList = $this->buildContentsListPayload(200);
        $assembler->handleSegment($contentsList, 0);

        // First stream data chunk: 60 bytes at offset 0 — cumulative 60
        $streamData1 = $this->buildStreamDataPayload(0, 1, 2, 0, str_repeat('A', 60));
        $assembler->handleSegment($streamData1, 100);

        // Second stream data chunk: 60 bytes at offset 60 — cumulative 120 > limit 100
        $streamData2 = $this->buildStreamDataPayload(0, 2, 2, 60, str_repeat('B', 60));

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1948);

        $assembler->handleSegment($streamData2, 200);
    }

    /**
     * Builds a complete FPXR contents-list payload with one stream entry.
     *
     * EXIF 3.0 §4.7.3.3–4: "FPXR" + NUL + version + entry-count(2B) + entries.
     *
     * @param int $entitySize Declared stream size for the single entry.
     */
    private function buildContentsListPayload(int $entitySize): string
    {
        $nameUtf16 = iconv('UTF-8', 'UTF-16LE', '/stream0');
        assert($nameUtf16 !== false);

        $entry = pack('N', $entitySize)  // entity size (4 bytes BE)
            . chr(0)                     // default byte
            . $nameUtf16                 // UTF-16LE name
            . "\x00\x00";               // NUL terminator (UTF-16LE)

        $body = pack('n', 1) . $entry;  // entry count = 1

        return "FPXR\x00\x00" . $body;
    }

    /**
     * Builds a complete FPXR stream-data payload.
     *
     * EXIF 3.0 §4.7.3.5: "FPXR" + NUL + version + index(2B) + seq(2B) + count(2B) + offset(4B) + data.
     */
    private function buildStreamDataPayload(int $index, int $seqNumber, int $seqCount, int $streamOffset, string $data): string
    {
        $body = pack('n', $index)
            . pack('n', $seqNumber)
            . pack('n', $seqCount)
            . pack('N', $streamOffset)
            . $data;

        return "FPXR\x00\x00" . $body;
    }
}
