<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\ExifFlash;
use MagicSunday\ImageMeta\Exif\Converters\FlashConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\FlashInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validates EXIF flash bitfield conversion into structured FlashInfo values.
 * It verifies EXIF 3.0 §4.6.6.7.21 conformance for various input representations.
 *
 * @internal
 */
#[CoversClass(FlashConverter::class)]
#[UsesClass(ExifFlash::class)]
#[UsesClass(FlashInfo::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(ExifRationalList::class)]
final class FlashConverterTest extends TestCase
{
    private FlashConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new FlashConverter();
    }

    /**
     * Converts an integer flash value into a FlashInfo object.
     */
    #[Test]
    public function fromShortConvertsInteger(): void
    {
        $info = $this->converter->fromShort(1);

        self::assertNotNull($info);
        self::assertTrue($info->fired);
    }

    /**
     * Converts a float flash value into a FlashInfo object.
     */
    #[Test]
    public function fromShortConvertsFloat(): void
    {
        $info = $this->converter->fromShort(1.0);

        self::assertNotNull($info);
        self::assertTrue($info->fired);
    }

    /**
     * Converts a numeric string flash value.
     */
    #[Test]
    public function fromShortConvertsNumericString(): void
    {
        $info = $this->converter->fromShort('0');

        self::assertNotNull($info);
        self::assertFalse($info->fired);
    }

    /**
     * Extracts the first value from an ExifNumericList.
     */
    #[Test]
    public function fromShortExtractsFromNumericList(): void
    {
        $info = $this->converter->fromShort(new ExifNumericList([1]));

        self::assertNotNull($info);
        self::assertTrue($info->fired);
    }

    /**
     * Returns null for an empty ExifNumericList.
     */
    #[Test]
    public function fromShortReturnsNullForEmptyNumericList(): void
    {
        self::assertNull($this->converter->fromShort(new ExifNumericList([])));
    }

    /**
     * Converts an ExifRational to an integer flash value.
     */
    #[Test]
    public function fromShortConvertsRational(): void
    {
        $info = $this->converter->fromShort(new ExifRational(1, 1));

        self::assertNotNull($info);
        self::assertTrue($info->fired);
    }

    /**
     * Returns null for an ExifRational with zero denominator.
     */
    #[Test]
    public function fromShortReturnsNullForZeroDenominatorRational(): void
    {
        self::assertNull($this->converter->fromShort(new ExifRational(1, 0)));
    }

    /**
     * Extracts the first rational from an ExifRationalList.
     */
    #[Test]
    public function fromShortExtractsFromRationalList(): void
    {
        $info = $this->converter->fromShort(
            new ExifRationalList([new ExifRational(1, 1)]),
        );

        self::assertNotNull($info);
        self::assertTrue($info->fired);
    }

    /**
     * Returns null for an empty ExifRationalList.
     */
    #[Test]
    public function fromShortReturnsNullForEmptyRationalList(): void
    {
        self::assertNull($this->converter->fromShort(new ExifRationalList([])));
    }

    /**
     * Returns null when input is null.
     */
    #[Test]
    public function fromShortReturnsNullForNull(): void
    {
        self::assertNull($this->converter->fromShort(null));
    }

    /**
     * Returns null for a non-numeric string.
     */
    #[Test]
    public function fromShortReturnsNullForNonNumericString(): void
    {
        self::assertNull($this->converter->fromShort('invalid'));
    }

    /**
     * Full bitfield decoding from an integer value.
     */
    #[Test]
    public function fromShortDecodesFullBitfield(): void
    {
        // Value 127: bits 0-6 all set
        $info = $this->converter->fromShort(127);

        self::assertNotNull($info);
        self::assertTrue($info->fired);
        self::assertSame(FlashMode::Auto, $info->mode);
        self::assertSame(FlashReturn::ReturnDetected, $info->returnDetection);
        self::assertSame(FlashFunction::Absent, $info->functionPresence);
        self::assertTrue($info->redEyeReduction);
    }
}
