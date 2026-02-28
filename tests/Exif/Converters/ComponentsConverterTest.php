<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validates component configuration parsing and YCbCr subsampling conversion.
 * It verifies EXIF 3.0 §4.6.5.1.3 and §4.6.5.1.12 conformance for valid and invalid inputs.
 *
 * @internal
 */
#[CoversClass(ComponentsConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(ExifNumericList::class)]
final class ComponentsConverterTest extends TestCase
{
    private ComponentsConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new ComponentsConverter(new NumericConverter());
    }

    /**
     * Parses a standard YCbCr configuration [1, 2, 3, 0] into integer codes.
     */
    #[Test]
    public function configurationParsesValidYCbCrComponents(): void
    {
        $result = $this->converter->configuration(new ExifNumericList([1, 2, 3, 0]));

        self::assertSame([1, 2, 3, 0], $result);
    }

    /**
     * Rejects configuration with component codes outside the 0-6 range.
     */
    #[Test]
    public function configurationRejectsCodeOutsideRange(): void
    {
        self::assertNull($this->converter->configuration(new ExifNumericList([1, 2, 7, 0])));
    }

    /**
     * Returns null for null input.
     */
    #[Test]
    public function configurationReturnsNullForNull(): void
    {
        self::assertNull($this->converter->configuration(null));
    }

    /**
     * Rejects negative component codes.
     */
    #[Test]
    public function configurationRejectsNegativeCode(): void
    {
        self::assertNull($this->converter->configuration(new ExifNumericList([-1, 2, 3, 0])));
    }

    /**
     * Converts standard YCbCr components to human-readable labels.
     */
    #[Test]
    public function configurationLabelsReturnsExpectedLabels(): void
    {
        $result = $this->converter->configurationLabels(new ExifNumericList([1, 2, 3, 0]));

        self::assertSame(['Y', 'Cb', 'Cr', '-'], $result);
    }

    /**
     * Converts RGB components to human-readable labels.
     */
    #[Test]
    public function configurationLabelsReturnsRgbLabels(): void
    {
        $result = $this->converter->configurationLabels(new ExifNumericList([4, 5, 6]));

        self::assertSame(['R', 'G', 'B'], $result);
    }

    /**
     * Returns null for labels when component code is out of range.
     */
    #[Test]
    public function configurationLabelsReturnsNullForInvalidCode(): void
    {
        self::assertNull($this->converter->configurationLabels(new ExifNumericList([1, 2, 8, 0])));
    }

    /**
     * Returns null for labels when input is null.
     */
    #[Test]
    public function configurationLabelsReturnsNullForNull(): void
    {
        self::assertNull($this->converter->configurationLabels(null));
    }

    /**
     * Returns a space-joined string description from component codes.
     */
    #[Test]
    public function configurationDescriptionReturnsJoinedLabels(): void
    {
        self::assertSame(
            'Y Cb Cr -',
            $this->converter->configurationDescription(new ExifNumericList([1, 2, 3, 0])),
        );
    }

    /**
     * Returns null description for invalid component codes.
     */
    #[Test]
    public function configurationDescriptionReturnsNullForInvalid(): void
    {
        self::assertNull($this->converter->configurationDescription(new ExifNumericList([1, 2, 99, 0])));
    }

    /**
     * Parses a valid "2,1" YCbCr subsampling string into a pair.
     */
    #[Test]
    public function ycbcrSubSamplingParsesValidPair(): void
    {
        self::assertSame([2, 1], $this->converter->ycbcrSubSamplingToPair('2,1'));
    }

    /**
     * Parses "2 2" (space-separated) as a valid YCbCr4:2:0 pair.
     */
    #[Test]
    public function ycbcrSubSamplingParsesSpaceSeparated(): void
    {
        self::assertSame([2, 2], $this->converter->ycbcrSubSamplingToPair('2 2'));
    }

    /**
     * Rejects illegal subsampling values like [1, 1].
     */
    #[Test]
    public function ycbcrSubSamplingRejectsIllegalValues(): void
    {
        self::assertNull($this->converter->ycbcrSubSamplingToPair('1,1'));
    }

    /**
     * Rejects null input for subsampling.
     */
    #[Test]
    public function ycbcrSubSamplingReturnsNullForNull(): void
    {
        self::assertNull($this->converter->ycbcrSubSamplingToPair(null));
    }

    /**
     * Rejects empty string for subsampling.
     */
    #[Test]
    public function ycbcrSubSamplingReturnsNullForEmptyString(): void
    {
        self::assertNull($this->converter->ycbcrSubSamplingToPair(''));
    }

    /**
     * Rejects a single-value subsampling string.
     */
    #[Test]
    public function ycbcrSubSamplingRejectsOneValue(): void
    {
        self::assertNull($this->converter->ycbcrSubSamplingToPair('2'));
    }

    /**
     * Rejects non-numeric subsampling values.
     */
    #[Test]
    public function ycbcrSubSamplingRejectsNonNumeric(): void
    {
        self::assertNull($this->converter->ycbcrSubSamplingToPair('A,B'));
    }
}
