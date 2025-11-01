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
 * Represents a DNG profile tone curve defined by normalised input/output points.
 */
final readonly class ColorProfileToneCurve
{
    /**
     * @param list<array{0: float, 1: float}> $points Normalised tone curve points (input, output).
     */
    public function __construct(public array $points)
    {
    }
}
