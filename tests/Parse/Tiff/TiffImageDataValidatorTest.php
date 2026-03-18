<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffImageDataValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValidationSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Verifies strip and tile image-data layout validation logic.
 *
 * @internal
 */
#[CoversClass(TiffImageDataValidator::class)]
#[UsesClass(TiffValidationSupport::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifNumericList::class)]
final class TiffImageDataValidatorTest extends TestCase
{
    // --- StripLayout ---

    #[Test]
    public function acceptsValidStripLayout(): void
    {
        $validator = $this->createValidator(2048);

        $ifd       = new Ifd([
            ExifTag::IMAGE_WIDTH       => new IfdEntry(ExifTag::IMAGE_WIDTH, TiffConst::TYPE_SHORT, 1, 64),
            ExifTag::IMAGE_LENGTH      => new IfdEntry(ExifTag::IMAGE_LENGTH, TiffConst::TYPE_SHORT, 1, 64),
            ExifTag::ROWS_PER_STRIP    => new IfdEntry(ExifTag::ROWS_PER_STRIP, TiffConst::TYPE_SHORT, 1, 64),
            ExifTag::STRIP_OFFSETS     => new IfdEntry(ExifTag::STRIP_OFFSETS, TiffConst::TYPE_LONG, 1, 0),
            ExifTag::STRIP_BYTE_COUNTS => new IfdEntry(ExifTag::STRIP_BYTE_COUNTS, TiffConst::TYPE_LONG, 1, 100),
        ]);

        $validator->validateStripLayoutConsistency($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsMissingRowsPerStripWhenStripTagsPresent(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('RowsPerStrip must be a positive integer');

        $validator = $this->createValidator();

        $ifd       = new Ifd([
            ExifTag::IMAGE_WIDTH       => new IfdEntry(ExifTag::IMAGE_WIDTH, TiffConst::TYPE_SHORT, 1, 64),
            ExifTag::IMAGE_LENGTH      => new IfdEntry(ExifTag::IMAGE_LENGTH, TiffConst::TYPE_SHORT, 1, 64),
            ExifTag::STRIP_OFFSETS     => new IfdEntry(ExifTag::STRIP_OFFSETS, TiffConst::TYPE_LONG, 1, 0),
            ExifTag::STRIP_BYTE_COUNTS => new IfdEntry(ExifTag::STRIP_BYTE_COUNTS, TiffConst::TYPE_LONG, 1, 100),
        ]);

        $validator->validateStripLayoutConsistency($ifd);
    }

    #[Test]
    public function rejectsStripOffsetCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('StripOffsets count 2 does not match expected strip count 1');

        $validator = $this->createValidator(2048);

        $ifd       = new Ifd([
            ExifTag::IMAGE_WIDTH    => new IfdEntry(ExifTag::IMAGE_WIDTH, TiffConst::TYPE_SHORT, 1, 64),
            ExifTag::IMAGE_LENGTH   => new IfdEntry(ExifTag::IMAGE_LENGTH, TiffConst::TYPE_SHORT, 1, 64),
            ExifTag::ROWS_PER_STRIP => new IfdEntry(ExifTag::ROWS_PER_STRIP, TiffConst::TYPE_SHORT, 1, 64),
            ExifTag::STRIP_OFFSETS  => new IfdEntry(ExifTag::STRIP_OFFSETS, TiffConst::TYPE_LONG, 2, new ExifNumericList([0, 100])),
        ]);

        $validator->validateStripLayoutConsistency($ifd);
    }

    // --- TileLayout ---

    #[Test]
    public function acceptsValidTileLayout(): void
    {
        $validator = $this->createValidator(65536);

        $ifd       = new Ifd([
            ExifTag::IMAGE_WIDTH      => new IfdEntry(ExifTag::IMAGE_WIDTH, TiffConst::TYPE_SHORT, 1, 64),
            ExifTag::IMAGE_LENGTH     => new IfdEntry(ExifTag::IMAGE_LENGTH, TiffConst::TYPE_SHORT, 1, 64),
            TiffTag::TILE_WIDTH       => new IfdEntry(TiffTag::TILE_WIDTH, TiffConst::TYPE_LONG, 1, 64),
            TiffTag::TILE_LENGTH      => new IfdEntry(TiffTag::TILE_LENGTH, TiffConst::TYPE_LONG, 1, 64),
            TiffTag::TILE_OFFSETS     => new IfdEntry(TiffTag::TILE_OFFSETS, TiffConst::TYPE_LONG, 1, 0),
            TiffTag::TILE_BYTE_COUNTS => new IfdEntry(TiffTag::TILE_BYTE_COUNTS, TiffConst::TYPE_LONG, 1, 100),
        ]);

        $validator->validateTileLayoutConsistency($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsTileWidthNotMultipleOf16(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TileWidth 15 must be an integer multiple of 16');

        $validator = $this->createValidator();

        $ifd       = new Ifd([
            TiffTag::TILE_WIDTH       => new IfdEntry(TiffTag::TILE_WIDTH, TiffConst::TYPE_LONG, 1, 15),
            TiffTag::TILE_LENGTH      => new IfdEntry(TiffTag::TILE_LENGTH, TiffConst::TYPE_LONG, 1, 16),
            TiffTag::TILE_OFFSETS     => new IfdEntry(TiffTag::TILE_OFFSETS, TiffConst::TYPE_LONG, 1, 0),
            TiffTag::TILE_BYTE_COUNTS => new IfdEntry(TiffTag::TILE_BYTE_COUNTS, TiffConst::TYPE_LONG, 1, 100),
        ]);

        $validator->validateTileLayoutConsistency($ifd);
    }

    #[Test]
    public function rejectsMixedStripAndTileLayout(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Strip and tile layout tags must not be mixed');

        $validator = $this->createValidator();

        $ifd       = new Ifd([
            ExifTag::ROWS_PER_STRIP   => new IfdEntry(ExifTag::ROWS_PER_STRIP, TiffConst::TYPE_SHORT, 1, 64),
            TiffTag::TILE_WIDTH       => new IfdEntry(TiffTag::TILE_WIDTH, TiffConst::TYPE_LONG, 1, 64),
            TiffTag::TILE_LENGTH      => new IfdEntry(TiffTag::TILE_LENGTH, TiffConst::TYPE_LONG, 1, 64),
            TiffTag::TILE_OFFSETS     => new IfdEntry(TiffTag::TILE_OFFSETS, TiffConst::TYPE_LONG, 1, 0),
            TiffTag::TILE_BYTE_COUNTS => new IfdEntry(TiffTag::TILE_BYTE_COUNTS, TiffConst::TYPE_LONG, 1, 100),
        ]);

        $validator->validateTileLayoutConsistency($ifd);
    }

    #[Test]
    public function rejectsMissingTileOffsetsOrByteCounts(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TileOffsets and TileByteCounts must both be present');

        $validator = $this->createValidator();

        $ifd       = new Ifd([
            TiffTag::TILE_WIDTH   => new IfdEntry(TiffTag::TILE_WIDTH, TiffConst::TYPE_LONG, 1, 16),
            TiffTag::TILE_LENGTH  => new IfdEntry(TiffTag::TILE_LENGTH, TiffConst::TYPE_LONG, 1, 16),
            TiffTag::TILE_OFFSETS => new IfdEntry(TiffTag::TILE_OFFSETS, TiffConst::TYPE_LONG, 1, 0),
        ]);

        $validator->validateTileLayoutConsistency($ifd);
    }

    private function createValidator(int $blobSize = 1024): TiffImageDataValidator
    {
        $buffer  = new MemoryBuffer(str_repeat("\0", $blobSize));
        $support = new TiffValidationSupport($buffer);

        return new TiffImageDataValidator($support);
    }
}
