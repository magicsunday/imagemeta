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
        $entries      = [
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
            $entries[TiffTag::MIN_SAMPLE_VALUE]      = pack('v', TiffTag::MIN_SAMPLE_VALUE)
                . pack('v', $minType)
                . pack('V', count($minValues));
            $payloadByTag[TiffTag::MIN_SAMPLE_VALUE] = $this->packNumericPayload($minType, $minValues);
        }

        if (($maxType !== null) && is_array($maxValues)) {
            $entries[TiffTag::MAX_SAMPLE_VALUE]      = pack('v', TiffTag::MAX_SAMPLE_VALUE)
                . pack('v', $maxType)
                . pack('V', count($maxValues));
            $payloadByTag[TiffTag::MAX_SAMPLE_VALUE] = $this->packNumericPayload($maxType, $maxValues);
        }

        ksort($entries);

        $ifdOffset    = 8;
        $entryCount   = count($entries);
        $ifdSize      = 2 + (12 * $entryCount) + 4;
        $nextOffset   = $ifdOffset + $ifdSize;
        $ifdEntries   = '';
        $payloadTail  = '';

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
