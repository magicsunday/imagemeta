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
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Tiff\TiffFieldType;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
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

use function count;

/**
 * Verifies TIFF fax options coupling and bitfield constraints.
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
final class TiffExifParserFaxOptionsTest extends TestCase
{
    /**
     * Compression=3 with legal T4Options bits parses.
     */
    #[Test]
    public function acceptsValidT4OptionsWithCcittGroup3Compression(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithFaxOptionsInThirdIfd(compression: 3, t4Options: 0b111, t6Options: null),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * T4Options is rejected when compression is not CCITT Group 3.
     */
    #[Test]
    public function rejectsT4OptionsWithWrongCompression(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('T4Options is only valid when Compression = 3');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithFaxOptionsInThirdIfd(compression: 4, t4Options: 0b001, t6Options: null),
        );
    }

    /**
     * T4Options reserved bits must be zero.
     */
    #[Test]
    public function rejectsT4OptionsReservedBits(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('only bits 0..2 are allowed');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithFaxOptionsInThirdIfd(compression: 3, t4Options: 0b1000, t6Options: null),
        );
    }

    /**
     * Compression=4 with legal T6Options bit mask parses.
     */
    #[Test]
    public function acceptsValidT6OptionsWithCcittGroup4Compression(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithFaxOptionsInThirdIfd(compression: 4, t4Options: null, t6Options: 0b10),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_LENGTH));
    }

    /**
     * T6Options is rejected when compression is not CCITT Group 4.
     */
    #[Test]
    public function rejectsT6OptionsWithWrongCompression(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('T6Options is only valid when Compression = 4');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithFaxOptionsInThirdIfd(compression: 3, t4Options: null, t6Options: 0b10),
        );
    }

    /**
     * T6Options rejects reserved bit 0 and any higher reserved bits.
     */
    #[Test]
    public function rejectsT6OptionsReservedBits(): void
    {
        $cases = [0b1, 0b100];

        foreach ($cases as $value) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildTiffWithFaxOptionsInThirdIfd(compression: 4, t4Options: null, t6Options: $value),
                );
                self::fail('Expected ParseError for invalid T6Options bitfield.');
            } catch (ParseError $parseError) {
                self::assertNotSame('', $parseError->getMessage());
            }
        }
    }

    /**
     * Builds a TIFF with fax option tags in a third IFD so EXIF IFD0/IFD1 compression-domain rules remain unaffected.
     */
    private function buildTiffWithFaxOptionsInThirdIfd(int $compression, ?int $t4Options, ?int $t6Options): string
    {
        $ifdOffset  = 8;
        $ifd0Count  = 2;
        $ifd0Size   = 2 + (12 * $ifd0Count) + 4;
        $ifd1Offset = $ifdOffset + $ifd0Size;

        $ifd1Count  = 2;
        $ifd1Size   = 2 + (12 * $ifd1Count) + 4;
        $ifd2Offset = $ifd1Offset + $ifd1Size;

        $ifd2Entries = [
            pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            pack('v', ExifTag::COMPRESSION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $compression) . pack('v', 0),
        ];

        if ($t4Options !== null) {
            $ifd2Entries[] = pack('v', TiffTag::T4_OPTIONS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $t4Options);
        }

        if ($t6Options !== null) {
            $ifd2Entries[] = pack('v', TiffTag::T6_OPTIONS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $t6Options);
        }

        $ifd2Count = count($ifd2Entries);

        $ifd0 = pack('v', $ifd0Count)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('V', $ifd1Offset);

        $ifd1 = pack('v', $ifd1Count)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('V', $ifd2Offset);

        $ifd2 = pack('v', $ifd2Count);

        foreach ($ifd2Entries as $entry) {
            $ifd2 .= $entry;
        }

        $ifd2 .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifd0
            . $ifd1
            . $ifd2;
    }
}
