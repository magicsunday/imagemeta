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
use PHPUnit\Framework\TestCase;

/**
 * Tests for SubjectArea value object.
 *
 * EXIF 3.0 §4.6.6 defines SubjectArea tag 0x9214 formats:
 * - 2 values: center point (x, y)
 * - 3 values: circle (center x, y, diameter)
 * - 4 values: rectangle (center x, y, width, height)
 */
#[CoversClass(SubjectArea::class)]
final class SubjectAreaTest extends TestCase
{
    public function testCreatePoint(): void
    {
        $area = SubjectArea::fromComponents([100, 200]);

        self::assertSame(SubjectAreaType::Point, $area->type);
        self::assertSame(100, $area->centerX);
        self::assertSame(200, $area->centerY);
        self::assertNull($area->diameter);
        self::assertNull($area->width);
        self::assertNull($area->height);
    }

    public function testCreateCircle(): void
    {
        $area = SubjectArea::fromComponents([100, 200, 50]);

        self::assertSame(SubjectAreaType::Circle, $area->type);
        self::assertSame(100, $area->centerX);
        self::assertSame(200, $area->centerY);
        self::assertSame(50, $area->diameter);
        self::assertNull($area->width);
        self::assertNull($area->height);
    }

    public function testCreateRectangle(): void
    {
        $area = SubjectArea::fromComponents([100, 200, 80, 120]);

        self::assertSame(SubjectAreaType::Rectangle, $area->type);
        self::assertSame(100, $area->centerX);
        self::assertSame(200, $area->centerY);
        self::assertNull($area->diameter);
        self::assertSame(80, $area->width);
        self::assertSame(120, $area->height);
    }

    public function testInvalidComponentCountReturnsNull(): void
    {
        self::assertNull(SubjectArea::fromComponents([]));
        self::assertNull(SubjectArea::fromComponents([100]));
        self::assertNull(SubjectArea::fromComponents([100, 200, 50, 60, 70]));
    }

    public function testNullInputReturnsNull(): void
    {
        self::assertNull(SubjectArea::fromComponents(null));
    }
}
