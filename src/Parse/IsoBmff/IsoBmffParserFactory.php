<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\Stream;

/**
 * Default ISO BMFF parser factory creating the built-in parser implementation.
 */
final class IsoBmffParserFactory implements IsoBmffParserFactoryInterface
{
    /**
     * Creates the built-in ISO BMFF parser for the supplied stream.
     */
    public function create(Stream $stream): IsoBmffParserInterface
    {
        return new IsoBmffParser($stream);
    }
}
