<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;

/**
 * Provides detailed information about the white balance configuration.
 */
final readonly class WhiteBalanceDetails
{
    /**
     * @param WhiteBalance|null $mode   Selected white balance mode.
     * @param int|null          $kelvin Colour temperature in Kelvin.
     * @param float|null        $rgGain Red/green channel gain ratio.
     * @param float|null        $bgGain Blue/green channel gain ratio.
     */
    public function __construct(
        public ?WhiteBalance $mode,
        public ?int $kelvin,
        public ?float $rgGain,
        public ?float $bgGain,
    ) {
    }
}
