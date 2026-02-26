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
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffTagConstraintValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValidationSupport;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies TIFF tag-level constraint validation logic.
 *
 * @internal
 */
#[CoversClass(TiffTagConstraintValidator::class)]
#[UsesClass(TiffValidationSupport::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifNumericList::class)]
final class TiffTagConstraintValidatorTest extends TestCase
{
    private TiffTagConstraintValidator $validator;

    protected function setUp(): void
    {
        $buffer          = new MemoryBuffer("\0");
        $support         = new TiffValidationSupport($buffer);
        $this->validator = new TiffTagConstraintValidator($support);
    }

    // --- EnhancedIfd ---

    #[Test]
    public function acceptsEnhancedIfdWithNonEmptyEnhanceParams(): void
    {
        $ifd = new Ifd([
            TiffTag::NEW_SUBFILE_TYPE => new IfdEntry(TiffTag::NEW_SUBFILE_TYPE, TiffConst::TYPE_LONG, 1, 16),
            DngTag::ENHANCE_PARAMS    => new IfdEntry(DngTag::ENHANCE_PARAMS, TiffConst::TYPE_ASCII, 5, 'test'),
        ]);

        $this->validator->validateEnhancedIfd($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsEnhancedIfdWithoutEnhanceParams(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Enhanced IFD (NewSubfileType bit 4) requires an EnhanceParams tag');

        $ifd = new Ifd([
            TiffTag::NEW_SUBFILE_TYPE => new IfdEntry(TiffTag::NEW_SUBFILE_TYPE, TiffConst::TYPE_LONG, 1, 16),
        ]);

        $this->validator->validateEnhancedIfd($ifd);
    }

    #[Test]
    public function rejectsEnhancedIfdWithEmptyEnhanceParams(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('EnhanceParams must not be empty');

        $ifd = new Ifd([
            TiffTag::NEW_SUBFILE_TYPE => new IfdEntry(TiffTag::NEW_SUBFILE_TYPE, TiffConst::TYPE_LONG, 1, 16),
            DngTag::ENHANCE_PARAMS    => new IfdEntry(DngTag::ENHANCE_PARAMS, TiffConst::TYPE_ASCII, 1, "\0"),
        ]);

        $this->validator->validateEnhancedIfd($ifd);
    }

    // --- CompressionDomain ---

    #[Test]
    public function acceptsCompressionOneInIfd0JpegContext(): void
    {
        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, 1),
        ]);

        $this->validator->validateCompressionDomain($ifd0, null, true);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsInvalidCompressionInIfd0JpegContext(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Compression value 5 in IFD0 is invalid');

        $ifd0 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, 5),
        ]);

        $this->validator->validateCompressionDomain($ifd0, null, true);
    }

    #[Test]
    public function rejectsInvalidCompressionInIfd1(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Compression value 5 in IFD1 is invalid');

        $ifd0 = new Ifd([]);
        $ifd1 = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, 5),
        ]);

        $this->validator->validateCompressionDomain($ifd0, $ifd1, false);
    }

    // --- FaxOptionTags ---

    #[Test]
    public function acceptsValidT4OptionsWithCompression3(): void
    {
        $ifd = new Ifd([
            TiffTag::T4_OPTIONS  => new IfdEntry(TiffTag::T4_OPTIONS, TiffConst::TYPE_LONG, 1, 0b101),
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, 3),
        ]);

        $this->validator->validateFaxOptionTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsT4OptionsWithWrongCompression(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('T4Options is only valid when Compression = 3');

        $ifd = new Ifd([
            TiffTag::T4_OPTIONS  => new IfdEntry(TiffTag::T4_OPTIONS, TiffConst::TYPE_LONG, 1, 0),
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, 5),
        ]);

        $this->validator->validateFaxOptionTags($ifd);
    }

    #[Test]
    public function rejectsT4OptionsWithReservedBits(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('T4Options has reserved bits set');

        $ifd = new Ifd([
            TiffTag::T4_OPTIONS  => new IfdEntry(TiffTag::T4_OPTIONS, TiffConst::TYPE_LONG, 1, 0b1000),
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, 3),
        ]);

        $this->validator->validateFaxOptionTags($ifd);
    }

    // --- FillOrder ---

    #[Test]
    public function acceptsFillOrderOne(): void
    {
        $ifd = new Ifd([
            TiffTag::FILL_ORDER => new IfdEntry(TiffTag::FILL_ORDER, TiffConst::TYPE_SHORT, 1, 1),
        ]);

        $this->validator->validateFillOrderTag($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsFillOrderInvalidValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('FillOrder value 3 is invalid');

        $ifd = new Ifd([
            TiffTag::FILL_ORDER => new IfdEntry(TiffTag::FILL_ORDER, TiffConst::TYPE_SHORT, 1, 3),
        ]);

        $this->validator->validateFillOrderTag($ifd);
    }

    // --- ThreshholdingAndCellTags ---

    #[Test]
    public function acceptsThreshholdingTwoWithBothCellTags(): void
    {
        $ifd = new Ifd([
            TiffTag::THRESHHOLDING => new IfdEntry(TiffTag::THRESHHOLDING, TiffConst::TYPE_SHORT, 1, 2),
            TiffTag::CELL_WIDTH    => new IfdEntry(TiffTag::CELL_WIDTH, TiffConst::TYPE_SHORT, 1, 8),
            TiffTag::CELL_LENGTH   => new IfdEntry(TiffTag::CELL_LENGTH, TiffConst::TYPE_SHORT, 1, 8),
        ]);

        $this->validator->validateThreshholdingAndCellTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsThreshholdingTwoWithoutCellTags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Threshholding=2 requires both CellWidth and CellLength');

        $ifd = new Ifd([
            TiffTag::THRESHHOLDING => new IfdEntry(TiffTag::THRESHHOLDING, TiffConst::TYPE_SHORT, 1, 2),
        ]);

        $this->validator->validateThreshholdingAndCellTags($ifd);
    }

    #[Test]
    public function rejectsCellTagsWithoutThreshholdingTwo(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('CellWidth/CellLength are only valid when Threshholding=2');

        $ifd = new Ifd([
            TiffTag::THRESHHOLDING => new IfdEntry(TiffTag::THRESHHOLDING, TiffConst::TYPE_SHORT, 1, 1),
            TiffTag::CELL_WIDTH    => new IfdEntry(TiffTag::CELL_WIDTH, TiffConst::TYPE_SHORT, 1, 8),
            TiffTag::CELL_LENGTH   => new IfdEntry(TiffTag::CELL_LENGTH, TiffConst::TYPE_SHORT, 1, 8),
        ]);

        $this->validator->validateThreshholdingAndCellTags($ifd);
    }

    // --- PositionTags ---

    #[Test]
    public function acceptsValidPositionTags(): void
    {
        $ifd = new Ifd([
            TiffTag::X_POSITION => new IfdEntry(TiffTag::X_POSITION, TiffConst::TYPE_RATIONAL, 1, new ExifRational(0, 1)),
            TiffTag::Y_POSITION => new IfdEntry(TiffTag::Y_POSITION, TiffConst::TYPE_RATIONAL, 1, new ExifRational(1, 1)),
        ]);

        $this->validator->validatePositionTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsPositionTagWithZeroDenominator(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('XPosition denominator must be non-zero');

        $ifd = new Ifd([
            TiffTag::X_POSITION => new IfdEntry(TiffTag::X_POSITION, TiffConst::TYPE_RATIONAL, 1, new ExifRational(1, 0)),
        ]);

        $this->validator->validatePositionTags($ifd);
    }

    // --- PredictorTag ---

    #[Test]
    public function acceptsPredictorTwoWithLzwCompression(): void
    {
        $ifd = new Ifd([
            TiffTag::PREDICTOR   => new IfdEntry(TiffTag::PREDICTOR, TiffConst::TYPE_SHORT, 1, 2),
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Lzw->value),
        ]);

        $this->validator->validatePredictorTag($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsPredictorTwoWithoutLzwOrDeflate(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Predictor=2 requires Compression=5 (LZW) or 8 (Deflate)');

        $ifd = new Ifd([
            TiffTag::PREDICTOR   => new IfdEntry(TiffTag::PREDICTOR, TiffConst::TYPE_SHORT, 1, 2),
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Uncompressed->value),
        ]);

        $this->validator->validatePredictorTag($ifd);
    }

    // --- ImageDimensions ---

    #[Test]
    public function acceptsValidImageDimensions(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, TiffConst::TYPE_SHORT, 1, 640),
            ExifTag::IMAGE_LENGTH => new IfdEntry(ExifTag::IMAGE_LENGTH, TiffConst::TYPE_SHORT, 1, 480),
        ]);

        $this->validator->validateImageDimensions($ifd0);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsZeroImageWidth(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ImageWidth value 0 is invalid');

        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, TiffConst::TYPE_SHORT, 1, 0),
            ExifTag::IMAGE_LENGTH => new IfdEntry(ExifTag::IMAGE_LENGTH, TiffConst::TYPE_SHORT, 1, 480),
        ]);

        $this->validator->validateImageDimensions($ifd0);
    }

    // --- SubfileAndPageTags ---

    #[Test]
    public function acceptsValidNewSubfileType(): void
    {
        $ifd = new Ifd([
            TiffTag::NEW_SUBFILE_TYPE => new IfdEntry(TiffTag::NEW_SUBFILE_TYPE, TiffConst::TYPE_LONG, 1, 0),
        ]);

        $this->validator->validateSubfileAndPageTags($ifd, true);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsNewSubfileTypeWithReservedBits(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('NewSubfileType value 128 contains reserved bits');

        $ifd = new Ifd([
            TiffTag::NEW_SUBFILE_TYPE => new IfdEntry(TiffTag::NEW_SUBFILE_TYPE, TiffConst::TYPE_LONG, 1, 128),
        ]);

        $this->validator->validateSubfileAndPageTags($ifd, true);
    }
}
