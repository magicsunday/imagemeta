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
 * Verifies TIFF HalftoneHints layout/range semantics.
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
final class TiffExifParserHalftoneHintsTest extends TestCase
{
    /**
     * Valid HalftoneHints parse successfully.
     */
    #[Test]
    public function acceptsValidHalftoneHints(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildHalftoneHintsTiff(
                bitsPerSample: 8,
                halftoneHintsType: TiffConst::TYPE_SHORT,
                halftoneHintsValues: [32, 220],
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::HALFTONE_HINTS));
    }

    /**
     * HalftoneHints type must be SHORT.
     */
    #[Test]
    public function rejectsHalftoneHintsWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('HalftoneHints must use TIFF type SHORT');

        (new TiffExifParser())->parseFromBlob(
            $this->buildHalftoneHintsTiff(
                bitsPerSample: 8,
                halftoneHintsType: TiffConst::TYPE_LONG,
                halftoneHintsValues: [32, 220],
            ),
        );
    }

    /**
     * HalftoneHints count must be 2.
     */
    #[Test]
    public function rejectsHalftoneHintsWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('HalftoneHints must contain exactly 2 bytes');

        (new TiffExifParser())->parseFromBlob(
            $this->buildHalftoneHintsTiff(
                bitsPerSample: 8,
                halftoneHintsType: TiffConst::TYPE_SHORT,
                halftoneHintsValues: [32],
            ),
        );
    }

    /**
     * HalftoneHints values must be within range implied by BitsPerSample.
     */
    #[Test]
    public function rejectsOutOfRangeHalftoneHintsValues(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('HalftoneHints component 1 value 300 exceeds max 255');

        (new TiffExifParser())->parseFromBlob(
            $this->buildHalftoneHintsTiff(
                bitsPerSample: 8,
                halftoneHintsType: TiffConst::TYPE_SHORT,
                halftoneHintsValues: [32, 300],
            ),
        );
    }

    /**
     * Builds a minimal TIFF with configurable HalftoneHints.
     *
     * @param list<int> $halftoneHintsValues
     */
    private function buildHalftoneHintsTiff(
        int $bitsPerSample,
        int $halftoneHintsType,
        array $halftoneHintsValues,
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
            TiffTag::HALFTONE_HINTS => pack('v', TiffTag::HALFTONE_HINTS)
                . pack('v', $halftoneHintsType)
                . pack('V', count($halftoneHintsValues)),
        ];

        $payloadByTag = [];

        if (($halftoneHintsType === TiffConst::TYPE_SHORT) && (count($halftoneHintsValues) === 2)) {
            $entries[TiffTag::HALFTONE_HINTS] .= pack('v', $halftoneHintsValues[0]) . pack('v', $halftoneHintsValues[1]);
        } else {
            $payloadByTag[TiffTag::HALFTONE_HINTS] = $this->packNumericPayload($halftoneHintsType, $halftoneHintsValues);
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
