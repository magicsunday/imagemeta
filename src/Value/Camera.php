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
 * Captures camera specific information.
 */
final readonly class Camera
{
    /**
     * @param string|null $make         Camera manufacturer.
     * @param string|null $model        Camera model name.
     * @param string|null $serialNumber Serial number reported by metadata.
     * @param string|null $software     Software or processing application.
     */
    public function __construct(
        public ?string $make,
        public ?string $model,
        public ?string $serialNumber = null,
        public ?string $software = null,
    ) {
    }
}
