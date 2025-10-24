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
 * Provides device specific metadata extracted from container level sources.
 */
final readonly class Device
{
    /**
     * @param string|null $software Software version or build identifier.
     */
    public function __construct(
        public ?string $software,
    ) {
    }
}
