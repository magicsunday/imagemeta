<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;

/**
 * Defines the contract for extracting metadata from ISO BMFF streams.
 */
interface IsoBmffParserInterface
{
    /**
     * @throws ParseError
     * @throws BoundsError
     */
    public function extract(): IsoBmffParseResult;
}
