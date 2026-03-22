<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Adobe\AdobeTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffFieldType;
use MagicSunday\ImageMeta\Parse\Icc\IccHeaderDecoder;
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Parse\Icc\IccTagDecoder;
use MagicSunday\ImageMeta\Parse\Tiff\DngCalibrationValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngGeometryValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngProfileValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngStructureValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngValidationSupport;
use MagicSunday\ImageMeta\Parse\Tiff\DngValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormalizer;
use MagicSunday\ImageMeta\Parse\Tiff\DngVersionValidator;
use MagicSunday\ImageMeta\Parse\Tiff\IfdParser;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffBinaryReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffByteOrderHandler;
use MagicSunday\ImageMeta\Parse\Tiff\TiffColorInkValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffImageDataValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffOffsetValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffSampleValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffStructuralValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffTagConstraintValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValidationSupport;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
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
#[UsesClass(ByteReader::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(PayloadGuard::class)]
#[UsesTrait(NormalizesOffsets::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(TiffFieldType::class)]
#[UsesClass(IccHeaderDecoder::class)]
#[UsesClass(IccParser::class)]
#[UsesClass(IccTagDecoder::class)]
#[UsesClass(DngCalibrationValidator::class)]
#[UsesClass(DngGeometryValidator::class)]
#[UsesClass(DngProfileValidator::class)]
#[UsesClass(DngStructureValidator::class)]
#[UsesClass(DngValidationSupport::class)]
#[UsesClass(DngValidator::class)]
#[UsesClass(DngVersionValidator::class)]
#[UsesClass(IfdParser::class)]
#[UsesClass(TiffByteOrderHandler::class)]
#[UsesClass(TiffColorInkValidator::class)]
#[UsesClass(TiffImageDataValidator::class)]
#[UsesClass(TiffJpegValidator::class)]
#[UsesClass(TiffSampleValidator::class)]
#[UsesClass(TiffStructuralValidator::class)]
#[UsesClass(TiffTagConstraintValidator::class)]
#[UsesClass(TiffValidationSupport::class)]
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
        $blob .= pack('v', AdobeTag::XMP_PACKET)
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
        $blob .= pack('v', AdobeTag::XMP_PACKET)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', 50)
            . pack('V', 9999);

        // Next IFD = 0
        $blob .= pack('V', 0);

        return $blob;
    }
}
