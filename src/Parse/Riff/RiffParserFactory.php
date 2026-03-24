<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Riff;

use MagicSunday\ImageMeta\Core\Stream;

/**
 * Factory creating RIFF parser instances from streams.
 */
final readonly class RiffParserFactory
{
    public function __construct(
        private RiffParserConfig $config = new RiffParserConfig(),
    ) {
    }

    /**
     * Creates a RIFF parser for the given stream.
     */
    public function create(Stream $stream): RiffParserInterface
    {
        return new RiffParser($stream, $this->config);
    }
}
