<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Enum\SubjectAreaType;
use MagicSunday\ImageMeta\Value\SubjectArea;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises SubjectArea parsing from the EXIF SubjectArea component formats.
 * It verifies point, circle, and rectangle inputs map to the correct type and fields.
 * The suite checks invalid component counts return null instead of partial data.
 * This keeps subject area metadata consistent with the EXIF-defined structures.
 */
#[CoversClass(SubjectArea::class)]
final class SubjectAreaTest extends TestCase
{
    /**
     * Builds subject area points from two-component input.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function createPoint(): void
    {
        $area = SubjectArea::fromComponents([100, 200]);

        self::assertNotNull($area);

        self::assertSame(SubjectAreaType::Point, $area->type);
        self::assertSame(100, $area->centerX);
        self::assertSame(200, $area->centerY);
        self::assertNull($area->diameter);
        self::assertNull($area->width);
        self::assertNull($area->height);
    }

    /**
     * Builds subject area circles from three-component input.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function createCircle(): void
    {
        $area = SubjectArea::fromComponents([100, 200, 50]);

        self::assertNotNull($area);

        self::assertSame(SubjectAreaType::Circle, $area->type);
        self::assertSame(100, $area->centerX);
        self::assertSame(200, $area->centerY);
        self::assertSame(50, $area->diameter);
        self::assertNull($area->width);
        self::assertNull($area->height);
    }

    /**
     * Builds subject area rectangles from four-component input.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function createRectangle(): void
    {
        $area = SubjectArea::fromComponents([100, 200, 80, 120]);

        self::assertNotNull($area);

        self::assertSame(SubjectAreaType::Rectangle, $area->type);
        self::assertSame(100, $area->centerX);
        self::assertSame(200, $area->centerY);
        self::assertNull($area->diameter);
        self::assertSame(80, $area->width);
        self::assertSame(120, $area->height);
    }

    /**
     * Returns null for invalid subject area component counts.
     * It verifies the error path and guardrail handling.
     */
    #[Test]
    public function invalidComponentCountReturnsNull(): void
    {
        self::assertNull(SubjectArea::fromComponents([]));
        self::assertNull(SubjectArea::fromComponents([100]));
        self::assertNull(SubjectArea::fromComponents([100, 200, 50, 60, 70]));
    }

    /**
     * Returns null when subject area input is missing.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function nullInputReturnsNull(): void
    {
        self::assertNull(SubjectArea::fromComponents(null));
    }

    /**
     * Rejects negative or non-numeric subject area components.
     * It verifies the error path and guardrail handling.
     */
    #[Test]
    public function rejectsNegativeOrNonNumericValues(): void
    {
        self::assertNull(SubjectArea::fromComponents([-1, 10]));
        self::assertNull(SubjectArea::fromComponents([10, 20, -5]));
        self::assertNull(SubjectArea::fromComponents(['a', 'b']));
    }
}
