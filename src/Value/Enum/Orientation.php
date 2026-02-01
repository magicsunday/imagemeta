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

    case UNKNOWN      = 0;
    case TOP_LEFT     = 1;
    case TOP_RIGHT    = 2;
    case BOTTOM_RIGHT = 3;
    case BOTTOM_LEFT  = 4;
    case LEFT_TOP     = 5;
    case RIGHT_TOP    = 6;
    case RIGHT_BOTTOM = 7;
    case LEFT_BOTTOM  = 8;

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
            self::UNKNOWN      => 'Unknown',
            self::TOP_LEFT     => 'Horizontal (normal)',
            self::TOP_RIGHT    => 'Mirror horizontal',
            self::BOTTOM_RIGHT => 'Rotate 180',
            self::BOTTOM_LEFT  => 'Mirror vertical',
            self::LEFT_TOP     => 'Mirror horizontal and rotate 270 CW',
            self::RIGHT_TOP    => 'Rotate 90 CW',
            self::RIGHT_BOTTOM => 'Mirror horizontal and rotate 90 CW',
            self::LEFT_BOTTOM  => 'Rotate 270 CW',
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
            self::UNKNOWN, self::TOP_LEFT, self::TOP_RIGHT, self::BOTTOM_LEFT => 0,
            self::BOTTOM_RIGHT, self::LEFT_TOP => 180,
            self::RIGHT_TOP, self::RIGHT_BOTTOM => 90,
            self::LEFT_BOTTOM => 270,
        };
    }

    /**
     * Returns true if the orientation includes a horizontal mirror flip.
     */
    public function isMirrored(): bool
    {
        return match ($this) {
            self::TOP_RIGHT, self::BOTTOM_LEFT, self::LEFT_TOP, self::RIGHT_BOTTOM => true,
            default => false,
        };
    }
}
