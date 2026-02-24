<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Video;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Video value object for timing, dimensions, and codec metadata.
 * It verifies duration, frame rate, width, and height fields are preserved.
 * The suite covers codec and HDR flags when provided.
 * This keeps video metadata consistent for playback and display.
 */
#[CoversClass(Video::class)]
final class VideoTest extends TestCase
{
    /**
     * Constructs a Video object with basic timing and dimension fields.
     * Verifies the value object preserves the supplied core properties.
     */
    #[Test]
    public function constructsWithBasicVideoInfo(): void
    {
        $video = new Video(
            durationSec: 120.5,
            frameRate: 30.0,
            width: 1920,
            height: 1080,
            codec: null,
            hdr: false,
        );

        self::assertSame(120.5, $video->durationSec);
        self::assertSame(30.0, $video->frameRate);
        self::assertSame(1920, $video->width);
        self::assertSame(1080, $video->height);
    }

    /**
     * Constructs a Video object with HDR-related metadata and codec details.
     * Ensures HDR flags and color metadata are stored as provided.
     */
    #[Test]
    public function constructsWithHDRInfo(): void
    {
        $video = new Video(
            durationSec: 60.0,
            frameRate: 24.0,
            width: 3840,
            height: 2160,
            codec: 'H.265',
            hdr: true,
            transferFunction: 'PQ',
            colorPrimaries: 'BT.2020',
        );

        self::assertSame('H.265', $video->codec);
        self::assertTrue($video->hdr);
        self::assertSame('PQ', $video->transferFunction);
        self::assertSame('BT.2020', $video->colorPrimaries);
    }

    /**
     * Creates a Video object with optional fields set to null.
     * Confirms nulls are preserved while the HDR flag remains false.
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $video = new Video(
            durationSec: null,
            frameRate: null,
            width: null,
            height: null,
            codec: null,
            hdr: false,
        );

        self::assertNull($video->durationSec);
        self::assertNull($video->frameRate);
        self::assertNull($video->width);
        self::assertNull($video->codec);
        self::assertFalse($video->hdr);
    }
}
