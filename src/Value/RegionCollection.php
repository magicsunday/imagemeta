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
 * Collection of annotated regions detected within the asset.
 */
final readonly class RegionCollection
{
    /**
     * Creates a region collection value object.
     *
     * @param list<Region> $items List of annotated regions.
     */
    public function __construct(public array $items)
    {
    }
}
