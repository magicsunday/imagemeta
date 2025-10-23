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
 * Holds Apple specific metadata coming from QuickTime containers.
 */
final readonly class Apple
{
    /**
     * @param string|null $contentIdentifier Unique content identifier assigned by Apple platforms.
     */
    public function __construct(public ?string $contentIdentifier)
    {
    }
}
