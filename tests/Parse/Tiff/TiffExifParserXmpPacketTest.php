<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
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

use function pack;
use function strlen;

/**
 * Exercises XMP packet extraction from TIFF IFD0 tag 700 (0x02BC).
 *
 * Adobe XMP Specification Part 3 defines tag 700 as the standard mechanism
 * for embedding XMP in TIFF files. The parser must capture the raw UTF-8
 * XMP/RDF XML bytes and expose them separately from the IFD entry.
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
final class TiffExifParserXmpPacketTest extends TestCase
{
    /**
     * Tag 700 (0x02BC) with a valid XMP packet is captured as xmpPacketRaw.
     *
     * Adobe XMP Part 3 — tag 700, type BYTE or UNDEFINED, UTF-8 encoded XMP/RDF XML.
     */
    #[Test]
    public function extractsXmpPacketFromIfd0Tag700(): void
    {
        $xmpPayload = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"/></x:xmpmeta>';
        $blob       = $this->buildTiffWithXmpPacket($xmpPayload);

        $result = (new TiffExifParser())->parseFromBlob($blob);

        self::assertSame($xmpPayload, $result->xmpPacketRaw);
    }

    /**
     * When no tag 700 is present, xmpPacketRaw is null.
     */
    #[Test]
    public function returnsNullWhenNoXmpPacketPresent(): void
    {
        $blob = $this->buildMinimalTiffWithoutXmp();

        $result = (new TiffExifParser())->parseFromBlob($blob);

        self::assertNull($result->xmpPacketRaw);
    }

    /**
     * Tag 700 pointing beyond the buffer boundary triggers a BoundsError
     * and the parser tolerates it (Postel's Law), returning null for xmpPacketRaw.
     */
    #[Test]
    public function toleratesTruncatedXmpPacketData(): void
    {
        $blob = $this->buildTiffWithTruncatedXmpPacket();

        $result = (new TiffExifParser())->parseFromBlob($blob);

        // Postel's Law: truncated IFD entry is skipped, xmpPacketRaw remains null
        self::assertNull($result->xmpPacketRaw);
    }

    /**
     * Builds a minimal classic TIFF (little-endian) with an XMP packet in IFD0 tag 700.
     *
     * Layout:
     *   [0..7]   TIFF header
     *   [8..]    IFD0 (3 entries: ImageWidth, ImageLength, tag 700) + next=0
     *   [..]     XMP payload
     */
    private function buildTiffWithXmpPacket(string $xmpPayload): string
    {
        // IFD0: 3 entries (ImageWidth, ImageLength, XMP Packet)
        $ifd0EntryCount = 3;
        $ifd0Offset     = 8; // right after header
        $ifd0Size       = 2 + ($ifd0EntryCount * 12) + 4; // count + entries + next pointer
        $xmpOffset      = $ifd0Offset + $ifd0Size;

        // TIFF header
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset);

        // IFD0 entries
        $blob .= pack('v', $ifd0EntryCount);

        // ImageWidth SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // ImageLength SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // Tag 700 (0x02BC) — XMP Packet, type UNDEFINED, external offset
        $blob .= pack('v', 0x02BC)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($xmpPayload))
            . pack('V', $xmpOffset);

        // Next IFD = 0
        $blob .= pack('V', 0);

        // XMP payload
        $blob .= $xmpPayload;

        return $blob;
    }

    /**
     * Builds a minimal classic TIFF without tag 700.
     */
    private function buildMinimalTiffWithoutXmp(): string
    {
        $ifd0Offset = 8;

        // TIFF header
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset);

        // IFD0: 2 entries (ImageWidth, ImageLength)
        $blob .= pack('v', 2);

        // ImageWidth SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // ImageLength SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // Next IFD = 0
        $blob .= pack('V', 0);

        return $blob;
    }

    /**
     * Builds a TIFF where tag 700 points beyond the buffer end.
     */
    private function buildTiffWithTruncatedXmpPacket(): string
    {
        $ifd0Offset = 8;

        // TIFF header
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset);

        // IFD0: 3 entries
        $blob .= pack('v', 3);

        // ImageWidth SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // ImageLength SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // Tag 700 pointing to offset 9999 (well beyond buffer end), count 50
        $blob .= pack('v', 0x02BC)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', 50)
            . pack('V', 9999);

        // Next IFD = 0
        $blob .= pack('V', 0);

        return $blob;
    }
}
