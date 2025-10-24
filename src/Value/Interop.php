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
 * Represents the EXIF interoperability metadata block.
 */
final readonly class Interop
{
    /**
     * @param string|null $index   Interoperability index identifier such as "R98".
     * @param string|null $version Interoperability version string or hexadecimal representation.
     */
    public function __construct(
        public ?string $index,
        public ?string $version,
    ) {
    }
}
