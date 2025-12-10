<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\SubjectAreaType;

use function array_any;
use function array_map;
use function array_values;
use function count;
use function is_numeric;

/**
 * Represents the subject area location and dimensions.
 *
 * EXIF 3.0 §4.6.6.7.22 (SubjectArea) defines tag 0x9214 as an unsigned SHORT
 * vector that expresses the location and area of the main subject prior to any
 * rotation processing. It can be:
 * - A point (2 values: X coordinate, Y coordinate)
 * - A circle (3 values: center X coordinate, center Y coordinate, diameter)
 * - A rectangle (4 values: center X coordinate, center Y coordinate, width, height)
 */
final readonly class SubjectArea
{
    /**
     * Creates a subject area value object.
     *
     * @param SubjectAreaType $type     Type of subject area (point, circle, or rectangle).
     * @param int             $centerX  X coordinate of the center point (origin: top-left).
     * @param int             $centerY  Y coordinate of the center point (origin: top-left).
     * @param int|null        $diameter Diameter for circular areas (SHORT, Count = 3).
     * @param int|null        $width    Width for rectangular areas (SHORT, Count = 4).
     * @param int|null        $height   Height for rectangular areas (SHORT, Count = 4).
     */
    public function __construct(
        public SubjectAreaType $type,
        public int $centerX,
        public int $centerY,
        public ?int $diameter = null,
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }

    /**
     * Creates a SubjectArea from component array.
     *
     * EXIF 3.0 §4.6.6.7.22: SubjectArea format depends on component count:
     * - 2 values: center point (x, y)
     * - 3 values: circle (x, y, diameter)
     * - 4 values: rectangle (x, y, width, height)
     *
     * @param array<int, int|float|string>|null $components Array of subject area components.
     *
     * @return self|null SubjectArea value object or null if components are invalid.
     */
    public static function fromComponents(?array $components): ?self
    {
        if ($components === null) {
            return null;
        }

        $values = array_values($components);
        $count  = count($values);

        if (($count < 2) || ($count > 4)) {
            return null;
        }

        if (array_any($values, static fn (mixed $component): bool => !is_numeric($component))) {
            return null;
        }

        /** @var list<int> $values */
        $values = array_map(static fn (int|float|string $component): int => (int) $component, $values);

        if (array_any($values, static fn (int $component): bool => $component < 0)) {
            return null;
        }

        $x = $values[0];
        $y = $values[1];

        return match ($count) {
            2 => new self(SubjectAreaType::Point, $x, $y),
            3 => new self(SubjectAreaType::Circle, $x, $y, diameter: $values[2]),
            4 => new self(SubjectAreaType::Rectangle, $x, $y, width: $values[2], height: $values[3]),
        };
    }
}
