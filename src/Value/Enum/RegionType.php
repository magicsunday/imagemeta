<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use function strtolower;
use function trim;

/**
 * Describes the semantic classification of an annotated region.
 *
 * XMP Metadata Platform (XMP) Specification Part 2: Standard Schemas §2.4
 * defines the Regions schema (mwg-rs) used for face detection, focus areas,
 * and object recognition metadata in image files.
 */
enum RegionType: string
{
    case FACE    = 'Face';
    case FOCUS   = 'Focus';
    case OBJECT  = 'Object';
    case UNKNOWN = 'Unknown';

    /**
     * Attempts to create a RegionType enum from a free-form label.
     */
    public static function fromLabel(?string $label): ?self
    {
        if ($label === null) {
            return null;
        }

        $normalized = strtolower(trim($label));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'face'  => self::FACE,
            'focus' => self::FOCUS,
            'object', 'pet', 'subject', 'rectangle', 'rect' => self::OBJECT,
            default => self::UNKNOWN,
        };
    }
}
