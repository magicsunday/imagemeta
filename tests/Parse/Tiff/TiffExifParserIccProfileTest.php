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
use MagicSunday\ImageMeta\Model\Tiff\TiffFieldType;
use MagicSunday\ImageMeta\Model\Tiff\TiffItTag;
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
use function str_repeat;
use function strlen;

/**
 * Exercises ICC profile extraction from TIFF IFD0 tag 34675 (0x8773).
 *
 * TIFF 6.0 §Appendix (TIFF/IT) and ICC.1 define tag 34675 as the standard
 * mechanism for embedding ICC color profiles in TIFF files. The parser must
 * capture the raw ICC binary and expose it via ParsedExif.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(TiffItTag::class)]
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
final class TiffExifParserIccProfileTest extends TestCase
{
    /**
     * Tag 34675 (0x8773) with a valid ICC profile payload is captured as iccProfileRaw.
     *
     * TIFF 6.0 §Appendix (TIFF/IT); ICC.1 — tag 34675, type UNDEFINED.
     */
    #[Test]
    public function extractsIccProfileFromIfd0Tag0x8773(): void
    {
        // Minimal synthetic ICC profile (128-byte header + minimal structure)
        $iccPayload = $this->buildMinimalIccProfile();
        $blob       = $this->buildTiffWithIccProfile($iccPayload);

        $result = (new TiffExifParser())->parseFromBlob($blob);

        self::assertSame($iccPayload, $result->iccProfileRaw);
    }

    /**
     * When no tag 34675 is present, iccProfileRaw is null.
     */
    #[Test]
    public function returnsNullWhenNoIccProfilePresent(): void
    {
        $blob = $this->buildMinimalTiffWithoutIcc();

        $result = (new TiffExifParser())->parseFromBlob($blob);

        self::assertNull($result->iccProfileRaw);
    }

    /**
     * Tag 34675 pointing beyond the buffer boundary is tolerated
     * and iccProfileRaw remains null (Postel's Law).
     */
    #[Test]
    public function toleratesTruncatedIccProfileData(): void
    {
        $blob = $this->buildTiffWithTruncatedIccProfile();

        $result = (new TiffExifParser())->parseFromBlob($blob);

        self::assertNull($result->iccProfileRaw);
    }

    /**
     * Builds a minimal synthetic ICC profile (128-byte header only).
     */
    private function buildMinimalIccProfile(): string
    {
        // ICC.1:2022 §7.2 — 128-byte header
        $header = pack('N', 128)       // Profile size
            . 'appl'                   // CMM type
            . pack('N', 0x02100000)    // Version 2.1.0
            . 'mntr'                   // Profile class (monitor)
            . 'RGB '                   // Color space
            . 'XYZ '                   // PCS
            . str_repeat("\0", 12)     // Date/time
            . 'acsp'                   // Signature
            . str_repeat("\0", 4)      // Primary platform
            . str_repeat("\0", 52);    // Remaining header fields

        return $header;
    }

    /**
     * Builds a classic TIFF with an ICC profile in IFD0 tag 0x8773.
     */
    private function buildTiffWithIccProfile(string $iccPayload): string
    {
        $ifd0EntryCount = 3;
        $ifd0Offset     = 8;
        $ifd0Size       = 2 + ($ifd0EntryCount * 12) + 4;
        $iccOffset      = $ifd0Offset + $ifd0Size;

        $blob = 'II'
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

        // Tag 0x8773 — ICC Profile, type UNDEFINED, external offset
        $blob .= pack('v', TiffItTag::ICC_PROFILE)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($iccPayload))
            . pack('V', $iccOffset);

        $blob .= pack('V', 0); // Next IFD = 0

        $blob .= $iccPayload;

        return $blob;
    }

    /**
     * Builds a minimal classic TIFF without tag 0x8773.
     */
    private function buildMinimalTiffWithoutIcc(): string
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
     * Builds a TIFF where tag 0x8773 points beyond the buffer end.
     */
    private function buildTiffWithTruncatedIccProfile(): string
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

        // Tag 0x8773 pointing to offset 9999, count 200
        $blob .= pack('v', TiffItTag::ICC_PROFILE)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', 200)
            . pack('V', 9999);

        return $blob . pack('V', 0);
    }
}
