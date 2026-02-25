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
use MagicSunday\ImageMeta\Parse\IsoBmff\VideoSampleEntryParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function str_repeat;
use function strlen;

/**
 * Tests for the VideoSampleEntryParser, covering all ParseError paths
 * and codec metadata extraction from synthetic video sample entries.
 */
#[CoversClass(VideoSampleEntryParser::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Unpack::class)]
final class VideoSampleEntryParserTest extends TestCase
{
    /**
     * Builds a 70-byte video sample entry payload with configurable fields.
     *
     * @param int    $version         Version field (u16).
     * @param int    $revisionLevel   Revision level (u16).
     * @param int    $vendor          Vendor (u32).
     * @param int    $temporalQuality Temporal quality (u32).
     * @param int    $spatialQuality  Spatial quality (u32).
     * @param int    $width           Width (u16).
     * @param int    $height          Height (u16).
     * @param int    $hRes            Horizontal resolution (u32, 16.16 fixed-point).
     * @param int    $vRes            Vertical resolution (u32, 16.16 fixed-point).
     * @param int    $dataSize        Data size (u32).
     * @param int    $frameCount      Frame count (u16).
     * @param int    $nameLength      Compressor name pascal length byte (u8).
     * @param string $nameData        Compressor name data (padded to 31 bytes).
     * @param int    $depth           Depth (u16).
     * @param int    $colorTableId    Color table id (u16, unsigned representation).
     */
    private function buildVideoSampleData(
        int $version = 0,
        int $revisionLevel = 0,
        int $vendor = 0,
        int $temporalQuality = 0,
        int $spatialQuality = 0,
        int $width = 1920,
        int $height = 1080,
        int $hRes = 0x00480000,
        int $vRes = 0x00480000,
        int $dataSize = 0,
        int $frameCount = 1,
        int $nameLength = 0,
        string $nameData = '',
        int $depth = 24,
        int $colorTableId = 0xFFFF,
    ): string {
        $paddedName = str_pad($nameData, 31, "\x00");

        return pack('n', $version)
            . pack('n', $revisionLevel)
            . pack('N', $vendor)
            . pack('N', $temporalQuality)
            . pack('N', $spatialQuality)
            . pack('n', $width)
            . pack('n', $height)
            . pack('N', $hRes)
            . pack('N', $vRes)
            . pack('N', $dataSize)
            . pack('n', $frameCount)
            . chr($nameLength) . substr($paddedName, 0, 31)
            . pack('n', $depth)
            . pack('n', $colorTableId);
    }

    private function validVideoSampleData(): string
    {
        return $this->buildVideoSampleData();
    }

    private function createWindow(string $data): StreamWindow
    {
        $handle = fopen('php://temp', 'wb+');
        if ($handle === false) {
            self::fail('Unable to create temporary stream handle.');
        }

        $bytesWritten = fwrite($handle, $data);
        if ($bytesWritten !== strlen($data)) {
            self::fail('Unable to populate temporary stream data.');
        }

        if (rewind($handle) === false) {
            self::fail('Unable to rewind temporary stream handle.');
        }

        return (new Stream($handle, strlen($data)))
            ->window(0, strlen($data));
    }

    /**
     * Parses a valid avc1 video sample entry and verifies extracted metadata.
     */
    #[Test]
    public function parsesValidVideoSampleEntry(): void
    {
        $data   = $this->buildVideoSampleData(width: 1920, height: 1080, nameLength: 5, nameData: 'H.264');
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $result = $parser->parseVideoSampleEntry($win, 70, 'avc1');

        self::assertSame('avc1', $result['format']);
        self::assertSame(1920, $result['width']);
        self::assertSame(1080, $result['height']);
        self::assertSame(72, $result['horizontalResolution']);
        self::assertSame(72, $result['verticalResolution']);
        self::assertSame(1, $result['frameCount']);
        self::assertSame('H.264', $result['compressorName']);
    }

    /**
     * Returns fractional 16.16 resolution for both integer and non-integer values.
     */
    #[Test]
    public function returnsIntegerAndFractionalResolutions(): void
    {
        // 72.5 in 16.16 = (72 << 16) | 0x8000 = 0x00488000
        $data   = $this->buildVideoSampleData(hRes: 0x00488000, vRes: 0x00480000);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $result = $parser->parseVideoSampleEntry($win, 70, 'avc1');

        self::assertSame(72.5, $result['horizontalResolution']);
        self::assertSame(72, $result['verticalResolution']);
    }

    /**
     * Rejects video sample entry when insufficient bytes remain (code 1159).
     */
    #[Test]
    public function rejectsTruncatedEntry(): void
    {
        $data   = str_repeat("\x00", 69);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1159);

        $parser->parseVideoSampleEntry($win, 69, 'avc1');
    }

    /**
     * Tolerates non-zero revision level.
     */
    #[Test]
    public function toleratesNonZeroRevisionLevel(): void
    {
        $data   = $this->buildVideoSampleData(revisionLevel: 1);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectNotToPerformAssertions();

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Tolerates temporal quality exceeding 1023.
     */
    #[Test]
    public function toleratesExcessiveTemporalQuality(): void
    {
        $data   = $this->buildVideoSampleData(temporalQuality: 1024);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectNotToPerformAssertions();

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Tolerates spatial quality exceeding 1024.
     */
    #[Test]
    public function toleratesExcessiveSpatialQuality(): void
    {
        $data   = $this->buildVideoSampleData(spatialQuality: 1025);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectNotToPerformAssertions();

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Rejects zero width (code 1601).
     */
    #[Test]
    public function rejectsZeroWidth(): void
    {
        $data   = $this->buildVideoSampleData(width: 0);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1601);

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Rejects zero height (code 1602).
     */
    #[Test]
    public function rejectsZeroHeight(): void
    {
        $data   = $this->buildVideoSampleData(height: 0);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1602);

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Provides resolution error test cases for decodeVideoResolution16_16.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function resolutionErrorProvider(): array
    {
        return [
            'horizontal zero'          => [0, 0x00480000, 1604],
            'horizontal high bit'      => [0x80000000, 0x00480000, 1605],
            'horizontal fraction only' => [0x00000001, 0x00480000, 1938],
            'vertical zero'            => [0x00480000, 0, 1604],
            'vertical high bit'        => [0x00480000, 0x80000000, 1605],
            'vertical fraction only'   => [0x00480000, 0x00000001, 1938],
        ];
    }

    /**
     * Rejects invalid 16.16 fixed-point resolution values (codes 1604, 1605, 1938).
     */
    #[Test]
    #[DataProvider('resolutionErrorProvider')]
    public function rejectsInvalidResolution(int $hRes, int $vRes, int $expectedCode): void
    {
        $data   = $this->buildVideoSampleData(hRes: $hRes, vRes: $vRes);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode($expectedCode);

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Rejects non-zero data size (code 1502).
     */
    #[Test]
    public function rejectsNonZeroDataSize(): void
    {
        $data   = $this->buildVideoSampleData(dataSize: 1);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1502);

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Rejects zero frame count (code 1606).
     */
    #[Test]
    public function rejectsZeroFrameCount(): void
    {
        $data   = $this->buildVideoSampleData(frameCount: 0);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1606);

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Rejects compressor name pascal string length exceeding 31 (code 1428).
     */
    #[Test]
    public function rejectsCompressorNameLengthExceeding31(): void
    {
        $data   = $this->buildVideoSampleData(nameLength: 32);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1428);

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Rejects depth values not in the QuickTime allowed set (code 1494).
     */
    #[Test]
    public function rejectsInvalidDepth(): void
    {
        $data   = $this->buildVideoSampleData(depth: 3);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1494);

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Rejects depth 16/24/32 with colorTableId other than -1 (code 1495).
     */
    #[Test]
    public function rejectsNoColorTableDepthWithColorTableId(): void
    {
        // depth=24 is in NO_COLOR_TABLE_DEPTHS; colorTableId=0 (not -1) triggers 1495
        $data   = $this->buildVideoSampleData(depth: 24, colorTableId: 0x0000);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1495);

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Rejects colorTableId=0 when no trailing ctab data is present (code 1496).
     */
    #[Test]
    public function rejectsColorTableIdZeroWithoutTrailingCtab(): void
    {
        // depth=8 passes NO_COLOR_TABLE_DEPTHS check; colorTableId=0 requires ctab
        $data   = $this->buildVideoSampleData(depth: 8, colorTableId: 0x0000);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1496);

        $parser->parseVideoSampleEntry($win, 70, 'avc1');
    }

    /**
     * Rejects colorTableId=0 when trailing atom type is not 'ctab' (code 1931).
     */
    #[Test]
    public function rejectsColorTableIdZeroWithWrongTrailingType(): void
    {
        $data = $this->buildVideoSampleData(depth: 8, colorTableId: 0x0000)
            . pack('N', 16) . 'xxxx' . str_repeat("\x00", 4);

        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1931);

        $parser->parseVideoSampleEntry($win, strlen($data), 'avc1');
    }

    /**
     * Rejects colorTableId=0 when ctab atom size exceeds remaining bytes (code 1498).
     */
    #[Test]
    public function rejectsTruncatedCtabAtom(): void
    {
        // ctab size claims 100 bytes but only 12 trailing bytes exist
        $data = $this->buildVideoSampleData(depth: 8, colorTableId: 0x0000)
            . pack('N', 100) . 'ctab' . str_repeat("\x00", 4);

        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1498);

        $parser->parseVideoSampleEntry($win, strlen($data), 'avc1');
    }

    /**
     * Rejects trailing 4-byte non-zero payload (code 1932).
     */
    #[Test]
    public function rejectsTrailing4ByteNonZeroPayload(): void
    {
        $data   = $this->validVideoSampleData() . "\x00\x00\x00\x01";
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1932);

        $parser->parseVideoSampleEntry($win, strlen($data), 'avc1');
    }

    /**
     * Rejects trailing payload shorter than 8 bytes but not exactly 4 (code 1933).
     */
    #[Test]
    public function rejectsTrailingPayloadShorterThan8Bytes(): void
    {
        $data   = $this->validVideoSampleData() . str_repeat("\x00", 5);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1933);

        $parser->parseVideoSampleEntry($win, strlen($data), 'avc1');
    }

    /**
     * Rejects trailing payload with box size less than 8 (code 1934).
     */
    #[Test]
    public function rejectsTrailingPayloadWithBadBoxSize(): void
    {
        // boxSize = 4 (< 8 minimum) inside a 12-byte trailing block
        $data   = $this->validVideoSampleData() . pack('N', 4) . 'test' . str_repeat("\x00", 4);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1934);

        $parser->parseVideoSampleEntry($win, strlen($data), 'avc1');
    }

    /**
     * Accepts a valid trailing 4-byte zero terminator without error.
     */
    #[Test]
    public function acceptsTrailing4ByteZeroTerminator(): void
    {
        $data   = $this->validVideoSampleData() . pack('N', 0);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $result = $parser->parseVideoSampleEntry($win, strlen($data), 'avc1');

        self::assertSame(1920, $result['width']);
    }

    /**
     * Accepts valid trailing child boxes without error.
     */
    #[Test]
    public function acceptsValidTrailingChildBoxes(): void
    {
        // Two valid 8-byte trailing boxes
        $data   = $this->validVideoSampleData() . pack('N', 8) . 'colr' . pack('N', 8) . 'pasp';
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $result = $parser->parseVideoSampleEntry($win, strlen($data), 'avc1');

        self::assertSame(1920, $result['width']);
    }

    /**
     * Accepts all valid QuickTime depth values.
     *
     * @return array<string, array{int, int}>
     */
    public static function validDepthProvider(): array
    {
        return [
            'depth 1'  => [1, 0xFFFF],
            'depth 2'  => [2, 0xFFFF],
            'depth 4'  => [4, 0xFFFF],
            'depth 8'  => [8, 0xFFFF],
            'depth 16' => [16, 0xFFFF],
            'depth 24' => [24, 0xFFFF],
            'depth 32' => [32, 0xFFFF],
            'depth 34' => [34, 0xFFFF],
            'depth 36' => [36, 0xFFFF],
            'depth 40' => [40, 0xFFFF],
        ];
    }

    /**
     * Accepts all 10 valid QuickTime video depth values.
     */
    #[Test]
    #[DataProvider('validDepthProvider')]
    public function acceptsAllValidDepths(int $depth, int $colorTableId): void
    {
        $data   = $this->buildVideoSampleData(depth: $depth, colorTableId: $colorTableId);
        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $result = $parser->parseVideoSampleEntry($win, 70, 'avc1');

        self::assertSame(1920, $result['width']);
    }

    /**
     * Accepts colorTableId=0 with valid trailing ctab atom.
     */
    #[Test]
    public function acceptsColorTableIdZeroWithValidCtab(): void
    {
        // depth=8, colorTableId=0, valid ctab of 16 bytes
        $data = $this->buildVideoSampleData(depth: 8, colorTableId: 0x0000)
            . pack('N', 16) . 'ctab' . str_repeat("\x00", 8);

        $win    = $this->createWindow($data);
        $parser = new VideoSampleEntryParser();

        $result = $parser->parseVideoSampleEntry($win, strlen($data), 'avc1');

        self::assertSame(1920, $result['width']);
    }
}
