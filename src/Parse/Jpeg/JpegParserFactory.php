<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\Stream;

/**
 * Default JPEG parser factory creating the built-in parser implementation.
 */
final readonly class JpegParserFactory
{
    /**
     * Initialises the factory with an optional JPEG parser configuration.
     */
    public function __construct(private JpegParserConfig $config = new JpegParserConfig())
    {
    }

    /**
     * Creates the built-in JPEG parser for the supplied stream.
     */
    public function create(Stream $stream): JpegParserInterface
    {
        return new JpegParser($stream, $this->config);
    }
}
