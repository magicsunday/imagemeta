<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\DngProfileGainTableTag;

/**
 * Encapsulates the optional DNG profile gain table map payload.
 */
final readonly class ColorProfileGainMap
{
    /**
     * @param DngProfileGainTableTag $tag    Enumerated gain table tag represented by this payload.
     * @param list<float>            $values Normalised per-pixel gain factors stored in scan-line order.
     */
    public function __construct(
        public DngProfileGainTableTag $tag,
        public array $values,
    ) {
    }
}
