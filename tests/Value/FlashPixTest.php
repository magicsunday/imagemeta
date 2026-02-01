<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\FlashPix;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the FlashPix value object for embedded stream payloads.
 * It verifies that stream maps keyed by identifier are preserved intact.
 * The suite covers empty maps to ensure optional data remains nullable.
 * This keeps FlashPix extension data stable for structured output.
 */
#[CoversClass(FlashPix::class)]
final class FlashPixTest extends TestCase
{
    /**
     * Stores FlashPix streams when provided.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithStreams(): void
    {
        $flashPix = new FlashPix(
            streams: [
                1 => 'stream_data_1',
                2 => 'stream_data_2',
            ],
        );

        self::assertSame([1 => 'stream_data_1', 2 => 'stream_data_2'], $flashPix->streams);
    }

    /**
     * Allows empty FlashPix stream maps.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function constructsWithEmptyStreams(): void
    {
        $flashPix = new FlashPix(streams: []);

        self::assertSame([], $flashPix->streams);
    }

    /**
     * Preserves stream keys alongside payloads.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function preservesStreamKeys(): void
    {
        $flashPix = new FlashPix(
            streams: [
                10 => 'metadata',
                20 => 'preview',
                30 => 'summary',
            ],
        );

        self::assertArrayHasKey(10, $flashPix->streams);
        self::assertArrayHasKey(20, $flashPix->streams);
        self::assertArrayHasKey(30, $flashPix->streams);
        self::assertSame('metadata', $flashPix->streams[10]);
        self::assertSame('preview', $flashPix->streams[20]);
        self::assertSame('summary', $flashPix->streams[30]);
    }
}
