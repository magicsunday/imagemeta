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
 * Represents FlashPix extension streams aggregated from APP2 segments.
 */
final readonly class FlashPix
{
    /**
     * Creates a FlashPix extension streams value object.
     *
     * @param array<int, string> $streams Concatenated FlashPix extension streams keyed by stream identifier.
     */
    public function __construct(public array $streams)
    {
    }
}
