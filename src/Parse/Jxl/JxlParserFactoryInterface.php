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
 * Creates JPEG XL parser instances for a specific input stream.
 */
interface JxlParserFactoryInterface
{
    /**
     * Creates a JPEG XL parser bound to the provided stream.
     */
    public function create(Stream $stream): JxlParserInterface;
}
