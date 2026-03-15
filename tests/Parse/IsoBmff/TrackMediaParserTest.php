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
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Parse\IsoBmff\AudioSampleEntryParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParseContext;
use MagicSunday\ImageMeta\Parse\IsoBmff\TrackMediaParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\VideoSampleEntryParser;
use MagicSunday\ImageMeta\Tests\Helpers\IsoBmffBoxTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_map;
use function chr;
use function pack;
use function str_repeat;
use function strlen;

/**
 * Tests for the TrackMediaParser, covering parseMvhd, parseHdlr, and parseTrak
 * including all ParseError paths through the nested parsing hierarchy.
 */
#[CoversClass(TrackMediaParser::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(BoxNavigator::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesClass(IsoBmffParseContext::class)]
#[UsesClass(VideoSampleEntryParser::class)]
#[UsesClass(AudioSampleEntryParser::class)]
final class TrackMediaParserTest extends TestCase
{
    use IsoBmffBoxTrait;

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Creates a Stream from raw binary data and returns the parser + BoxDescriptor.
     *
     * @return array{0: TrackMediaParser, 1: BoxDescriptor}
     */
    private function createParserWithDescriptor(string $type, string $content): array
    {
        $contentLength = strlen($content);
        $stream        = $this->createIsoBmffTempStream($content);
        $navigator     = new BoxNavigator($stream);

        $parser = new TrackMediaParser(
            $navigator,
            static function (BoxDescriptor $box, IsoBmffParseContext $ctx): void {},
            static fn (BoxDescriptor $box): array => [],
        );

        $window = $stream->window(0, $contentLength);

        $descriptor = new BoxDescriptor(
            type: $type,
            size: 8 + $contentLength,
            offset: 0,
            contentOffset: 0,
            contentSize: $contentLength,
            window: $window,
            userType: null,
        );

        return [$parser, $descriptor];
    }

    /**
     * Creates a parser for parseTrak tests with the given trak content.
     *
     * @return array{0: TrackMediaParser, 1: BoxDescriptor, 2: IsoBmffParseContext}
     */
    private function createParseTrakSetup(string $trakContent): array
    {
        [$parser, $descriptor] = $this->createParserWithDescriptor('trak', $trakContent);

        return [$parser, $descriptor, new IsoBmffParseContext()];
    }

    // =========================================================================
    // Valid box payload builders
    // =========================================================================

    /**
     * Builds valid v0 mvhd content (100 bytes: version/flags + payload).
     */
    private function validMvhdContent(): string
    {
        return "\x00\x00\x00\x00"           // version(1)=0 + flags(3)=0
            . str_repeat("\x00", 8)         // creation_time(4) + modification_time(4)
            . pack('N', 1000)               // timescale
            . pack('N', 5000)               // duration
            . str_repeat("\x00", 76)        // rate(4)+volume(2)+reserved(10)+matrix(36)+pre_defined(24)
            . pack('N', 1);                 // next_track_ID
    }

    /**
     * Builds valid v1 mvhd content (112 bytes).
     */
    private function validMvhdV1Content(): string
    {
        return "\x01\x00\x00\x00"           // version(1)=1 + flags(3)=0
            . str_repeat("\x00", 16)        // creation_time(8) + modification_time(8)
            . pack('N', 1000)               // timescale
            . str_repeat("\x00", 8)         // duration(8)
            . str_repeat("\x00", 76)        // rate(4)+volume(2)+reserved(10)+matrix(36)+pre_defined(24)
            . pack('N', 1);                 // next_track_ID
    }

    /**
     * Builds valid v0 tkhd box (enabled + in_movie).
     */
    private function validTkhdBox(): string
    {
        $payload = str_repeat("\x00", 8)    // creation(4) + modification(4)
            . pack('N', 1)                  // track_ID
            . str_repeat("\x00", 4)         // reserved32
            . pack('N', 1000)               // duration
            . str_repeat("\x00", 8)         // reserved64
            . str_repeat("\x00", 2)         // layer
            . str_repeat("\x00", 2)         // alt_group
            . str_repeat("\x00", 2)         // volume
            . str_repeat("\x00", 2)         // reserved16
            . str_repeat("\x00", 36)        // matrix
            . pack('N', 1920 << 16)         // width (16.16)
            . pack('N', 1080 << 16);        // height (16.16)

        return $this->fullBox('tkhd', $payload, 0, 3);
    }

    /**
     * Builds valid v0 hdlr box with the given handler type.
     */
    private function validHdlrBox(string $handler = 'vide'): string
    {
        $payload = str_repeat("\x00", 4)    // pre_defined
            . $handler                      // handler_type
            . str_repeat("\x00", 12);       // reserved

        return $this->fullBox('hdlr', $payload, 0, 0);
    }

    /**
     * Builds valid v0 mdhd box with timescale=1000.
     */
    private function validMdhdBox(): string
    {
        $payload = str_repeat("\x00", 8)    // creation(4) + modification(4)
            . pack('N', 1000)               // timescale
            . pack('N', 1000)               // duration
            . str_repeat("\x00", 4);        // language(2) + pre_defined(2)

        return $this->fullBox('mdhd', $payload, 0, 0);
    }

    /**
     * Builds a minimal vmhd box.
     */
    private function validVmhdBox(): string
    {
        return $this->fullBox('vmhd', str_repeat("\x00", 8), 0, 1);
    }

    /**
     * Builds a minimal smhd box.
     */
    private function validSmhdBox(): string
    {
        return $this->fullBox('smhd', str_repeat("\x00", 4), 0, 0);
    }

    /**
     * Builds a minimal nmhd box.
     */
    private function validNmhdBox(): string
    {
        return $this->fullBox('nmhd', '', 0, 0);
    }

    /**
     * Builds a minimal dinf box.
     */
    private function validDinfBox(): string
    {
        return $this->box('dinf', str_repeat("\x00", 8));
    }

    /**
     * Builds valid 70-byte video sample entry data.
     */
    private function validVideoSampleEntryData(): string
    {
        return pack('n', 0)                 // version
            . pack('n', 0)                  // revisionLevel
            . pack('N', 0)                  // vendor
            . pack('N', 0)                  // temporalQuality
            . pack('N', 0)                  // spatialQuality
            . pack('n', 1920)               // width
            . pack('n', 1080)               // height
            . pack('N', 0x00480000)         // hRes (72.0)
            . pack('N', 0x00480000)         // vRes (72.0)
            . pack('N', 0)                  // dataSize
            . pack('n', 1)                  // frameCount
            . "\x00" . str_repeat("\x00", 31) // compressorName
            . pack('n', 24)                 // depth
            . pack('n', 0xFFFF);            // colorTableId (-1)
    }

    /**
     * Builds a valid stsd box for the given handler type.
     */
    private function validStsdBox(string $handler = 'vide'): string
    {
        if ($handler === 'vide') {
            $entry = pack('N', 86) . 'avc1'
                . str_repeat("\x00", 6)
                . pack('n', 1)
                . $this->validVideoSampleEntryData();
        } else {
            // Generic entry for non-video handlers (16-byte minimum entry)
            $entry = pack('N', 16) . 'genr'
                . str_repeat("\x00", 6)
                . pack('n', 1);
        }

        $payload = pack('N', 1) . $entry;

        return $this->fullBox('stsd', $payload, 0, 0);
    }

    /**
     * Builds a minimal stts box.
     */
    private function validSttsBox(): string
    {
        return $this->fullBox('stts', pack('N', 0), 0, 0);
    }

    /**
     * Builds a minimal stsc box.
     */
    private function validStscBox(): string
    {
        return $this->fullBox('stsc', pack('N', 0), 0, 0);
    }

    /**
     * Builds a minimal stsz box.
     */
    private function validStszBox(): string
    {
        return $this->fullBox('stsz', pack('N', 0) . pack('N', 0), 0, 0);
    }

    /**
     * Builds a minimal stco box.
     */
    private function validStcoBox(): string
    {
        return $this->fullBox('stco', pack('N', 0), 0, 0);
    }

    /**
     * Builds valid stbl content for the given handler.
     */
    private function validStblContent(string $handler = 'vide'): string
    {
        return $this->validStsdBox($handler)
            . $this->validSttsBox()
            . $this->validStscBox()
            . $this->validStszBox()
            . $this->validStcoBox();
    }

    /**
     * Builds a valid minf box for the given handler.
     */
    private function validMinfBox(string $handler = 'vide'): string
    {
        $mediaHeader = match ($handler) {
            'vide'  => $this->validVmhdBox(),
            'soun'  => $this->validSmhdBox(),
            default => $this->validNmhdBox(),
        };

        return $this->box('minf', $this->validDinfBox() . $mediaHeader . $this->box('stbl', $this->validStblContent($handler)));
    }

    /**
     * Builds a valid mdia box for the given handler.
     */
    private function validMdiaBox(string $handler = 'vide'): string
    {
        return $this->box('mdia', $this->validHdlrBox($handler) . $this->validMdhdBox() . $this->validMinfBox($handler));
    }

    /**
     * Builds valid trak content (tkhd + mdia).
     */
    private function validTrakContent(): string
    {
        return $this->validTkhdBox() . $this->validMdiaBox();
    }

    // =========================================================================
    // parseMvhd tests
    // =========================================================================

    /**
     * Accepts valid version 0 mvhd box and returns extracted metadata keys.
     */
    #[Test]
    public function parseMvhdAcceptsValidV0(): void
    {
        [$parser, $descriptor] = $this->createParserWithDescriptor('mvhd', $this->validMvhdContent());

        $result = $parser->parseMvhd($descriptor);

        // ISO/IEC 14496-12 §8.2.2: timescale must be returned
        self::assertSame(1000, $result['com.apple.quicktime.timeScale']);
    }

    /**
     * Accepts valid version 1 mvhd box.
     */
    #[Test]
    public function parseMvhdAcceptsValidV1(): void
    {
        [$parser, $descriptor] = $this->createParserWithDescriptor('mvhd', $this->validMvhdV1Content());

        $result = $parser->parseMvhd($descriptor);

        self::assertSame(1000, $result['com.apple.quicktime.timeScale']);
    }

    /**
     * Extracts duration in seconds from the mvhd box.
     *
     * ISO/IEC 14496-12 §8.2.2: duration / timescale gives elapsed time in seconds.
     */
    #[Test]
    public function parseMvhdExtractsDurationInSeconds(): void
    {
        $content = "\x00\x00\x00\x00"        // version=0, flags=0
            . str_repeat("\x00", 8)          // creation_time(4) + modification_time(4)
            . pack('N', 1000)                // timescale = 1000
            . pack('N', 5000)                // duration = 5000 units → 5.0 seconds
            . str_repeat("\x00", 76)         // rate + volume + reserved + matrix + pre_defined
            . pack('N', 1);                  // next_track_ID

        [$parser, $descriptor] = $this->createParserWithDescriptor('mvhd', $content);

        $result = $parser->parseMvhd($descriptor);

        self::assertSame(5.0, $result['com.apple.quicktime.duration']);
    }

    /**
     * Extracts preferredRate decoded from 16.16 fixed-point in the mvhd box.
     *
     * ISO/IEC 14496-12 §8.2.2: rate = 0x00010000 is normal playback speed (1.0).
     */
    #[Test]
    public function parseMvhdExtractsPreferredRate(): void
    {
        $content = "\x00\x00\x00\x00"        // version=0, flags=0
            . str_repeat("\x00", 8)          // creation/modification
            . pack('N', 1000)                // timescale
            . pack('N', 0)                   // duration
            . pack('N', 0x00010000)          // rate = 1.0 (normal playback)
            . str_repeat("\x00", 72)         // volume + reserved + matrix + pre_defined
            . pack('N', 1);

        [$parser, $descriptor] = $this->createParserWithDescriptor('mvhd', $content);

        $result = $parser->parseMvhd($descriptor);

        self::assertSame(1.0, $result['com.apple.quicktime.preferredRate']);
    }

    /**
     * Extracts preferredVolume decoded from 8.8 fixed-point in the mvhd box.
     *
     * ISO/IEC 14496-12 §8.2.2: volume = 0x0100 is full volume (1.0).
     */
    #[Test]
    public function parseMvhdExtractsPreferredVolume(): void
    {
        $content = "\x00\x00\x00\x00"        // version=0, flags=0
            . str_repeat("\x00", 8)          // creation/modification
            . pack('N', 1000)                // timescale
            . pack('N', 0)                   // duration
            . pack('N', 0x00010000)          // rate = 1.0
            . pack('n', 0x0100)              // volume = 1.0 (full volume)
            . str_repeat("\x00", 70)         // reserved + matrix + pre_defined
            . pack('N', 1);

        [$parser, $descriptor] = $this->createParserWithDescriptor('mvhd', $content);

        $result = $parser->parseMvhd($descriptor);

        self::assertSame(1.0, $result['com.apple.quicktime.preferredVolume']);
    }

    /**
     * Extracts formatted creation date from the mvhd box.
     *
     * ISO/IEC 14496-12 §8.2.2: creation_time is seconds since 1904-01-01 00:00:00 UTC.
     */
    #[Test]
    public function parseMvhdExtractsFormattedCreationDate(): void
    {
        // 2025-06-14 09:48:44 UTC in Mac epoch (= Unix 1749894524 + 2082844800)
        $macTs = 1749894524 + 2082844800;

        $content = "\x00\x00\x00\x00"        // version=0, flags=0
            . pack('N', $macTs)              // creation_time
            . pack('N', 0)                   // modification_time
            . pack('N', 1000)                // timescale
            . pack('N', 0)                   // duration
            . str_repeat("\x00", 76)         // rate + volume + reserved + matrix + pre_defined
            . pack('N', 1);

        [$parser, $descriptor] = $this->createParserWithDescriptor('mvhd', $content);

        $result = $parser->parseMvhd($descriptor);

        self::assertSame('2025:06:14 09:48:44', $result['com.apple.quicktime.creationDate']);
    }

    /**
     * Does not include creation date key when creation_time is zero (undefined).
     *
     * ISO/IEC 14496-12 §8.2.2: a value of 0 means "undefined".
     */
    #[Test]
    public function parseMvhdOmitsCreationDateWhenZero(): void
    {
        [$parser, $descriptor] = $this->createParserWithDescriptor('mvhd', $this->validMvhdContent());

        $result = $parser->parseMvhd($descriptor);

        self::assertArrayNotHasKey('com.apple.quicktime.creationDate', $result);
    }

    /**
     * Provides mvhd error test cases.
     *
     * @return array<string, array{string, int}>
     */
    public static function mvhdErrorProvider(): array
    {
        return [
            'truncated under 4 bytes (1906)' => [
                str_repeat("\x00", 3),
                1906,
            ],
            'unsupported version 2 (1908)' => [
                "\x02\x00\x00\x00" . str_repeat("\x00", 108),
                1908,
            ],
            'non-zero flags (1407)' => [
                "\x00\x00\x00\x01" . str_repeat("\x00", 96),
                1407,
            ],
            'v0 truncated payload (1907)' => [
                "\x00\x00\x00\x00" . str_repeat("\x00", 50),
                1907,
            ],
            'v1 truncated payload (1907)' => [
                "\x01\x00\x00\x00" . str_repeat("\x00", 50),
                1907,
            ],
            'zero timescale (1408)' => [
                "\x00\x00\x00\x00"
                . str_repeat("\x00", 8)
                . pack('N', 0)
                . str_repeat("\x00", 84),
                1408,
            ],
            'zero next_track_ID (1409)' => [
                "\x00\x00\x00\x00"
                . str_repeat("\x00", 8)
                . pack('N', 1)
                . str_repeat("\x00", 80)
                . pack('N', 0),
                1409,
            ],
        ];
    }

    /**
     * Rejects invalid mvhd payloads.
     */
    #[Test]
    #[DataProvider('mvhdErrorProvider')]
    public function parseMvhdRejectsInvalidPayload(string $content, int $expectedCode): void
    {
        [$parser, $descriptor] = $this->createParserWithDescriptor('mvhd', $content);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode($expectedCode);

        $parser->parseMvhd($descriptor);
    }

    // =========================================================================
    // parseHdlr tests
    // =========================================================================

    /**
     * Parses a hdlr box with a QuickTime counted-string handler name.
     */
    #[Test]
    public function parseHdlrParsesCountedStringName(): void
    {
        $payload = str_repeat("\x00", 4)    // pre_defined
            . 'vide'                        // handler_type
            . str_repeat("\x00", 12)        // reserved
            . "\x05Hello";                  // counted string: length=5, "Hello"

        [$parser, $descriptor] = $this->createParserWithDescriptor('hdlr', "\x00\x00\x00\x00" . $payload);

        $result = $parser->parseHdlr($descriptor);

        self::assertSame('vide', $result[0]);
        self::assertSame('Hello', $result[1]);
    }

    /**
     * Parses a hdlr box with an ISO NUL-terminated handler name.
     */
    #[Test]
    public function parseHdlrParsesNulTerminatedName(): void
    {
        // countedLen = ord('V') = 86, which exceeds remaining-1, triggering NUL path
        $payload = str_repeat("\x00", 4)    // pre_defined
            . 'vide'                        // handler_type
            . str_repeat("\x00", 12)        // reserved
            . "VideoHandler\x00";           // NUL-terminated

        [$parser, $descriptor] = $this->createParserWithDescriptor('hdlr', "\x00\x00\x00\x00" . $payload);

        $result = $parser->parseHdlr($descriptor);

        self::assertSame('vide', $result[0]);
        self::assertSame('VideoHandler', $result[1]);
    }

    /**
     * Parses a minimal hdlr box without a handler name.
     */
    #[Test]
    public function parseHdlrParsesMinimalBoxWithoutName(): void
    {
        $payload = str_repeat("\x00", 4)    // pre_defined
            . 'vide'                        // handler_type
            . str_repeat("\x00", 12);       // reserved

        [$parser, $descriptor] = $this->createParserWithDescriptor('hdlr', "\x00\x00\x00\x00" . $payload);

        $result = $parser->parseHdlr($descriptor);

        self::assertSame('vide', $result[0]);
        self::assertNull($result[1]);
    }

    /**
     * Provides hdlr error test cases.
     *
     * @return array<string, array{string, int}>
     */
    public static function hdlrErrorProvider(): array
    {
        return [
            'truncated under 24 bytes (1147)' => [
                str_repeat("\x00", 23),
                1147,
            ],
            'unsupported version (1148)' => [
                "\x01\x00\x00\x00" . str_repeat("\x00", 20),
                1148,
            ],
            'non-zero flags (1149)' => [
                "\x00\x00\x00\x01" . str_repeat("\x00", 20),
                1149,
            ],
            'invalid UTF-8 name (1384)' => [
                "\x00\x00\x00\x00"
                . str_repeat("\x00", 4)
                . 'vide'
                . str_repeat("\x00", 12)
                . "\xFE\x00",
                1384,
            ],
        ];
    }

    /**
     * Rejects invalid hdlr payloads.
     */
    #[Test]
    #[DataProvider('hdlrErrorProvider')]
    public function parseHdlrRejectsInvalidPayload(string $content, int $expectedCode): void
    {
        [$parser, $descriptor] = $this->createParserWithDescriptor('hdlr', $content);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode($expectedCode);

        $parser->parseHdlr($descriptor);
    }

    // =========================================================================
    // parseTrak tests — structural (tkhd/mdia/udta)
    // =========================================================================

    /**
     * Parses a valid video track and returns expected metadata.
     */
    #[Test]
    public function parseTrakParsesValidVideoTrack(): void
    {
        [$parser, $descriptor, $context] = $this->createParseTrakSetup($this->validTrakContent());

        $result = $parser->parseTrak($descriptor, $context);

        self::assertSame('vide', $result['handler']);
        self::assertTrue($result['isEnabledInMovie']);
    }

    /**
     * Rejects trak without a tkhd box (code 1891).
     */
    #[Test]
    public function parseTrakRejectsMissingTkhd(): void
    {
        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validMdiaBox(),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1891);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects trak without an mdia box (code 1892).
     */
    #[Test]
    public function parseTrakRejectsMissingMdia(): void
    {
        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox(),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1892);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects trak with duplicate tkhd boxes (code 1376).
     */
    #[Test]
    public function parseTrakRejectsDuplicateTkhd(): void
    {
        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $this->validTkhdBox() . $this->validMdiaBox(),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1376);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects trak with duplicate mdia boxes (code 1377).
     */
    #[Test]
    public function parseTrakRejectsDuplicateMdia(): void
    {
        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $this->validMdiaBox() . $this->validMdiaBox(),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1377);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects trak with duplicate udta boxes (code 1912).
     */
    #[Test]
    public function parseTrakRejectsDuplicateUdta(): void
    {
        $udta = $this->box('udta', str_repeat("\x00", 8));

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $this->validMdiaBox() . $udta . $udta,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1912);

        $parser->parseTrak($descriptor, $context);
    }

    // =========================================================================
    // parseTkhd tests (via parseTrak)
    // =========================================================================

    /**
     * Provides tkhd error test cases. Each provides a tkhd fullBox payload and expected code.
     *
     * @return array<string, array{string, int}>
     */
    public static function tkhdErrorProvider(): array
    {
        return [
            'truncated under 84 bytes (1144)' => [
                chr(0) . "\x00\x00\x03" . str_repeat("\x00", 50),
                1144,
            ],
            'unsupported version 2 (1145)' => [
                chr(2) . "\x00\x00\x03" . str_repeat("\x00", 88),
                1145,
            ],
            'v1 truncated under 96 bytes (1146)' => [
                chr(1) . "\x00\x00\x03" . str_repeat("\x00", 84),
                1146,
            ],
            'zero track_ID (1369)' => [
                chr(0) . "\x00\x00\x03"
                . str_repeat("\x00", 8)
                . pack('N', 0)
                . str_repeat("\x00", 68),
                1369,
            ],
        ];
    }

    /**
     * Rejects invalid tkhd payloads detected through parseTrak.
     */
    #[Test]
    #[DataProvider('tkhdErrorProvider')]
    public function parseTrakRejectsInvalidTkhd(string $tkhdContent, int $expectedCode): void
    {
        $tkhd = $this->box('tkhd', $tkhdContent);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $tkhd . $this->validMdiaBox(),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode($expectedCode);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Provides tkhd payloads with non-zero reserved fields that should be tolerated.
     *
     * @return array<string, array{string}>
     */
    public static function tkhdToleratedReservedProvider(): array
    {
        return [
            'non-zero reserved32' => [
                chr(0) . "\x00\x00\x03"
                . str_repeat("\x00", 8)
                . pack('N', 1)
                . "\x00\x00\x00\x01"
                . str_repeat("\x00", 64),
            ],
            'non-zero reserved64' => [
                chr(0) . "\x00\x00\x03"
                . str_repeat("\x00", 8)
                . pack('N', 1)
                . str_repeat("\x00", 4)
                . pack('N', 1000)
                . "\x00\x00\x00\x00\x00\x00\x00\x01"
                . str_repeat("\x00", 52),
            ],
            'non-zero reserved16' => [
                chr(0) . "\x00\x00\x03"
                . str_repeat("\x00", 8)
                . pack('N', 1)
                . str_repeat("\x00", 4)
                . pack('N', 1000)
                . str_repeat("\x00", 8)
                . str_repeat("\x00", 4)
                . "\x00\x00"
                . "\x00\x01"
                . str_repeat("\x00", 44),
            ],
        ];
    }

    /**
     * Tolerates tkhd payloads with non-zero reserved fields.
     */
    #[Test]
    #[DataProvider('tkhdToleratedReservedProvider')]
    public function parseTrakToleratesTkhdReservedFields(string $tkhdContent): void
    {
        $tkhd = $this->box('tkhd', $tkhdContent);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $tkhd . $this->validMdiaBox(),
        );

        $this->expectNotToPerformAssertions();
        $parser->parseTrak($descriptor, $context);
    }

    // =========================================================================
    // parseMdia tests (via parseTrak) — structural errors
    // =========================================================================

    /**
     * Rejects mdia without a hdlr box (code 1893).
     */
    #[Test]
    public function parseTrakRejectsMdiaMissingHdlr(): void
    {
        $mdia = $this->box('mdia', $this->validMdhdBox() . $this->validMinfBox());

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1893);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects mdia without a minf box (code 1894).
     */
    #[Test]
    public function parseTrakRejectsMdiaMissingMinf(): void
    {
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMdhdBox());

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1894);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects mdia without a mdhd box (code 1895).
     */
    #[Test]
    public function parseTrakRejectsMdiaMissingMdhd(): void
    {
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMinfBox());

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1895);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects mdia with duplicate hdlr boxes (code 1378).
     */
    #[Test]
    public function parseTrakRejectsMdiaDuplicateHdlr(): void
    {
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validHdlrBox() . $this->validMdhdBox() . $this->validMinfBox());

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1378);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects mdia with duplicate minf boxes (code 1379).
     */
    #[Test]
    public function parseTrakRejectsMdiaDuplicateMinf(): void
    {
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMdhdBox() . $this->validMinfBox() . $this->validMinfBox());

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1379);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects mdia with duplicate mdhd boxes (code 1380).
     */
    #[Test]
    public function parseTrakRejectsMdiaDuplicateMdhd(): void
    {
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMdhdBox() . $this->validMdhdBox() . $this->validMinfBox());

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1380);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects mdia with duplicate udta boxes (code 1463).
     */
    #[Test]
    public function parseTrakRejectsMdiaDuplicateUdta(): void
    {
        $udta = $this->box('udta', str_repeat("\x00", 8));
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMdhdBox() . $this->validMinfBox() . $udta . $udta);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1990);

        $parser->parseTrak($descriptor, $context);
    }

    // =========================================================================
    // parseMdhd tests (via parseTrak)
    // =========================================================================

    /**
     * Provides mdhd error test cases (content includes version/flags).
     *
     * @return array<string, array{string, int}>
     */
    public static function mdhdErrorProvider(): array
    {
        return [
            'truncated under 4 bytes (1901)' => [
                str_repeat("\x00", 3),
                1901,
            ],
            'unsupported version 2 (1903)' => [
                "\x02\x00\x00\x00" . str_repeat("\x00", 32),
                1903,
            ],
            'non-zero flags (1904)' => [
                "\x00\x00\x00\x01" . str_repeat("\x00", 20),
                1904,
            ],
            'v0 truncated payload (1902)' => [
                "\x00\x00\x00\x00" . str_repeat("\x00", 16),
                1902,
            ],
            'v1 truncated payload (1902)' => [
                "\x01\x00\x00\x00" . str_repeat("\x00", 16),
                1902,
            ],
            'zero timescale (1905)' => [
                "\x00\x00\x00\x00"
                . str_repeat("\x00", 8)
                . pack('N', 0)
                . str_repeat("\x00", 8),
                1905,
            ],
        ];
    }

    /**
     * Rejects invalid mdhd payloads detected through parseTrak.
     */
    #[Test]
    #[DataProvider('mdhdErrorProvider')]
    public function parseTrakRejectsInvalidMdhd(string $mdhdContent, int $expectedCode): void
    {
        $mdhd = $this->box('mdhd', $mdhdContent);
        $mdia = $this->box('mdia', $this->validHdlrBox() . $mdhd . $this->validMinfBox());

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode($expectedCode);

        $parser->parseTrak($descriptor, $context);
    }

    // =========================================================================
    // parseMinf tests (via parseTrak)
    // =========================================================================

    /**
     * Rejects minf without stbl (code 1896).
     */
    #[Test]
    public function parseTrakRejectsMinfMissingStbl(): void
    {
        $minf = $this->box('minf', $this->validDinfBox() . $this->validVmhdBox());
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMdhdBox() . $minf);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1896);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects minf without dinf (code 1897).
     */
    #[Test]
    public function parseTrakRejectsMinfMissingDinf(): void
    {
        $minf = $this->box('minf', $this->validVmhdBox() . $this->box('stbl', $this->validStblContent()));
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMdhdBox() . $minf);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1897);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects minf with duplicate stbl (code 1381).
     */
    #[Test]
    public function parseTrakRejectsMinfDuplicateStbl(): void
    {
        $stbl = $this->box('stbl', $this->validStblContent());
        $minf = $this->box('minf', $this->validDinfBox() . $this->validVmhdBox() . $stbl . $stbl);
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMdhdBox() . $minf);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1381);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects minf with duplicate dinf (code 1382).
     */
    #[Test]
    public function parseTrakRejectsMinfDuplicateDinf(): void
    {
        $minf = $this->box('minf', $this->validDinfBox() . $this->validDinfBox() . $this->validVmhdBox() . $this->box('stbl', $this->validStblContent()));
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMdhdBox() . $minf);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1382);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects minf with duplicate media header boxes (code 1421).
     */
    #[Test]
    public function parseTrakRejectsMinfDuplicateMediaHeader(): void
    {
        $minf = $this->box('minf', $this->validDinfBox() . $this->validVmhdBox() . $this->validVmhdBox() . $this->box('stbl', $this->validStblContent()));
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMdhdBox() . $minf);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1421);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects minf missing required media header (code 1422).
     */
    #[Test]
    public function parseTrakRejectsMinfMissingMediaHeader(): void
    {
        $minf = $this->box('minf', $this->validDinfBox() . $this->box('stbl', $this->validStblContent()));
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMdhdBox() . $minf);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1422);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Metadata handler tracks tolerate minf without nmhd and continue parsing.
     */
    #[Test]
    public function itToleratesMissingNmhdBoxInMetadataTrack(): void
    {
        $minf = $this->box('minf', $this->validDinfBox() . $this->box('stbl', $this->validStblContent('meta')));
        $mdia = $this->box('mdia', $this->validHdlrBox('meta') . $this->validMdhdBox() . $minf);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectNotToPerformAssertions();

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Tolerates minf with mismatched media header for handler.
     */
    #[Test]
    public function parseTrakToleratesMinfMismatchedMediaHeader(): void
    {
        // Handler is 'vide' but media header is 'smhd' (expected 'vmhd')
        $minf = $this->box('minf', $this->validDinfBox() . $this->validSmhdBox() . $this->box('stbl', $this->validStblContent()));
        $mdia = $this->box('mdia', $this->validHdlrBox() . $this->validMdhdBox() . $minf);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->validTkhdBox() . $mdia,
        );

        $this->expectNotToPerformAssertions();

        $parser->parseTrak($descriptor, $context);
    }

    // =========================================================================
    // parseStbl tests (via parseTrak)
    // =========================================================================

    /**
     * Helper to build a trak hierarchy with a custom stbl content and given handler.
     */
    private function buildTrakWithStbl(string $stblContent, string $handler = 'meta'): string
    {
        $mediaHeader = match ($handler) {
            'vide'  => $this->validVmhdBox(),
            'soun'  => $this->validSmhdBox(),
            default => $this->validNmhdBox(),
        };

        $minf = $this->box('minf', $this->validDinfBox() . $mediaHeader . $this->box('stbl', $stblContent));
        $mdia = $this->box('mdia', $this->validHdlrBox($handler) . $this->validMdhdBox() . $minf);

        return $this->validTkhdBox() . $mdia;
    }

    /**
     * Provides stbl "duplicate box" error test cases.
     *
     * @return array<string, array{string, int}>
     */
    public static function stblDuplicateErrorProvider(): array
    {
        return [
            'duplicate stsd (1383)' => ['stsd', 1383],
            'duplicate stts (1424)' => ['stts', 1424],
            'duplicate stsc (1425)' => ['stsc', 1425],
            'duplicate stsz (1426)' => ['stsz', 1426],
            'duplicate stco (1427)' => ['stco', 1427],
        ];
    }

    /**
     * Rejects stbl with duplicate mandatory boxes.
     */
    #[Test]
    #[DataProvider('stblDuplicateErrorProvider')]
    public function parseTrakRejectsStblDuplicateBox(string $boxType, int $expectedCode): void
    {
        $extra = match ($boxType) {
            'stsd'  => $this->validStsdBox('meta'),
            'stts'  => $this->validSttsBox(),
            'stsc'  => $this->validStscBox(),
            'stsz'  => $this->validStszBox(),
            default => $this->validStcoBox(),
        };

        $stblContent = $this->validStblContent('meta') . $extra;

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStbl($stblContent),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode($expectedCode);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Provides stbl "missing box" error test cases.
     *
     * @return array<string, array{string, int}>
     */
    public static function stblMissingErrorProvider(): array
    {
        return [
            'missing stsd (1898)' => ['stsd', 1898],
            'missing stts (1914)' => ['stts', 1914],
            'missing stsc (1915)' => ['stsc', 1915],
            'missing stsz (1916)' => ['stsz', 1916],
            'missing stco (1917)' => ['stco', 1917],
        ];
    }

    /**
     * Rejects stbl with missing mandatory boxes.
     */
    #[Test]
    #[DataProvider('stblMissingErrorProvider')]
    public function parseTrakRejectsStblMissingBox(string $missingType, int $expectedCode): void
    {
        $boxes = [
            'stsd' => $this->validStsdBox('meta'),
            'stts' => $this->validSttsBox(),
            'stsc' => $this->validStscBox(),
            'stsz' => $this->validStszBox(),
            'stco' => $this->validStcoBox(),
        ];

        unset($boxes[$missingType]);

        $stblContent = implode('', $boxes);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStbl($stblContent),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode($expectedCode);

        $parser->parseTrak($descriptor, $context);
    }

    // =========================================================================
    // parseStsd tests (via parseTrak)
    // =========================================================================

    /**
     * Helper to build a trak hierarchy with a custom stsd box.
     */
    private function buildTrakWithStsd(string $stsdBox, string $handler = 'meta'): string
    {
        $stblContent = $stsdBox
            . $this->validSttsBox()
            . $this->validStscBox()
            . $this->validStszBox()
            . $this->validStcoBox();

        return $this->buildTrakWithStbl($stblContent, $handler);
    }

    /**
     * Rejects stsd truncated under 8 bytes (code 1153).
     */
    #[Test]
    public function parseTrakRejectsStsdTruncated(): void
    {
        $stsd = $this->box('stsd', str_repeat("\x00", 3));

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStsd($stsd),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1153);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stsd with unsupported version (code 1154).
     */
    #[Test]
    public function parseTrakRejectsStsdUnsupportedVersion(): void
    {
        $stsd = $this->fullBox('stsd', pack('N', 1) . pack('N', 16) . 'genr' . str_repeat("\x00", 6) . pack('n', 1), 2, 0);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStsd($stsd),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1154);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stsd with non-zero flags (code 1155).
     */
    #[Test]
    public function parseTrakRejectsStsdNonZeroFlags(): void
    {
        $stsd = $this->fullBox('stsd', pack('N', 1) . pack('N', 16) . 'genr' . str_repeat("\x00", 6) . pack('n', 1), 0, 1);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStsd($stsd),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1155);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stsd version 1 with non-audio handler (code 1925).
     */
    #[Test]
    public function parseTrakRejectsStsdV1NonAudioHandler(): void
    {
        $stsd = $this->fullBox('stsd', pack('N', 1) . pack('N', 16) . 'genr' . str_repeat("\x00", 6) . pack('n', 1), 1, 0);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStsd($stsd, 'vide'),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1925);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stsd with zero entry count (code 1926).
     */
    #[Test]
    public function parseTrakRejectsStsdZeroEntryCount(): void
    {
        $stsd = $this->fullBox('stsd', pack('N', 0), 0, 0);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStsd($stsd),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1926);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stsd with entry count exceeding limit (code 1156).
     */
    #[Test]
    public function parseTrakRejectsStsdExcessiveEntryCount(): void
    {
        $stsd = $this->fullBox('stsd', pack('N', 101) . str_repeat("\x00", 16), 0, 0);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStsd($stsd),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1156);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stsd with truncated entry header (code 1157).
     */
    #[Test]
    public function parseTrakRejectsStsdTruncatedEntry(): void
    {
        // entry count = 1 but only 4 bytes of entry (need 8 for size+type)
        $stsd = $this->fullBox('stsd', pack('N', 1) . str_repeat("\x00", 4), 0, 0);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStsd($stsd),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1157);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stsd with invalid entry size (code 1158).
     */
    #[Test]
    public function parseTrakRejectsStsdInvalidEntrySize(): void
    {
        // entry size = 8 (< 16 minimum)
        $stsd = $this->fullBox('stsd', pack('N', 1) . pack('N', 8) . 'genr', 0, 0);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStsd($stsd),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1158);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Tolerates stsd entry with non-zero reserved field.
     */
    #[Test]
    public function parseTrakToleratesStsdNonZeroReserved(): void
    {
        $entry = pack('N', 16) . 'genr' . "\x00\x00\x00\x00\x00\x01" . pack('n', 1);
        $stsd  = $this->fullBox('stsd', pack('N', 1) . $entry, 0, 0);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStsd($stsd),
        );

        $this->expectNotToPerformAssertions();

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stsd entry with zero data_reference_index (code 1399).
     */
    #[Test]
    public function parseTrakRejectsStsdZeroDataRefIndex(): void
    {
        $entry = pack('N', 16) . 'genr' . str_repeat("\x00", 6) . pack('n', 0);
        $stsd  = $this->fullBox('stsd', pack('N', 1) . $entry, 0, 0);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStsd($stsd),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1399);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stsd whose entries do not fill the container (code 1161).
     */
    #[Test]
    public function parseTrakRejectsStsdEntriesNotFillingContainer(): void
    {
        $entry   = pack('N', 16) . 'genr' . str_repeat("\x00", 6) . pack('n', 1);
        $payload = pack('N', 1) . $entry . str_repeat("\x00", 4);
        $stsd    = $this->fullBox('stsd', $payload, 0, 0);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildTrakWithStsd($stsd),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1161);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Guards parseTrak refactoring by requiring explicit video/audio key assembly helpers.
     */
    #[Test]
    public function parseTrakUsesDedicatedTrackKeyAssemblers(): void
    {
        $reflection = new ReflectionClass(TrackMediaParser::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertContains('buildVideoTrackKeys', $methods);
        self::assertContains('buildAudioTrackKeys', $methods);
        self::assertContains('copyLpcmSampleInfoKeys', $methods);
        self::assertContains('buildMetaTrackKeys', $methods);
    }

    // =========================================================================
    // stts frame-rate tests
    // =========================================================================

    /**
     * Builds a valid stts box with the given entries.
     *
     * @param list<array{int, int}> $entries List of [sample_count, sample_delta] pairs.
     */
    private function buildSttsBox(array $entries): string
    {
        $payload = pack('N', count($entries));

        foreach ($entries as [$sampleCount, $sampleDelta]) {
            $payload .= pack('N', $sampleCount) . pack('N', $sampleDelta);
        }

        return $this->fullBox('stts', $payload, 0, 0);
    }

    /**
     * Builds a video trak that includes a custom stts box.
     */
    private function buildVideoTrakWithStts(string $sttsBox): string
    {
        $minfContent = $this->validDinfBox()
            . $this->validVmhdBox()
            . $this->box('stbl', $this->validStsdBox() . $sttsBox . $this->validStscBox() . $this->validStszBox() . $this->validStcoBox());

        $mdiaContent = $this->validHdlrBox()
            . $this->validMdhdBox()
            . $this->box('minf', $minfContent);

        return $this->validTkhdBox() . $this->box('mdia', $mdiaContent);
    }

    /**
     * Computes frame rate from a constant-rate stts (single entry).
     *
     * ISO/IEC 14496-12 §8.6.1: fps = timescale / sample_delta when all deltas are equal.
     * validMdhdBox uses timescale=1000, so fps = 1000/40 = 25.0.
     */
    #[Test]
    public function parseTrakExtractsFrameRateFromConstantRateStts(): void
    {
        // 300 frames at sample_delta=40 ticks each, timescale=1000 → 25 fps
        $stts = $this->buildSttsBox([[300, 40]]);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildVideoTrakWithStts($stts),
        );

        $result = $parser->parseTrak($descriptor, $context);

        self::assertSame(25.0, $result['keys']['com.apple.quicktime.videoFrameRate']);
    }

    /**
     * Computes frame rate from a variable-rate stts (multiple entries with different deltas).
     *
     * ISO/IEC 14496-12 §8.6.1: fps = total_samples × timescale / total_ticks.
     * timescale=1000, entries: 100@33 + 100@34 → total_samples=200, total_ticks=6700 → fps≈29.85.
     */
    #[Test]
    public function parseTrakExtractsFrameRateFromVariableRateStts(): void
    {
        // timescale=1000, 100 frames at delta=33 + 100 frames at delta=34
        // fps = 200 × 1000 / (100×33 + 100×34) = 200000 / 6700 ≈ 29.85
        $stts = $this->buildSttsBox([[100, 33], [100, 34]]);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildVideoTrakWithStts($stts),
        );

        $result = $parser->parseTrak($descriptor, $context);

        $expectedFrameRate = 200000.0 / 6700.0;

        self::assertEqualsWithDelta($expectedFrameRate, $result['keys']['com.apple.quicktime.videoFrameRate'], 0.001);
    }

    /**
     * Returns no frame rate key when stts entry_count is zero.
     *
     * ISO/IEC 14496-12 §8.6.1: an empty stts cannot produce a frame rate.
     */
    #[Test]
    public function parseTrakOmitsFrameRateKeyWhenSttsHasNoEntries(): void
    {
        $stts = $this->buildSttsBox([]);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildVideoTrakWithStts($stts),
        );

        $result = $parser->parseTrak($descriptor, $context);

        self::assertArrayNotHasKey('com.apple.quicktime.videoFrameRate', $result['keys']);
    }

    /**
     * Rejects stts box that is truncated (code 2101).
     */
    #[Test]
    public function parseTrakRejectsTruncatedStts(): void
    {
        // Build a deliberately truncated stts: only 3 bytes instead of minimum 8
        $stts = $this->box('stts', str_repeat("\x00", 3));

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildVideoTrakWithStts($stts),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2101);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stts box with unsupported version (code 2102).
     *
     * ISO/IEC 14496-12 §8.6.1: stts must have version = 0.
     */
    #[Test]
    public function parseTrakRejectsUnsupportedSttsVersion(): void
    {
        $payload = "\x01\x00\x00\x00" . pack('N', 0); // version=1 (invalid)
        $stts    = $this->box('stts', $payload);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildVideoTrakWithStts($stts),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2102);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stts box with non-zero flags (code 2103).
     */
    #[Test]
    public function parseTrakRejectsNonZeroSttsFlags(): void
    {
        $payload = "\x00\x00\x00\x01" . pack('N', 0); // version=0, flags=1 (invalid)
        $stts    = $this->box('stts', $payload);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildVideoTrakWithStts($stts),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2103);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stts box with entry_count exceeding limit (code 2104).
     */
    #[Test]
    public function parseTrakRejectsSttsEntryCountExceedingLimit(): void
    {
        // entry_count = 16385 > MAX_STTS_ENTRIES(16384)
        $payload = "\x00\x00\x00\x00" . pack('N', 16385);
        $stts    = $this->box('stts', $payload);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildVideoTrakWithStts($stts),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2104);

        $parser->parseTrak($descriptor, $context);
    }

    /**
     * Rejects stts box whose declared entry_count exceeds available bytes (code 2105).
     */
    #[Test]
    public function parseTrakRejectsTruncatedSttsEntries(): void
    {
        // Declares 5 entries but only provides 2 complete entries (16 bytes of data)
        $payload = "\x00\x00\x00\x00" . pack('N', 5) . str_repeat("\x00", 16);
        $stts    = $this->box('stts', $payload);

        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildVideoTrakWithStts($stts),
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2105);

        $parser->parseTrak($descriptor, $context);
    }

    // =========================================================================
    // meta handler track tests
    // =========================================================================

    /**
     * Builds a valid stsd box for a meta handler track with the given format.
     */
    private function validMetaStsdBox(string $format): string
    {
        $entry   = pack('N', 16) . $format . str_repeat("\x00", 6) . pack('n', 1);
        $payload = pack('N', 1) . $entry;

        return $this->fullBox('stsd', $payload, 0, 0);
    }

    /**
     * Builds a valid stbl box for a meta handler track.
     */
    private function validMetaStblContent(string $format): string
    {
        return $this->validMetaStsdBox($format)
            . $this->validSttsBox()
            . $this->validStscBox()
            . $this->validStszBox()
            . $this->validStcoBox();
    }

    /**
     * Builds a valid minf box for a meta handler track.
     */
    private function validMetaMinfBox(string $format): string
    {
        return $this->box('minf',
            $this->validDinfBox()
            . $this->validNmhdBox()
            . $this->box('stbl', $this->validMetaStblContent($format)));
    }

    /**
     * Builds a valid trak for a meta handler track.
     */
    private function buildMetaTrakContent(string $format, string $handlerName = ''): string
    {
        $hdlrPayload = str_repeat("\x00", 4) . 'meta' . str_repeat("\x00", 12);

        if ($handlerName !== '') {
            $hdlrPayload .= chr(strlen($handlerName)) . $handlerName;
        }

        $hdlr = $this->fullBox('hdlr', $hdlrPayload, 0, 0);
        $mdia = $this->box('mdia', $hdlr . $this->validMdhdBox() . $this->validMetaMinfBox($format));

        return $this->validTkhdBox() . $mdia;
    }

    /**
     * Extracts MetaFormat from a DJI metadata track (handler='meta', format='djmd').
     *
     * DJI NEO and other DJI cameras include a dedicated metadata track with handler
     * type 'meta' and sample-entry format 'djmd' for per-frame telemetry data.
     */
    #[Test]
    public function parseTrakExtractsMetaFormatFromDjiMetadataTrack(): void
    {
        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildMetaTrakContent('djmd', 'DJI meta'),
        );

        $parser->parseTrak($descriptor, $context);

        self::assertSame('djmd', $context->qtKeys['com.apple.quicktime.metaFormat']);
    }

    /**
     * Does not overwrite MetaFormat when it was already set by a previous track.
     *
     * First-wins semantics: only the first meta track's format is recorded.
     */
    #[Test]
    public function parseTrakDoesNotOverwriteExistingMetaFormat(): void
    {
        [$parser, $descriptor, $context] = $this->createParseTrakSetup(
            $this->buildMetaTrakContent('djmd'),
        );

        $context->qtKeys['com.apple.quicktime.metaFormat'] = 'prev';

        $parser->parseTrak($descriptor, $context);

        self::assertSame('prev', $context->qtKeys['com.apple.quicktime.metaFormat']);
    }
}
