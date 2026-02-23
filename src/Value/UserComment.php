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
 * Stores a user comment with its declared encoding.
 */
final readonly class UserComment
{
    /**
     * @param string|null $value    Arbitrary user comment stored by the device.
     * @param string|null $encoding Declared encoding for the user comment payload.
     */
    public function __construct(
        public ?string $value = null,
        public ?string $encoding = null,
    ) {
    }
}
