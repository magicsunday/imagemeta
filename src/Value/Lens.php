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
 * Represents lens information used when capturing the image.
 */
final readonly class Lens
{
    /**
     * @param string|null $model           Lens model description.
     * @param float|null  $focalLengthMm   Focal length used in millimetres.
     */
    public function __construct(
        public ?string $model,
        public ?float $focalLengthMm,
    ) {
    }
}
