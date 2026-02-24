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
 * Verifies TIFF FillOrder semantics.
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
final class TiffExifParserFillOrderTest extends TestCase
{
    /**
     * FillOrder=1 is valid.
     */
    #[Test]
    public function acceptsFillOrderOne(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildFillOrderTiff(
                fillOrderType: TiffConst::TYPE_SHORT,
                fillOrderCount: 1,
                fillOrderValues: [1],
                bitsPerSample: 8,
                compression: 1,
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::FILL_ORDER));
    }

    /**
     * FillOrder=2 with BitsPerSample=1 and CCITT compression is valid.
     */
    #[Test]
    public function acceptsFillOrderTwoWithCompatibleContext(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildFillOrderTiff(
                fillOrderType: TiffConst::TYPE_SHORT,
                fillOrderCount: 1,
                fillOrderValues: [2],
                bitsPerSample: 1,
                compression: 1,
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::FILL_ORDER));
    }

    /**
     * FillOrder domain is restricted to {1,2}.
     */
    #[Test]
    public function rejectsInvalidFillOrderDomainValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('FillOrder value 3 is invalid');

        (new TiffExifParser())->parseFromBlob(
            $this->buildFillOrderTiff(
                fillOrderType: TiffConst::TYPE_SHORT,
                fillOrderCount: 1,
                fillOrderValues: [3],
                bitsPerSample: 1,
                compression: 1,
            ),
        );
    }

    /**
     * FillOrder must be SHORT[1].
     */
    #[Test]
    public function rejectsInvalidFillOrderTypeOrCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('FillOrder must use TIFF type SHORT');

        (new TiffExifParser())->parseFromBlob(
            $this->buildFillOrderTiff(
                fillOrderType: TiffConst::TYPE_ASCII,
                fillOrderCount: 1,
                fillOrderValues: [2],
                bitsPerSample: 1,
                compression: 1,
            ),
        );
    }

    /**
     * FillOrder=2 requires BitsPerSample=1 and compatible compression.
     */
    #[Test]
    public function rejectsFillOrderTwoWithIncompatibleContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('FillOrder=2 requires BitsPerSample=1');

        (new TiffExifParser())->parseFromBlob(
            $this->buildFillOrderTiff(
                fillOrderType: TiffConst::TYPE_SHORT,
                fillOrderCount: 1,
                fillOrderValues: [2],
                bitsPerSample: 8,
                compression: 1,
            ),
        );
    }

    /**
     * Builds a minimal TIFF with configurable FillOrder context.
     *
     * @param list<int> $fillOrderValues
     */
    private function buildFillOrderTiff(
        int $fillOrderType,
        int $fillOrderCount,
        array $fillOrderValues,
        int $bitsPerSample,
        int $compression,
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
            ExifTag::BITS_PER_SAMPLE => pack('v', ExifTag::BITS_PER_SAMPLE)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $bitsPerSample) . pack('v', 0),
            ExifTag::COMPRESSION => pack('v', ExifTag::COMPRESSION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $compression) . pack('v', 0),
            TiffTag::FILL_ORDER => pack('v', TiffTag::FILL_ORDER)
                . pack('v', $fillOrderType)
                . pack('V', $fillOrderCount),
        ];

        $payloadByTag = [];

        if (($fillOrderType === TiffConst::TYPE_SHORT) && ($fillOrderCount === 1)) {
            $entries[TiffTag::FILL_ORDER] .= pack('v', $fillOrderValues[0] ?? 0) . pack('v', 0);
        } elseif (($fillOrderType === TiffConst::TYPE_BYTE) && ($fillOrderCount === 1)) {
            $entries[TiffTag::FILL_ORDER] .= pack('C', $fillOrderValues[0] ?? 0) . "\0\0\0";
        } else {
            $payloadByTag[TiffTag::FILL_ORDER] = $this->packNumericPayload($fillOrderType, $fillOrderValues);
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
