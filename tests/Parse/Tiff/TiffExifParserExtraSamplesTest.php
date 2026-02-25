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
 * Verifies strict TIFF 6.0 baseline ExtraSamples semantics.
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
final class TiffExifParserExtraSamplesTest extends TestCase
{
    /**
     * ExtraSamples SHORT[1]=1 is valid.
     */
    #[Test]
    public function acceptsBaselineExtraSamplesValue(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildExtraSamplesTiff(
                extraSamplesType: TiffConst::TYPE_SHORT,
                extraSamplesValues: [1],
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::EXTRA_SAMPLES));
    }

    /**
     * ExtraSamples type must be SHORT.
     */
    #[Test]
    public function rejectsInvalidExtraSamplesType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ExtraSamples must be SHORT[1]');

        (new TiffExifParser())->parseFromBlob(
            $this->buildExtraSamplesTiff(
                extraSamplesType: TiffConst::TYPE_BYTE,
                extraSamplesValues: [1],
            ),
        );
    }

    /**
     * ExtraSamples count must be exactly 1 in strict TIFF 6.0 mode.
     */
    #[Test]
    public function rejectsInvalidExtraSamplesCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ExtraSamples must be SHORT[1]');

        (new TiffExifParser())->parseFromBlob(
            $this->buildExtraSamplesTiff(
                extraSamplesType: TiffConst::TYPE_SHORT,
                extraSamplesValues: [1, 1],
            ),
        );
    }

    /**
     * ExtraSamples value 2 (pre-multiplied alpha) is accepted.
     */
    #[Test]
    public function acceptsExtraSamplesValueTwo(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildExtraSamplesTiff(
                extraSamplesType: TiffConst::TYPE_SHORT,
                extraSamplesValues: [2],
            ),
        );

        $entry = $parsed->ifd0->get(TiffTag::EXTRA_SAMPLES);
        self::assertNotNull($entry);
        self::assertSame(2, $entry->value);
    }

    /**
     * ExtraSamples value 0 (unspecified) is accepted.
     */
    #[Test]
    public function acceptsExtraSamplesValueZero(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildExtraSamplesTiff(
                extraSamplesType: TiffConst::TYPE_SHORT,
                extraSamplesValues: [0],
            ),
        );

        $entry = $parsed->ifd0->get(TiffTag::EXTRA_SAMPLES);
        self::assertNotNull($entry);
        self::assertSame(0, $entry->value);
    }

    /**
     * Builds a minimal TIFF with configurable ExtraSamples entry.
     *
     * @param list<int> $extraSamplesValues
     */
    private function buildExtraSamplesTiff(int $extraSamplesType, array $extraSamplesValues): string
    {
        $entries = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
            TiffTag::EXTRA_SAMPLES => pack('v', TiffTag::EXTRA_SAMPLES)
                . pack('v', $extraSamplesType)
                . pack('V', count($extraSamplesValues)),
        ];

        $payloadByTag = [];

        if (($extraSamplesType === TiffConst::TYPE_SHORT) && (count($extraSamplesValues) === 1)) {
            $entries[TiffTag::EXTRA_SAMPLES] .= pack('v', $extraSamplesValues[0]) . pack('v', 0);
        } elseif (($extraSamplesType === TiffConst::TYPE_BYTE) && (count($extraSamplesValues) === 1)) {
            $entries[TiffTag::EXTRA_SAMPLES] .= pack('C', $extraSamplesValues[0]) . "\0\0\0";
        } else {
            $payloadByTag[TiffTag::EXTRA_SAMPLES] = $this->packNumericPayload($extraSamplesType, $extraSamplesValues);
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
