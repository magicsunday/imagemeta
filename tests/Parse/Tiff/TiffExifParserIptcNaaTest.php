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
use MagicSunday\ImageMeta\Model\Iptc\IptcTag;
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
 * Exercises IPTC/NAA extraction from TIFF IFD0 tag 33723 (0x83BB).
 *
 * Tag 33723 is the standard TIFF mechanism for embedding IPTC-IIM metadata.
 * The parser must capture the raw IPTC binary and expose it via ParsedExif.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(IptcTag::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserIptcNaaTest extends TestCase
{
    /**
     * Tag 33723 (0x83BB) with a valid IPTC payload is captured as iptcNaaRaw.
     *
     * IPTC-IIM — tag 33723, type LONG or UNDEFINED.
     */
    #[Test]
    public function extractsIptcNaaFromIfd0Tag0x83BB(): void
    {
        // Minimal synthetic IPTC-IIM record: 1C 02 78 (record 2, dataset 120 = Caption)
        $iptcPayload = "\x1C\x02\x78\x00\x05Hello";
        $blob        = $this->buildTiffWithIptcNaa($iptcPayload);

        $result      = (new TiffExifParser())->parseFromBlob($blob);

        self::assertSame($iptcPayload, $result->iptcNaaRaw);
    }

    /**
     * When no tag 33723 is present, iptcNaaRaw is null.
     */
    #[Test]
    public function returnsNullWhenNoIptcNaaPresent(): void
    {
        $blob   = $this->buildMinimalTiffWithoutIptc();

        $result = (new TiffExifParser())->parseFromBlob($blob);

        self::assertNull($result->iptcNaaRaw);
    }

    /**
     * Tag 33723 pointing beyond the buffer boundary is tolerated
     * and iptcNaaRaw remains null (Postel's Law).
     */
    #[Test]
    public function toleratesTruncatedIptcNaaData(): void
    {
        $blob   = $this->buildTiffWithTruncatedIptcNaa();

        $result = (new TiffExifParser())->parseFromBlob($blob);

        self::assertNull($result->iptcNaaRaw);
    }

    /**
     * Builds a classic TIFF with an IPTC/NAA record in IFD0 tag 0x83BB.
     */
    private function buildTiffWithIptcNaa(string $iptcPayload): string
    {
        $ifd0EntryCount = 3;
        $ifd0Offset     = 8;
        $ifd0Size       = 2 + ($ifd0EntryCount * 12) + 4;
        $iptcOffset     = $ifd0Offset + $ifd0Size;

        $blob           = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset);

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

        // Tag 0x83BB — IPTC/NAA, type UNDEFINED, external offset
        $blob .= pack('v', IptcTag::IPTC_NAA)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($iptcPayload))
            . pack('V', $iptcOffset);

        $blob .= pack('V', 0); // Next IFD = 0

        $blob .= $iptcPayload;

        return $blob;
    }

    /**
     * Builds a minimal classic TIFF without tag 0x83BB.
     */
    private function buildMinimalTiffWithoutIptc(): string
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 2);

        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        return $blob . pack('V', 0);
    }

    /**
     * Builds a TIFF where tag 0x83BB points beyond the buffer end.
     */
    private function buildTiffWithTruncatedIptcNaa(): string
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 3);

        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // Tag 0x83BB pointing to offset 9999, count 200
        $blob .= pack('v', IptcTag::IPTC_NAA)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', 200)
            . pack('V', 9999);

        return $blob . pack('V', 0);
    }
}
