<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

/**
 * Represents a single entry within an image file directory (IFD).
 */
final readonly class IfdEntry
{
    /**
     * @param int   $tag   The numeric identifier of the entry.
     * @param int   $type  The TIFF field type code.
     * @param int   $count The number of values stored in the entry.
     * @param mixed $value The raw value or values decoded from the IFD.
     */
    public function __construct(
        public int $tag,
        public int $type,
        public int $count,
        public mixed $value,
    ) {
    }
}
