<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffColorInkValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffImageDataValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffSampleValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffStructuralValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffTagConstraintValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValidationSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies TiffStructuralValidator orchestrator delegates to sub-validators correctly.
 *
 * @internal
 */
#[CoversClass(TiffStructuralValidator::class)]
#[UsesClass(TiffTagConstraintValidator::class)]
#[UsesClass(TiffJpegValidator::class)]
#[UsesClass(TiffSampleValidator::class)]
#[UsesClass(TiffColorInkValidator::class)]
#[UsesClass(TiffImageDataValidator::class)]
#[UsesClass(TiffValidationSupport::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ByteReader::class)]
final class TiffStructuralValidatorTest extends TestCase
{
    private TiffStructuralValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $buffer          = new MemoryBuffer("\0");
        $this->validator = new TiffStructuralValidator($buffer);
    }

    #[Test]
    public function acceptsMinimalValidIfd0(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, TiffConst::TYPE_SHORT, 1, 640),
            ExifTag::IMAGE_LENGTH => new IfdEntry(ExifTag::IMAGE_LENGTH, TiffConst::TYPE_SHORT, 1, 480),
        ]);

        $this->validator->validateIfd0($ifd0, null, false, false);

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
    public function validatePerIfdDelegatesConstraintChecks(): void
    {
        $ifd = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, TiffConst::TYPE_SHORT, 1, 640),
            ExifTag::IMAGE_LENGTH => new IfdEntry(ExifTag::IMAGE_LENGTH, TiffConst::TYPE_SHORT, 1, 480),
        ]);

        $this->validator->validatePerIfd($ifd, false);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validateImageDataDelegatesStripAndTileChecks(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, TiffConst::TYPE_SHORT, 1, 640),
            ExifTag::IMAGE_LENGTH => new IfdEntry(ExifTag::IMAGE_LENGTH, TiffConst::TYPE_SHORT, 1, 480),
        ]);

        $this->validator->validateImageData($ifd0);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsZeroImageWidthViaImageDataValidation(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ImageWidth value 0 is invalid');

        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, TiffConst::TYPE_SHORT, 1, 0),
            ExifTag::IMAGE_LENGTH => new IfdEntry(ExifTag::IMAGE_LENGTH, TiffConst::TYPE_SHORT, 1, 480),
        ]);

        $this->validator->validateImageData($ifd0);
    }
}
