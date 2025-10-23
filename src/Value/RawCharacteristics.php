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
 * Captures raw sensor processing characteristics such as CFA pattern.
 */
final readonly class RawCharacteristics
{
    /**
     * @param string|null $cfaPattern                 CFA pattern description.
     * @param int|null    $blackLevel                 Black level applied to the raw data.
     * @param int|null    $whiteLevel                 White level saturation point.
     * @param string|null $colorMatrix                Colour transformation matrix serialized form.
     * @param int|null    $linearizationTableEntries  Number of entries in the linearisation table.
     */
    public function __construct(
        public ?string $cfaPattern,
        public ?int $blackLevel,
        public ?int $whiteLevel,
        public ?string $colorMatrix,
        public ?int $linearizationTableEntries,
    ) {
    }
}
