<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Represents depth map metadata embedded in XMP packets.
 */
final readonly class DepthMap
{
    /**
     * Creates a depth map metadata value object.
     *
     * @param string|null $data Base64-encoded depth map payload.
     * @param string|null $mime Mime type of the depth map payload.
     * @param float|null  $near Nearest depth value reported by the map.
     * @param float|null  $far  Farthest depth value reported by the map.
     */
    public function __construct(
        public ?string $data,
        public ?string $mime,
        public ?float $near,
        public ?float $far,
    ) {
    }
}
