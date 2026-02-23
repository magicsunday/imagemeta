<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\GpsDirectionRef;
use MagicSunday\ImageMeta\Value\Enum\GpsSpeedRef;

/**
 * Describes GPS movement data including speed, track, and image direction.
 */
final readonly class GpsMovement
{
    /**
     * @param GpsSpeedRef|null     $speedRef          Speed reference unit (K/M/N).
     * @param float|null           $speedMs           Ground speed in metres per second.
     * @param GpsSpeedRef|null     $speedOriginalRef  Original speed reference unit.
     * @param float|null           $speedOriginal     Raw ground speed in the original unit.
     * @param GpsDirectionRef|null $trackRef          Course over ground reference (T/M).
     * @param float|null           $track             Course over ground in degrees.
     * @param GpsDirectionRef|null $imageDirectionRef Image direction reference (T/M).
     * @param float|null           $imageDirection    Image direction in degrees.
     */
    public function __construct(
        public ?GpsSpeedRef $speedRef = null,
        public ?float $speedMs = null,
        public ?GpsSpeedRef $speedOriginalRef = null,
        public ?float $speedOriginal = null,
        public ?GpsDirectionRef $trackRef = null,
        public ?float $track = null,
        public ?GpsDirectionRef $imageDirectionRef = null,
        public ?float $imageDirection = null,
    ) {
    }
}
