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
 * Creates ISO BMFF parser instances for a specific input stream.
 */
interface IsoBmffParserFactoryInterface
{
    /**
     * Creates an ISO BMFF parser bound to the provided stream.
     */
    public function create(Stream $stream): IsoBmffParserInterface;
}
