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
 * Metadata Working Group Regions (MWG-RS) defines `mwg-rs:Type` in the
 * regions schema (`http://www.metadataworkinggroup.com/schemas/regions/`),
 * where values such as Face/Object classify region semantics.
 */
enum RegionType: string
{
    case Face    = 'Face';
    case Focus   = 'Focus';
    case Object  = 'Object';
    case Unknown = 'Unknown';

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
            'face'  => self::Face,
            'focus' => self::Focus,
            'object', 'pet', 'subject', 'rectangle', 'rect' => self::Object,
            default => self::Unknown,
        };
    }
}
