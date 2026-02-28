<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValidationSupport;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies JPEG-related TIFF structural validation logic.
 *
 * @internal
 */
#[CoversClass(TiffJpegValidator::class)]
#[UsesClass(TiffValidationSupport::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifNumericList::class)]
final class TiffJpegValidatorTest extends TestCase
{
    private TiffJpegValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a buffer with some content so that JPEG table offset validation can work
        $buffer          = new MemoryBuffer("\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0");
        $support         = new TiffValidationSupport($buffer);
        $this->validator = new TiffJpegValidator($support);
    }

    // --- JpegProcTag ---

    #[Test]
    public function acceptsJpegProcOneWithJpegCompression(): void
    {
        $ifd = new Ifd([
            TiffTag::JPEG_PROC   => new IfdEntry(TiffTag::JPEG_PROC, TiffConst::TYPE_SHORT, 1, 1),
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Jpeg->value),
        ]);

        $this->validator->validateJpegProcTag($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsJpegProcInvalidValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGProc value 5 is invalid');

        $ifd = new Ifd([
            TiffTag::JPEG_PROC   => new IfdEntry(TiffTag::JPEG_PROC, TiffConst::TYPE_SHORT, 1, 5),
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Jpeg->value),
        ]);

        $this->validator->validateJpegProcTag($ifd);
    }

    #[Test]
    public function rejectsJpegProcWithoutJpegCompression(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGProc is only valid when Compression=6 (JPEG)');

        $ifd = new Ifd([
            TiffTag::JPEG_PROC   => new IfdEntry(TiffTag::JPEG_PROC, TiffConst::TYPE_SHORT, 1, 1),
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Lzw->value),
        ]);

        $this->validator->validateJpegProcTag($ifd);
    }

    // --- JpegRestartInterval ---

    #[Test]
    public function acceptsJpegRestartIntervalWithJpegCompression(): void
    {
        $ifd = new Ifd([
            TiffTag::JPEG_RESTART_INTERVAL => new IfdEntry(TiffTag::JPEG_RESTART_INTERVAL, TiffConst::TYPE_SHORT, 1, 64),
            ExifTag::COMPRESSION           => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Jpeg->value),
        ]);

        $this->validator->validateJpegRestartIntervalTag($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsJpegRestartIntervalWithoutJpegCompression(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGRestartInterval is only valid when Compression=6 (JPEG)');

        $ifd = new Ifd([
            TiffTag::JPEG_RESTART_INTERVAL => new IfdEntry(TiffTag::JPEG_RESTART_INTERVAL, TiffConst::TYPE_SHORT, 1, 64),
            ExifTag::COMPRESSION           => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Lzw->value),
        ]);

        $this->validator->validateJpegRestartIntervalTag($ifd);
    }

    // --- JpegInterchangePair ---

    #[Test]
    public function acceptsValidJpegInterchangePair(): void
    {
        $ifd = new Ifd([
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, TiffConst::TYPE_LONG, 1, 8),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, TiffConst::TYPE_LONG, 1, 100),
        ]);

        $this->validator->validateJpegInterchangePairTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function toleratesLengthWithoutOffset(): void
    {
        $ifd = new Ifd([
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, TiffConst::TYPE_LONG, 1, 100),
        ]);

        $this->validator->validateJpegInterchangePairTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsLengthWhenOffsetIsZero(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGInterchangeFormatLength is invalid when JPEGInterchangeFormat is zero');

        $ifd = new Ifd([
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, TiffConst::TYPE_LONG, 1, 0),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, TiffConst::TYPE_LONG, 1, 100),
        ]);

        $this->validator->validateJpegInterchangePairTags($ifd);
    }

    // --- JpegLosslessTags ---

    #[Test]
    public function acceptsLosslessPredictorsWithJpegProc14(): void
    {
        $ifd = new Ifd([
            TiffTag::JPEG_PROC                => new IfdEntry(TiffTag::JPEG_PROC, TiffConst::TYPE_SHORT, 1, 14),
            TiffTag::JPEG_LOSSLESS_PREDICTORS => new IfdEntry(TiffTag::JPEG_LOSSLESS_PREDICTORS, TiffConst::TYPE_SHORT, 1, 1),
            ExifTag::COMPRESSION              => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Jpeg->value),
        ]);

        $this->validator->validateJpegLosslessTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsLosslessPredictorsWithoutJpegProc14(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGLosslessPredictors is only valid when JPEGProc=14');

        $ifd = new Ifd([
            TiffTag::JPEG_PROC                => new IfdEntry(TiffTag::JPEG_PROC, TiffConst::TYPE_SHORT, 1, 1),
            TiffTag::JPEG_LOSSLESS_PREDICTORS => new IfdEntry(TiffTag::JPEG_LOSSLESS_PREDICTORS, TiffConst::TYPE_SHORT, 1, 1),
            ExifTag::COMPRESSION              => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Jpeg->value),
        ]);

        $this->validator->validateJpegLosslessTags($ifd);
    }

    #[Test]
    public function rejectsMissingLosslessPredictorsWhenJpegProc14(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGProc=14 requires JPEGLosslessPredictors');

        $ifd = new Ifd([
            TiffTag::JPEG_PROC   => new IfdEntry(TiffTag::JPEG_PROC, TiffConst::TYPE_SHORT, 1, 14),
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Jpeg->value),
        ]);

        $this->validator->validateJpegLosslessTags($ifd);
    }

    // --- JpegTableTags ---

    #[Test]
    public function acceptsValidJpegTableTagsWithJpegProc1(): void
    {
        $ifd = new Ifd([
            TiffTag::JPEG_PROC      => new IfdEntry(TiffTag::JPEG_PROC, TiffConst::TYPE_SHORT, 1, 1),
            TiffTag::JPEG_Q_TABLES  => new IfdEntry(TiffTag::JPEG_Q_TABLES, TiffConst::TYPE_LONG, 1, 8),
            TiffTag::JPEG_DC_TABLES => new IfdEntry(TiffTag::JPEG_DC_TABLES, TiffConst::TYPE_LONG, 1, 8),
            TiffTag::JPEG_AC_TABLES => new IfdEntry(TiffTag::JPEG_AC_TABLES, TiffConst::TYPE_LONG, 1, 8),
            ExifTag::COMPRESSION    => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Jpeg->value),
        ]);

        $this->validator->validateJpegTableTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsMissingDcTablesWhenJpegProc1(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JPEGDCTables is required when JPEGProc=1');

        $ifd = new Ifd([
            TiffTag::JPEG_PROC     => new IfdEntry(TiffTag::JPEG_PROC, TiffConst::TYPE_SHORT, 1, 1),
            TiffTag::JPEG_Q_TABLES => new IfdEntry(TiffTag::JPEG_Q_TABLES, TiffConst::TYPE_LONG, 1, 8),
            ExifTag::COMPRESSION   => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Jpeg->value),
        ]);

        $this->validator->validateJpegTableTags($ifd);
    }
}
