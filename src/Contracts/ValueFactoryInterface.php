<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Contracts;

use MagicSunday\ImageMeta\Model\Metadata;

/**
 * Factory contract for building value-object aggregates from metadata structures.
 */
interface ValueFactoryInterface
{
    /**
     * Creates metadata value objects grouped by category.
     *
     * @param Metadata $metadata Aggregated metadata extracted from the source image.
     *
     * @return array<string, mixed> Associative map of component identifiers to their value objects.
     */
    public function createComponents(Metadata $metadata): array;
}
