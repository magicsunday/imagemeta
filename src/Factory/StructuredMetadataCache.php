<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Factory;

use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\StructuredMetadata;

/**
 * Lazily assembles and caches structured metadata derived from the aggregate.
 */
final class StructuredMetadataCache
{
    private ?StructuredMetadata $structured = null;

    /**
     * @param StructuredMetadataBuilder $builder Builder used to build structured metadata.
     */
    public function __construct(
        private readonly StructuredMetadataBuilder $builder = new StructuredMetadataBuilder(),
    ) {
    }

    /**
     * Returns a cached structured metadata instance, assembling on first access.
     *
     * @param Metadata $metadata Source metadata aggregate.
     *
     * @return StructuredMetadata Structured metadata container.
     */
    public function resolve(Metadata $metadata): StructuredMetadata
    {
        if (!$this->structured instanceof StructuredMetadata) {
            $this->structured = $this->builder->assemble($metadata);
        }

        return $this->structured;
    }
}
