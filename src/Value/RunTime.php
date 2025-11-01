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
 * Represents a CoreMedia runtime structure exposed via curated metadata.
 */
final readonly class RunTime
{
    /**
     * @param int|null $epoch     Timeline epoch of the runtime value.
     * @param int|null $timescale Timescale used to interpret the runtime value.
     * @param int|null $value     Raw runtime value expressed in timescale units.
     * @param int|null $flags     Bit mask describing the runtime value state.
     */
    public function __construct(
        public ?int $epoch,
        public ?int $timescale,
        public ?int $value,
        public ?int $flags,
    ) {
    }
}
