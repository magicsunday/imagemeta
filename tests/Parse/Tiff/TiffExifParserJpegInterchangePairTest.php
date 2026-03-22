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
use MagicSunday\ImageMeta\Parse\Tiff\ExifTagDecoder;
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
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function count;
use function implode;
use function ksort;
use function str_pad;
use function strlen;

/**
 * Verifies JPEGInterchangeFormat/JPEGInterchangeFormatLength pair semantics.
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
#[UsesClass(ExifTagDecoder::class)]
#[UsesClass(IfdParser::class)]
#[UsesClass(TiffByteOrderHandler::class)]
#[UsesClass(TiffColorInkValidator::class)]
#[UsesClass(TiffImageDataValidator::class)]
#[UsesClass(TiffJpegValidator::class)]
#[UsesClass(TiffSampleValidator::class)]
#[UsesClass(TiffStructuralValidator::class)]
#[UsesClass(TiffTagConstraintValidator::class)]
#[UsesClass(TiffValidationSupport::class)]
final class TiffExifParserJpegInterchangePairTest extends TestCase
{
    /**
     * Valid non-zero offset+length pair inside bounds parses.
     */
    #[Test]
    public function acceptsValidInterchangeOffsetAndLengthPair(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: -1,
                lengthValue: -1,
            ),
        );

        $ifd1 = $parsed->ifd1;
        self::assertNotNull($ifd1);
        self::assertNotNull($ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT));
        self::assertNotNull($ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH));
    }

    /**
     * Non-zero offset with missing length is tolerated (Postel's Law).
     */
    #[Test]
    public function toleratesMissingInterchangeLengthForNonZeroOffset(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: -1,
                lengthValue: null,
            ),
        );

        self::assertNotNull($parsed->ifd1);
        self::assertNull($parsed->ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH));
    }

    /**
     * Length without offset is tolerated (Postel's Law): thumbnail extraction is skipped.
     */
    #[Test]
    public function itToleratesMissingJpegInterchangeFormat(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: null,
                lengthValue: -1,
            ),
        );

        self::assertNotNull($parsed->ifd1);
        self::assertNull($parsed->ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT));
        self::assertNotNull($parsed->ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH));
    }

    /**
     * Non-zero offset with length=0 is tolerated (Postel's Law).
     */
    #[Test]
    public function skipsThumbnailWhenJpegInterchangeFormatLengthIsZero(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: -1,
                lengthValue: 0,
            ),
        );

        self::assertNotNull($parsed->ifd1);
        self::assertNotNull($parsed->ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT));
        self::assertNotNull($parsed->ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH));
    }

    /**
     * Offset=0 invalidates any present length.
     */
    #[Test]
    public function rejectsLengthWhenInterchangeOffsetIsZero(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGInterchangeFormatLength is invalid when JPEGInterchangeFormat is zero');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: 0,
                lengthValue: 4,
            ),
        );
    }

    /**
     * Interchange tags must use LONG[1] layout.
     */
    #[Test]
    public function rejectsInvalidInterchangeFieldLayout(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGInterchangeFormat must be LONG[1].');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: -1,
                lengthValue: -1,
                offsetType: TiffConst::TYPE_ASCII,
            ),
        );
    }

    /**
     * Out-of-bounds offset+length is tolerated (Postel's Law): thumbnail skipped.
     */
    #[Test]
    public function itSkipsThumbnailWhenInterchangeFormatRangeExceedsBounds(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: -1,
                lengthValue: 4096,
            ),
        );

        self::assertNotNull($parsed->ifd1);
        self::assertNotNull($parsed->ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT));
        self::assertNotNull($parsed->ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH));
    }

    /**
     * Tolerates a JPEG thumbnail stream whose SOI marker is missing (Postel's Law).
     */
    #[Test]
    public function itSkipsThumbnailWhenSoiMarkerIsMissing(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: -1,
                lengthValue: -1,
                jpegPayload: "\x00\xD8\xFF\xD9",
            ),
        );

        self::assertNotNull($parsed->ifd1);
        self::assertNotNull($parsed->ifd1->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Tolerates a JPEG thumbnail stream whose EOI marker is missing (Postel's Law).
     */
    #[Test]
    public function itSkipsThumbnailWhenEoiMarkerIsMissing(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: -1,
                lengthValue: -1,
                jpegPayload: "\xFF\xD8\xFF\xDB\x00\x04\x00\x00\xFF\x00",
            ),
        );

        self::assertNotNull($parsed->ifd1);
        self::assertNotNull($parsed->ifd1->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Builds a TIFF with IFD1 JPEG tags and optional interchange pair entries.
     *
     * Sentinel values:
     * - offsetValue=-1 => auto-calc to payload start.
     * - lengthValue=-1 => auto-calc to payload length.
     */
    private function buildBlobWithIfd1JpegInterchange(
        ?int $offsetValue,
        ?int $lengthValue,
        int $offsetType = TiffConst::TYPE_LONG,
        int $lengthType = TiffConst::TYPE_LONG,
        string $jpegPayload = "\xFF\xD8\xFF\xD9",
    ): string {
        $ifd0Entries = [
            ExifTag::IMAGE_WIDTH  => $this->shortEntry(ExifTag::IMAGE_WIDTH, 64),
            ExifTag::IMAGE_LENGTH => $this->shortEntry(ExifTag::IMAGE_LENGTH, 64),
        ];

        $ifd1Entries = [
            ExifTag::IMAGE_WIDTH  => $this->shortEntry(ExifTag::IMAGE_WIDTH, 16),
            ExifTag::IMAGE_LENGTH => $this->shortEntry(ExifTag::IMAGE_LENGTH, 16),
            ExifTag::COMPRESSION  => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
            TiffTag::JPEG_PROC    => $this->shortEntry(TiffTag::JPEG_PROC, 1),
        ];

        if ($offsetValue !== null) {
            $ifd1Entries[ExifTag::JPEG_INTERCHANGE_FORMAT] = $this->numericEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT,
                $offsetType,
                1,
                [0],
            );
        }

        if ($lengthValue !== null) {
            $ifd1Entries[ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH] = $this->numericEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                $lengthType,
                1,
                [0],
            );
        }

        ksort($ifd0Entries);
        ksort($ifd1Entries);

        $ifd0Offset = 8;
        $ifd0Size   = $this->ifdSize($ifd0Entries);
        $ifd1Offset = $ifd0Offset + $ifd0Size;
        $ifd1Size   = $this->ifdSize($ifd1Entries);
        $dataOffset = $ifd1Offset + $ifd1Size;

        if ($offsetValue !== null) {
            $resolvedOffset                                = $offsetValue === -1 ? $dataOffset : $offsetValue;
            $ifd1Entries[ExifTag::JPEG_INTERCHANGE_FORMAT] = $this->numericEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT,
                $offsetType,
                1,
                [$resolvedOffset],
            );
        }

        if ($lengthValue !== null) {
            $resolvedLength                                       = $lengthValue === -1 ? strlen($jpegPayload) : $lengthValue;
            $ifd1Entries[ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH] = $this->numericEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                $lengthType,
                1,
                [$resolvedLength],
            );
        }

        ksort($ifd1Entries);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $this->buildIfdBlock($ifd0Entries, $ifd1Offset)
            . $this->buildIfdBlock($ifd1Entries, 0)
            . $jpegPayload;
    }

    /**
     * @param array<int, string> $entries
     */
    private function ifdSize(array $entries): int
    {
        return 2 + (12 * count($entries)) + 4;
    }

    /**
     * @param array<int, string> $entries
     */
    private function buildIfdBlock(array $entries, int $nextIfdOffset): string
    {
        return pack('v', count($entries))
            . implode('', $entries)
            . pack('V', $nextIfdOffset);
    }

    private function shortEntry(int $tag, int $value): string
    {
        return pack('v', $tag)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', $value)
            . pack('v', 0);
    }

    /**
     * @param list<int> $values
     */
    private function numericEntry(int $tag, int $type, int $count, array $values): string
    {
        $valueBytes = implode('', array_map(
            static fn (int $value): string => match ($type) {
                TiffConst::TYPE_SHORT => pack('v', $value),
                TiffConst::TYPE_LONG  => pack('V', $value),
                default               => pack('V', $value),
            },
            $values,
        ));

        return pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count)
            . str_pad($valueBytes, 4, "\0");
    }
}
