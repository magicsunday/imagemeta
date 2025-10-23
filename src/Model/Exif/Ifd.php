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
 * Represents an image file directory (IFD) containing EXIF entries.
 */
final readonly class Ifd
{
    /**
     * @param array<int, IfdEntry> $entries       Map of tag identifiers to entries.
     * @param int|null             $nextIfdOffset Optional offset to the next directory.
     */
    public function __construct(
        public readonly array $entries,
        public readonly ?int $nextIfdOffset = null,
    ) {
    }

    /**
     * Returns the entry for the provided tag identifier if it exists.
     *
     * @param int $tag The EXIF tag identifier to look up.
     *
     * @return IfdEntry|null
     */
    public function get(int $tag): ?IfdEntry
    {
        return $this->entries[$tag] ?? null;
    }
}
