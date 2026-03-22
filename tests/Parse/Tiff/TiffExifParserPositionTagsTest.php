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
use function strlen;

/**
 * Verifies TIFF XPosition/YPosition conformance rules.
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
final class TiffExifParserPositionTagsTest extends TestCase
{
    /**
     * Valid positive XPosition/YPosition parse successfully.
     */
    #[Test]
    public function acceptsValidPositionTags(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildPositionTiff(
                xPositionType: TiffConst::TYPE_RATIONAL,
                xPositionCount: 1,
                xPositionNumerator: 5,
                xPositionDenominator: 1,
                yPositionType: TiffConst::TYPE_RATIONAL,
                yPositionCount: 1,
                yPositionNumerator: 10,
                yPositionDenominator: 1,
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::X_POSITION));
        self::assertNotNull($parsed->ifd0->get(TiffTag::Y_POSITION));
    }

    /**
     * Wrong type/count for position tags is rejected.
     */
    #[Test]
    public function rejectsWrongPositionTagLayout(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('XPosition must be RATIONAL[1].');

        (new TiffExifParser())->parseFromBlob(
            $this->buildPositionTiff(
                xPositionType: TiffConst::TYPE_SHORT,
                xPositionCount: 1,
                xPositionNumerator: 5,
                xPositionDenominator: 1,
                yPositionType: TiffConst::TYPE_RATIONAL,
                yPositionCount: 1,
                yPositionNumerator: 10,
                yPositionDenominator: 1,
            ),
        );
    }

    /**
     * Zero YPosition is tolerated.
     */
    #[Test]
    public function toleratesZeroYPosition(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildPositionTiff(
                xPositionType: TiffConst::TYPE_RATIONAL,
                xPositionCount: 1,
                xPositionNumerator: 5,
                xPositionDenominator: 1,
                yPositionType: TiffConst::TYPE_RATIONAL,
                yPositionCount: 1,
                yPositionNumerator: 0,
                yPositionDenominator: 1,
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::Y_POSITION));
    }

    /**
     * Denominator zero is malformed and must be rejected.
     */
    #[Test]
    public function rejectsMalformedPositionRational(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('YPosition denominator must be non-zero');

        (new TiffExifParser())->parseFromBlob(
            $this->buildPositionTiff(
                xPositionType: TiffConst::TYPE_RATIONAL,
                xPositionCount: 1,
                xPositionNumerator: 5,
                xPositionDenominator: 1,
                yPositionType: TiffConst::TYPE_RATIONAL,
                yPositionCount: 1,
                yPositionNumerator: 10,
                yPositionDenominator: 0,
            ),
        );
    }

    /**
     * Builds a minimal TIFF with XPosition/YPosition entries.
     */
    private function buildPositionTiff(
        int $xPositionType,
        int $xPositionCount,
        int $xPositionNumerator,
        int $xPositionDenominator,
        int $yPositionType,
        int $yPositionCount,
        int $yPositionNumerator,
        int $yPositionDenominator,
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
            TiffTag::X_POSITION => pack('v', TiffTag::X_POSITION)
                . pack('v', $xPositionType)
                . pack('V', $xPositionCount),
            TiffTag::Y_POSITION => pack('v', TiffTag::Y_POSITION)
                . pack('v', $yPositionType)
                . pack('V', $yPositionCount),
        ];

        $payloadByTag = [
            TiffTag::X_POSITION => pack('V', $xPositionNumerator) . pack('V', $xPositionDenominator),
            TiffTag::Y_POSITION => pack('V', $yPositionNumerator) . pack('V', $yPositionDenominator),
        ];

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
}
