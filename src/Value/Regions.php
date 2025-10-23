<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Regions\Region;

/**
 * Collection of annotated regions detected within the asset.
 */
final readonly class Regions
{
    /**
     * @param list<Region> $items List of annotated regions.
     */
    public function __construct(public array $items)
    {
    }
}
