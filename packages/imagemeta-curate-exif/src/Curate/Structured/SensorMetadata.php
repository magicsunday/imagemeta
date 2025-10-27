<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Uav;

/**
 * Groups sensor hardware data with focus and motion telemetry.
 */
final readonly class SensorMetadata
{
    public function __construct(
        public Sensor $hardware,
        public Focus $focus,
        public Motion $motion,
        public Uav $uav,
    ) {
    }
}
