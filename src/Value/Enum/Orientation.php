<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;

/**
 * Enumerates the orientation values defined for the Orientation tag in EXIF
 * 3.0 §4.6.5.1.6 (image data structure).
 */
enum Orientation: int
{
    use EnumFromIntStringNullable;

    case Unknown     = 0;
    case TopLeft     = 1;
    case TopRight    = 2;
    case BottomRight = 3;
    case BottomLeft  = 4;
    case LeftTop     = 5;
    case RightTop    = 6;
    case RightBottom = 7;
    case LeftBottom  = 8;

    /**
     * Returns the rotation description as commonly displayed by ExifTool.
     *
     * EXIF 3.0 §4.6.5.1.6 defines eight orientation states that combine
     * rotation and mirroring transformations required to display the image
     * with the correct orientation.
     */
    public function rotationDescription(): string
    {
        return match ($this) {
            self::Unknown     => 'Unknown',
            self::TopLeft     => 'Horizontal (normal)',
            self::TopRight    => 'Mirror horizontal',
            self::BottomRight => 'Rotate 180',
            self::BottomLeft  => 'Mirror vertical',
            self::LeftTop     => 'Mirror horizontal and rotate 270 CW',
            self::RightTop    => 'Rotate 90 CW',
            self::RightBottom => 'Mirror horizontal and rotate 90 CW',
            self::LeftBottom  => 'Rotate 270 CW',
        };
    }

    /**
     * Returns the clockwise rotation angle in degrees (0, 90, 180, 270).
     *
     * For mirrored orientations, this returns the rotation that would be
     * applied after the horizontal flip.
     */
    public function rotationDegrees(): int
    {
        return match ($this) {
            self::Unknown, self::TopLeft, self::TopRight => 0,
            self::BottomRight, self::BottomLeft => 180,
            self::RightTop, self::RightBottom => 90,
            self::LeftTop, self::LeftBottom => 270,
        };
    }

    /**
     * Returns true if the orientation includes a horizontal mirror flip.
     */
    public function isMirrored(): bool
    {
        return match ($this) {
            self::TopRight, self::BottomLeft, self::LeftTop, self::RightBottom => true,
            default => false,
        };
    }
}
