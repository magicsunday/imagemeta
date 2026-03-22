<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\PayloadGuard;
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
use PHPUnit\Framework\TestCase;

use function pack;

/**
 * Verifies that reusing a TiffExifParser instance across multiple blobs
 * correctly resets internal state, particularly the bigTiff flag.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(Endian::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(PayloadGuard::class)]
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
final class TiffExifParserReuseTest extends TestCase
{
    /**
     * Parsing a classic TIFF after a BigTIFF with the same parser instance must
     * produce correct results. Without resetting bigTiff in initializeState(),
     * the TiffBinaryReader uses an 8-byte inline threshold instead of 4-byte,
     * corrupting all subsequent IFD entries.
     */
    #[Test]
    public function classicTiffAfterBigTiffProducesCorrectResults(): void
    {
        $parser = new TiffExifParser();

        // First parse: BigTIFF blob
        $bigTiffBlob = $this->buildMinimalBigTiffBlob();
        $bigResult   = $parser->parseFromBlob($bigTiffBlob);

        self::assertCount(2, $bigResult->ifd0->entries);

        // Second parse: classic TIFF blob using the SAME parser instance
        $classicBlob   = $this->buildMinimalClassicTiffBlob();
        $classicResult = $parser->parseFromBlob($classicBlob);

        self::assertCount(2, $classicResult->ifd0->entries);

        $width = $classicResult->ifd0->get(ExifTag::IMAGE_WIDTH);
        self::assertInstanceOf(IfdEntry::class, $width);
        self::assertSame(640, $width->value);

        $length = $classicResult->ifd0->get(ExifTag::IMAGE_LENGTH);
        self::assertInstanceOf(IfdEntry::class, $length);
        self::assertSame(480, $length->value);
    }

    /**
     * Builds a minimal BigTIFF blob with two SHORT entries (ImageWidth, ImageLength).
     */
    private function buildMinimalBigTiffBlob(): string
    {
        // BigTIFF header: LE byte order, magic 0x002B, offset-size=8, reserved=0, first IFD at 16
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_BIG)
            . pack('v', 8)
            . pack('v', 0)
            . pack('P', 16);

        // IFD with 2 entries (64-bit count)
        $blob .= pack('P', 2);

        // ImageWidth SHORT[1] = 1920
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('P', 1)
            . pack('v', 1920) . pack('a6', '');

        // ImageLength SHORT[1] = 1080
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('P', 1)
            . pack('v', 1080) . pack('a6', '');

        // Next IFD offset (none)
        $blob .= pack('P', 0);

        return $blob;
    }

    /**
     * Builds a minimal classic TIFF blob with two SHORT entries (ImageWidth, ImageLength).
     */
    private function buildMinimalClassicTiffBlob(): string
    {
        // Classic TIFF header: LE byte order, magic 42, first IFD at offset 8
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        // IFD with 2 entries (16-bit count)
        $blob .= pack('v', 2);

        // ImageWidth SHORT[1] = 640 (inline: 2 value bytes + 2 padding in 4-byte field)
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 640) . pack('v', 0);

        // ImageLength SHORT[1] = 480
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 480) . pack('v', 0);

        // Next IFD offset (none)
        $blob .= pack('V', 0);

        return $blob;
    }
}
