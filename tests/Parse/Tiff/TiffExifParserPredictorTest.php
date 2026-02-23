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
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies TIFF Predictor semantics and Compression coupling.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserPredictorTest extends TestCase
{
    /**
     * Predictor=1 is valid and parses.
     */
    #[Test]
    public function acceptsPredictorOne(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildSingleIfdBlob(
                predictorType: TiffConst::TYPE_SHORT,
                predictorCount: 1,
                predictorValues: [1],
                compression: Compression::UNCOMPRESSED->value,
            ),
        );

        self::assertSame(1, $parsed->ifd0->get(TiffTag::PREDICTOR)?->value);
    }

    /**
     * Predictor=2 is valid when paired with Compression=5 in a subsequent IFD.
     */
    #[Test]
    public function acceptsPredictorTwoWithLzwCompression(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob($this->buildThreeIfdBlobWithLzwPredictor());

        $subsequentIfds = $parsed->subsequentIfds();

        self::assertCount(2, $subsequentIfds);
        self::assertSame(2, $subsequentIfds[1]->get(TiffTag::PREDICTOR)?->value);
        self::assertSame(Compression::LZW->value, $subsequentIfds[1]->get(ExifTag::COMPRESSION)?->value);
    }

    /**
     * Predictor must use SHORT type.
     */
    #[Test]
    public function rejectsPredictorWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Predictor must use TIFF type SHORT');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSingleIfdBlob(
                predictorType: TiffConst::TYPE_LONG,
                predictorCount: 1,
                predictorValues: [1],
            ),
        );
    }

    /**
     * Predictor must have count=1.
     */
    #[Test]
    public function rejectsPredictorWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Predictor must contain exactly 1 bytes');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSingleIfdBlob(
                predictorType: TiffConst::TYPE_SHORT,
                predictorCount: 2,
                predictorValues: [1, 1],
            ),
        );
    }

    /**
     * Predictor value domain is {1,2}.
     */
    #[Test]
    public function rejectsPredictorInvalidValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Predictor value 3 is outside the valid domain {1, 2}');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSingleIfdBlob(
                predictorType: TiffConst::TYPE_SHORT,
                predictorCount: 1,
                predictorValues: [3],
            ),
        );
    }

    /**
     * Predictor=2 requires Compression=5 (LZW).
     */
    #[Test]
    public function rejectsPredictorTwoWithoutLzwCompression(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Predictor=2 requires Compression=5 (LZW)');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSingleIfdBlob(
                predictorType: TiffConst::TYPE_SHORT,
                predictorCount: 1,
                predictorValues: [2],
                compression: Compression::UNCOMPRESSED->value,
            ),
        );
    }

    /**
     * Builds a minimal single-IFD TIFF with optional Predictor and Compression tags.
     *
     * @param list<int> $predictorValues
     */
    private function buildSingleIfdBlob(
        int $predictorType,
        int $predictorCount,
        array $predictorValues,
        ?int $compression = null,
    ): string {
        $entries = [
            ExifTag::IMAGE_WIDTH  => $this->shortEntry(ExifTag::IMAGE_WIDTH, 64),
            ExifTag::IMAGE_LENGTH => $this->shortEntry(ExifTag::IMAGE_LENGTH, 64),
            TiffTag::PREDICTOR    => $this->numericEntry(TiffTag::PREDICTOR, $predictorType, $predictorCount, $predictorValues),
        ];

        if ($compression !== null) {
            $entries[ExifTag::COMPRESSION] = $this->shortEntry(ExifTag::COMPRESSION, $compression);
        }

        ksort($entries);

        $ifdOffset = 8;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $this->buildIfdBlock($entries, 0);
    }

    /**
     * Builds a 3-IFD chain where the second subsequent IFD carries Predictor=2 and Compression=5.
     */
    private function buildThreeIfdBlobWithLzwPredictor(): string
    {
        $ifd0Entries = [
            ExifTag::IMAGE_WIDTH  => $this->shortEntry(ExifTag::IMAGE_WIDTH, 64),
            ExifTag::IMAGE_LENGTH => $this->shortEntry(ExifTag::IMAGE_LENGTH, 64),
        ];

        $ifd1Entries = [
            ExifTag::IMAGE_WIDTH  => $this->shortEntry(ExifTag::IMAGE_WIDTH, 32),
            ExifTag::IMAGE_LENGTH => $this->shortEntry(ExifTag::IMAGE_LENGTH, 32),
        ];

        $ifd2Entries = [
            ExifTag::IMAGE_WIDTH  => $this->shortEntry(ExifTag::IMAGE_WIDTH, 16),
            ExifTag::IMAGE_LENGTH => $this->shortEntry(ExifTag::IMAGE_LENGTH, 16),
            ExifTag::COMPRESSION  => $this->shortEntry(ExifTag::COMPRESSION, Compression::LZW->value),
            TiffTag::PREDICTOR    => $this->numericEntry(TiffTag::PREDICTOR, TiffConst::TYPE_SHORT, 1, [2]),
        ];

        ksort($ifd0Entries);
        ksort($ifd1Entries);
        ksort($ifd2Entries);

        $ifdOffset = 8;
        $ifd0Size  = $this->ifdSize($ifd0Entries);
        $ifd1Off   = $ifdOffset + $ifd0Size;
        $ifd1Size  = $this->ifdSize($ifd1Entries);
        $ifd2Off   = $ifd1Off + $ifd1Size;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $this->buildIfdBlock($ifd0Entries, $ifd1Off)
            . $this->buildIfdBlock($ifd1Entries, $ifd2Off)
            . $this->buildIfdBlock($ifd2Entries, 0);
    }

    /**
     * @param array<int, string> $entries
     */
    private function ifdSize(array $entries): int
    {
        return 2 + (12 * count($entries)) + 4;
    }

    /**
     * @param array<int, string> $entries
     */
    private function buildIfdBlock(array $entries, int $nextIfdOffset): string
    {
        $entryPayload = '';

        foreach ($entries as $entry) {
            $entryPayload .= $entry;
        }

        return pack('v', count($entries))
            . $entryPayload
            . pack('V', $nextIfdOffset);
    }

    /**
     * Encodes a SHORT[1] inline entry.
     */
    private function shortEntry(int $tag, int $value): string
    {
        return pack('v', $tag)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', $value)
            . pack('v', 0);
    }

    /**
     * @param list<int> $values
     */
    private function numericEntry(int $tag, int $type, int $count, array $values): string
    {
        $payload = '';

        foreach ($values as $value) {
            $payload .= match ($type) {
                TiffConst::TYPE_SHORT => pack('v', $value),
                TiffConst::TYPE_LONG  => pack('V', $value),
                default               => pack('v', $value),
            };
        }

        return pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count)
            . str_pad($payload, 4, "\0");
    }
}
