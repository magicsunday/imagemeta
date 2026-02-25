<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Parse\IsoBmff\AudioSampleEntryParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxType;
use MagicSunday\ImageMeta\Tests\Core\CreatesTempStream;
use MagicSunday\ImageMeta\Tests\Helpers\IsoBmffBoxTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;

/**
 * Tests AudioSampleEntryParser srat (SamplingRateBox) FullBox offset handling.
 *
 * ISO/IEC 14496-12 §12.2.1 — SamplingRateBox is a FullBox, so the
 * sampling rate value sits at offset 12 (after size+type+version/flags),
 * not at offset 8.
 *
 * @internal
 */
#[CoversClass(AudioSampleEntryParser::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(BoxType::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(Unpack::class)]
final class AudioSampleEntryParserTest extends TestCase
{
    use CreatesTempStream;
    use IsoBmffBoxTrait;

    /**
     * Sampling rate from a properly formed srat FullBox is extracted at the correct offset.
     *
     * ISO/IEC 14496-12 §12.2.1 — SamplingRateBox is a FullBox:
     * size(4) + type(4) + version(1) + flags(3) + samplingRate(4) = 16 bytes.
     */
    #[Test]
    public function extractsSamplingRateFromSratFullBox(): void
    {
        $expectedRate = 96000;

        // Build version-1 audio sample entry specific fields
        $entryFields = pack('n', 1)          // version = 1
            . pack('n', 0)                   // revision level
            . pack('N', 0)                   // vendor
            . pack('n', 2)                   // channels
            . pack('n', 16)                  // sample size (bits)
            . pack('n', 0)                   // compression ID
            . pack('n', 0)                   // packet size
            . pack('N', 44100 << 16)         // sample rate 16.16 fixed
            . pack('N', 1024)                // samplesPerPacket
            . pack('N', 0)                   // bytesPerPacket
            . pack('N', 0)                   // bytesPerFrame
            . pack('N', 0);                  // bytesPerSample

        // Append srat FullBox: size(4) + type(4) + version/flags(4) + samplingRate(4) = 16
        $sratBox = $this->fullBox('srat', pack('N', $expectedRate));
        $payload = $entryFields . $sratBox;

        $stream = new Stream($this->createTempStream($payload), strlen($payload));
        $win    = new StreamWindow($stream, 0, strlen($payload));

        $parser = new AudioSampleEntryParser();
        $result = $parser->parseSoundSampleEntry(
            $win,
            0,
            strlen($payload),
            strlen($payload) + 16,
            'mp4a',
            1,
        );

        self::assertSame($expectedRate, $result['sampleRate']);
    }
}
