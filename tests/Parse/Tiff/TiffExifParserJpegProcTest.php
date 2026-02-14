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
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function ksort;
use function str_pad;

/**
 * Verifies TIFF JPEGProc semantics and Compression coupling.
 */
#[CoversClass(TiffExifParser::class)]
final class TiffExifParserJpegProcTest extends TestCase
{
    /**
     * Compression=6 with JPEGProc=1 is valid.
     */
    #[Test]
    public function acceptsJpegProcBaselineWithJpegCompression(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION => $this->shortEntry(ExifTag::COMPRESSION, Compression::JPEG->value),
                TiffTag::JPEG_PROC   => $this->shortEntry(TiffTag::JPEG_PROC, 1),
            ]),
        );

        self::assertSame(1, $parsed->ifd1?->get(TiffTag::JPEG_PROC)?->value);
    }

    /**
     * Compression=6 with JPEGProc=14 is valid.
     */
    #[Test]
    public function acceptsJpegProcLosslessWithJpegCompression(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION => $this->shortEntry(ExifTag::COMPRESSION, Compression::JPEG->value),
                TiffTag::JPEG_PROC   => $this->shortEntry(TiffTag::JPEG_PROC, 14),
            ]),
        );

        self::assertSame(14, $parsed->ifd1?->get(TiffTag::JPEG_PROC)?->value);
    }

    /**
     * Compression=6 requires JPEGProc.
     */
    #[Test]
    public function rejectsMissingJpegProcForJpegCompression(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Compression=6 requires JPEGProc');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION => $this->shortEntry(ExifTag::COMPRESSION, Compression::JPEG->value),
            ]),
        );
    }

    /**
     * JPEGProc must use SHORT[1] layout.
     */
    #[Test]
    public function rejectsJpegProcWrongTypeOrCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGProc must use TIFF type SHORT');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION => $this->shortEntry(ExifTag::COMPRESSION, Compression::JPEG->value),
                TiffTag::JPEG_PROC   => $this->numericEntry(
                    TiffTag::JPEG_PROC,
                    TiffConst::TYPE_LONG,
                    1,
                    [1],
                ),
            ]),
        );
    }

    /**
     * JPEGProc value domain is {1,14}.
     */
    #[Test]
    public function rejectsUnsupportedJpegProcValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGProc value 2 is invalid; allowed values are 1 or 14');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION => $this->shortEntry(ExifTag::COMPRESSION, Compression::JPEG->value),
                TiffTag::JPEG_PROC   => $this->shortEntry(TiffTag::JPEG_PROC, 2),
            ]),
        );
    }

    /**
     * JPEGProc is invalid for non-JPEG Compression values.
     */
    #[Test]
    public function rejectsJpegProcWithNonJpegCompression(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGProc is only valid when Compression=6 (JPEG)');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd0([
                ExifTag::COMPRESSION => $this->shortEntry(ExifTag::COMPRESSION, Compression::UNCOMPRESSED->value),
                TiffTag::JPEG_PROC   => $this->shortEntry(TiffTag::JPEG_PROC, 1),
            ]),
        );
    }

    /**
     * @param array<int, string> $ifd0ExtraEntries
     */
    private function buildBlobWithIfd0(array $ifd0ExtraEntries): string
    {
        $ifd0Entries = [
            ExifTag::IMAGE_WIDTH  => $this->shortEntry(ExifTag::IMAGE_WIDTH, 64),
            ExifTag::IMAGE_LENGTH => $this->shortEntry(ExifTag::IMAGE_LENGTH, 64),
            ...$ifd0ExtraEntries,
        ];

        ksort($ifd0Entries);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . $this->buildIfdBlock($ifd0Entries, 0);
    }

    /**
     * Builds a TIFF with a thumbnail IFD1, where Compression=6 is allowed.
     *
     * @param array<int, string> $ifd1ExtraEntries
     */
    private function buildBlobWithIfd1(array $ifd1ExtraEntries): string
    {
        $ifd0Entries = [
            ExifTag::IMAGE_WIDTH  => $this->shortEntry(ExifTag::IMAGE_WIDTH, 64),
            ExifTag::IMAGE_LENGTH => $this->shortEntry(ExifTag::IMAGE_LENGTH, 64),
        ];

        $ifd1Entries = [
            ExifTag::IMAGE_WIDTH  => $this->shortEntry(ExifTag::IMAGE_WIDTH, 16),
            ExifTag::IMAGE_LENGTH => $this->shortEntry(ExifTag::IMAGE_LENGTH, 16),
            ...$ifd1ExtraEntries,
        ];

        ksort($ifd0Entries);
        ksort($ifd1Entries);

        $ifd1Offset = 8 + $this->ifdSize($ifd0Entries);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . $this->buildIfdBlock($ifd0Entries, $ifd1Offset)
            . $this->buildIfdBlock($ifd1Entries, 0);
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
        $ifdEntries = '';

        foreach ($entries as $entry) {
            $ifdEntries .= $entry;
        }

        return pack('v', count($entries))
            . $ifdEntries
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
