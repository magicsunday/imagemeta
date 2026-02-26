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
use function count;
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
     * Tolerates trailing bytes in a FlashPix contents-list payload.
     */
    #[Test]
    public function toleratesTrailingBytesInContentsList(): void
    {
        $assembler = new FlashPixStreamAssembler(
            maxContentEntries: 10,
            maxStreamSize: 1_000_000,
            maxFlashPixTotalSize: 100_000,
        );

        // Build a valid contents-list payload and append trailing bytes
        $contentsList = $this->buildContentsListPayload(200) . "\x00\x00\x00";

        $assembler->handleSegment($contentsList, 0);

        // Finalise should succeed without error
        $assembler->finalise();
        self::assertSame([], $assembler->getStreams());
    }

    /**
     * Tolerates a FlashPix contents-list entry whose name does not start with '/'.
     */
    #[Test]
    public function itToleratesInvalidNamePrefix(): void
    {
        $assembler = new FlashPixStreamAssembler(
            maxContentEntries: 10,
            maxStreamSize: 1_000_000,
            maxFlashPixTotalSize: 100_000,
        );

        // Build a contents-list with a name that lacks the leading '/'
        $contentsList = $this->buildContentsListPayloadWithName(200, 'stream0');
        $assembler->handleSegment($contentsList, 0);

        // Stream data for the entry should be accepted
        $streamData = $this->buildStreamDataPayload(0, 1, 1, 0, str_repeat('X', 10));
        $assembler->handleSegment($streamData, 100);

        $assembler->finalise();
        self::assertSame([0 => str_repeat('X', 10) . str_repeat("\x00", 190)], $assembler->getStreams());
    }

    /**
     * Tolerates a FlashPix contents-list entry with an empty name.
     */
    #[Test]
    public function itToleratesEmptyEntryName(): void
    {
        $assembler = new FlashPixStreamAssembler(
            maxContentEntries: 10,
            maxStreamSize: 1_000_000,
            maxFlashPixTotalSize: 100_000,
        );

        // Build a contents-list with an empty name (just the NUL terminator)
        $contentsList = $this->buildContentsListPayloadWithEmptyName(200);
        $assembler->handleSegment($contentsList, 0);

        // Stream data for the entry should be accepted
        $streamData = $this->buildStreamDataPayload(0, 1, 1, 0, str_repeat('Y', 10));
        $assembler->handleSegment($streamData, 100);

        $assembler->finalise();
        self::assertSame([0 => str_repeat('Y', 10) . str_repeat("\x00", 190)], $assembler->getStreams());
    }

    /**
     * Tolerates oversized FlashPix contents-list entries and skips their stream assembly.
     */
    #[Test]
    public function itToleratesFlashPixStreamEntryExceedingMaximumSize(): void
    {
        $assembler = new FlashPixStreamAssembler(
            maxContentEntries: 10,
            maxStreamSize: 100,
            maxFlashPixTotalSize: 100_000,
        );

        $contentsList = $this->buildContentsListPayloadWithEntries([
            ['size' => 200, 'defaultByte' => 0, 'name' => '/too-big'],
            ['size' => 8, 'defaultByte' => 0, 'name' => '/ok'],
        ]);
        $assembler->handleSegment($contentsList, 0);

        // Oversized stream entry must be tolerated and skipped instead of aborting parsing.
        $assembler->handleSegment($this->buildStreamDataPayload(0, 1, 1, 0, 'ignored'), 100);
        $assembler->handleSegment($this->buildStreamDataPayload(1, 1, 1, 0, 'ABCDEFGH'), 120);

        $assembler->finalise();

        self::assertSame([1 => 'ABCDEFGH'], $assembler->getStreams());
    }

    /**
     * Tolerates truncated contents-list entries and skips malformed tails.
     */
    #[Test]
    public function itToleratesTruncatedFlashPixContentsEntry(): void
    {
        $assembler = new FlashPixStreamAssembler(
            maxContentEntries: 10,
            maxStreamSize: 1_000_000,
            maxFlashPixTotalSize: 100_000,
        );

        $payload = "FPXR\x00\x00"
            . pack('n', 1)
            . "\x00\x00\x00\x10";

        $assembler->handleSegment($payload, 0);
        $assembler->finalise();

        self::assertSame([], $assembler->getStreams());
    }

    /**
     * Tolerates unterminated UTF-16LE contents-list names and skips malformed entries.
     */
    #[Test]
    public function itToleratesUnterminatedFlashPixContentsName(): void
    {
        $assembler = new FlashPixStreamAssembler(
            maxContentEntries: 10,
            maxStreamSize: 1_000_000,
            maxFlashPixTotalSize: 100_000,
        );

        $nameUtf16 = iconv('UTF-8', 'UTF-16LE', '/unterminated');
        assert($nameUtf16 !== false);

        $payload = "FPXR\x00\x00"
            . pack('n', 1)
            . pack('N', 32)
            . chr(0)
            . $nameUtf16;

        $assembler->handleSegment($payload, 0);
        $assembler->finalise();

        self::assertSame([], $assembler->getStreams());
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
        return $this->buildContentsListPayloadWithName($entitySize, '/stream0');
    }

    /**
     * Builds a complete FPXR contents-list payload with one stream entry and a custom name.
     *
     * @param int    $entitySize Declared stream size for the single entry.
     * @param string $name       UTF-8 name for the contents-list entry.
     */
    private function buildContentsListPayloadWithName(int $entitySize, string $name): string
    {
        $nameUtf16 = iconv('UTF-8', 'UTF-16LE', $name);
        assert($nameUtf16 !== false);

        $entry = pack('N', $entitySize)  // entity size (4 bytes BE)
            . chr(0)                     // default byte
            . $nameUtf16                 // UTF-16LE name
            . "\x00\x00";               // NUL terminator (UTF-16LE)

        $body = pack('n', 1) . $entry;  // entry count = 1

        return "FPXR\x00\x00" . $body;
    }

    /**
     * Builds a complete FPXR contents-list payload with multiple stream entries.
     *
     * @param list<array{size:int, defaultByte:int, name:string}> $entries
     */
    private function buildContentsListPayloadWithEntries(array $entries): string
    {
        $body = pack('n', count($entries));

        foreach ($entries as $entry) {
            $nameUtf16 = iconv('UTF-8', 'UTF-16LE', $entry['name']);
            assert($nameUtf16 !== false);

            $body .= pack('N', $entry['size'])
                . chr($entry['defaultByte'])
                . $nameUtf16
                . "\x00\x00";
        }

        return "FPXR\x00\x00" . $body;
    }

    /**
     * Builds a complete FPXR contents-list payload with one stream entry whose name is empty.
     *
     * The name field contains only the UTF-16LE NUL terminator with no preceding code units.
     *
     * @param int $entitySize Declared stream size for the single entry.
     */
    private function buildContentsListPayloadWithEmptyName(int $entitySize): string
    {
        $entry = pack('N', $entitySize)  // entity size (4 bytes BE)
            . chr(0)                     // default byte
            . "\x00\x00";               // NUL terminator only (empty name)

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
