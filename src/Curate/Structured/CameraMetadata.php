<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\Value\Camera as CameraValue;
use MagicSunday\ImageMeta\Value\Device;

/**
 * Groups camera and host device information into a unified view.
 */
final readonly class CameraMetadata
{
    public function __construct(
        public CameraValue $camera,
        public Device $device,
    ) {
    }

    public function camera(): CameraValue
    {
        return $this->camera;
    }

    public function device(): Device
    {
        return $this->device;
    }
}
