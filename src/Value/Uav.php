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
 * Describes metadata reported by unmanned aerial vehicles (drones).
 */
final readonly class Uav
{
    /**
     * @param string|null $manufacturer Drone manufacturer name.
     * @param string|null $model        Drone model name.
     * @param float|null  $flightYaw    Flight yaw angle in degrees.
     * @param float|null  $flightPitch  Flight pitch angle in degrees.
     * @param float|null  $flightRoll   Flight roll angle in degrees.
     * @param float|null  $gimbalYaw    Gimbal yaw angle in degrees.
     * @param float|null  $gimbalPitch  Gimbal pitch angle in degrees.
     * @param float|null  $gimbalRoll   Gimbal roll angle in degrees.
     */
    public function __construct(
        public ?string $manufacturer,
        public ?string $model,
        public ?float $flightYaw,
        public ?float $flightPitch,
        public ?float $flightRoll,
        public ?float $gimbalYaw,
        public ?float $gimbalPitch,
        public ?float $gimbalRoll,
    ) {
    }
}
