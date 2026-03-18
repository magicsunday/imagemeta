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
use MagicSunday\ImageMeta\Exif\Model\Ifd;
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
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function count;
use function implode;
use function ksort;
use function str_pad;

/**
 * Verifies TIFF JPEGProc semantics and Compression coupling.
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
                ExifTag::COMPRESSION       => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                ExifTag::SAMPLES_PER_PIXEL => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
                TiffTag::JPEG_PROC         => $this->shortEntry(TiffTag::JPEG_PROC, 1),
                TiffTag::JPEG_Q_TABLES     => $this->numericEntry(TiffTag::JPEG_Q_TABLES, TiffConst::TYPE_LONG, 1, [8]),
                TiffTag::JPEG_DC_TABLES    => $this->numericEntry(TiffTag::JPEG_DC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
                TiffTag::JPEG_AC_TABLES    => $this->numericEntry(TiffTag::JPEG_AC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
            ]),
        );

        self::assertSame(1, $parsed->ifd1?->get(TiffTag::JPEG_PROC)?->value);
    }

    /**
     * Compression=6 with JPEGProc=14 is valid when lossless predictors are present.
     */
    #[Test]
    public function acceptsJpegProcLosslessWithJpegCompression(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION              => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                ExifTag::SAMPLES_PER_PIXEL        => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
                TiffTag::JPEG_PROC                => $this->shortEntry(TiffTag::JPEG_PROC, 14),
                TiffTag::JPEG_LOSSLESS_PREDICTORS => $this->shortEntry(TiffTag::JPEG_LOSSLESS_PREDICTORS, 1),
                TiffTag::JPEG_DC_TABLES           => $this->numericEntry(TiffTag::JPEG_DC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
            ]),
        );

        self::assertSame(14, $parsed->ifd1?->get(TiffTag::JPEG_PROC)?->value);
    }

    /**
     * Compression=6 without JPEGProc is tolerated — the JPEG stream's SOF
     * marker is self-describing, so JPEGProc is not needed for decoding.
     */
    #[Test]
    public function acceptsMissingJpegProcForJpegCompression(): void
    {
        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
            ]),
        );

        $this->addToAssertionCount(1);
    }

    /**
     * JPEGProc must use SHORT[1] layout.
     */
    #[Test]
    public function rejectsJpegProcWrongTypeOrCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGProc must be SHORT[1].');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                TiffTag::JPEG_PROC   => $this->numericEntry(
                    TiffTag::JPEG_PROC,
                    TiffConst::TYPE_RATIONAL,
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
                ExifTag::COMPRESSION => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
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
                ExifTag::COMPRESSION => $this->shortEntry(ExifTag::COMPRESSION, Compression::Uncompressed->value),
                TiffTag::JPEG_PROC   => $this->shortEntry(TiffTag::JPEG_PROC, 1),
            ]),
        );
    }

    /**
     * JPEGRestartInterval accepts positive values when JPEG metadata is coherent.
     */
    #[Test]
    public function acceptsJpegRestartIntervalWithJpegCompression(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION           => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                TiffTag::JPEG_PROC             => $this->shortEntry(TiffTag::JPEG_PROC, 1),
                TiffTag::JPEG_RESTART_INTERVAL => $this->shortEntry(TiffTag::JPEG_RESTART_INTERVAL, 16),
            ]),
        );

        self::assertSame(16, $parsed->ifd1?->get(TiffTag::JPEG_RESTART_INTERVAL)?->value);
    }

    /**
     * JPEGRestartInterval value 0 is valid and means no restart markers.
     */
    #[Test]
    public function acceptsZeroJpegRestartIntervalWithJpegCompression(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION           => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                TiffTag::JPEG_PROC             => $this->shortEntry(TiffTag::JPEG_PROC, 1),
                TiffTag::JPEG_RESTART_INTERVAL => $this->shortEntry(TiffTag::JPEG_RESTART_INTERVAL, 0),
            ]),
        );

        self::assertSame(0, $parsed->ifd1?->get(TiffTag::JPEG_RESTART_INTERVAL)?->value);
    }

    /**
     * JPEGRestartInterval must use SHORT[1] layout.
     */
    #[Test]
    public function rejectsInvalidJpegRestartIntervalLayout(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGRestartInterval must be SHORT[1].');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION           => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                TiffTag::JPEG_PROC             => $this->shortEntry(TiffTag::JPEG_PROC, 1),
                TiffTag::JPEG_RESTART_INTERVAL => $this->numericEntry(
                    TiffTag::JPEG_RESTART_INTERVAL,
                    TiffConst::TYPE_RATIONAL,
                    1,
                    [16],
                ),
            ]),
        );
    }

    /**
     * JPEGRestartInterval is invalid outside JPEG compression context.
     */
    #[Test]
    public function rejectsJpegRestartIntervalWithNonJpegCompression(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGRestartInterval is only valid when Compression=6 (JPEG)');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd0([
                ExifTag::COMPRESSION           => $this->shortEntry(ExifTag::COMPRESSION, Compression::Uncompressed->value),
                TiffTag::JPEG_RESTART_INTERVAL => $this->shortEntry(TiffTag::JPEG_RESTART_INTERVAL, 16),
            ]),
        );
    }

    /**
     * JPEGRestartInterval is accepted even without JPEGProc when Compression=6.
     */
    #[Test]
    public function acceptsJpegRestartIntervalWhenJpegProcIsMissing(): void
    {
        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION           => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                TiffTag::JPEG_RESTART_INTERVAL => $this->shortEntry(TiffTag::JPEG_RESTART_INTERVAL, 16),
            ]),
        );

        $this->addToAssertionCount(1);
    }

    /**
     * JPEG lossless tags are valid for JPEGProc=14 with SHORT[SamplesPerPixel] layout.
     */
    #[Test]
    public function acceptsLosslessJpegPredictorsAndPointTransforms(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION              => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                TiffTag::JPEG_PROC                => $this->shortEntry(TiffTag::JPEG_PROC, 14),
                ExifTag::SAMPLES_PER_PIXEL        => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
                TiffTag::JPEG_LOSSLESS_PREDICTORS => $this->shortEntry(TiffTag::JPEG_LOSSLESS_PREDICTORS, 4),
                TiffTag::JPEG_POINT_TRANSFORMS    => $this->shortEntry(TiffTag::JPEG_POINT_TRANSFORMS, 0),
                TiffTag::JPEG_DC_TABLES           => $this->numericEntry(TiffTag::JPEG_DC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
            ]),
        );

        $ifd1 = $parsed->ifd1;
        self::assertInstanceOf(Ifd::class, $ifd1);
        self::assertSame(4, $ifd1->get(TiffTag::JPEG_LOSSLESS_PREDICTORS)?->value);
        self::assertSame(0, $ifd1->get(TiffTag::JPEG_POINT_TRANSFORMS)?->value);
    }

    /**
     * JPEGProc=14 requires JPEGLosslessPredictors.
     */
    #[Test]
    public function rejectsLosslessJpegProcWithoutPredictors(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGProc=14 requires JPEGLosslessPredictors');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION       => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                TiffTag::JPEG_PROC         => $this->shortEntry(TiffTag::JPEG_PROC, 14),
                ExifTag::SAMPLES_PER_PIXEL => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
            ]),
        );
    }

    /**
     * JPEGLosslessPredictors values are restricted to 1..7.
     */
    #[Test]
    public function rejectsOutOfRangeLosslessPredictorValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGLosslessPredictors component 0 value 8 is invalid');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION              => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                TiffTag::JPEG_PROC                => $this->shortEntry(TiffTag::JPEG_PROC, 14),
                ExifTag::SAMPLES_PER_PIXEL        => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
                TiffTag::JPEG_LOSSLESS_PREDICTORS => $this->shortEntry(TiffTag::JPEG_LOSSLESS_PREDICTORS, 8),
            ]),
        );
    }

    /**
     * JPEGLosslessPredictors and JPEGPointTransforms must use SHORT[SamplesPerPixel].
     */
    #[Test]
    public function rejectsInvalidLosslessJpegTagLayout(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGLosslessPredictors must be SHORT[SamplesPerPixel]');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION              => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                TiffTag::JPEG_PROC                => $this->shortEntry(TiffTag::JPEG_PROC, 14),
                ExifTag::SAMPLES_PER_PIXEL        => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 2),
                TiffTag::JPEG_LOSSLESS_PREDICTORS => $this->numericEntry(
                    TiffTag::JPEG_LOSSLESS_PREDICTORS,
                    TiffConst::TYPE_LONG,
                    1,
                    [4],
                ),
            ]),
        );
    }

    /**
     * JPEGLosslessPredictors is invalid unless JPEGProc=14.
     */
    #[Test]
    public function rejectsLosslessPredictorsWithNonLosslessJpegProc(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGLosslessPredictors is only valid when JPEGProc=14');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION              => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                TiffTag::JPEG_PROC                => $this->shortEntry(TiffTag::JPEG_PROC, 1),
                ExifTag::SAMPLES_PER_PIXEL        => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
                TiffTag::JPEG_LOSSLESS_PREDICTORS => $this->shortEntry(TiffTag::JPEG_LOSSLESS_PREDICTORS, 4),
            ]),
        );
    }

    /**
     * JPEGPointTransforms is invalid unless JPEGProc=14.
     */
    #[Test]
    public function rejectsPointTransformsWithNonLosslessJpegProc(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGPointTransforms is only valid when JPEGProc=14');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION           => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                TiffTag::JPEG_PROC             => $this->shortEntry(TiffTag::JPEG_PROC, 1),
                ExifTag::SAMPLES_PER_PIXEL     => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
                TiffTag::JPEG_POINT_TRANSFORMS => $this->shortEntry(TiffTag::JPEG_POINT_TRANSFORMS, 0),
            ]),
        );
    }

    /**
     * JPEGProc=1 accepts Q/DC/AC table offsets with LONG[SamplesPerPixel] layout.
     */
    #[Test]
    public function acceptsJpegDctProcessWithAllTableOffsets(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION       => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                ExifTag::SAMPLES_PER_PIXEL => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
                TiffTag::JPEG_PROC         => $this->shortEntry(TiffTag::JPEG_PROC, 1),
                TiffTag::JPEG_Q_TABLES     => $this->numericEntry(TiffTag::JPEG_Q_TABLES, TiffConst::TYPE_LONG, 1, [8]),
                TiffTag::JPEG_DC_TABLES    => $this->numericEntry(TiffTag::JPEG_DC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
                TiffTag::JPEG_AC_TABLES    => $this->numericEntry(TiffTag::JPEG_AC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
            ]),
        );

        $ifd1 = $parsed->ifd1;
        self::assertInstanceOf(Ifd::class, $ifd1);
        self::assertSame(8, $ifd1->get(TiffTag::JPEG_Q_TABLES)?->value);
        self::assertSame(8, $ifd1->get(TiffTag::JPEG_DC_TABLES)?->value);
        self::assertSame(8, $ifd1->get(TiffTag::JPEG_AC_TABLES)?->value);
    }

    /**
     * JPEGProc=14 accepts DC table offsets without AC tables.
     */
    #[Test]
    public function acceptsJpegLosslessProcessWithDcTablesOnly(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION              => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                ExifTag::SAMPLES_PER_PIXEL        => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
                TiffTag::JPEG_PROC                => $this->shortEntry(TiffTag::JPEG_PROC, 14),
                TiffTag::JPEG_LOSSLESS_PREDICTORS => $this->shortEntry(TiffTag::JPEG_LOSSLESS_PREDICTORS, 1),
                TiffTag::JPEG_DC_TABLES           => $this->numericEntry(TiffTag::JPEG_DC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
            ]),
        );

        self::assertSame(8, $parsed->ifd1?->get(TiffTag::JPEG_DC_TABLES)?->value);
    }

    /**
     * JPEGProc=1 requires JPEGQTables and JPEGACTables in addition to JPEGDCTables.
     */
    #[Test]
    public function rejectsMissingMandatoryDctTableFields(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGQTables and JPEGACTables are required when JPEGProc=1');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION       => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                ExifTag::SAMPLES_PER_PIXEL => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
                TiffTag::JPEG_PROC         => $this->shortEntry(TiffTag::JPEG_PROC, 1),
                TiffTag::JPEG_DC_TABLES    => $this->numericEntry(TiffTag::JPEG_DC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
            ]),
        );
    }

    /**
     * JPEGProc=14 requires JPEGDCTables and forbids JPEGACTables.
     */
    #[Test]
    public function rejectsForbiddenAcTablesForLosslessProcess(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGACTables are not used when JPEGProc=14');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION              => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                ExifTag::SAMPLES_PER_PIXEL        => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
                TiffTag::JPEG_PROC                => $this->shortEntry(TiffTag::JPEG_PROC, 14),
                TiffTag::JPEG_LOSSLESS_PREDICTORS => $this->shortEntry(TiffTag::JPEG_LOSSLESS_PREDICTORS, 1),
                TiffTag::JPEG_DC_TABLES           => $this->numericEntry(TiffTag::JPEG_DC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
                TiffTag::JPEG_AC_TABLES           => $this->numericEntry(TiffTag::JPEG_AC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
            ]),
        );
    }

    /**
     * JPEG table tags must use LONG[SamplesPerPixel] layout.
     */
    #[Test]
    public function rejectsInvalidJpegTableTagLayout(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGQTables must be LONG[SamplesPerPixel]');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION       => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                ExifTag::SAMPLES_PER_PIXEL => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 2),
                TiffTag::JPEG_PROC         => $this->shortEntry(TiffTag::JPEG_PROC, 1),
                TiffTag::JPEG_Q_TABLES     => $this->shortEntry(TiffTag::JPEG_Q_TABLES, 8),
                TiffTag::JPEG_DC_TABLES    => $this->numericEntry(TiffTag::JPEG_DC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
                TiffTag::JPEG_AC_TABLES    => $this->numericEntry(TiffTag::JPEG_AC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
            ]),
        );
    }

    /**
     * JPEG table offsets must point inside the TIFF blob.
     */
    #[Test]
    public function rejectsOutOfRangeJpegTableOffsets(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGDCTables component 0 offset');

        (new TiffExifParser())->parseFromBlob(
            $this->buildBlobWithIfd1([
                ExifTag::COMPRESSION       => $this->shortEntry(ExifTag::COMPRESSION, Compression::Jpeg->value),
                ExifTag::SAMPLES_PER_PIXEL => $this->shortEntry(ExifTag::SAMPLES_PER_PIXEL, 1),
                TiffTag::JPEG_PROC         => $this->shortEntry(TiffTag::JPEG_PROC, 1),
                TiffTag::JPEG_Q_TABLES     => $this->numericEntry(TiffTag::JPEG_Q_TABLES, TiffConst::TYPE_LONG, 1, [8]),
                TiffTag::JPEG_DC_TABLES    => $this->numericEntry(TiffTag::JPEG_DC_TABLES, TiffConst::TYPE_LONG, 1, [0x7FFFFFFF]),
                TiffTag::JPEG_AC_TABLES    => $this->numericEntry(TiffTag::JPEG_AC_TABLES, TiffConst::TYPE_LONG, 1, [8]),
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
        ];

        foreach ($ifd0ExtraEntries as $tag => $entry) {
            $ifd0Entries[$tag] = $entry;
        }

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
        ];

        foreach ($ifd1ExtraEntries as $tag => $entry) {
            $ifd1Entries[$tag] = $entry;
        }

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
        return pack('v', count($entries))
            . implode('', $entries)
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
        $payload = implode('', array_map(
            static fn (int $value): string => match ($type) {
                TiffConst::TYPE_SHORT => pack('v', $value),
                TiffConst::TYPE_LONG  => pack('V', $value),
                default               => pack('v', $value),
            },
            $values,
        ));

        return pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count)
            . str_pad($payload, 4, "\0");
    }
}
