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
use function implode;
use function ksort;

/**
 * Verifies TIFF NewSubfileType/SubfileType/PageNumber semantics.
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
final class TiffExifParserSubfilePageTagsTest extends TestCase
{
    /**
     * Valid NewSubfileType bit patterns are accepted.
     */
    #[Test]
    public function acceptsValidNewSubfileTypePatterns(): void
    {
        $cases = [
            [0, null],
            [1, null],
            [2, null],
            [3, null],
            [4, 4],
        ];

        foreach ($cases as [$newSubfileType, $photometric]) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildSubfilePageTiff(
                    newSubfileType: $newSubfileType,
                    subfileType: null,
                    pageNumber: null,
                    photometric: $photometric,
                ),
            );

            self::assertNotNull($parsed->ifd0->get(TiffTag::NEW_SUBFILE_TYPE));
        }
    }

    /**
     * Reserved bits in NewSubfileType must be zero.
     */
    #[Test]
    public function rejectsReservedBitsInNewSubfileType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('NewSubfileType value 32 contains reserved bits');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSubfilePageTiff(
                newSubfileType: 32,
                subfileType: null,
                pageNumber: null,
                photometric: null,
            ),
        );
    }

    /**
     * NewSubfileType transparency-mask bit requires PhotometricInterpretation=4.
     */
    #[Test]
    public function rejectsTransparencyMaskWithoutPhotometricFour(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('NewSubfileType transparency-mask bit requires PhotometricInterpretation=4');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSubfilePageTiff(
                newSubfileType: 4,
                subfileType: null,
                pageNumber: null,
                photometric: 1,
            ),
        );
    }

    /**
     * SubfileType must be in domain 1..3.
     */
    #[Test]
    public function rejectsInvalidSubfileTypeValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('SubfileType value 4 is invalid; allowed values are 1..3');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSubfilePageTiff(
                newSubfileType: null,
                subfileType: 4,
                pageNumber: null,
                photometric: null,
            ),
        );
    }

    /**
     * PageNumber index must be less than total pages when total is known.
     */
    #[Test]
    public function rejectsInvalidPageNumberSemantics(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('PageNumber index 2 must be less than total pages 2');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSubfilePageTiff(
                newSubfileType: null,
                subfileType: null,
                pageNumber: [2, 2],
                photometric: null,
            ),
        );
    }

    /**
     * SubfileType and NewSubfileType representations must agree.
     */
    #[Test]
    public function rejectsConflictingSubfileTypeRepresentations(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('SubfileType 2 conflicts with NewSubfileType 2');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSubfilePageTiff(
                newSubfileType: 2,
                subfileType: 2,
                pageNumber: null,
                photometric: null,
            ),
        );
    }

    /**
     * Builds a minimal TIFF with subfile/page tags.
     *
     * @param array{int, int}|null $pageNumber
     */
    private function buildSubfilePageTiff(
        ?int $newSubfileType,
        ?int $subfileType,
        ?array $pageNumber,
        ?int $photometric,
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
        ];

        if ($newSubfileType !== null) {
            $entries[TiffTag::NEW_SUBFILE_TYPE] = pack('v', TiffTag::NEW_SUBFILE_TYPE)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $newSubfileType);
        }

        if ($subfileType !== null) {
            $entries[TiffTag::SUBFILE_TYPE] = pack('v', TiffTag::SUBFILE_TYPE)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $subfileType) . pack('v', 0);
        }

        if ($pageNumber !== null) {
            $entries[TiffTag::PAGE_NUMBER] = pack('v', TiffTag::PAGE_NUMBER)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 2)
                . pack('v', $pageNumber[0]) . pack('v', $pageNumber[1]);
        }

        if ($photometric !== null) {
            $entries[ExifTag::PHOTOMETRIC_INTERPRETATION] = pack('v', ExifTag::PHOTOMETRIC_INTERPRETATION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $photometric) . pack('v', 0);
        }

        ksort($entries);

        $ifdOffset  = 8;
        $entryCount = count($entries);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . implode('', $entries)
            . pack('V', 0);
    }
}
