<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Jpeg;

/**
 * Represents a parsed JFIF APP0 segment.
 *
 * JFIF 1.02 (2009) §3 defines the layout immediately following the "JFIF\0" identifier.
 */
final readonly class JfifSegment
{
    /**
     * @param int $versionMajor Major version byte (e.g. 1).
     * @param int $versionMinor Minor version byte (e.g. 2 → "1.02").
     * @param int $densityUnits 0 = no units (aspect ratio), 1 = dots/inch, 2 = dots/cm.
     * @param int $xDensity     Horizontal pixel density.
     * @param int $yDensity     Vertical pixel density.
     * @param int $xThumbnail   Horizontal thumbnail pixel count (0 = no thumbnail).
     * @param int $yThumbnail   Vertical thumbnail pixel count (0 = no thumbnail).
     */
    public function __construct(
        public int $versionMajor,
        public int $versionMinor,
        public int $densityUnits,
        public int $xDensity,
        public int $yDensity,
        public int $xThumbnail,
        public int $yThumbnail,
    ) {
    }
}
