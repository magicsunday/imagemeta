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
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormaliser;
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
 * Verifies TIFF sample-domain tag family semantics.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(DngValueNormaliser::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserSampleDomainTest extends TestCase
{
    /**
     * Valid SampleFormat + SMin/SMax set parses.
     */
    #[Test]
    public function acceptsValidSampleDomainFamily(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildSampleDomainTiff(
                samplesPerPixel: 3,
                sampleFormatType: TiffConst::TYPE_SHORT,
                sampleFormatValues: [1, 1, 1],
                sMinType: TiffConst::TYPE_SHORT,
                sMinValues: [0, 0, 0],
                sMaxType: TiffConst::TYPE_SHORT,
                sMaxValues: [255, 255, 255],
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::SAMPLE_FORMAT));
    }

    /**
     * SampleFormat count must match SamplesPerPixel.
     */
    #[Test]
    public function rejectsSampleFormatCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('SampleFormat count 2 must match SamplesPerPixel 3');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSampleDomainTiff(
                samplesPerPixel: 3,
                sampleFormatType: TiffConst::TYPE_SHORT,
                sampleFormatValues: [1, 1],
            ),
        );
    }

    /**
     * SampleFormat values must be in enum domain {1,2,3,4}.
     */
    #[Test]
    public function rejectsInvalidSampleFormatEnumValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('SampleFormat component 0 value 5 is invalid');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSampleDomainTiff(
                samplesPerPixel: 1,
                sampleFormatType: TiffConst::TYPE_SHORT,
                sampleFormatValues: [5],
            ),
        );
    }

    /**
     * SMin/SMax counts must match SamplesPerPixel.
     */
    #[Test]
    public function rejectsSMinSMaxCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('SMinSampleValue count 2 must match SamplesPerPixel 3');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSampleDomainTiff(
                samplesPerPixel: 3,
                sampleFormatType: TiffConst::TYPE_SHORT,
                sampleFormatValues: [1, 1, 1],
                sMinType: TiffConst::TYPE_SHORT,
                sMinValues: [0, 0],
                sMaxType: TiffConst::TYPE_SHORT,
                sMaxValues: [255, 255, 255],
            ),
        );
    }

    /**
     * Per-component ordering must satisfy SMin <= SMax.
     */
    #[Test]
    public function rejectsSMinGreaterThanSMax(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('SMinSampleValue component 1 must be <= SMaxSampleValue');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSampleDomainTiff(
                samplesPerPixel: 2,
                sampleFormatType: TiffConst::TYPE_SHORT,
                sampleFormatValues: [1, 1],
                sMinType: TiffConst::TYPE_SHORT,
                sMinValues: [0, 300],
                sMaxType: TiffConst::TYPE_SHORT,
                sMaxValues: [255, 200],
            ),
        );
    }

    /**
     * SMin/SMax type must match SampleFormat semantics.
     */
    #[Test]
    public function rejectsIncompatibleSMinSMaxTypeForSampleFormat(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('SMinSampleValue type 3 is incompatible with SampleFormat component 0 value 3');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSampleDomainTiff(
                samplesPerPixel: 1,
                sampleFormatType: TiffConst::TYPE_SHORT,
                sampleFormatValues: [3],
                sMinType: TiffConst::TYPE_SHORT,
                sMinValues: [0],
                sMaxType: TiffConst::TYPE_SHORT,
                sMaxValues: [100],
            ),
        );
    }

    /**
     * Builds a minimal TIFF with sample-domain tags.
     *
     * @param list<int|float>|null $sampleFormatValues
     * @param list<int|float>|null $sMinValues
     * @param list<int|float>|null $sMaxValues
     */
    private function buildSampleDomainTiff(
        int $samplesPerPixel,
        ?int $sampleFormatType = null,
        ?array $sampleFormatValues = null,
        ?int $sMinType = null,
        ?array $sMinValues = null,
        ?int $sMaxType = null,
        ?array $sMaxValues = null,
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
        ];

        $payloadByTag = [];

        if (($sampleFormatType !== null) && is_array($sampleFormatValues)) {
            $entries[TiffTag::SAMPLE_FORMAT] = pack('v', TiffTag::SAMPLE_FORMAT)
                . pack('v', $sampleFormatType)
                . pack('V', count($sampleFormatValues));
            $payloadByTag[TiffTag::SAMPLE_FORMAT] = $this->packNumericPayload($sampleFormatType, $sampleFormatValues);
        }

        if (($sMinType !== null) && is_array($sMinValues)) {
            $entries[TiffTag::S_MIN_SAMPLE_VALUE] = pack('v', TiffTag::S_MIN_SAMPLE_VALUE)
                . pack('v', $sMinType)
                . pack('V', count($sMinValues));
            $payloadByTag[TiffTag::S_MIN_SAMPLE_VALUE] = $this->packNumericPayload($sMinType, $sMinValues);
        }

        if (($sMaxType !== null) && is_array($sMaxValues)) {
            $entries[TiffTag::S_MAX_SAMPLE_VALUE] = pack('v', TiffTag::S_MAX_SAMPLE_VALUE)
                . pack('v', $sMaxType)
                . pack('V', count($sMaxValues));
            $payloadByTag[TiffTag::S_MAX_SAMPLE_VALUE] = $this->packNumericPayload($sMaxType, $sMaxValues);
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
     * @param list<int|float> $values
     */
    private function packNumericPayload(int $type, array $values): string
    {
        $payload = '';

        foreach ($values as $value) {
            $payload .= match ($type) {
                TiffConst::TYPE_BYTE   => pack('C', (int) $value),
                TiffConst::TYPE_SBYTE  => pack('c', (int) $value),
                TiffConst::TYPE_SHORT  => pack('v', (int) $value),
                TiffConst::TYPE_SSHORT => pack('s', (int) $value),
                TiffConst::TYPE_LONG   => pack('V', (int) $value),
                TiffConst::TYPE_SLONG  => pack('l', (int) $value),
                TiffConst::TYPE_FLOAT  => pack('f', (float) $value),
                TiffConst::TYPE_DOUBLE => pack('d', (float) $value),
                default                => pack('V', (int) $value),
            };
        }

        return $payload;
    }
}
