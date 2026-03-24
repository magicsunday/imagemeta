<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Riff;

use MagicSunday\ImageMeta\Core\StreamWindow;

/**
 * Describes a single RIFF chunk after header parsing.
 */
final readonly class RiffChunkDescriptor
{
    /**
     * @param string       $type     Four-character code identifying the chunk.
     * @param int          $dataSize Declared payload size in bytes (excludes header and pad).
     * @param StreamWindow $window   Bounded view over the chunk payload.
     * @param string|null  $listType List sub-type when $type is 'LIST', null otherwise.
     */
    public function __construct(
        public string $type,
        public int $dataSize,
        public StreamWindow $window,
        public ?string $listType = null,
    ) {
    }
}
