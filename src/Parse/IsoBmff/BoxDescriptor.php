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
    /**
     * Initialises the immutable box descriptor with layout and payload information.
     *
     * @param string       $type          Four-character box type.
     * @param int          $size          Total box size including header.
     * @param int          $offset        Absolute offset of the box within the stream.
     * @param int          $contentOffset Offset to the box payload relative to the stream.
     * @param int          $contentSize   Size of the box payload in bytes.
     * @param StreamWindow $window        Stream window exposing the box payload.
     * @param string|null  $userType      UUID for user boxes when applicable.
     */
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
