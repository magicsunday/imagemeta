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
use MagicSunday\ImageMeta\Parse\Tiff\TiffSampleValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValidationSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_map;

/**
 * Verifies TIFF sample-related tag validation logic.
 *
 * @internal
 */
#[CoversClass(TiffSampleValidator::class)]
#[UsesClass(TiffValidationSupport::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ExifNumericList::class)]
final class TiffSampleValidatorTest extends TestCase
{
    private TiffSampleValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $buffer          = new MemoryBuffer("\0");
        $support         = new TiffValidationSupport($buffer);
        $this->validator = new TiffSampleValidator($support);
    }

    #[Test]
    public function usesDedicatedSampleCountAndMinMaxHelpers(): void
    {
        $reflection = new ReflectionClass(TiffSampleValidator::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertContains('resolveSamplesPerPixel', $methods);
        self::assertContains('validateMinOrMaxSampleValueEntry', $methods);
    }

    // --- MinMaxSampleValue ---

    #[Test]
    public function acceptsValidMinMaxSampleValues(): void
    {
        $ifd = new Ifd([
            TiffTag::MIN_SAMPLE_VALUE => new IfdEntry(TiffTag::MIN_SAMPLE_VALUE, TiffConst::TYPE_SHORT, 1, 0),
            TiffTag::MAX_SAMPLE_VALUE => new IfdEntry(TiffTag::MAX_SAMPLE_VALUE, TiffConst::TYPE_SHORT, 1, 255),
            ExifTag::BITS_PER_SAMPLE  => new IfdEntry(ExifTag::BITS_PER_SAMPLE, TiffConst::TYPE_SHORT, 1, 8),
        ]);

        $this->validator->validateMinMaxSampleValueTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsMinSampleValueWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('MinSampleValue must be SHORT');

        $ifd = new Ifd([
            TiffTag::MIN_SAMPLE_VALUE => new IfdEntry(TiffTag::MIN_SAMPLE_VALUE, TiffConst::TYPE_LONG, 1, 0),
        ]);

        $this->validator->validateMinMaxSampleValueTags($ifd);
    }

    #[Test]
    public function rejectsMinSampleValueCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('MinSampleValue count 1 must match SamplesPerPixel 3');

        $ifd = new Ifd([
            ExifTag::SAMPLES_PER_PIXEL => new IfdEntry(ExifTag::SAMPLES_PER_PIXEL, TiffConst::TYPE_SHORT, 1, 3),
            TiffTag::MIN_SAMPLE_VALUE  => new IfdEntry(TiffTag::MIN_SAMPLE_VALUE, TiffConst::TYPE_SHORT, 1, 0),
        ]);

        $this->validator->validateMinMaxSampleValueTags($ifd);
    }

    #[Test]
    public function rejectsMinGreaterThanMax(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('MinSampleValue component 0 must be <= MaxSampleValue component 0');

        $ifd = new Ifd([
            TiffTag::MIN_SAMPLE_VALUE => new IfdEntry(TiffTag::MIN_SAMPLE_VALUE, TiffConst::TYPE_SHORT, 1, 200),
            TiffTag::MAX_SAMPLE_VALUE => new IfdEntry(TiffTag::MAX_SAMPLE_VALUE, TiffConst::TYPE_SHORT, 1, 100),
            ExifTag::BITS_PER_SAMPLE  => new IfdEntry(ExifTag::BITS_PER_SAMPLE, TiffConst::TYPE_SHORT, 1, 8),
        ]);

        $this->validator->validateMinMaxSampleValueTags($ifd);
    }

    // --- ExtraSamples ---

    #[Test]
    public function acceptsValidExtraSamples(): void
    {
        $ifd = new Ifd([
            TiffTag::EXTRA_SAMPLES => new IfdEntry(TiffTag::EXTRA_SAMPLES, TiffConst::TYPE_SHORT, 1, 1),
        ]);

        $this->validator->validateExtraSamplesTag($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsExtraSamplesOutOfDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ExtraSamples value 5 is outside the valid domain');

        $ifd = new Ifd([
            TiffTag::EXTRA_SAMPLES => new IfdEntry(TiffTag::EXTRA_SAMPLES, TiffConst::TYPE_SHORT, 1, 5),
        ]);

        $this->validator->validateExtraSamplesTag($ifd);
    }

    // --- SampleDomainTags ---

    #[Test]
    public function acceptsValidSampleFormat(): void
    {
        $ifd = new Ifd([
            TiffTag::SAMPLE_FORMAT => new IfdEntry(TiffTag::SAMPLE_FORMAT, TiffConst::TYPE_SHORT, 1, 1),
        ]);

        $this->validator->validateSampleDomainTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsSampleFormatWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('SampleFormat must use SHORT type');

        $ifd = new Ifd([
            TiffTag::SAMPLE_FORMAT => new IfdEntry(TiffTag::SAMPLE_FORMAT, TiffConst::TYPE_LONG, 1, 1),
        ]);

        $this->validator->validateSampleDomainTags($ifd);
    }

    #[Test]
    public function rejectsSampleFormatCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('SampleFormat count 1 must match SamplesPerPixel 3');

        $ifd = new Ifd([
            ExifTag::SAMPLES_PER_PIXEL => new IfdEntry(ExifTag::SAMPLES_PER_PIXEL, TiffConst::TYPE_SHORT, 1, 3),
            TiffTag::SAMPLE_FORMAT     => new IfdEntry(TiffTag::SAMPLE_FORMAT, TiffConst::TYPE_SHORT, 1, 1),
        ]);

        $this->validator->validateSampleDomainTags($ifd);
    }

    // --- GrayResponseTags ---

    #[Test]
    public function acceptsGrayResponseUnitWithGrayscalePhotometric(): void
    {
        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 0),
            TiffTag::GRAY_RESPONSE_UNIT         => new IfdEntry(TiffTag::GRAY_RESPONSE_UNIT, TiffConst::TYPE_SHORT, 1, 3),
        ]);

        $this->validator->validateGrayResponseTags($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsGrayResponseTagsWithNonGrayscalePhotometric(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('GrayResponse tags are only valid for grayscale PhotometricInterpretation');

        $ifd = new Ifd([
            ExifTag::PHOTOMETRIC_INTERPRETATION => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, TiffConst::TYPE_SHORT, 1, 2),
            TiffTag::GRAY_RESPONSE_UNIT         => new IfdEntry(TiffTag::GRAY_RESPONSE_UNIT, TiffConst::TYPE_SHORT, 1, 3),
        ]);

        $this->validator->validateGrayResponseTags($ifd);
    }

    // --- HalftoneHints ---

    #[Test]
    public function acceptsValidHalftoneHints(): void
    {
        $ifd = new Ifd([
            TiffTag::HALFTONE_HINTS  => new IfdEntry(TiffTag::HALFTONE_HINTS, TiffConst::TYPE_SHORT, 2, new ExifNumericList([10, 200])),
            ExifTag::BITS_PER_SAMPLE => new IfdEntry(ExifTag::BITS_PER_SAMPLE, TiffConst::TYPE_SHORT, 1, 8),
        ]);

        $this->validator->validateHalftoneHintsTag($ifd);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsHalftoneHintsExceedingBitRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('HalftoneHints component 1 value 300 exceeds max 255');

        $ifd = new Ifd([
            TiffTag::HALFTONE_HINTS  => new IfdEntry(TiffTag::HALFTONE_HINTS, TiffConst::TYPE_SHORT, 2, new ExifNumericList([10, 300])),
            ExifTag::BITS_PER_SAMPLE => new IfdEntry(ExifTag::BITS_PER_SAMPLE, TiffConst::TYPE_SHORT, 1, 8),
        ]);

        $this->validator->validateHalftoneHintsTag($ifd);
    }
}
