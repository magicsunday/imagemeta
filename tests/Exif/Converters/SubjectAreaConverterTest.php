<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\SubjectAreaConverter;
use MagicSunday\ImageMeta\Value\SubjectArea;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validates EXIF subject area parsing for point, circle, and rectangle representations.
 * It verifies EXIF 3.0 §4.6.6.7.22 conformance and rejection of invalid component counts.
 *
 * @internal
 */
#[CoversClass(SubjectAreaConverter::class)]
#[UsesClass(SubjectArea::class)]
final class SubjectAreaConverterTest extends TestCase
{
    private SubjectAreaConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new SubjectAreaConverter();
    }

    /**
     * Converts a 2-component subject area (point) to a rect with null dimensions.
     */
    #[Test]
    public function toRectConvertsPointComponents(): void
    {
        $result = $this->converter->toRect([100, 200]);

        self::assertNotNull($result);
        self::assertSame(100, $result['x']);
        self::assertSame(200, $result['y']);
        self::assertNull($result['w']);
        self::assertNull($result['h']);
    }

    /**
     * Converts a 3-component subject area (circle) to a rect with equal width and height.
     */
    #[Test]
    public function toRectConvertsCircleComponents(): void
    {
        $result = $this->converter->toRect([100, 200, 50]);

        self::assertNotNull($result);
        self::assertSame(100, $result['x']);
        self::assertSame(200, $result['y']);
        self::assertSame(50, $result['w']);
        self::assertSame(50, $result['h']);
    }

    /**
     * Converts a 4-component subject area (rectangle) to a rect.
     */
    #[Test]
    public function toRectConvertsRectangleComponents(): void
    {
        $result = $this->converter->toRect([100, 200, 300, 400]);

        self::assertNotNull($result);
        self::assertSame(100, $result['x']);
        self::assertSame(200, $result['y']);
        self::assertSame(300, $result['w']);
        self::assertSame(400, $result['h']);
    }

    /**
     * Returns null for a single-component array (invalid per EXIF spec).
     */
    #[Test]
    public function toRectReturnsNullForSingleComponent(): void
    {
        self::assertNull($this->converter->toRect([100]));
    }

    /**
     * Returns null for a 5-component array (invalid per EXIF spec).
     */
    #[Test]
    public function toRectReturnsNullForFiveComponents(): void
    {
        self::assertNull($this->converter->toRect([1, 2, 3, 4, 5]));
    }

    /**
     * Returns null for empty array.
     */
    #[Test]
    public function toRectReturnsNullForEmptyArray(): void
    {
        self::assertNull($this->converter->toRect([]));
    }

    /**
     * Returns null for negative component values.
     */
    #[Test]
    public function toRectReturnsNullForNegativeValues(): void
    {
        self::assertNull($this->converter->toRect([-1, 200]));
    }

    /**
     * Returns null for non-numeric string values.
     */
    #[Test]
    public function toRectReturnsNullForNonNumericValues(): void
    {
        self::assertNull($this->converter->toRect(['abc', 'def']));
    }

    /**
     * Accepts zero coordinates as valid subject area values.
     */
    #[Test]
    public function toRectAcceptsZeroCoordinates(): void
    {
        $result = $this->converter->toRect([0, 0]);

        self::assertNotNull($result);
        self::assertSame(0, $result['x']);
        self::assertSame(0, $result['y']);
    }

    /**
     * Converts string representations of numbers.
     */
    #[Test]
    public function toRectConvertsStringNumbers(): void
    {
        $result = $this->converter->toRect(['100', '200', '50']);

        self::assertNotNull($result);
        self::assertSame(100, $result['x']);
        self::assertSame(200, $result['y']);
        self::assertSame(50, $result['w']);
        self::assertSame(50, $result['h']);
    }
}
