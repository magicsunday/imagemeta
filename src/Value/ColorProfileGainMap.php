<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Encapsulates the optional DNG profile gain table map payload.
 */
final readonly class ColorProfileGainMap
{
    /**
     * @param list<float> $values Normalised per-pixel gain factors stored in scan-line order.
     */
    public function __construct(public array $values)
    {
    }
}
