<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

/**
 * Parsed FullBox header fields (ISO/IEC 14496-12 §4.2).
 *
 * A FullBox extends a Box with an 8-bit version and 24-bit flags field,
 * occupying a total of 4 bytes immediately after the standard box header.
 */
final readonly class FullBoxHeader
{
    /**
     * @param int $version 8-bit version number.
     * @param int $flags   24-bit flags field.
     */
    public function __construct(
        public int $version,
        public int $flags,
    ) {
    }
}
