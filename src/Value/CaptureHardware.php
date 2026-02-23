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
 * Groups capture hardware metadata: camera body, lens, sensor, device, and focus.
 */
final readonly class CaptureHardware
{
    /**
     * @param Camera $camera Camera body information.
     * @param Lens   $lens   Lens information.
     * @param Sensor $sensor Sensor characteristics.
     * @param Device $device Device information.
     * @param Focus  $focus  Focus settings.
     */
    public function __construct(
        public Camera $camera,
        public Lens $lens,
        public Sensor $sensor,
        public Device $device,
        public Focus $focus,
    ) {
    }
}
