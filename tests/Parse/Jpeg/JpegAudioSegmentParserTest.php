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
     * Builds an EXIF audio APP2 segment payload with configurable header fields.
     */
    private function buildAudioPayload(
        int $majorVersion = 1,
        int $minorVersion = 0,
        int $format = 0,
        int $channels = 1,
        int $sampleRate = 8000,
        int $bitDepth = 16,
        int $sampleCount = 0,
        string $audioData = '',
    ): string {
        return self::AUDIO_SIGNATURE
            . chr($majorVersion)
            . chr($minorVersion)
            . chr($format)
            . chr($channels)
            . pack('N', $sampleRate)
            . chr($bitDepth)
            . pack('N', $sampleCount)
            . $audioData;
    }

    /**
     * Parses a valid PCM mono audio segment with 8 kHz sample rate and 16-bit depth.
     */
    #[Test]
    public function parsesValidPcmMonoAudioSegment(): void
    {
        $sampleCount = 4;
        $audioData   = str_repeat("\x00\x01", $sampleCount);

        $payload     = $this->buildAudioPayload(sampleCount: $sampleCount, audioData: $audioData);

        $parser      = new JpegAudioSegmentParser();
        $parser->handleSegment($payload, 0);

        $streams     = $parser->getStreams();
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
        $audioData   = str_repeat("\x7F", $sampleCount);

        $payload     = $this->buildAudioPayload(format: 1, bitDepth: 8, sampleCount: $sampleCount, audioData: $audioData);

        $parser      = new JpegAudioSegmentParser();
        $parser->handleSegment($payload, 0);

        $streams     = $parser->getStreams();
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

        $parser  = new JpegAudioSegmentParser();

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
        $payload = $this->buildAudioPayload(majorVersion: 2);

        $parser  = new JpegAudioSegmentParser();

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
        $payload = $this->buildAudioPayload(channels: 0);

        $parser  = new JpegAudioSegmentParser();

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
        $payload = $this->buildAudioPayload(sampleRate: 7777);

        $parser  = new JpegAudioSegmentParser();

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
        $payload = $this->buildAudioPayload(bitDepth: 32);

        $parser  = new JpegAudioSegmentParser();

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
        $payload = $this->buildAudioPayload(sampleCount: 4, audioData: "\x00\x01\x02");

        $parser  = new JpegAudioSegmentParser();

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

        $payload     = $this->buildAudioPayload(sampleCount: $sampleCount, audioData: $audioData);

        $parser      = new JpegAudioSegmentParser();
        $parser->handleSegment($payload, 0);

        self::assertCount(1, $parser->getStreams());

        $parser->reset();

        self::assertCount(0, $parser->getStreams());
    }
}
