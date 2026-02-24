<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormalizer;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffBinaryReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffOffsetValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function count;
use function ksort;
use function str_pad;
use function strlen;

/**
 * Verifies TIFF transfer-family semantic validation.
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
final class TiffExifParserTransferTagsTest extends TestCase
{
    /**
     * TransferFunction with one shared table is valid.
     */
    #[Test]
    public function acceptsTransferFunctionSharedTableCount(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTransferTiff(
                photometric: 2,
                bitsPerSample: 8,
                transferFunctionType: TiffConst::TYPE_SHORT,
                transferFunctionValues: $this->buildShortRamp(256),
            ),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::TRANSFER_FUNCTION));
    }

    /**
     * TransferFunction with three tables is valid.
     */
    #[Test]
    public function acceptsTransferFunctionThreeTableCount(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTransferTiff(
                photometric: 2,
                bitsPerSample: 8,
                transferFunctionType: TiffConst::TYPE_SHORT,
                transferFunctionValues: $this->buildShortRamp(768),
            ),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::TRANSFER_FUNCTION));
    }

    /**
     * TransferFunction count must match the BitsPerSample formula.
     */
    #[Test]
    public function rejectsInvalidTransferFunctionCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TransferFunction count 300 must be 256 or 768');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTransferTiff(
                photometric: 2,
                bitsPerSample: 8,
                transferFunctionType: TiffConst::TYPE_SHORT,
                transferFunctionValues: $this->buildShortRamp(300),
            ),
        );
    }

    /**
     * TransferRange is only valid for RGB/YCbCr.
     */
    #[Test]
    public function rejectsTransferRangeForNonRgbOrYcbcr(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TransferRange is only valid for PhotometricInterpretation RGB(2) or YCbCr(6)');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTransferTiff(
                photometric: 1,
                bitsPerSample: 8,
                transferRangeType: TiffConst::TYPE_SHORT,
                transferRangeValues: [0, 255, 0, 255, 0, 255],
            ),
        );
    }

    /**
     * TransferRange must be SHORT[6].
     */
    #[Test]
    public function rejectsInvalidTransferRangeTypeOrCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TransferRange must use TIFF type SHORT');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTransferTiff(
                photometric: 2,
                bitsPerSample: 8,
                transferRangeType: TiffConst::TYPE_LONG,
                transferRangeValues: [0, 255, 0, 255, 0, 255],
            ),
        );
    }

    /**
     * ReferenceBlackWhite must be RATIONAL[6].
     */
    #[Test]
    public function rejectsInvalidReferenceBlackWhiteTypeOrCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ReferenceBlackWhite must use TIFF type RATIONAL');

        (new TiffExifParser())->parseFromBlob(
            $this->buildTransferTiff(
                photometric: 2,
                bitsPerSample: 8,
                referenceBlackWhiteType: TiffConst::TYPE_SHORT,
                referenceBlackWhiteValues: [0, 255, 0, 255, 0, 255],
            ),
        );
    }

    /**
     * Builds a minimal TIFF for transfer-family tag checks.
     *
     * @param list<int>|null $transferFunctionValues
     * @param list<int>|null $transferRangeValues
     * @param list<int>|null $referenceBlackWhiteValues
     */
    private function buildTransferTiff(
        int $photometric,
        int $bitsPerSample,
        ?int $transferFunctionType = null,
        ?array $transferFunctionValues = null,
        ?int $transferRangeType = null,
        ?array $transferRangeValues = null,
        ?int $referenceBlackWhiteType = null,
        ?array $referenceBlackWhiteValues = null,
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

        if (($transferFunctionType !== null) && is_array($transferFunctionValues)) {
            $entries[ExifTag::TRANSFER_FUNCTION] = pack('v', ExifTag::TRANSFER_FUNCTION)
                . pack('v', $transferFunctionType)
                . pack('V', count($transferFunctionValues));
            $payloadByTag[ExifTag::TRANSFER_FUNCTION] = $this->packNumericPayload(
                $transferFunctionType,
                $transferFunctionValues,
            );
        }

        if (($transferRangeType !== null) && is_array($transferRangeValues)) {
            $entries[TiffTag::TRANSFER_RANGE] = pack('v', TiffTag::TRANSFER_RANGE)
                . pack('v', $transferRangeType)
                . pack('V', count($transferRangeValues));
            $payloadByTag[TiffTag::TRANSFER_RANGE] = $this->packNumericPayload($transferRangeType, $transferRangeValues);
        }

        if (($referenceBlackWhiteType !== null) && is_array($referenceBlackWhiteValues)) {
            $entries[ExifTag::REFERENCE_BLACK_WHITE] = pack('v', ExifTag::REFERENCE_BLACK_WHITE)
                . pack('v', $referenceBlackWhiteType)
                . pack('V', count($referenceBlackWhiteValues));
            $payloadByTag[ExifTag::REFERENCE_BLACK_WHITE] = $referenceBlackWhiteType === TiffConst::TYPE_RATIONAL
                ? $this->packRationalPayload($referenceBlackWhiteValues)
                : $this->packNumericPayload($referenceBlackWhiteType, $referenceBlackWhiteValues);
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

            $ifdEntries .= $prefix . pack('V', $nextOffset);
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
    private function buildShortRamp(int $count): array
    {
        $values = [];

        for ($i = 0; $i < $count; ++$i) {
            $values[] = $i % 65536;
        }

        return $values;
    }

    /**
     * @param list<int> $values
     */
    private function packRationalPayload(array $values): string
    {
        $payload = '';

        foreach ($values as $value) {
            $payload .= pack('V', $value);
            $payload .= pack('V', 1);
        }

        return $payload;
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
