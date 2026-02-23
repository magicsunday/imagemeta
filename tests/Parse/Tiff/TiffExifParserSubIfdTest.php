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
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
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
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
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
     * Builds a minimal valid TIFF blob with the given number of SubIFDs.
     */
    private function buildTiffWithSubIfds(int $subIfdCount): string
    {
        // Layout:
        // [0..7]   TIFF header (8 bytes)
        // [8..]    IFD0 entries
        // After IFD0: SubIFD data blocks (each with their own IFD)

        $ifd0Offset = 8;

        // Build SubIFD entries first to know their offsets
        $subIfdBlobs   = [];
        $subIfdOffsets = [];

        // Entries for IFD0 (sorted by tag): ImageWidth, ImageLength, Compression, SubIFDs
        $ifd0EntryCount = 4;
        $ifd0Size       = 2 + (12 * $ifd0EntryCount) + 4; // entry count + entries + next offset

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
            $offset = $subIfdDataStart;
            foreach ($subIfdBlobs as $blob) {
                $offset += strlen($blob);
            }

            $subIfdOffsets[] = $offset;

            // Minimal SubIFD: 1 entry (ImageWidth SHORT 100), next=0
            $subIfdBlob = pack('v', 1) // entry count
                . pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100 + $i) . pack('v', 0)
                . pack('V', 0); // next IFD = 0

            $subIfdBlobs[] = $subIfdBlob;
        }

        // Build IFD0 entries (must be sorted by tag)
        $entries = [];

        // Compression SHORT[1] = 1 (tag 0x0103)
        $entries[ExifTag::COMPRESSION] = pack('v', ExifTag::COMPRESSION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0);

        // ImageWidth SHORT[1] = 64 (tag 0x0100)
        $entries[ExifTag::IMAGE_WIDTH] = pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 64) . pack('v', 0);

        // ImageLength SHORT[1] = 64 (tag 0x0101)
        $entries[ExifTag::IMAGE_LENGTH] = pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 64) . pack('v', 0);

        // SubIFDs LONG[N] (tag 0x014A)
        if ($subIfdCount === 1 && !$needsExternal) {
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

        ksort($entries);

        // Assemble IFD0
        $ifd0 = pack('v', count($entries));
        foreach ($entries as $entry) {
            $ifd0 .= $entry;
        }

        $ifd0 .= pack('V', 0); // no next IFD

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
        $entries = [
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

        ksort($entries);

        $ifd0 = pack('v', count($entries));
        foreach ($entries as $entry) {
            $ifd0 .= $entry;
        }

        $ifd0 .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . $ifd0;
    }

    /**
     * Builds a TIFF with a SubIFDs tag containing a single given offset.
     */
    private function buildTiffWithSubIfdOffset(int $offset): string
    {
        $entries = [
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
            TiffTag::SUB_IFDS => pack('v', TiffTag::SUB_IFDS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $offset),
        ];

        ksort($entries);

        $ifd0 = pack('v', count($entries));
        foreach ($entries as $entry) {
            $ifd0 .= $entry;
        }

        $ifd0 .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . $ifd0;
    }
}
