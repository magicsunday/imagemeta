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
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
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
use function ksort;
use function str_pad;
use function strlen;

/**
 * Verifies TIFF gray-response tag semantics.
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
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRational::class)]
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
final class TiffExifParserGrayResponseTest extends TestCase
{
    /**
     * Valid GrayResponseUnit/GrayResponseCurve set parses.
     */
    #[Test]
    public function acceptsValidGrayResponseTags(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildGrayResponseTiff(
                photometric: 1,
                bitsPerSample: 8,
                grayResponseUnitType: TiffConst::TYPE_SHORT,
                grayResponseUnitValues: [2],
                grayResponseCurveType: TiffConst::TYPE_SHORT,
                grayResponseCurveValues: $this->buildCurveValues(256),
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::GRAY_RESPONSE_CURVE));
    }

    /**
     * GrayResponseCurve count must match 1<<BitsPerSample.
     */
    #[Test]
    public function rejectsGrayResponseCurveCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('GrayResponseCurve count 255 must be 1<<BitsPerSample (256)');

        (new TiffExifParser())->parseFromBlob(
            $this->buildGrayResponseTiff(
                photometric: 1,
                bitsPerSample: 8,
                grayResponseCurveType: TiffConst::TYPE_SHORT,
                grayResponseCurveValues: $this->buildCurveValues(255),
            ),
        );
    }

    /**
     * GrayResponseCurve type must be SHORT.
     */
    #[Test]
    public function rejectsGrayResponseCurveWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('GrayResponseCurve must use SHORT type');

        (new TiffExifParser())->parseFromBlob(
            $this->buildGrayResponseTiff(
                photometric: 1,
                bitsPerSample: 8,
                grayResponseCurveType: TiffConst::TYPE_LONG,
                grayResponseCurveValues: $this->buildCurveValues(256),
            ),
        );
    }

    /**
     * GrayResponseUnit must be SHORT[1].
     */
    #[Test]
    public function rejectsGrayResponseUnitWrongTypeOrCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('GrayResponseUnit must be SHORT[1].');

        (new TiffExifParser())->parseFromBlob(
            $this->buildGrayResponseTiff(
                photometric: 1,
                bitsPerSample: 8,
                grayResponseUnitType: TiffConst::TYPE_RATIONAL,
                grayResponseUnitValues: [2],
            ),
        );
    }

    /**
     * GrayResponseUnit values must be in 1..5.
     */
    #[Test]
    public function rejectsGrayResponseUnitOutOfRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('GrayResponseUnit value 6 is outside the valid domain 1..5');

        (new TiffExifParser())->parseFromBlob(
            $this->buildGrayResponseTiff(
                photometric: 1,
                bitsPerSample: 8,
                grayResponseUnitType: TiffConst::TYPE_SHORT,
                grayResponseUnitValues: [6],
            ),
        );
    }

    /**
     * Builds a minimal TIFF with optional gray-response tags.
     *
     * @param list<int>|null $grayResponseUnitValues
     * @param list<int>|null $grayResponseCurveValues
     */
    private function buildGrayResponseTiff(
        int $photometric,
        int $bitsPerSample,
        ?int $grayResponseUnitType = null,
        ?array $grayResponseUnitValues = null,
        ?int $grayResponseCurveType = null,
        ?array $grayResponseCurveValues = null,
    ): string {
        $entries = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
            ExifTag::PHOTOMETRIC_INTERPRETATION => pack('v', ExifTag::PHOTOMETRIC_INTERPRETATION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $photometric) . pack('v', 0),
            ExifTag::BITS_PER_SAMPLE => pack('v', ExifTag::BITS_PER_SAMPLE)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $bitsPerSample) . pack('v', 0),
        ];

        $payloadByTag = [];

        if (($grayResponseUnitType !== null) && is_array($grayResponseUnitValues)) {
            $entries[TiffTag::GRAY_RESPONSE_UNIT] = pack('v', TiffTag::GRAY_RESPONSE_UNIT)
                . pack('v', $grayResponseUnitType)
                . pack('V', count($grayResponseUnitValues));
            $payloadByTag[TiffTag::GRAY_RESPONSE_UNIT] = $this->packNumericPayload(
                $grayResponseUnitType,
                $grayResponseUnitValues,
            );
        }

        if (($grayResponseCurveType !== null) && is_array($grayResponseCurveValues)) {
            $entries[TiffTag::GRAY_RESPONSE_CURVE] = pack('v', TiffTag::GRAY_RESPONSE_CURVE)
                . pack('v', $grayResponseCurveType)
                . pack('V', count($grayResponseCurveValues));
            $payloadByTag[TiffTag::GRAY_RESPONSE_CURVE] = $this->packNumericPayload(
                $grayResponseCurveType,
                $grayResponseCurveValues,
            );
        }

        ksort($entries);

        $ifdOffset   = 8;
        $entryCount  = count($entries);
        $ifdSize     = 2 + (12 * $entryCount) + 4;
        $nextOffset  = $ifdOffset + $ifdSize;
        $ifdEntries  = '';
        $payloadTail = '';

        foreach ($entries as $tag => $prefix) {
            if (!isset($payloadByTag[$tag])) {
                $ifdEntries .= $prefix;

                continue;
            }

            $payload = $payloadByTag[$tag];

            if (strlen($payload) <= 4) {
                $ifdEntries .= $prefix . str_pad($payload, 4, "\0");

                continue;
            }

            $ifdEntries  .= $prefix . pack('V', $nextOffset);
            $payloadTail .= $payload;
            $nextOffset += strlen($payload);
        }

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . $ifdEntries
            . pack('V', 0)
            . $payloadTail;
    }

    /**
     * @return list<int>
     */
    private function buildCurveValues(int $count): array
    {
        $values = [];

        for ($index = 0; $index < $count; ++$index) {
            $values[] = $index % 65536;
        }

        return $values;
    }

    /**
     * @param list<int> $values
     */
    private function packNumericPayload(int $type, array $values): string
    {
        $payload = '';

        foreach ($values as $value) {
            $payload .= match ($type) {
                TiffConst::TYPE_BYTE  => pack('C', $value),
                TiffConst::TYPE_SHORT => pack('v', $value),
                TiffConst::TYPE_LONG  => pack('V', $value),
                default               => pack('V', $value),
            };
        }

        return $payload;
    }
}
