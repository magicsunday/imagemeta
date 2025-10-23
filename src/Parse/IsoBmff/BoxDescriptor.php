<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\StreamWindow;

/**
 * Immutable descriptor for an ISO BMFF box including its typed payload window.
 */
final readonly class BoxDescriptor
{
    public function __construct(
        public string $type,
        public int $size,
        public int $offset,
        public int $contentOffset,
        public int $contentSize,
        public StreamWindow $window,
        public ?string $userType,
    ) {
    }
}
