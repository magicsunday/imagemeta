<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

/**
 * HDR capture metadata extracted from Apple maker notes.
 */
final readonly class AppleHdr
{
    /**
     * @param float|null       $headroom  HDR headroom value reported by the device.
     * @param list<float>|null $gain      HDR gain values per colour channel.
     * @param string|null      $imageType HDR image classification (e.g. "HDR").
     */
    public function __construct(
        public ?float $headroom,
        public ?array $gain,
        public ?string $imageType,
    ) {
    }

    /**
     * Creates an instance when at least one field is non-null, or returns null.
     *
     * @param list<float>|null $gain HDR gain values per colour channel.
     */
    public static function createIfPresent(
        ?float $headroom,
        ?array $gain,
        ?string $imageType,
    ): ?self {
        if ($headroom === null && $gain === null && $imageType === null) {
            return null;
        }

        return new self($headroom, $gain, $imageType);
    }
}
