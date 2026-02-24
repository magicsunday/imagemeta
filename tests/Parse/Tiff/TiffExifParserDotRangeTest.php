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
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
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
 * Verifies TIFF DotRange semantic validation.
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
final class TiffExifParserDotRangeTest extends TestCase
{
    /**
     * DotRange with one pair is accepted.
     */
    #[Test]
    public function acceptsSinglePairDotRange(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDotRangeTiff(
                samplesPerPixel: 4,
                bitsPerSample: [8, 8, 8, 8],
                dotRangeValues: [0, 255],
            ),
        );

        $entry = $parsed->ifd0->get(TiffTag::DOT_RANGE);
        self::assertNotNull($entry);
        self::assertSame(2, $entry->count);
    }

    /**
     * DotRange with per-component pairs is accepted.
     */
    #[Test]
    public function acceptsPerComponentDotRange(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDotRangeTiff(
                samplesPerPixel: 3,
                bitsPerSample: [8, 8, 8],
                dotRangeValues: [0, 255, 1, 254, 2, 253],
            ),
        );

        $dotRange = $parsed->ifd0->get(TiffTag::DOT_RANGE);
        self::assertNotNull($dotRange);
        self::assertInstanceOf(ExifNumericList::class, $dotRange->value);
        self::assertCount(6, $dotRange->value->values);
    }

    /**
     * DotRange count must be 2 or 2*SamplesPerPixel.
     */
    #[Test]
    public function rejectsInvalidDotRangeCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DotRange count 4 must be 2 or 2*SamplesPerPixel (6)');

        (new TiffExifParser())->parseFromBlob(
            $this->buildDotRangeTiff(
                samplesPerPixel: 3,
                bitsPerSample: [8, 8, 8],
                dotRangeValues: [0, 255, 1, 254],
            ),
        );
    }

    /**
     * DotRange values must be within [0, 2^BitsPerSample - 1].
     */
    #[Test]
    public function rejectsOutOfRangeDotRangeValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DotRange pair index 0 white value 256 exceeds max 255');

        (new TiffExifParser())->parseFromBlob(
            $this->buildDotRangeTiff(
                samplesPerPixel: 1,
                bitsPerSample: [8],
                dotRangeValues: [0, 256],
            ),
        );
    }

    /**
     * DotRange pairs must satisfy black < white.
     */
    #[Test]
    public function rejectsReversedDotRangePair(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DotRange pair index 0 requires black < white');

        (new TiffExifParser())->parseFromBlob(
            $this->buildDotRangeTiff(
                samplesPerPixel: 1,
                bitsPerSample: [8],
                dotRangeValues: [200, 100],
            ),
        );
    }

    /**
     * Builds a minimal TIFF with separated-image DotRange fields.
     *
     * @param list<int> $bitsPerSample
     * @param list<int> $dotRangeValues
     */
    private function buildDotRangeTiff(
        int $samplesPerPixel,
        array $bitsPerSample,
        array $dotRangeValues,
    ): string {
        $bitsPayload = '';
        foreach ($bitsPerSample as $value) {
            $bitsPayload .= pack('v', $value);
        }

        $dotRangePayload = '';
        foreach ($dotRangeValues as $value) {
            $dotRangePayload .= pack('v', $value);
        }

        $entries = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 128) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 128) . pack('v', 0),
            ExifTag::PHOTOMETRIC_INTERPRETATION => pack('v', ExifTag::PHOTOMETRIC_INTERPRETATION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 5) . pack('v', 0),
            ExifTag::SAMPLES_PER_PIXEL => pack('v', ExifTag::SAMPLES_PER_PIXEL)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $samplesPerPixel) . pack('v', 0),
            ExifTag::BITS_PER_SAMPLE => pack('v', ExifTag::BITS_PER_SAMPLE)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', count($bitsPerSample)),
            TiffTag::DOT_RANGE => pack('v', TiffTag::DOT_RANGE)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', count($dotRangeValues)),
        ];

        $payloadByTag = [
            ExifTag::BITS_PER_SAMPLE => $bitsPayload,
            TiffTag::DOT_RANGE       => $dotRangePayload,
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
}
