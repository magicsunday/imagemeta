<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Jpeg;

use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the JpegAudioStream value object for embedded EXIF audio stream data.
 * It verifies construction and property access for audio metadata fields.
 */
#[CoversClass(JpegAudioStream::class)]
final class JpegAudioStreamTest extends TestCase
{
    /**
     * Constructs an audio stream and verifies all properties are preserved.
     */
    #[Test]
    public function constructionPreservesProperties(): void
    {
        $stream = new JpegAudioStream(
            format: 'PCM',
            channels: 2,
            sampleRate: 44100,
            bitDepth: 16,
            data: "\x00\x01\x02\x03",
            version: '1.0',
        );

        self::assertSame('PCM', $stream->format);
        self::assertSame(2, $stream->channels);
        self::assertSame(44100, $stream->sampleRate);
        self::assertSame(16, $stream->bitDepth);
        self::assertSame("\x00\x01\x02\x03", $stream->data);
        self::assertSame('1.0', $stream->version);
    }

    /**
     * Accepts empty strings and zero values as edge cases.
     * Audio streams may carry zero-length data or unknown formats.
     */
    #[Test]
    public function acceptsEmptyAndZeroEdgeCases(): void
    {
        $stream = new JpegAudioStream(
            format: '',
            channels: 0,
            sampleRate: 0,
            bitDepth: 0,
            data: '',
            version: '',
        );

        self::assertSame('', $stream->format);
        self::assertSame(0, $stream->channels);
        self::assertSame(0, $stream->sampleRate);
        self::assertSame(0, $stream->bitDepth);
        self::assertSame('', $stream->data);
        self::assertSame('', $stream->version);
    }
}
