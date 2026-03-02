<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Dji;

/**
 * Value object holding telemetry extracted from a DJI drone video's mdat stream.
 *
 * DJI embeds per-frame Protocol Buffers records in the media data box.
 * Each record may contain the drone model name, GPS coordinates (stored
 * as radians), gimbal orientation, and flight speed components.
 */
final readonly class DjiTelemetry
{
    public function __construct(
        public ?string $model = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $altitude = null,
    ) {
    }
}
