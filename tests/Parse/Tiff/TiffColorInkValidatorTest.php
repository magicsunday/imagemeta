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
use MagicSunday\ImageMeta\Parse\Tiff\TiffColorInkValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValidationSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies TIFF color, ink, and transfer-function validation logic.
 *
 * @internal
 */
#[CoversClass(TiffColorInkValidator::class)]
#[UsesClass(TiffValidationSupport::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifNumericList::class)]
final class TiffColorInkValidatorTest extends TestCase
{
    private TiffColorInkValidator $validator;

    protected function setUp(): void
    {
        $buffer          = new MemoryBuffer("\0");
        $support         = new TiffValidationSupport($buffer);
        $this->validator = new TiffColorInkValidator($support);
    }

    // --- SeparatedImageInkTags ---

    #[Test]
    public function acceptsSeparatedImageWithDefaultCmykInkSet(): void
    {
        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 5),
            TiffTag::INK_SET                    => new IfdEntry(TiffTag::INK_SET, TiffConst::TYPE_SHORT, 1, 1),
        ]);

        $this->validator->validateSeparatedImageInkTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsInkNamesWhenInkSetIsCmyk(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('InkNames must not be present when InkSet=1 (CMYK)');

        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 5),
            TiffTag::INK_SET                    => new IfdEntry(TiffTag::INK_SET, TiffConst::TYPE_SHORT, 1, 1),
            TiffTag::INK_NAMES                  => new IfdEntry(TiffTag::INK_NAMES, TiffConst::TYPE_ASCII, 5, "Cyan"),
        ]);

        $this->validator->validateSeparatedImageInkTags($ifd);
    }

    #[Test]
    public function acceptsSeparatedImageWithCustomInkNames(): void
    {
        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 5),
            TiffTag::INK_SET                    => new IfdEntry(TiffTag::INK_SET, TiffConst::TYPE_SHORT, 1, 2),
            TiffTag::NUMBER_OF_INKS             => new IfdEntry(TiffTag::NUMBER_OF_INKS, TiffConst::TYPE_SHORT, 1, 3),
            TiffTag::INK_NAMES                  => new IfdEntry(TiffTag::INK_NAMES, TiffConst::TYPE_ASCII, 14, "Red\0Green\0Blue"),
        ]);

        $this->validator->validateSeparatedImageInkTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsMissingInkNamesWhenInkSetIsCustom(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('InkSet=2 requires an InkNames ASCII list');

        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 5),
            TiffTag::INK_SET                    => new IfdEntry(TiffTag::INK_SET, TiffConst::TYPE_SHORT, 1, 2),
        ]);

        $this->validator->validateSeparatedImageInkTags($ifd);
    }

    #[Test]
    public function rejectsInkNameCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('InkNames string count 2 must match NumberOfInks 4');

        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 5),
            TiffTag::INK_SET                    => new IfdEntry(TiffTag::INK_SET, TiffConst::TYPE_SHORT, 1, 2),
            TiffTag::NUMBER_OF_INKS             => new IfdEntry(TiffTag::NUMBER_OF_INKS, TiffConst::TYPE_SHORT, 1, 4),
            TiffTag::INK_NAMES                  => new IfdEntry(TiffTag::INK_NAMES, TiffConst::TYPE_ASCII, 10, "Red\0Green"),
        ]);

        $this->validator->validateSeparatedImageInkTags($ifd);
    }

    // --- PaletteColorMap ---

    #[Test]
    public function acceptsValidPaletteColorMap(): void
    {
        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 3),
            TiffTag::COLOR_MAP                  => new IfdEntry(TiffTag::COLOR_MAP, TiffConst::TYPE_SHORT, 768, 0),
            ExifTag::BITS_PER_SAMPLE            => new IfdEntry(ExifTag::BITS_PER_SAMPLE, TiffConst::TYPE_SHORT, 1, 8),
        ]);

        $this->validator->validatePaletteColorMapTag($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsMissingColorMapForPaletteImage(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Palette images (PhotometricInterpretation=3) require ColorMap');

        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 3),
        ]);

        $this->validator->validatePaletteColorMapTag($ifd);
    }

    #[Test]
    public function rejectsColorMapForNonPaletteImage(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ColorMap is only valid for palette images');

        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 2),
            TiffTag::COLOR_MAP                  => new IfdEntry(TiffTag::COLOR_MAP, TiffConst::TYPE_SHORT, 768, 0),
        ]);

        $this->validator->validatePaletteColorMapTag($ifd);
    }

    // --- TransferFamilyTags ---

    #[Test]
    public function acceptsTransferFunctionWithGrayscalePhotometric(): void
    {
        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 1),
            ExifTag::TRANSFER_FUNCTION          => new IfdEntry(ExifTag::TRANSFER_FUNCTION, TiffConst::TYPE_SHORT, 256, 0),
            ExifTag::BITS_PER_SAMPLE            => new IfdEntry(ExifTag::BITS_PER_SAMPLE, TiffConst::TYPE_SHORT, 1, 8),
        ]);

        $this->validator->validateTransferFamilyTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsTransferFunctionWithInvalidPhotometric(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TransferFunction is only valid for PhotometricInterpretation {0,1,2,3,6}');

        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 5),
            ExifTag::TRANSFER_FUNCTION          => new IfdEntry(ExifTag::TRANSFER_FUNCTION, TiffConst::TYPE_SHORT, 256, 0),
        ]);

        $this->validator->validateTransferFamilyTags($ifd);
    }

    #[Test]
    public function rejectsTransferRangeWithWrongTypeOrCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TransferRange must be SHORT[6]');

        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 2),
            TiffTag::TRANSFER_RANGE             => new IfdEntry(TiffTag::TRANSFER_RANGE, TiffConst::TYPE_SHORT, 3, 0),
        ]);

        $this->validator->validateTransferFamilyTags($ifd);
    }

    #[Test]
    public function rejectsReferenceBlackWhiteWithInvalidPhotometric(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ReferenceBlackWhite is only valid for PhotometricInterpretation RGB(2) or YCbCr(6)');

        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 1),
            ExifTag::REFERENCE_BLACK_WHITE      => new IfdEntry(ExifTag::REFERENCE_BLACK_WHITE, TiffConst::TYPE_RATIONAL, 6, 0),
        ]);

        $this->validator->validateTransferFamilyTags($ifd);
    }
}
