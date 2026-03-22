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
use function ksort;
use function str_pad;
use function strlen;

/**
 * Verifies TIFF MinSampleValue/MaxSampleValue structural semantics.
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
final class TiffExifParserMinMaxSampleValueTest extends TestCase
{
    /**
     * Valid MinSampleValue/MaxSampleValue arrays parse.
     */
    #[Test]
    public function acceptsValidMinMaxSampleValues(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildMinMaxTiff(
                samplesPerPixel: 3,
                bitsPerSample: [8, 8, 8],
                minType: TiffConst::TYPE_SHORT,
                minValues: [0, 0, 0],
                maxType: TiffConst::TYPE_SHORT,
                maxValues: [255, 200, 128],
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::MIN_SAMPLE_VALUE));
        self::assertNotNull($parsed->ifd0->get(TiffTag::MAX_SAMPLE_VALUE));
    }

    /**
     * MinSampleValue/MaxSampleValue must use SHORT type.
     */
    #[Test]
    public function rejectsWrongTypeForMinSampleValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('MinSampleValue must be SHORT');

        (new TiffExifParser())->parseFromBlob(
            $this->buildMinMaxTiff(
                samplesPerPixel: 1,
                bitsPerSample: [8],
                minType: TiffConst::TYPE_LONG,
                minValues: [0],
            ),
        );
    }

    /**
     * MinSampleValue/MaxSampleValue count must match SamplesPerPixel.
     */
    #[Test]
    public function rejectsCountMismatchAgainstSamplesPerPixel(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('MinSampleValue count 2 must match SamplesPerPixel 3');

        (new TiffExifParser())->parseFromBlob(
            $this->buildMinMaxTiff(
                samplesPerPixel: 3,
                bitsPerSample: [8, 8, 8],
                minType: TiffConst::TYPE_SHORT,
                minValues: [0, 0],
            ),
        );
    }

    /**
     * Per-component ordering must satisfy MinSampleValue <= MaxSampleValue.
     */
    #[Test]
    public function rejectsMinGreaterThanMaxPerComponent(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('MinSampleValue component 1 must be <= MaxSampleValue component 1');

        (new TiffExifParser())->parseFromBlob(
            $this->buildMinMaxTiff(
                samplesPerPixel: 2,
                bitsPerSample: [8, 8],
                minType: TiffConst::TYPE_SHORT,
                minValues: [0, 201],
                maxType: TiffConst::TYPE_SHORT,
                maxValues: [255, 200],
            ),
        );
    }

    /**
     * Values must stay within the coding range implied by BitsPerSample.
     */
    #[Test]
    public function rejectsOutOfRangeValueForBitsPerSample(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('MaxSampleValue component 0 value 300 exceeds 8-bit range 0..255');

        (new TiffExifParser())->parseFromBlob(
            $this->buildMinMaxTiff(
                samplesPerPixel: 1,
                bitsPerSample: [8],
                minType: TiffConst::TYPE_SHORT,
                minValues: [0],
                maxType: TiffConst::TYPE_SHORT,
                maxValues: [300],
            ),
        );
    }

    /**
     * Builds a minimal TIFF with MinSampleValue/MaxSampleValue tags.
     *
     * @param list<int>      $bitsPerSample
     * @param list<int>|null $minValues
     * @param list<int>|null $maxValues
     */
    private function buildMinMaxTiff(
        int $samplesPerPixel,
        array $bitsPerSample,
        ?int $minType = null,
        ?array $minValues = null,
        ?int $maxType = null,
        ?array $maxValues = null,
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
            ExifTag::SAMPLES_PER_PIXEL => pack('v', ExifTag::SAMPLES_PER_PIXEL)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $samplesPerPixel) . pack('v', 0),
            ExifTag::BITS_PER_SAMPLE => pack('v', ExifTag::BITS_PER_SAMPLE)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', count($bitsPerSample)),
        ];

        $payloadByTag = [
            ExifTag::BITS_PER_SAMPLE => $this->packNumericPayload(TiffConst::TYPE_SHORT, $bitsPerSample),
        ];

        if (($minType !== null) && is_array($minValues)) {
            $entries[TiffTag::MIN_SAMPLE_VALUE] = pack('v', TiffTag::MIN_SAMPLE_VALUE)
                . pack('v', $minType)
                . pack('V', count($minValues));
            $payloadByTag[TiffTag::MIN_SAMPLE_VALUE] = $this->packNumericPayload($minType, $minValues);
        }

        if (($maxType !== null) && is_array($maxValues)) {
            $entries[TiffTag::MAX_SAMPLE_VALUE] = pack('v', TiffTag::MAX_SAMPLE_VALUE)
                . pack('v', $maxType)
                . pack('V', count($maxValues));
            $payloadByTag[TiffTag::MAX_SAMPLE_VALUE] = $this->packNumericPayload($maxType, $maxValues);
        }

        ksort($entries);

        $ifdOffset   = 8;
        $entryCount  = count($entries);
        $ifdSize     = 2 + (12 * $entryCount) + 4;
        $nextOffset  = $ifdOffset + $ifdSize;
        $ifdEntries  = '';
        $payloadTail = '';

        foreach ($entries as $tag => $prefix) {
            $payload = $payloadByTag[$tag] ?? null;

            if (!is_string($payload)) {
                $ifdEntries .= $prefix;

                continue;
            }

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
     * @param list<int> $values
     */
    private function packNumericPayload(int $type, array $values): string
    {
        $payload = '';

        foreach ($values as $value) {
            $payload .= match ($type) {
                TiffConst::TYPE_SHORT => pack('v', $value),
                TiffConst::TYPE_LONG  => pack('V', $value),
                default               => pack('v', $value),
            };
        }

        return $payload;
    }
}
