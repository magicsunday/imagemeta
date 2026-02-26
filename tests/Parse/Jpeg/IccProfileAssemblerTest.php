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
use MagicSunday\ImageMeta\Parse\Jpeg\IccProfileAssembler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;
use function str_repeat;

/**
 * Tests ICC profile segment assembly, chunk ordering, sequence validation,
 * and maxIccProfileSize enforcement in IccProfileAssembler.
 *
 * ICC.1:2022 section B.4 defines the JPEG APP2 embedding mechanism for ICC profiles.
 *
 * @internal
 */
#[CoversClass(IccProfileAssembler::class)]
#[UsesClass(PayloadGuard::class)]
final class IccProfileAssemblerTest extends TestCase
{
    private const string ICC_SIGNATURE = "ICC_PROFILE\0";

    /**
     * Assembled ICC profile within the configured limit is returned successfully.
     */
    #[Test]
    public function assemblesProfileWithinMaxSize(): void
    {
        $assembler = new IccProfileAssembler(maxIccProfileSize: 1024);

        $data    = str_repeat('A', 100);
        $payload = self::ICC_SIGNATURE . "\x01\x01" . $data;

        $assembler->handleSegment($payload, 0);
        $assembler->finalise();

        self::assertSame($data, $assembler->getProfile());
    }

    /**
     * Assembled ICC profile exceeding the configured limit throws ParseError.
     */
    #[Test]
    public function throwsWhenAssembledProfileExceedsMaxSize(): void
    {
        $assembler = new IccProfileAssembler(maxIccProfileSize: 50);

        $data    = str_repeat('B', 100);
        $payload = self::ICC_SIGNATURE . "\x01\x01" . $data;

        $assembler->handleSegment($payload, 0);

        $this->expectException(ParseError::class);
        $assembler->finalise();
    }

    /**
     * Assembles multi-chunk ICC profile in correct order regardless of arrival order.
     */
    #[Test]
    public function assemblesMultiChunkProfileInOrder(): void
    {
        $assembler = new IccProfileAssembler(maxIccProfileSize: 4096);

        $chunk1 = 'AAAA';
        $chunk2 = 'BBBB';
        $chunk3 = 'CCCC';

        // Deliver chunks out of order: 2, 3, 1
        $assembler->handleSegment(self::ICC_SIGNATURE . chr(2) . chr(3) . $chunk2, 100);
        $assembler->handleSegment(self::ICC_SIGNATURE . chr(3) . chr(3) . $chunk3, 200);
        $assembler->handleSegment(self::ICC_SIGNATURE . chr(1) . chr(3) . $chunk1, 300);
        $assembler->finalise();

        self::assertSame('AAAABBBBCCCC', $assembler->getProfile());
    }

    /**
     * Returns all raw segments in the order they were encountered.
     */
    #[Test]
    public function returnsRawSegmentsInEncounterOrder(): void
    {
        $assembler = new IccProfileAssembler();

        $payload1 = self::ICC_SIGNATURE . chr(1) . chr(2) . 'part1';
        $payload2 = self::ICC_SIGNATURE . chr(2) . chr(2) . 'part2';

        $assembler->handleSegment($payload1, 0);
        $assembler->handleSegment($payload2, 100);

        self::assertSame([$payload1, $payload2], $assembler->getSegments());
    }

    /**
     * Throws ParseError when sequence count is zero.
     */
    #[Test]
    public function throwsWhenSequenceCountIsZero(): void
    {
        $assembler = new IccProfileAssembler();

        $payload = self::ICC_SIGNATURE . chr(1) . chr(0) . 'data';

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1301);

        $assembler->handleSegment($payload, 0);
    }

    /**
     * Throws ParseError when sequence number is out of range.
     */
    #[Test]
    public function throwsWhenSequenceNumberOutOfRange(): void
    {
        $assembler = new IccProfileAssembler();

        // Sequence number 5 with count 3 is out of range
        $payload = self::ICC_SIGNATURE . chr(5) . chr(3) . 'data';

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1302);

        $assembler->handleSegment($payload, 0);
    }

    /**
     * Throws ParseError when sequence number is zero.
     */
    #[Test]
    public function throwsWhenSequenceNumberIsZero(): void
    {
        $assembler = new IccProfileAssembler();

        $payload = self::ICC_SIGNATURE . chr(0) . chr(2) . 'data';

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1302);

        $assembler->handleSegment($payload, 0);
    }

    /**
     * Throws ParseError when two segments disagree on the total chunk count.
     */
    #[Test]
    public function throwsWhenSequenceCountIsInconsistent(): void
    {
        $assembler = new IccProfileAssembler();

        $assembler->handleSegment(self::ICC_SIGNATURE . chr(1) . chr(3) . 'data1', 0);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1303);

        $assembler->handleSegment(self::ICC_SIGNATURE . chr(2) . chr(4) . 'data2', 100);
    }

    /**
     * Throws ParseError when a duplicate sequence number appears.
     */
    #[Test]
    public function throwsWhenDuplicateSequenceNumber(): void
    {
        $assembler = new IccProfileAssembler();

        $assembler->handleSegment(self::ICC_SIGNATURE . chr(1) . chr(2) . 'data1', 0);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1304);

        $assembler->handleSegment(self::ICC_SIGNATURE . chr(1) . chr(2) . 'data2', 100);
    }

    /**
     * Throws ParseError when payload is too short for ICC header bytes.
     */
    #[Test]
    public function throwsWhenPayloadTooShort(): void
    {
        $assembler = new IccProfileAssembler();

        // Only signature, missing sequence/count bytes
        $payload = self::ICC_SIGNATURE . chr(1);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1268);

        $assembler->handleSegment($payload, 0);
    }

    /**
     * Profile is null when not all chunks have been received.
     */
    #[Test]
    public function profileIsNullWhenIncomplete(): void
    {
        $assembler = new IccProfileAssembler();

        // Deliver only chunk 1 of 2
        $assembler->handleSegment(self::ICC_SIGNATURE . chr(1) . chr(2) . 'part1', 0);
        $assembler->finalise();

        self::assertNull($assembler->getProfile());
    }

    /**
     * Reset clears all accumulated state.
     */
    #[Test]
    public function resetClearsAllState(): void
    {
        $assembler = new IccProfileAssembler();

        $assembler->handleSegment(self::ICC_SIGNATURE . chr(1) . chr(1) . 'data', 0);
        $assembler->finalise();

        self::assertSame('data', $assembler->getProfile());

        $assembler->reset();

        self::assertSame([], $assembler->getSegments());
        self::assertNull($assembler->getProfile());
    }
}
