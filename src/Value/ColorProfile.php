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
 * Provides ICC colour profile information.
 */
final readonly class ColorProfile
{
    /**
     * @param string|null $profileName     Human readable profile description.
     * @param string|null $profileVersion  Profile version string.
     * @param string|null $pcs             Profile connection space.
     * @param string|null $renderingIntent Rendering intent description.
     * @param float|null  $gamma           Scene gamma value when provided by EXIF.
     */
    public function __construct(
        public ?string $profileName,
        public ?string $profileVersion,
        public ?string $pcs,
        public ?string $renderingIntent,
        public ?float $gamma,
    ) {
    }
}
