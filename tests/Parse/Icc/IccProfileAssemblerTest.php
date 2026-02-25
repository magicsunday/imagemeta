<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Icc;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Parse\Icc\IccProfileAssembler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Tests IccProfileAssembler chunk concatenation and size-limit enforcement.
 *
 * ICC.1:2022 §B.4 — JPEG APP2 multi-segment embedding mechanism.
 *
 * @internal
 */
#[CoversClass(IccProfileAssembler::class)]
final class IccProfileAssemblerTest extends TestCase
{
    /**
     * Chunks within the configured limit are concatenated and returned.
     */
    #[Test]
    public function assemblesChunksWithinSizeLimit(): void
    {
        $assembler = new IccProfileAssembler(maxSize: 1024);

        $assembler->addChunk('foo');
        $assembler->addChunk('bar');
        $assembler->finalise();

        self::assertSame('foobar', $assembler->getProfile());
    }

    /**
     * finalise() returns null when no chunks have been added.
     */
    #[Test]
    public function returnsNullWhenNoChunksAdded(): void
    {
        $assembler = new IccProfileAssembler(maxSize: 1024);
        $assembler->finalise();

        self::assertNull($assembler->getProfile());
    }

    /**
     * Concatenated chunks exceeding the configured limit throw ParseError.
     *
     * A crafted image with many ICC chunks could exhaust memory if no size guard
     * is applied before or during concatenation.
     */
    #[Test]
    public function throwsWhenConcatenatedSizeExceedsLimit(): void
    {
        $assembler = new IccProfileAssembler(maxSize: 50);

        $assembler->addChunk(str_repeat('X', 30));
        $assembler->addChunk(str_repeat('Y', 30));

        $this->expectException(ParseError::class);
        $assembler->finalise();
    }

    /**
     * reset() clears chunks and assembled profile so the assembler can be reused.
     */
    #[Test]
    public function resetClearsState(): void
    {
        $assembler = new IccProfileAssembler(maxSize: 1024);

        $assembler->addChunk('abc');
        $assembler->finalise();
        $assembler->reset();
        $assembler->finalise();

        self::assertNull($assembler->getProfile());
    }
}
