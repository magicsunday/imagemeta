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
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegAudioSegmentParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;
use function pack;
use function str_repeat;

/**
 * Exercises the Exif audio APP2 segment parser for valid and invalid audio headers.
 *
 * EXIF 3.0 section 4.7.3 defines the APP2 audio stream format including
 * sample rate, version, channel, format, and bit depth validation.
 *
 * @internal
 */
#[CoversClass(JpegAudioSegmentParser::class)]
#[UsesClass(JpegAudioStream::class)]
#[UsesClass(PayloadGuard::class)]
#[UsesClass(Unpack::class)]
final class JpegAudioSegmentParserTest extends TestCase
{
    private const string AUDIO_SIGNATURE = "Exif\0\0Audio";

    /**
     * Parses a valid PCM mono audio segment with 8 kHz sample rate and 16-bit depth.
     */
    #[Test]
    public function parsesValidPcmMonoAudioSegment(): void
    {
        $sampleCount = 4;
        $channels    = 1;
        $bitDepth    = 16;
        $audioData   = str_repeat("\x00\x01", $sampleCount);

        $payload = self::AUDIO_SIGNATURE
            . chr(1)                            // major version
            . chr(0)                            // minor version
            . chr(0)                            // format: PCM
            . chr($channels)                    // channels: mono
            . pack('N', 8000)                   // sample rate: 8 kHz
            . chr($bitDepth)                    // bit depth: 16
            . pack('N', $sampleCount)           // sample count
            . $audioData;

        $parser = new JpegAudioSegmentParser();
        $parser->handleSegment($payload, 0);

        $streams = $parser->getStreams();
        self::assertCount(1, $streams);
        self::assertSame('PCM', $streams[0]->format);
        self::assertSame(1, $streams[0]->channels);
        self::assertSame(8000, $streams[0]->sampleRate);
        self::assertSame(16, $streams[0]->bitDepth);
        self::assertSame($audioData, $streams[0]->data);
        self::assertSame('1.00', $streams[0]->version);
    }

    /**
     * Parses a valid mu-law audio segment.
     */
    #[Test]
    public function parsesValidMuLawAudioSegment(): void
    {
        $sampleCount = 8;
        $channels    = 1;
        $bitDepth    = 8;
        $audioData   = str_repeat("\x7F", $sampleCount);

        $payload = self::AUDIO_SIGNATURE
            . chr(1) . chr(0)                   // version 1.00
            . chr(1)                            // format: MU_LAW
            . chr($channels)
            . pack('N', 8000)
            . chr($bitDepth)
            . pack('N', $sampleCount)
            . $audioData;

        $parser = new JpegAudioSegmentParser();
        $parser->handleSegment($payload, 0);

        $streams = $parser->getStreams();
        self::assertCount(1, $streams);
        self::assertSame('MU_LAW_PCM', $streams[0]->format);
        self::assertSame(8, $streams[0]->bitDepth);
    }

    /**
     * Throws ParseError when payload is shorter than the minimum audio header length.
     */
    #[Test]
    public function throwsWhenPayloadTooShort(): void
    {
        $payload = self::AUDIO_SIGNATURE . chr(1);

        $parser = new JpegAudioSegmentParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1269);

        $parser->handleSegment($payload, 0);
    }

    /**
     * Throws ParseError when major version is not 1.
     */
    #[Test]
    public function throwsWhenMajorVersionIsUnsupported(): void
    {
        $payload = self::AUDIO_SIGNATURE
            . chr(2) . chr(0)                   // unsupported major version
            . chr(0) . chr(1)
            . pack('N', 8000)
            . chr(16)
            . pack('N', 0);

        $parser = new JpegAudioSegmentParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1452);

        $parser->handleSegment($payload, 0);
    }

    /**
     * Throws ParseError when channel count is zero.
     */
    #[Test]
    public function throwsWhenChannelCountIsZero(): void
    {
        $payload = self::AUDIO_SIGNATURE
            . chr(1) . chr(0)
            . chr(0)                            // format: PCM
            . chr(0)                            // zero channels
            . pack('N', 8000)
            . chr(16)
            . pack('N', 0);

        $parser = new JpegAudioSegmentParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1272);

        $parser->handleSegment($payload, 0);
    }

    /**
     * Throws ParseError when sample rate is not in the allowed set for the format.
     */
    #[Test]
    public function throwsWhenSampleRateIsUnsupported(): void
    {
        $payload = self::AUDIO_SIGNATURE
            . chr(1) . chr(0)
            . chr(0)                            // format: PCM
            . chr(1)                            // mono
            . pack('N', 7777)                   // unsupported sample rate
            . chr(16)
            . pack('N', 0);

        $parser = new JpegAudioSegmentParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1273);

        $parser->handleSegment($payload, 0);
    }

    /**
     * Throws ParseError when PCM bit depth is not 8, 16, or 24.
     */
    #[Test]
    public function throwsWhenPcmBitDepthIsInvalid(): void
    {
        $payload = self::AUDIO_SIGNATURE
            . chr(1) . chr(0)
            . chr(0)                            // format: PCM
            . chr(1)                            // mono
            . pack('N', 8000)
            . chr(32)                           // invalid bit depth for PCM
            . pack('N', 0);

        $parser = new JpegAudioSegmentParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1276);

        $parser->handleSegment($payload, 0);
    }

    /**
     * Throws ParseError when data length does not match expected sample count for PCM.
     */
    #[Test]
    public function throwsWhenPcmDataLengthIsInconsistent(): void
    {
        $payload = self::AUDIO_SIGNATURE
            . chr(1) . chr(0)
            . chr(0)                            // format: PCM
            . chr(1)                            // mono
            . pack('N', 8000)
            . chr(16)                           // 16-bit = 2 bytes per sample
            . pack('N', 4)                      // 4 samples expected = 8 bytes
            . "\x00\x01\x02";                   // only 3 bytes of data

        $parser = new JpegAudioSegmentParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1279);

        $parser->handleSegment($payload, 0);
    }

    /**
     * Resets state between parse passes.
     */
    #[Test]
    public function resetClearsAllStreams(): void
    {
        $sampleCount = 2;
        $audioData   = str_repeat("\x00\x01", $sampleCount);

        $payload = self::AUDIO_SIGNATURE
            . chr(1) . chr(0)
            . chr(0) . chr(1)
            . pack('N', 8000)
            . chr(16)
            . pack('N', $sampleCount)
            . $audioData;

        $parser = new JpegAudioSegmentParser();
        $parser->handleSegment($payload, 0);

        self::assertCount(1, $parser->getStreams());

        $parser->reset();

        self::assertCount(0, $parser->getStreams());
    }
}
