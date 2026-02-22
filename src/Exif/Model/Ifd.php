<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Model;

use function is_float;
use function is_int;
use function is_string;

/**
 * Represents an image file directory (IFD) containing EXIF entries.
 *
 * EXIF 3.0 §4.5.2 defines the structure of IFDs embedded within EXIF payloads
 * and their linkage via the optional next-IFD pointer.
 */
final readonly class Ifd
{
    /**
     * @param array<int, IfdEntry> $entries       Map of tag identifiers to entries.
     * @param int|null             $nextIfdOffset Optional offset to the next directory.
     */
    public function __construct(
        public array $entries,
        public ?int $nextIfdOffset = null,
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

    /**
     * Indicates whether an entry for the provided tag identifier exists.
     *
     * @param int $tag The EXIF tag identifier to check.
     */
    public function has(int $tag): bool
    {
        return isset($this->entries[$tag]);
    }

    /**
     * Returns the string value for the provided tag, or null if the tag is absent or non-string.
     *
     * @param int $tag The EXIF tag identifier to look up.
     */
    public function getString(int $tag): ?string
    {
        $value = $this->get($tag)?->value;

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the integer value for the provided tag, or null if the tag is absent or non-integer.
     *
     * @param int $tag The EXIF tag identifier to look up.
     */
    public function getInt(int $tag): ?int
    {
        $value = $this->get($tag)?->value;

        return is_int($value) ? $value : null;
    }

    /**
     * Returns the float value for the provided tag, or null if the tag is absent or non-float.
     *
     * @param int $tag The EXIF tag identifier to look up.
     */
    public function getFloat(int $tag): ?float
    {
        $value = $this->get($tag)?->value;

        return is_float($value) ? $value : null;
    }
}
