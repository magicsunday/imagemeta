<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;

use function in_array;
use function ord;
use function sprintf;
use function strlen;
use function substr;

/**
 * Parses Exif audio APP2 segments and validates their headers.
 *
 * EXIF 3.0 §4.7.3 defines the APP2 audio stream format, including the
 * four-byte sample rate and two-byte version fields honoured here.
 */
final class JpegAudioSegmentParser implements SegmentAssemblerInterface
{
    /**
     * Header prefix for Exif audio APP2 payloads (EXIF 3.0 §4.7.3).
     */
    public const string AUDIO_SIGNATURE   = "Exif\0\0Audio";

    private const int AUDIO_HEADER_LENGTH = 24;

    /** @var list<JpegAudioStream> */
    private array $streams                = [];

    /**
     * Processes one Exif audio APP2 segment.
     *
     * @param string $payload Raw segment payload including signature.
     * @param int    $offset  Offset in the stream where the marker begins.
     *
     * @throws ParseError When the audio segment header is invalid.
     */
    public function handleSegment(string $payload, int $offset): void
    {
        PayloadGuard::ensureMinimumLength($payload, self::AUDIO_HEADER_LENGTH, sprintf('Audio segment at offset %d', $offset), 1269);

        $signatureLength    = strlen(self::AUDIO_SIGNATURE);
        $major              = ord($payload[$signatureLength]);
        $minor              = ord($payload[$signatureLength + 1]);

        // Validate audio version compatibility per EXIF 3.0 §5.2
        if ($major !== 1) {
            throw new ParseError(
                sprintf('Audio segment at offset %d uses unsupported major version %d', $offset, $major),
                1452,
            );
        }

        $formatCode         = ord($payload[$signatureLength + 2]);
        $channels           = ord($payload[$signatureLength + 3]);

        $sampleRate         = Unpack::int('N', substr($payload, $signatureLength + 4, 4), 'audio sample rate');
        $bitDepth           = ord($payload[$signatureLength + 8]);

        $sampleCount        = Unpack::int('N', substr($payload, $signatureLength + 9, 4), 'audio sample count');
        $data               = substr($payload, self::AUDIO_HEADER_LENGTH);

        if (($channels === 0) || ($channels > 2)) {
            throw new ParseError(sprintf('Audio segment at offset %d has unsupported channel count %d', $offset, $channels), 1272);
        }

        $format             = JpegAudioFormat::tryFrom($formatCode);

        if (!$format instanceof JpegAudioFormat) {
            throw new ParseError(sprintf('Audio segment at offset %d uses unknown format %d', $offset, $formatCode), 1275);
        }

        // Format-aware sampling rate validation per EXIF 3.0 §5.4.1
        $allowedSampleRates = $format->allowedSampleRates();

        if (!in_array($sampleRate, $allowedSampleRates, true)) {
            throw new ParseError(sprintf('Audio segment at offset %d uses unsupported sample rate %d', $offset, $sampleRate), 1273);
        }

        $formatName         = $format->label();

        // Allow PCM 24-bit sample size per EXIF 3.0 §5.4.2
        if (($format === JpegAudioFormat::Pcm) && (!in_array($bitDepth, [8, 16, 24], true))) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid PCM bit depth %d', $offset, $bitDepth), 1276);
        }

        if (($format === JpegAudioFormat::MuLaw) && ($bitDepth !== 8)) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid μ-law bit depth %d', $offset, $bitDepth), 1277);
        }

        if (($format === JpegAudioFormat::ImaAdpcm) && ($bitDepth !== 4)) {
            throw new ParseError(sprintf('Audio segment at offset %d has invalid IMA-ADPCM bit depth %d', $offset, $bitDepth), 1278);
        }

        if ($format !== JpegAudioFormat::ImaAdpcm) {
            $bytesPerSample = (int) (($bitDepth / 8) * $channels);

            if ($bytesPerSample > 0) {
                $expectedLength = $sampleCount * $bytesPerSample;

                if ($expectedLength !== strlen($data)) {
                    throw new ParseError(sprintf('Audio segment at offset %d has inconsistent data length', $offset), 1279);
                }
            }
        }

        // Non-empty IMA-ADPCM payload with dwSampleLength=0 is semantically inconsistent
        if (($format === JpegAudioFormat::ImaAdpcm) && ($sampleCount === 0) && ($data !== '')) {
            throw new ParseError(sprintf('Audio segment at offset %d has non-empty IMA-ADPCM payload with zero sample count', $offset), 1883);
        }

        $version            = sprintf('%d.%02d', $major, $minor);

        $this->streams[]    = new JpegAudioStream(
            $formatName,
            $channels,
            $sampleRate,
            $bitDepth,
            $data,
            $version,
        );
    }

    /**
     * Returns all parsed audio streams.
     *
     * @return list<JpegAudioStream>
     */
    public function getStreams(): array
    {
        return $this->streams;
    }

    /**
     * No-op: audio segments are self-contained and require no deferred assembly.
     */
    public function finalise(): void
    {
    }

    /**
     * Resets all audio state for a fresh parse pass.
     */
    public function reset(): void
    {
        $this->streams = [];
    }
}
