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
 * Tests for the Video value object.
 */
#[CoversClass(Video::class)]
final class VideoTest extends TestCase
{
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
            transferFunction: null,
            colorPrimaries: null,
        );

        self::assertSame(120.5, $video->durationSec);
        self::assertSame(30.0, $video->frameRate);
        self::assertSame(1920, $video->width);
        self::assertSame(1080, $video->height);
    }

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
            transferFunction: null,
            colorPrimaries: null,
        );

        self::assertNull($video->durationSec);
        self::assertNull($video->frameRate);
        self::assertNull($video->width);
        self::assertNull($video->codec);
        self::assertFalse($video->hdr);
    }
}
