<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

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
use function ksort;
use function pack;
use function strlen;

/**
 * Verifies SubIFD tree parsing (Tag 0x014A).
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
final class TiffExifParserSubIfdTest extends TestCase
{
    /**
     * A single SubIFD pointer resolves to a parsed IFD.
     */
    #[Test]
    public function parsesSingleSubIfd(): void
    {
        $blob   = $this->buildTiffWithSubIfds(1);
        $parsed = (new TiffExifParser())->parseFromBlob($blob);

        self::assertCount(1, $parsed->subIfds());
    }

    /**
     * Two SubIFD pointers resolve to two parsed IFDs.
     */
    #[Test]
    public function parsesMultipleSubIfds(): void
    {
        $blob   = $this->buildTiffWithSubIfds(2);
        $parsed = (new TiffExifParser())->parseFromBlob($blob);

        self::assertCount(2, $parsed->subIfds());
    }

    /**
     * IFD0 without SubIFDs tag results in empty subIfds array.
     */
    #[Test]
    public function emptyWhenNoSubIfdTag(): void
    {
        $blob   = $this->buildMinimalTiff();
        $parsed = (new TiffExifParser())->parseFromBlob($blob);

        self::assertSame([], $parsed->subIfds());
    }

    /**
     * SubIFDs pointer with offset 0 produces no sub-IFDs.
     */
    #[Test]
    public function skipsZeroOffsetSubIfd(): void
    {
        $blob   = $this->buildTiffWithSubIfdOffset(0);
        $parsed = (new TiffExifParser())->parseFromBlob($blob);

        self::assertSame([], $parsed->subIfds());
    }

    /**
     * ExifIFD pointer with wrong field type is skipped gracefully.
     */
    #[Test]
    public function skipsExifIfdWithWrongPointerType(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithBadPointer(ExifTag::EXIF_IFD_POINTER),
        );

        self::assertNull($parsed->exifIfd);
        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * GPSIFD pointer with non-numeric offset is skipped gracefully.
     */
    #[Test]
    public function skipsGpsIfdWithNonNumericOffset(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithBadPointer(ExifTag::GPS_IFD_POINTER),
        );

        self::assertNull($parsed->gpsIfd);
        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * InteropIFD pointer with out-of-bounds offset is skipped gracefully.
     */
    #[Test]
    public function skipsInteropIfdWithOutOfBoundsOffset(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithOutOfBoundsInteropPointer(),
        );

        self::assertNull($parsed->interopIfd);
        self::assertNotNull($parsed->exifIfd);
    }

    /**
     * A cyclic SubIFD reference is silently skipped — parsing completes.
     */
    #[Test]
    public function toleratesCyclicSubIfdReference(): void
    {
        $blob   = $this->buildTiffWithCyclicSubIfd();
        $parsed = (new TiffExifParser())->parseFromBlob($blob);

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * TIFF 6.0 §2 defines byte offsets as file-relative; invalid offsets must not abort reader parsing.
     * DNG 1.7.1.0 §DNG Format Overview (SubIFD Trees) allows SubIFD usage but does not require all pointers to be usable.
     */
    #[Test]
    public function itToleratesIfdPointerExceedingTiffDataLength(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithOutOfRangeAndValidSubIfdPointers(),
        );

        self::assertCount(1, $parsed->subIfds());
        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Builds a minimal valid TIFF blob with the given number of SubIFDs.
     */
    private function buildTiffWithSubIfds(int $subIfdCount): string
    {
        // Layout:
        // [0..7]   TIFF header (8 bytes)
        // [8..]    IFD0 entries
        // After IFD0: SubIFD data blocks (each with their own IFD)

        $ifd0Offset      = 8;

        // Build SubIFD entries first to know their offsets
        $subIfdBlobs     = [];
        $subIfdOffsets   = [];

        // Entries for IFD0 (sorted by tag): ImageWidth, ImageLength, Compression, SubIFDs
        $ifd0EntryCount  = 4;
        $ifd0Size        = 2 + (12 * $ifd0EntryCount) + 4; // entry count + entries + next offset

        // SubIFDs value: array of LONG offsets, stored inline if <= 4 bytes, else external
        $subIfdValueSize = 4 * $subIfdCount;
        $needsExternal   = $subIfdValueSize > 4;

        // External data starts after IFD0
        $externalStart   = $ifd0Offset + $ifd0Size;
        $subIfdDataStart = $externalStart;

        if ($needsExternal) {
            $subIfdDataStart += $subIfdValueSize; // skip SubIFDs offset array
        }

        // Build SubIFD blobs — each has 1 entry (ImageWidth) + next=0
        for ($i = 0; $i < $subIfdCount; ++$i) {
            $offset          = $subIfdDataStart;

            foreach ($subIfdBlobs as $blob) {
                $offset += strlen($blob);
            }

            $subIfdOffsets[] = $offset;

            // Minimal SubIFD: 1 entry (ImageWidth SHORT 100), next=0
            $subIfdBlob      = pack('v', 1) // entry count
                . pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100 + $i) . pack('v', 0)
                . pack('V', 0); // next IFD = 0

            $subIfdBlobs[]   = $subIfdBlob;
        }

        // Build IFD0 entries (must be sorted by tag)
        $entries         = $this->baseIfd0Entries();

        // SubIFDs LONG[N] (tag 0x014A)
        if (($subIfdCount === 1) && !$needsExternal) {
            $entries[TiffTag::SUB_IFDS] = pack('v', TiffTag::SUB_IFDS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $subIfdOffsets[0]);
        } else {
            $entries[TiffTag::SUB_IFDS] = pack('v', TiffTag::SUB_IFDS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', $subIfdCount)
                . pack('V', $externalStart);
        }

        $ifd0            = $this->assembleIfdBlock($entries);

        // External SubIFD offset array
        $externalOffsets = '';

        if ($needsExternal) {
            foreach ($subIfdOffsets as $offset) {
                $externalOffsets .= pack('V', $offset);
            }
        }

        // Assemble TIFF
        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $ifd0
            . $externalOffsets
            . implode('', $subIfdBlobs);
    }

    /**
     * Builds a minimal TIFF without SubIFDs tag.
     */
    private function buildMinimalTiff(): string
    {
        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . $this->assembleIfdBlock($this->baseIfd0Entries());
    }

    /**
     * Builds a TIFF with a SubIFDs tag containing a single given offset.
     */
    private function buildTiffWithSubIfdOffset(int $offset): string
    {
        $entries = $this->baseIfd0Entries() + [
            TiffTag::SUB_IFDS => pack('v', TiffTag::SUB_IFDS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $offset),
        ];

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . $this->assembleIfdBlock($entries);
    }

    /**
     * Builds a TIFF where the given pointer tag uses TYPE_ASCII (non-numeric value).
     */
    private function buildTiffWithBadPointer(int $pointerTag): string
    {
        $entries = $this->baseIfd0Entries() + [
            $pointerTag => pack('v', $pointerTag)
                . pack('v', TiffConst::TYPE_ASCII)
                . pack('V', 4)
                . 'abcd',
        ];

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . $this->assembleIfdBlock($entries);
    }

    /**
     * Builds a TIFF with IFD0 → valid ExifIFD → out-of-bounds InteropIFD pointer.
     */
    private function buildTiffWithOutOfBoundsInteropPointer(): string
    {
        $ifd0Offset     = 8;

        // IFD0: IMAGE_WIDTH, IMAGE_LENGTH, COMPRESSION, EXIF_IFD_POINTER
        $ifd0EntryCount = 4;
        $ifd0Size       = 2 + (12 * $ifd0EntryCount) + 4;
        $exifIfdOffset  = $ifd0Offset + $ifd0Size;

        $ifd0Entries    = $this->baseIfd0Entries() + [
            ExifTag::EXIF_IFD_POINTER => pack('v', ExifTag::EXIF_IFD_POINTER)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $exifIfdOffset),
        ];

        $exifEntries    = [
            ExifTag::COLOR_SPACE => pack('v', ExifTag::COLOR_SPACE)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 1) . pack('v', 0),
            ExifTag::INTEROPERABILITY_IFD_POINTER => pack('v', ExifTag::INTEROPERABILITY_IFD_POINTER)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', 99999),
        ];

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $this->assembleIfdBlock($ifd0Entries)
            . $this->assembleIfdBlock($exifEntries);
    }

    /**
     * Builds a TIFF where the SubIFDs tag has two pointers to the same offset (cycle).
     */
    private function buildTiffWithCyclicSubIfd(): string
    {
        $ifd0Offset      = 8;

        // IFD0: 4 entries (ImageWidth, ImageLength, Compression, SubIFDs)
        $ifd0EntryCount  = 4;
        $ifd0Size        = 2 + (12 * $ifd0EntryCount) + 4;

        // External SubIFDs offset array (2 LONGs = 8 bytes)
        $externalStart   = $ifd0Offset + $ifd0Size;

        // SubIFD starts after the external offsets array
        $subIfdOffset    = $externalStart + 8;

        // SubIFD: 1 entry (ImageWidth)
        $subIfdBlock     = pack('v', 1) // entry count
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 200) . pack('v', 0)
            . pack('V', 0); // no next IFD

        // IFD0 entries — SubIFDs has count=2, both pointing to same offset
        $ifd0Entries     = $this->baseIfd0Entries() + [
            TiffTag::SUB_IFDS => pack('v', TiffTag::SUB_IFDS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 2)
                . pack('V', $externalStart), // offset to external value
        ];

        // External offset array: two pointers to the same SubIFD (cycle)
        $externalOffsets = pack('V', $subIfdOffset) . pack('V', $subIfdOffset);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $this->assembleIfdBlock($ifd0Entries)
            . $externalOffsets
            . $subIfdBlock;
    }

    /**
     * Builds a TIFF with SubIFDs LONG[2]: one out-of-range pointer and one valid pointer.
     */
    private function buildTiffWithOutOfRangeAndValidSubIfdPointers(): string
    {
        $ifd0Offset      = 8;

        // IFD0: 4 entries (ImageWidth, ImageLength, Compression, SubIFDs)
        $ifd0EntryCount  = 4;
        $ifd0Size        = 2 + (12 * $ifd0EntryCount) + 4;

        // External SubIFDs offset array (2 LONGs = 8 bytes)
        $externalStart   = $ifd0Offset + $ifd0Size;

        // Valid SubIFD starts after the external offsets array
        $subIfdOffset    = $externalStart + 8;

        // Minimal SubIFD: 1 entry (ImageWidth)
        $subIfdBlock     = pack('v', 1) // entry count
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 200) . pack('v', 0)
            . pack('V', 0); // no next IFD

        $ifd0Entries     = $this->baseIfd0Entries() + [
            TiffTag::SUB_IFDS => pack('v', TiffTag::SUB_IFDS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 2)
                . pack('V', $externalStart),
        ];

        $externalOffsets = pack('V', 99999) . pack('V', $subIfdOffset);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $this->assembleIfdBlock($ifd0Entries)
            . $externalOffsets
            . $subIfdBlock;
    }

    /**
     * Returns the three base IFD0 entries shared across all test builders.
     *
     * @return array<int, string>
     */
    private function baseIfd0Entries(): array
    {
        return [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
            ExifTag::COMPRESSION => pack('v', ExifTag::COMPRESSION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 1) . pack('v', 0),
        ];
    }

    /**
     * Sorts entries by tag and assembles a TIFF IFD block with next-IFD offset = 0.
     *
     * @param array<int, string> $entries
     */
    private function assembleIfdBlock(array $entries): string
    {
        ksort($entries);

        $block = pack('v', count($entries));

        foreach ($entries as $entry) {
            $block .= $entry;
        }

        return $block . pack('V', 0);
    }
}
