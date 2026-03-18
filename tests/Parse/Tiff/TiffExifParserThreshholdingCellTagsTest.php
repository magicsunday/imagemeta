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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function count;
use function implode;
use function ksort;

/**
 * Verifies TIFF Threshholding/Cell tag semantics.
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
final class TiffExifParserThreshholdingCellTagsTest extends TestCase
{
    /**
     * Threshholding=1 without cell tags is valid.
     */
    #[Test]
    public function acceptsThreshholdingOneWithoutCells(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildThreshholdingTiff(threshholding: 1),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::THRESHHOLDING));
    }

    /**
     * Threshholding=2 with both cell tags is valid.
     */
    #[Test]
    public function acceptsThreshholdingTwoWithCells(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildThreshholdingTiff(threshholding: 2, cellWidth: 8, cellLength: 8),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::CELL_WIDTH));
        self::assertNotNull($parsed->ifd0->get(TiffTag::CELL_LENGTH));
    }

    /**
     * Threshholding values must be in {1,2,3}.
     */
    #[Test]
    public function rejectsInvalidThreshholdingValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Threshholding value 4 is invalid');

        (new TiffExifParser())->parseFromBlob(
            $this->buildThreshholdingTiff(threshholding: 4),
        );
    }

    /**
     * Cell tags are only allowed when Threshholding=2.
     */
    #[Test]
    public function rejectsCellTagsWhenThreshholdingNotTwo(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('CellWidth/CellLength are only valid when Threshholding=2');

        (new TiffExifParser())->parseFromBlob(
            $this->buildThreshholdingTiff(threshholding: 1, cellWidth: 8, cellLength: 8),
        );
    }

    /**
     * Threshholding=2 requires both cell tags.
     */
    #[Test]
    public function rejectsMissingCellTagWhenThreshholdingIsTwo(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Threshholding=2 requires both CellWidth and CellLength');

        (new TiffExifParser())->parseFromBlob(
            $this->buildThreshholdingTiff(threshholding: 2, cellWidth: 8),
        );
    }

    /**
     * Cell sizes must be positive integers.
     */
    #[Test]
    public function rejectsNonPositiveCellSize(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('CellWidth must be > 0');

        (new TiffExifParser())->parseFromBlob(
            $this->buildThreshholdingTiff(threshholding: 2, cellWidth: 0, cellLength: 8),
        );
    }

    /**
     * Builds a minimal TIFF with optional threshholding/cell tags.
     */
    private function buildThreshholdingTiff(int $threshholding, ?int $cellWidth = null, ?int $cellLength = null): string
    {
        $entries    = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
            TiffTag::THRESHHOLDING => pack('v', TiffTag::THRESHHOLDING)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $threshholding) . pack('v', 0),
        ];

        if ($cellWidth !== null) {
            $entries[TiffTag::CELL_WIDTH] = pack('v', TiffTag::CELL_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $cellWidth) . pack('v', 0);
        }

        if ($cellLength !== null) {
            $entries[TiffTag::CELL_LENGTH] = pack('v', TiffTag::CELL_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $cellLength) . pack('v', 0);
        }

        ksort($entries);

        $ifdOffset  = 8;
        $entryCount = count($entries);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . implode('', $entries)
            . pack('V', 0);
    }
}
