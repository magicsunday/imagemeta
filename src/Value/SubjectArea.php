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

use function count;

/**
 * Represents the subject area location and dimensions.
 *
 * EXIF 3.0 §4.6.6: The SubjectArea tag indicates the location and area of the main subject
 * in the overall scene. It can be:
 * - A point (2 values: x, y)
 * - A circle (3 values: x, y, diameter)
 * - A rectangle (4 values: x, y, width, height)
 */
final readonly class SubjectArea
{
    /**
     * Creates a subject area value object.
     *
     * @param SubjectAreaType $type     Type of subject area (point, circle, or rectangle).
     * @param int             $centerX  X coordinate of the center point.
     * @param int             $centerY  Y coordinate of the center point.
     * @param int|null        $diameter Diameter for circular areas.
     * @param int|null        $width    Width for rectangular areas.
     * @param int|null        $height   Height for rectangular areas.
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
     * EXIF 3.0 §4.6.6: SubjectArea format depends on component count:
     * - 2 values: center point (x, y)
     * - 3 values: circle (x, y, diameter)
     * - 4 values: rectangle (x, y, width, height)
     *
     * @param list<int>|null $components Array of subject area components.
     *
     * @return self|null SubjectArea value object or null if components are invalid.
     */
    public static function fromComponents(?array $components): ?self
    {
        if ($components === null || count($components) < 2 || count($components) > 4) {
            return null;
        }

        $x = $components[0];
        $y = $components[1];

        return match (count($components)) {
            2       => new self(SubjectAreaType::Point, $x, $y),
            3       => new self(SubjectAreaType::Circle, $x, $y, diameter: $components[2]),
            4       => new self(SubjectAreaType::Rectangle, $x, $y, width: $components[2], height: $components[3]),
            default => null,
        };
    }
}
