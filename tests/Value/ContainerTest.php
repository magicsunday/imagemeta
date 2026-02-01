<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Container value object for file-level format metadata.
 * It verifies format, encoder, bitrate, and codec fields are stored together.
 * The suite covers video and audio codec combinations typical for container formats.
 * This ensures container metadata is preserved for downstream reporting.
 */
#[CoversClass(Container::class)]
final class ContainerTest extends TestCase
{
    /**
     * Stores the container format when provided.
     * It validates the transformation using representative inputs.
     *
     * @return void
     */
    #[Test]
    public function constructsWithFormat(): void
    {
        $container = new Container(
            format: 'JPEG',
            encoder: null,
            bitrate: null,
            videoCodec: null,
            audioCodec: null,
        );

        self::assertSame('JPEG', $container->format);
    }

    /**
     * Stores video container metadata fields together.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithVideoMetadata(): void
    {
        $container = new Container(
            format: 'MP4',
            encoder: 'FFmpeg',
            bitrate: 5000000,
            videoCodec: 'H.264',
            audioCodec: 'AAC',
        );

        self::assertSame('MP4', $container->format);
        self::assertSame('FFmpeg', $container->encoder);
        self::assertSame(5000000, $container->bitrate);
        self::assertSame('H.264', $container->videoCodec);
        self::assertSame('AAC', $container->audioCodec);
    }

    /**
     * Accepts null container metadata values.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $container = new Container(
            format: null,
            encoder: null,
            bitrate: null,
            videoCodec: null,
            audioCodec: null,
        );

        self::assertNull($container->format);
        self::assertNull($container->encoder);
        self::assertNull($container->bitrate);
        self::assertNull($container->videoCodec);
        self::assertNull($container->audioCodec);
    }
}
