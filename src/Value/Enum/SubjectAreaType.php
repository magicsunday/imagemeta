<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

/**
 * Subject area types as defined by EXIF 3.0 §4.6.6.7.22.
 *
 * EXIF 3.0 §4.6.6.7.22: SubjectArea tag 0x9214 indicates the location and area of the main
 * subject in the overall scene. The area is expressed as:
 * - Point (2 values): center coordinates
 * - Circle (3 values): center coordinates and diameter
 * - Rectangle (4 values): center coordinates, width, and height
 */
enum SubjectAreaType: string
{
    /**
     * Subject area defined as a center point (x, y).
     */
    case Point = 'point';

    /**
     * Subject area defined as a circle (center x, y, diameter).
     */
    case Circle = 'circle';

    /**
     * Subject area defined as a rectangle (center x, y, width, height).
     */
    case Rectangle = 'rectangle';
}
