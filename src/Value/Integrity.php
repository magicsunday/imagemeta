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
 * Represents provenance and integrity information about the asset.
 */
final readonly class Integrity
{
    /**
     * @param string|null $originalFileName Original file name when available.
     * @param string|null $originalDigest   Digest identifying the original asset.
     * @param bool|null   $edited           Indicates whether editing history is present.
     * @param string|null $historyLastSoftware Last software reported in the editing history.
     */
    public function __construct(
        public ?string $originalFileName,
        public ?string $originalDigest,
        public ?bool $edited,
        public ?string $historyLastSoftware,
    ) {
    }
}
