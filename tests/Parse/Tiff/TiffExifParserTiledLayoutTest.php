<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormalizer;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffBinaryReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffOffsetValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function intdiv;
use function ksort;
use function max;
use function min;
use function str_pad;
use function str_repeat;
use function strlen;

/**
 * Verifies TIFF 6.0 tiled-layout semantic constraints.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserTiledLayoutTest extends TestCase
{
    /**
     * A structurally valid tiled layout parses successfully.
     */
    #[Test]
    public function acceptsValidTiledLayout(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTiledLayout(padToStorageRanges: true),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::TILE_OFFSETS));
        self::assertNotNull($parsed->ifd0->get(TiffTag::TILE_BYTE_COUNTS));
    }

    /**
     * Rejects TileByteCounts entries that use floating-point TIFF types.
     */
    #[Test]
    #[DataProvider('floatingPointTileByteCountTypeProvider')]
    public function rejectsTileByteCountsWithFloatingPointType(int $tileByteCountsType): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TileByteCounts (tag 0x0145) must use integer TIFF field types');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTiledLayout(tileByteCountsType: $tileByteCountsType),
        );
    }

    /**
     * @return iterable<string, array{0:int}>
     */
    public static function floatingPointTileByteCountTypeProvider(): iterable
    {
        yield 'float tile byte counts' => [TiffConst::TYPE_FLOAT];
        yield 'double tile byte counts' => [TiffConst::TYPE_DOUBLE];
    }

    /**
     * TileWidth must be an integer multiple of 16.
     */
    #[Test]
    public function rejectsTileWidthNotMultipleOf16(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TileWidth 18 must be an integer multiple of 16.');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTiledLayout(tileWidth: 18),
        );
    }

    /**
     * TileLength must be an integer multiple of 16.
     */
    #[Test]
    public function rejectsTileLengthNotMultipleOf16(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TileLength 18 must be an integer multiple of 16.');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTiledLayout(tileLength: 18),
        );
    }

    /**
     * TileOffsets count must match computed TilesPerImage.
     */
    #[Test]
    public function rejectsTileOffsetsCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TileOffsets count 11 does not match expected tile count 12');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTiledLayout(tileOffsetsCount: 11),
        );
    }

    /**
     * TileByteCounts count uses SamplesPerPixel multiplier for PlanarConfiguration=2.
     */
    #[Test]
    public function rejectsTileByteCountsCountMismatchForPlanarSeparate(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TileByteCounts count 23 does not match expected tile count 24');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTiledLayout(
                imageWidth: 64,
                imageLength: 32,
                planarConfiguration: 2,
                samplesPerPixel: 3,
                tileByteCountsCount: 23,
            ),
        );
    }

    /**
     * Rejects tile storage entries whose offset+byteCount range exceeds TIFF blob bounds.
     */
    #[Test]
    public function rejectsTileStorageRangeExceedingBlobBounds(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TileOffsets[0]=4096 with TileByteCounts[0]=256 exceeds TIFF data bounds');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTiledLayout(
                tileOffsetsBase: 4096,
                tileByteCountsBase: 256,
                padToStorageRanges: false,
            ),
        );
    }

    /**
     * Strip and tile descriptors must not be mixed in one IFD image organization.
     */
    #[Test]
    public function rejectsMixedStripAndTileLayout(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Strip and tile layout tags must not be mixed');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTiledLayout(includeStripTags: true),
        );
    }

    /**
     * Builds a minimal classic TIFF IFD with tiled layout tags.
     */
    private function buildTiffWithTiledLayout(
        int $imageWidth = 64,
        int $imageLength = 48,
        int $tileWidth = 16,
        int $tileLength = 16,
        int $planarConfiguration = 1,
        int $samplesPerPixel = 1,
        ?int $tileOffsetsCount = null,
        ?int $tileByteCountsCount = null,
        int $tileByteCountsType = TiffConst::TYPE_LONG,
        bool $includeStripTags = false,
        int $tileOffsetsBase = 64,
        int $tileByteCountsBase = 8,
        bool $padToStorageRanges = false,
    ): string {
        $tilesAcross = intdiv($imageWidth + $tileWidth - 1, $tileWidth);
        $tilesDown   = intdiv($imageLength + $tileLength - 1, $tileLength);
        $tileCount   = $tilesAcross * $tilesDown;
        $expected    = $planarConfiguration === 2 ? $tileCount * $samplesPerPixel : $tileCount;

        $tileOffsetsCount ??= $expected;
        $tileByteCountsCount ??= $expected;

        $tileOffsetsPayload = $this->packNumericList($tileOffsetsCount, $tileOffsetsBase);
        $tileBytesPayload   = $this->packNumericList($tileByteCountsCount, $tileByteCountsBase, $tileByteCountsType);

        $entries = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $imageWidth),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $imageLength),
            ExifTag::PLANAR_CONFIGURATION => pack('v', ExifTag::PLANAR_CONFIGURATION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $planarConfiguration) . pack('v', 0),
            ExifTag::SAMPLES_PER_PIXEL => pack('v', ExifTag::SAMPLES_PER_PIXEL)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $samplesPerPixel) . pack('v', 0),
            TiffTag::TILE_WIDTH => pack('v', TiffTag::TILE_WIDTH)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $tileWidth),
            TiffTag::TILE_LENGTH => pack('v', TiffTag::TILE_LENGTH)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $tileLength),
            TiffTag::TILE_OFFSETS => pack('v', TiffTag::TILE_OFFSETS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', $tileOffsetsCount),
            TiffTag::TILE_BYTE_COUNTS => pack('v', TiffTag::TILE_BYTE_COUNTS)
                . pack('v', $tileByteCountsType)
                . pack('V', $tileByteCountsCount),
        ];

        $payloadByTag = [
            TiffTag::TILE_OFFSETS     => $tileOffsetsPayload,
            TiffTag::TILE_BYTE_COUNTS => $tileBytesPayload,
        ];

        if ($includeStripTags) {
            $rowsPerStrip   = 16;
            $stripsPerImage = intdiv($imageLength + $rowsPerStrip - 1, $rowsPerStrip);
            $stripCount     = $planarConfiguration === 2
                ? $stripsPerImage * $samplesPerPixel
                : $stripsPerImage;

            $entries[ExifTag::ROWS_PER_STRIP] = pack('v', ExifTag::ROWS_PER_STRIP)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $rowsPerStrip);
            $entries[ExifTag::STRIP_OFFSETS] = pack('v', ExifTag::STRIP_OFFSETS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', $stripCount);
            $entries[ExifTag::STRIP_BYTE_COUNTS] = pack('v', ExifTag::STRIP_BYTE_COUNTS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', $stripCount);

            $payloadByTag[ExifTag::STRIP_OFFSETS]     = $this->packNumericList($stripCount, 8192);
            $payloadByTag[ExifTag::STRIP_BYTE_COUNTS] = $this->packNumericList($stripCount, 128);
        }

        ksort($entries);

        $ifdOffset   = 8;
        $entryCount  = count($entries);
        $ifdSize     = 2 + (12 * $entryCount) + 4;
        $nextOffset  = $ifdOffset + $ifdSize;
        $ifdEntries  = '';
        $payloadTail = '';

        foreach ($entries as $tag => $prefix) {
            if (!isset($payloadByTag[$tag])) {
                $ifdEntries .= $prefix;

                continue;
            }

            $payload = $payloadByTag[$tag];

            if (strlen($payload) <= 4) {
                $ifdEntries .= $prefix . str_pad($payload, 4, "\0");

                continue;
            }

            $ifdEntries  .= $prefix . pack('V', $nextOffset);
            $payloadTail .= $payload;
            $nextOffset += strlen($payload);
        }

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . $ifdEntries
            . pack('V', 0)
            . $payloadTail;

        if ($padToStorageRanges) {
            $requiredLength = 0;
            $pairCount      = min($tileOffsetsCount, $tileByteCountsCount);

            for ($i = 0; $i < $pairCount; ++$i) {
                $offset    = $tileOffsetsBase + $i;
                $byteCount = $tileByteCountsBase + $i;

                if ($offset < 0) {
                    continue;
                }

                if ($byteCount < 0) {
                    continue;
                }

                $requiredLength = max($requiredLength, $offset + $byteCount);
            }

            if (strlen($blob) < $requiredLength) {
                $blob .= str_repeat("\0", $requiredLength - strlen($blob));
            }
        }

        return $blob;
    }

    /**
     * Packs deterministic numeric payload values for TIFF list entries.
     */
    private function packNumericList(int $count, int $baseValue, int $type = TiffConst::TYPE_LONG): string
    {
        $payload = '';

        for ($i = 0; $i < $count; ++$i) {
            $value = $baseValue + $i;
            $payload .= match ($type) {
                TiffConst::TYPE_LONG   => pack('V', $value),
                TiffConst::TYPE_FLOAT  => pack('g', (float) $value),
                TiffConst::TYPE_DOUBLE => pack('e', (float) $value),
                default                => pack('V', $value),
            };
        }

        return $payload;
    }
}
