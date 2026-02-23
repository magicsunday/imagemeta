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
 * Groups location and temporal metadata: GPS, temporal timestamps, and capture context.
 */
final readonly class LocationTime
{
    /**
     * @param Gps      $gps      GPS position and navigation metadata.
     * @param Temporal $temporal Capture and modification timestamps.
     * @param Capture  $capture  Capture context metadata.
     */
    public function __construct(
        public Gps $gps,
        public Temporal $temporal,
        public Capture $capture,
    ) {
    }
}
