<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jxl;

use MagicSunday\ImageMeta\Core\Stream;

/**
 * Default JPEG XL parser factory creating the built-in parser implementation.
 */
final readonly class JxlParserFactory implements JxlParserFactoryInterface
{
    /**
     * Creates the built-in JPEG XL parser for the supplied stream.
     */
    public function create(Stream $stream): JxlParserInterface
    {
        return new JxlParser($stream);
    }
}
