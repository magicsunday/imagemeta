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
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
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
 * Verifies TIFF ColorMap palette semantics.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserColorMapTest extends TestCase
{
    /**
     * Palette image with valid ColorMap count is accepted.
     */
    #[Test]
    public function acceptsPaletteColorMapWithValidCount(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildColorMapTiff(
                photometric: 3,
                bitsPerSample: 8,
                colorMapType: TiffConst::TYPE_SHORT,
                colorMapValues: $this->buildColorMapValues(768),
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::COLOR_MAP));
    }

    /**
     * Palette image requires ColorMap.
     */
    #[Test]
    public function rejectsPaletteImageWithoutColorMap(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Palette images (PhotometricInterpretation=3) require ColorMap');

        (new TiffExifParser())->parseFromBlob(
            $this->buildColorMapTiff(
                photometric: 3,
                bitsPerSample: 8,
            ),
        );
    }

    /**
     * ColorMap count must match 3*(1<<BitsPerSample).
     */
    #[Test]
    public function rejectsPaletteColorMapWithWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ColorMap count 767 must be 3*(1<<BitsPerSample) = 768');

        (new TiffExifParser())->parseFromBlob(
            $this->buildColorMapTiff(
                photometric: 3,
                bitsPerSample: 8,
                colorMapType: TiffConst::TYPE_SHORT,
                colorMapValues: $this->buildColorMapValues(767),
            ),
        );
    }

    /**
     * ColorMap type must be SHORT for palette images.
     */
    #[Test]
    public function rejectsPaletteColorMapWithWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ColorMap must use SHORT type');

        (new TiffExifParser())->parseFromBlob(
            $this->buildColorMapTiff(
                photometric: 3,
                bitsPerSample: 8,
                colorMapType: TiffConst::TYPE_LONG,
                colorMapValues: $this->buildColorMapValues(768),
            ),
        );
    }

    /**
     * Non-palette images must not include ColorMap.
     */
    #[Test]
    public function rejectsColorMapForNonPalettePhotometricMode(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ColorMap is only valid for palette images');

        (new TiffExifParser())->parseFromBlob(
            $this->buildColorMapTiff(
                photometric: 2,
                bitsPerSample: 8,
                colorMapType: TiffConst::TYPE_SHORT,
                colorMapValues: $this->buildColorMapValues(768),
            ),
        );
    }

    /**
     * Builds a minimal TIFF with optional ColorMap.
     *
     * @param list<int>|null $colorMapValues
     */
    private function buildColorMapTiff(
        int $photometric,
        int $bitsPerSample,
        ?int $colorMapType = null,
        ?array $colorMapValues = null,
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

        if (($colorMapType !== null) && is_array($colorMapValues)) {
            $entries[TiffTag::COLOR_MAP] = pack('v', TiffTag::COLOR_MAP)
                . pack('v', $colorMapType)
                . pack('V', count($colorMapValues));
            $payloadByTag[TiffTag::COLOR_MAP] = $this->packNumericPayload($colorMapType, $colorMapValues);
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
    private function buildColorMapValues(int $count): array
    {
        $values = [];

        for ($index = 0; $index < $count; ++$index) {
            $values[] = $index % 65536;
        }

        return $values;
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
