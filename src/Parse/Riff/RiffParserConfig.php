<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Riff;

use MagicSunday\ImageMeta\Core\ParseError;

/**
 * Configuration limits for the RIFF container parser.
 */
final readonly class RiffParserConfig
{
    /**
     * @param int $maxChunkCount          Maximum number of chunks to scan before stopping.
     * @param int $maxMetadataPayloadSize Maximum allowed size for a single metadata payload in bytes.
     * @param int $maxListDepth           Maximum nesting depth for LIST chunks.
     */
    public function __construct(
        public int $maxChunkCount = 100_000,
        public int $maxMetadataPayloadSize = 16 * 1024 * 1024,
        public int $maxListDepth = 50,
    ) {
        if ($this->maxChunkCount <= 0) {
            throw new ParseError('maxChunkCount must be positive', 2139);
        }

        if ($this->maxMetadataPayloadSize <= 0) {
            throw new ParseError('maxMetadataPayloadSize must be positive', 2140);
        }

        if ($this->maxListDepth <= 0) {
            throw new ParseError('maxListDepth must be positive', 2141);
        }
    }
}
