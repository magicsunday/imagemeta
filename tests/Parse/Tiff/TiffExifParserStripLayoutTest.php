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
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function count;
use function pack;
use function str_pad;
use function str_repeat;
use function strlen;
use function usort;

/**
 * Verifies strip-layout consistency validation for non-JPEG TIFF/EXIF payloads.
 * It covers chunky and planar-separate layouts and rejects inconsistent strip counts.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(TiffConst::class)]
final class TiffExifParserStripLayoutTest extends TestCase
{
    /**
     * Accepts a valid strip layout for PlanarConfiguration=1 (chunky).
     *
     * @return void
     */
    #[Test]
    public function acceptsValidStripLayoutWithChunkyPlanarConfiguration(): void
    {
        $blob = $this->buildStripLayoutTiff(
            imageLength: 10,
            rowsPerStrip: 4,
            stripOffsets: [512, 768, 1024],
            stripByteCounts: [120, 120, 80],
            planarConfiguration: 1,
            samplesPerPixel: null,
            padToStorageRanges: true,
        );

        $parsed = (new TiffExifParser())->parseFromBlob($blob);

        self::assertSame(4, $parsed->rowsPerStrip());
        self::assertSame([512, 768, 1024], $parsed->stripOffsets());
        self::assertSame([120, 120, 80], $parsed->stripByteCounts());
    }

    /**
     * Accepts a valid strip layout for PlanarConfiguration=2 (separate planes).
     *
     * @return void
     */
    #[Test]
    public function acceptsValidStripLayoutWithSeparatePlanarConfiguration(): void
    {
        $blob = $this->buildStripLayoutTiff(
            imageLength: 10,
            rowsPerStrip: 4,
            stripOffsets: [100, 200, 300, 400, 500, 600, 700, 800, 900],
            stripByteCounts: [32, 32, 16, 32, 32, 16, 32, 32, 16],
            planarConfiguration: 2,
            samplesPerPixel: 3,
            padToStorageRanges: true,
        );

        $parsed = (new TiffExifParser())->parseFromBlob($blob);

        self::assertSame(PlanarConfiguration::PLANAR, $parsed->planarConfiguration());
        self::assertCount(9, $parsed->stripOffsets() ?? []);
        self::assertCount(9, $parsed->stripByteCounts() ?? []);
    }

    /**
     * Rejects StripOffsets entries that use floating-point TIFF types.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('floatingPointStripOffsetTypeProvider')]
    public function rejectsStripOffsetsWithFloatingPointType(int $stripOffsetsType): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('StripOffsets (tag 0x0111) must use integer TIFF field types');

        (new TiffExifParser())->parseFromBlob(
            $this->buildStripLayoutTiff(
                imageLength: 10,
                rowsPerStrip: 4,
                stripOffsets: [512, 768, 1024],
                stripByteCounts: [120, 120, 80],
                planarConfiguration: 1,
                samplesPerPixel: null,
                stripOffsetsType: $stripOffsetsType,
            ),
        );
    }

    /**
     * @return iterable<string, array{0:int}>
     */
    public static function floatingPointStripOffsetTypeProvider(): iterable
    {
        yield 'float strip offsets' => [TiffConst::TYPE_FLOAT];
        yield 'double strip offsets' => [TiffConst::TYPE_DOUBLE];
    }

    /**
     * Rejects StripOffsets count mismatches against expected strip count.
     *
     * @return void
     */
    #[Test]
    public function rejectsMismatchedStripOffsetsCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('StripOffsets count 2 does not match expected strip count 3');

        (new TiffExifParser())->parseFromBlob(
            $this->buildStripLayoutTiff(
                imageLength: 10,
                rowsPerStrip: 4,
                stripOffsets: [512, 768],
                stripByteCounts: [120, 120, 80],
                planarConfiguration: 1,
                samplesPerPixel: null,
            ),
        );
    }

    /**
     * Rejects StripByteCounts count mismatches against expected strip count.
     *
     * @return void
     */
    #[Test]
    public function rejectsMismatchedStripByteCountsCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('StripByteCounts count 2 does not match expected strip count 3');

        (new TiffExifParser())->parseFromBlob(
            $this->buildStripLayoutTiff(
                imageLength: 10,
                rowsPerStrip: 4,
                stripOffsets: [512, 768, 1024],
                stripByteCounts: [120, 120],
                planarConfiguration: 1,
                samplesPerPixel: null,
            ),
        );
    }

    /**
     * Rejects strip storage entries whose offset+byteCount range exceeds TIFF blob bounds.
     *
     * @return void
     */
    #[Test]
    public function rejectsStripStorageRangeExceedingBlobBounds(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('exceeds TIFF data bounds');

        (new TiffExifParser())->parseFromBlob(
            $this->buildStripLayoutTiff(
                imageLength: 10,
                rowsPerStrip: 4,
                stripOffsets: [512, 768, 1024],
                stripByteCounts: [120, 120, 80],
                planarConfiguration: 1,
                samplesPerPixel: null,
                padToStorageRanges: false,
            ),
        );
    }

    /**
     * Rejects zero RowsPerStrip when strip tags are present.
     *
     * @return void
     */
    #[Test]
    public function rejectsZeroRowsPerStripWithStripTags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('RowsPerStrip must be a positive integer');

        (new TiffExifParser())->parseFromBlob(
            $this->buildStripLayoutTiff(
                imageLength: 10,
                rowsPerStrip: 0,
                stripOffsets: [512],
                stripByteCounts: [120],
                planarConfiguration: 1,
                samplesPerPixel: null,
            ),
        );
    }

    /**
     * Rejects StripOffsets encoded with signed SLONG type.
     */
    #[Test]
    public function rejectsStripOffsetsWithSignedType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1600);

        (new TiffExifParser())->parseFromBlob(
            $this->buildStripLayoutTiff(
                imageLength: 10,
                rowsPerStrip: 10,
                stripOffsets: [512],
                stripByteCounts: [120],
                planarConfiguration: 1,
                samplesPerPixel: null,
                stripOffsetsType: TiffConst::TYPE_SLONG,
                padToStorageRanges: true,
            ),
        );
    }

    /**
     * Rejects StripByteCounts encoded with signed SSHORT type.
     */
    #[Test]
    public function rejectsStripByteCountsWithSignedType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1600);

        (new TiffExifParser())->parseFromBlob(
            $this->buildStripLayoutTiff(
                imageLength: 10,
                rowsPerStrip: 10,
                stripOffsets: [512],
                stripByteCounts: [120],
                planarConfiguration: 1,
                samplesPerPixel: null,
                stripByteCountsType: TiffConst::TYPE_SSHORT,
                padToStorageRanges: true,
            ),
        );
    }

    /**
     * Builds a classic TIFF with strip-layout tags in IFD0.
     *
     * @param int       $imageLength         Value for ImageLength (tag 0x0101).
     * @param int       $rowsPerStrip        Value for RowsPerStrip (tag 0x0116).
     * @param list<int> $stripOffsets        Values for StripOffsets (tag 0x0111).
     * @param list<int> $stripByteCounts     Values for StripByteCounts (tag 0x0117).
     * @param int       $planarConfiguration PlanarConfiguration value (1 or 2).
     * @param int|null  $samplesPerPixel     Optional SamplesPerPixel value.
     * @param int       $stripOffsetsType    TIFF type for StripOffsets values.
     * @param int       $stripByteCountsType TIFF type for StripByteCounts values.
     */
    private function buildStripLayoutTiff(
        int $imageLength,
        int $rowsPerStrip,
        array $stripOffsets,
        array $stripByteCounts,
        int $planarConfiguration,
        ?int $samplesPerPixel,
        int $stripOffsetsType = TiffConst::TYPE_LONG,
        int $stripByteCountsType = TiffConst::TYPE_LONG,
        bool $padToStorageRanges = false,
    ): string {
        $entries = [
            ['tag' => ExifTag::IMAGE_WIDTH, 'type' => TiffConst::TYPE_LONG, 'values' => [32]],
            ['tag' => ExifTag::IMAGE_LENGTH, 'type' => TiffConst::TYPE_LONG, 'values' => [$imageLength]],
            ['tag' => ExifTag::STRIP_OFFSETS, 'type' => $stripOffsetsType, 'values' => $stripOffsets],
            ['tag' => ExifTag::ROWS_PER_STRIP, 'type' => TiffConst::TYPE_LONG, 'values' => [$rowsPerStrip]],
            ['tag' => ExifTag::STRIP_BYTE_COUNTS, 'type' => $stripByteCountsType, 'values' => $stripByteCounts],
            ['tag' => ExifTag::PLANAR_CONFIGURATION, 'type' => TiffConst::TYPE_SHORT, 'values' => [$planarConfiguration]],
        ];

        if ($samplesPerPixel !== null) {
            $entries[] = ['tag' => ExifTag::SAMPLES_PER_PIXEL, 'type' => TiffConst::TYPE_SHORT, 'values' => [$samplesPerPixel]];
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => $left['tag'] <=> $right['tag'],
        );

        $blob = $this->buildClassicTiff($entries);

        if ($padToStorageRanges) {
            $requiredLength = 0;
            foreach ($stripOffsets as $index => $offset) {
                $byteCount = $stripByteCounts[$index] ?? 0;
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
     * Encodes a classic TIFF IFD with optional out-of-line value blocks.
     *
     * @param list<array{tag:int, type:int, values:list<int|float>}> $entries
     */
    private function buildClassicTiff(array $entries): string
    {
        $entryCount = count($entries);
        $ifdOffset  = 8;
        $dataOffset = $ifdOffset + 2 + ($entryCount * 12) + 4;
        $ifdBytes   = pack('v', $entryCount);
        $outOfLine  = '';

        foreach ($entries as $entry) {
            $valueBytes = $this->encodeValues($entry['type'], $entry['values']);
            $count      = count($entry['values']);
            $valueField = '';

            if (strlen($valueBytes) <= 4) {
                $valueField = str_pad($valueBytes, 4, "\0");
            } else {
                $valueField = pack('V', $dataOffset + strlen($outOfLine));
                $outOfLine .= $valueBytes;
            }

            $ifdBytes .= pack('v', $entry['tag'])
                . pack('v', $entry['type'])
                . pack('V', $count)
                . $valueField;
        }

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdBytes
            . pack('V', 0)
            . $outOfLine;
    }

    /**
     * Encodes numeric values using little-endian classic TIFF field encoding.
     *
     * @param int             $type   TIFF type constant.
     * @param list<int|float> $values Entry values.
     */
    private function encodeValues(int $type, array $values): string
    {
        $bytes = '';

        foreach ($values as $value) {
            $bytes .= match ($type) {
                TiffConst::TYPE_SHORT,
                TiffConst::TYPE_SSHORT => pack('v', (int) $value),
                TiffConst::TYPE_LONG,
                TiffConst::TYPE_SLONG  => pack('V', (int) $value),
                TiffConst::TYPE_FLOAT  => pack('g', (float) $value),
                TiffConst::TYPE_DOUBLE => pack('e', (float) $value),
                default                => pack('V', (int) $value),
            };
        }

        return $bytes;
    }
}
