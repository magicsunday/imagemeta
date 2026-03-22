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
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\DngStructureValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngValidationSupport;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Verifies DNG structural rules, role photometric, and constraint validation.
 *
 * @internal
 */
#[CoversClass(DngStructureValidator::class)]
#[UsesClass(DngValidationSupport::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ByteReader::class)]
final class DngStructureValidatorTest extends TestCase
{
    private DngStructureValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $buffer          = new MemoryBuffer(str_repeat("\0", 256));
        $support         = new DngValidationSupport(Endian::Little, $buffer);
        $this->validator = new DngStructureValidator($support);
    }

    // --- RequiredOrientation ---

    #[Test]
    public function acceptsDngWithOrientation(): void
    {
        $ifd = new Ifd([
            DngTag::DNG_VERSION         => new IfdEntry(DngTag::DNG_VERSION, TiffConst::TYPE_BYTE, 4, new ExifNumericList([1, 7, 1, 0])),
            ExifTag::ORIENTATION        => new IfdEntry(ExifTag::ORIENTATION, TiffConst::TYPE_SHORT, 1, 1),
            DngTag::UNIQUE_CAMERA_MODEL => new IfdEntry(DngTag::UNIQUE_CAMERA_MODEL, TiffConst::TYPE_ASCII, 5, 'Test'),
        ]);

        $this->validator->validateDngRequiredOrientation($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsDngWithoutOrientation(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DNG requires Orientation tag in IFD0');

        $ifd = new Ifd([
            DngTag::DNG_VERSION => new IfdEntry(DngTag::DNG_VERSION, TiffConst::TYPE_BYTE, 4, new ExifNumericList([1, 7, 1, 0])),
        ]);

        $this->validator->validateDngRequiredOrientation($ifd);
    }

    // --- RequiredUniqueCameraModel ---

    #[Test]
    public function acceptsDngWithUniqueCameraModel(): void
    {
        $ifd = new Ifd([
            DngTag::DNG_VERSION         => new IfdEntry(DngTag::DNG_VERSION, TiffConst::TYPE_BYTE, 4, new ExifNumericList([1, 7, 1, 0])),
            DngTag::UNIQUE_CAMERA_MODEL => new IfdEntry(DngTag::UNIQUE_CAMERA_MODEL, TiffConst::TYPE_ASCII, 5, 'Test'),
        ]);

        $this->validator->validateDngRequiredUniqueCameraModel($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsDngWithoutUniqueCameraModel(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DNG requires UniqueCameraModel tag in IFD0');

        $ifd = new Ifd([
            DngTag::DNG_VERSION => new IfdEntry(DngTag::DNG_VERSION, TiffConst::TYPE_BYTE, 4, new ExifNumericList([1, 7, 1, 0])),
        ]);

        $this->validator->validateDngRequiredUniqueCameraModel($ifd);
    }

    // --- RolePhotometric ---

    #[Test]
    public function acceptsDepthMapIfdWithCorrectPhotometric(): void
    {
        $ifd = new Ifd([
            TiffTag::NEW_SUBFILE_TYPE           => new IfdEntry(TiffTag::NEW_SUBFILE_TYPE, TiffConst::TYPE_LONG, 1, 8),
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 51177),
        ]);

        $this->validator->validateDngRolePhotometric($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsDepthMapIfdWithWrongPhotometric(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DNG IFD with NewSubFileType 8 requires PhotometricInterpretation 51177');

        $ifd = new Ifd([
            TiffTag::NEW_SUBFILE_TYPE           => new IfdEntry(TiffTag::NEW_SUBFILE_TYPE, TiffConst::TYPE_LONG, 1, 8),
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 2),
        ]);

        $this->validator->validateDngRolePhotometric($ifd);
    }

    // --- IFD0-only tags ---

    #[Test]
    public function acceptsAdditionalIfdWithoutIfd0OnlyTags(): void
    {
        $ifd = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, TiffConst::TYPE_SHORT, 1, 640),
            ExifTag::IMAGE_LENGTH => new IfdEntry(ExifTag::IMAGE_LENGTH, TiffConst::TYPE_SHORT, 1, 480),
        ]);

        $this->validator->validateDngIfd0OnlyTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsDngVersionInAdditionalIfd(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DNG tag 0xC612 is restricted to IFD 0');

        $ifd = new Ifd([
            DngTag::DNG_VERSION => new IfdEntry(DngTag::DNG_VERSION, TiffConst::TYPE_BYTE, 4, new ExifNumericList([1, 7, 1, 0])),
        ]);

        $this->validator->validateDngIfd0OnlyTags($ifd);
    }

    // --- JXL tags ---

    #[Test]
    public function acceptsJxlTagsWithJpegXlCompression(): void
    {
        $ifd = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::JpegXl->value),
            DngTag::JXL_EFFORT   => new IfdEntry(DngTag::JXL_EFFORT, TiffConst::TYPE_LONG, 1, 7),
        ]);

        $this->validator->validateDngJxlTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsJxlTagsWithoutJpegXlCompression(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JXL tags (JXLDistance, JXLEffort, JXLDecodeSpeed) require Compression = 52546');

        $ifd = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::Lzw->value),
            DngTag::JXL_EFFORT   => new IfdEntry(DngTag::JXL_EFFORT, TiffConst::TYPE_LONG, 1, 7),
        ]);

        $this->validator->validateDngJxlTags($ifd);
    }

    #[Test]
    public function rejectsJxlEffortOutOfRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('JXLEffort must be 1');

        $ifd = new Ifd([
            ExifTag::COMPRESSION => new IfdEntry(ExifTag::COMPRESSION, TiffConst::TYPE_SHORT, 1, Compression::JpegXl->value),
            DngTag::JXL_EFFORT   => new IfdEntry(DngTag::JXL_EFFORT, TiffConst::TYPE_LONG, 1, 0),
        ]);

        $this->validator->validateDngJxlTags($ifd);
    }

    // --- DigestTags ---

    #[Test]
    public function acceptsValidDigestTag(): void
    {
        $ifd = new Ifd([
            DngTag::RAW_IMAGE_DIGEST => new IfdEntry(DngTag::RAW_IMAGE_DIGEST, TiffConst::TYPE_BYTE, 16, 'digest16bytesval'),
        ]);

        $this->validator->validateDngDigestTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsDigestTagWithWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('RawImageDigest must be BYTE[16]');

        $ifd = new Ifd([
            DngTag::RAW_IMAGE_DIGEST => new IfdEntry(DngTag::RAW_IMAGE_DIGEST, TiffConst::TYPE_BYTE, 8, 'digest8b'),
        ]);

        $this->validator->validateDngDigestTags($ifd);
    }

    // --- PreviewColorSpace ---

    #[Test]
    public function acceptsValidPreviewColorSpace(): void
    {
        $ifd = new Ifd([
            DngTag::PREVIEW_COLOR_SPACE => new IfdEntry(DngTag::PREVIEW_COLOR_SPACE, TiffConst::TYPE_LONG, 1, 2),
        ]);

        $this->validator->validateDngPreviewColorSpace($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsPreviewColorSpaceOutOfRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('PreviewColorSpace value must be 0..4');

        $ifd = new Ifd([
            DngTag::PREVIEW_COLOR_SPACE => new IfdEntry(DngTag::PREVIEW_COLOR_SPACE, TiffConst::TYPE_LONG, 1, 5),
        ]);

        $this->validator->validateDngPreviewColorSpace($ifd);
    }

    // --- SemanticMaskIdentity ---

    #[Test]
    public function acceptsSemanticMaskWithName(): void
    {
        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, Photometric::PhotometricMask->value),
            DngTag::SEMANTIC_NAME               => new IfdEntry(DngTag::SEMANTIC_NAME, TiffConst::TYPE_ASCII, 5, 'skin'),
        ]);

        $this->validator->validateDngSemanticMaskIdentity($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsSemanticMaskWithoutName(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('SemanticName is required in Semantic Mask IFD');

        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, Photometric::PhotometricMask->value),
        ]);

        $this->validator->validateDngSemanticMaskIdentity($ifd);
    }

    // --- DepthEnums ---

    #[Test]
    public function acceptsValidDepthEnums(): void
    {
        $ifd = new Ifd([
            DngTag::DEPTH_FORMAT       => new IfdEntry(DngTag::DEPTH_FORMAT, TiffConst::TYPE_SHORT, 1, 1),
            DngTag::DEPTH_UNITS        => new IfdEntry(DngTag::DEPTH_UNITS, TiffConst::TYPE_SHORT, 1, 0),
            DngTag::DEPTH_MEASURE_TYPE => new IfdEntry(DngTag::DEPTH_MEASURE_TYPE, TiffConst::TYPE_SHORT, 1, 2),
        ]);

        $this->validator->validateDngDepthEnums($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsDepthFormatOutOfDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DepthFormat value 5 is out of domain');

        $ifd = new Ifd([
            DngTag::DEPTH_FORMAT => new IfdEntry(DngTag::DEPTH_FORMAT, TiffConst::TYPE_SHORT, 1, 5),
        ]);

        $this->validator->validateDngDepthEnums($ifd);
    }

    // --- NoiseReductionApplied ---

    #[Test]
    public function acceptsValidNoiseReductionApplied(): void
    {
        $ifd = new Ifd([
            DngTag::NOISE_REDUCTION_APPLIED => new IfdEntry(
                DngTag::NOISE_REDUCTION_APPLIED,
                TiffConst::TYPE_RATIONAL,
                1,
                new ExifRational(1, 2),
            ),
        ]);

        $this->validator->validateDngNoiseReductionApplied($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsNoiseReductionAppliedOutOfRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('NoiseReductionApplied must be in [0.0, 1.0]');

        $ifd = new Ifd([
            DngTag::NOISE_REDUCTION_APPLIED => new IfdEntry(
                DngTag::NOISE_REDUCTION_APPLIED,
                TiffConst::TYPE_RATIONAL,
                1,
                new ExifRational(3, 2),
            ),
        ]);

        $this->validator->validateDngNoiseReductionApplied($ifd);
    }

    #[Test]
    public function acceptsNoiseReductionAppliedSentinel(): void
    {
        $ifd = new Ifd([
            DngTag::NOISE_REDUCTION_APPLIED => new IfdEntry(
                DngTag::NOISE_REDUCTION_APPLIED,
                TiffConst::TYPE_RATIONAL,
                1,
                new ExifRational(0, 0),
            ),
        ]);

        $this->validator->validateDngNoiseReductionApplied($ifd);

        $this->addToAssertionCount(1);
    }

    // --- EnhanceParams ---

    #[Test]
    public function acceptsValidEnhanceParams(): void
    {
        $ifd = new Ifd([
            DngTag::ENHANCE_PARAMS => new IfdEntry(DngTag::ENHANCE_PARAMS, TiffConst::TYPE_ASCII, 5, 'test'),
        ]);

        $this->validator->validateDngEnhanceParams($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsEnhanceParamsWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('EnhanceParams must use ASCII type');

        $ifd = new Ifd([
            DngTag::ENHANCE_PARAMS => new IfdEntry(DngTag::ENHANCE_PARAMS, TiffConst::TYPE_UNDEFINED, 5, 'test'),
        ]);

        $this->validator->validateDngEnhanceParams($ifd);
    }

    #[Test]
    public function rejectsEmptyEnhanceParams(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('EnhanceParams must not be empty');

        $ifd = new Ifd([
            DngTag::ENHANCE_PARAMS => new IfdEntry(DngTag::ENHANCE_PARAMS, TiffConst::TYPE_ASCII, 0, ''),
        ]);

        $this->validator->validateDngEnhanceParams($ifd);
    }
}
