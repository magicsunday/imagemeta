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

use function count;
use function ksort;
use function str_pad;
use function strlen;

/**
 * Verifies JPEGInterchangeFormat/JPEGInterchangeFormatLength pair semantics.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserJpegInterchangePairTest extends TestCase
{
    /**
     * Valid non-zero offset+length pair inside bounds parses.
     */
    #[Test]
    public function acceptsValidInterchangeOffsetAndLengthPair(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: -1,
                lengthValue: -1,
            ),
        );

        $ifd1 = $parsed->ifd1;
        self::assertNotNull($ifd1);
        self::assertNotNull($ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT));
        self::assertNotNull($ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH));
    }

    /**
     * Non-zero offset requires length.
     */
    #[Test]
    public function rejectsMissingInterchangeLengthForNonZeroOffset(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Non-zero JPEGInterchangeFormat requires JPEGInterchangeFormatLength');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: -1,
                lengthValue: null,
            ),
        );
    }

    /**
     * Offset=0 invalidates any present length.
     */
    #[Test]
    public function rejectsLengthWhenInterchangeOffsetIsZero(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGInterchangeFormatLength is invalid when JPEGInterchangeFormat is zero');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: 0,
                lengthValue: 4,
            ),
        );
    }

    /**
     * Interchange tags must use LONG[1] layout.
     */
    #[Test]
    public function rejectsInvalidInterchangeFieldLayout(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGInterchangeFormat must use TIFF type LONG');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: -1,
                lengthValue: -1,
                offsetType: TiffConst::TYPE_SHORT,
            ),
        );
    }

    /**
     * offset+length must be in-bounds.
     */
    #[Test]
    public function rejectsOutOfBoundsInterchangeRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGInterchangeFormat range exceeds TIFF data length');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1JpegInterchange(
                offsetValue: -1,
                lengthValue: 4096,
            ),
        );
    }

    /**
     * Builds a TIFF with IFD1 JPEG tags and optional interchange pair entries.
     *
     * Sentinel values:
     * - offsetValue=-1 => auto-calc to payload start.
     * - lengthValue=-1 => auto-calc to payload length.
     */
    private function buildBlobWithIfd1JpegInterchange(
        ?int $offsetValue,
        ?int $lengthValue,
        int $offsetType = TiffConst::TYPE_LONG,
        int $lengthType = TiffConst::TYPE_LONG,
        string $jpegPayload = "\xFF\xD8\xFF\xD9",
    ): string {
        $ifd0Entries = [
            ExifTag::IMAGE_WIDTH  => $this->shortEntry(ExifTag::IMAGE_WIDTH, 64),
            ExifTag::IMAGE_LENGTH => $this->shortEntry(ExifTag::IMAGE_LENGTH, 64),
        ];

        $ifd1Entries = [
            ExifTag::IMAGE_WIDTH  => $this->shortEntry(ExifTag::IMAGE_WIDTH, 16),
            ExifTag::IMAGE_LENGTH => $this->shortEntry(ExifTag::IMAGE_LENGTH, 16),
            ExifTag::COMPRESSION  => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
            TiffTag::JPEG_PROC    => $this->shortEntry(TiffTag::JPEG_PROC, 1),
        ];

        if ($offsetValue !== null) {
            $ifd1Entries[ExifTag::JPEG_INTERCHANGE_FORMAT] = $this->numericEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT,
                $offsetType,
                1,
                [0],
            );
        }

        if ($lengthValue !== null) {
            $ifd1Entries[ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH] = $this->numericEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                $lengthType,
                1,
                [0],
            );
        }

        ksort($ifd0Entries);
        ksort($ifd1Entries);

        $ifd0Offset = 8;
        $ifd0Size   = $this->ifdSize($ifd0Entries);
        $ifd1Offset = $ifd0Offset + $ifd0Size;
        $ifd1Size   = $this->ifdSize($ifd1Entries);
        $dataOffset = $ifd1Offset + $ifd1Size;

        if ($offsetValue !== null) {
            $resolvedOffset                                = $offsetValue === -1 ? $dataOffset : $offsetValue;
            $ifd1Entries[ExifTag::JPEG_INTERCHANGE_FORMAT] = $this->numericEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT,
                $offsetType,
                1,
                [$resolvedOffset],
            );
        }

        if ($lengthValue !== null) {
            $resolvedLength                                       = $lengthValue === -1 ? strlen($jpegPayload) : $lengthValue;
            $ifd1Entries[ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH] = $this->numericEntry(
                ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH,
                $lengthType,
                1,
                [$resolvedLength],
            );
        }

        ksort($ifd1Entries);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $this->buildIfdBlock($ifd0Entries, $ifd1Offset)
            . $this->buildIfdBlock($ifd1Entries, 0)
            . $jpegPayload;
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
        $payload = '';

        foreach ($entries as $entry) {
            $payload .= $entry;
        }

        return pack('v', count($entries))
            . $payload
            . pack('V', $nextIfdOffset);
    }

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
        $valueBytes = '';

        foreach ($values as $value) {
            $valueBytes .= match ($type) {
                TiffConst::TYPE_SHORT => pack('v', $value),
                TiffConst::TYPE_LONG  => pack('V', $value),
                default               => pack('V', $value),
            };
        }

        return pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count)
            . str_pad($valueBytes, 4, "\0");
    }
}
