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
 * Creates JPEG parser instances for a specific input stream.
 */
interface JpegParserFactoryInterface
{
    /**
     * Creates a JPEG parser bound to the provided stream.
     */
    public function create(Stream $stream): JpegParserInterface;
}
