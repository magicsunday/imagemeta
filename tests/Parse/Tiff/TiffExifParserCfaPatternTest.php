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
use MagicSunday\ImageMeta\Core\ParseError;
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
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\ValueConverters;
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
 * Validates CFAPattern (0xA302) structured payload parsing per EXIF 3.0 §4.6.6.7.34.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifTag::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffConst::class)]
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
#[UsesClass(IfdValueReader::class)]
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
final class TiffExifParserCfaPatternTest extends TestCase
{
    /**
     * A valid 2x2 Bayer pattern parses to an ExifNumericList.
     */
    #[Test]
    public function parsesValidCfaPattern(): void
    {
        // 2x2 RGGB pattern
        $payload = pack('v', 2) . pack('v', 2) . "\x00\x01\x01\x02";
        $result  = $this->parseWithCfaPattern($payload);

        $entry = $result->exifIfd?->get(ExifTag::CFA_PATTERN);
        self::assertNotNull($entry);
        self::assertInstanceOf(ExifNumericList::class, $entry->value);
    }

    /**
     * Payload shorter than 4 bytes is rejected.
     */
    #[Test]
    public function rejectsCfaPatternPayloadTooShort(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('CFAPattern payload is too short');

        $this->parseWithCfaPattern("\x02\x00");
    }

    /**
     * Zero horizontal repeat unit is rejected.
     */
    #[Test]
    public function rejectsCfaPatternZeroHorizontalRepeat(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('non-zero');

        $this->parseWithCfaPattern(pack('v', 0) . pack('v', 2) . "\x00\x01");
    }

    /**
     * Zero vertical repeat unit is rejected.
     */
    #[Test]
    public function rejectsCfaPatternZeroVerticalRepeat(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('non-zero');

        $this->parseWithCfaPattern(pack('v', 2) . pack('v', 0) . "\x00\x01");
    }

    /**
     * Payload size mismatch is tolerated and available CFAPattern bytes are kept.
     */
    #[Test]
    public function itToleratesCfaPatternPayloadSizeMismatch(): void
    {
        // Declares 2x2 (4 pattern bytes expected) but only 2 bytes are available.
        $result = $this->parseWithCfaPattern(pack('v', 2) . pack('v', 2) . "\x00\x01");

        $entry = $result->exifIfd?->get(ExifTag::CFA_PATTERN);
        self::assertNotNull($entry);
        self::assertInstanceOf(ExifNumericList::class, $entry->value);
        self::assertSame([2, 2, 0, 1], $entry->value->values);
    }

    /**
     * Trailing extra bytes after the pattern matrix are rejected.
     */
    #[Test]
    public function rejectsCfaPatternWithTrailingBytes(): void
    {
        // 2x2 = 4 pattern bytes, but 5 provided
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('does not match');

        $this->parseWithCfaPattern(pack('v', 2) . pack('v', 2) . "\x00\x01\x01\x02\xFF");
    }

    /**
     * A CFA matrix byte with an extended code (> 7) is silently accepted.
     */
    #[Test]
    public function toleratesCfaPatternWithExtendedCode(): void
    {
        // 1x1 pattern with code 8 (extended, beyond EXIF 3.0 Table 13)
        $result = $this->parseWithCfaPattern(pack('v', 1) . pack('v', 1) . "\x08");

        $entry = $result->exifIfd?->get(ExifTag::CFA_PATTERN);
        self::assertNotNull($entry);
        self::assertInstanceOf(ExifNumericList::class, $entry->value);
    }

    /**
     * A CFA matrix with mixed standard and extended codes is silently accepted.
     */
    #[Test]
    public function toleratesCfaPatternWithMixedExtendedCodes(): void
    {
        // 2x2 pattern: codes 0, 1, 2, 255 — last one is extended
        $result = $this->parseWithCfaPattern(pack('v', 2) . pack('v', 2) . "\x00\x01\x02\xFF");

        $entry = $result->exifIfd?->get(ExifTag::CFA_PATTERN);
        self::assertNotNull($entry);
        self::assertInstanceOf(ExifNumericList::class, $entry->value);
    }

    /**
     * Builds a minimal TIFF with a CFAPattern tag in the EXIF sub-IFD.
     */
    private function parseWithCfaPattern(string $payload): ParsedExif
    {
        $ifd0Offset      = 8;
        $ifd0EntryCount  = 3;
        $ifd0Size        = 2 + ($ifd0EntryCount * 12) + 4;
        $exifIfdOffset   = $ifd0Offset + $ifd0Size;
        $exifEntryCount  = 1;
        $exifIfdSize     = 2 + ($exifEntryCount * 12) + 4;
        $payloadOffset   = $exifIfdOffset + $exifIfdSize;
        $payloadByteSize = strlen($payload);

        $ifd0 = pack('v', $ifd0EntryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $exifIfdOffset)
            . pack('V', 0);

        $exifIfd = pack('v', $exifEntryCount)
            . pack('v', ExifTag::CFA_PATTERN)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', $payloadByteSize)
            . pack('V', $payloadOffset)
            . pack('V', 0);

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $ifd0
            . $exifIfd
            . $payload;

        return (new TiffExifParser())->parseFromBlob($blob);
    }
}
