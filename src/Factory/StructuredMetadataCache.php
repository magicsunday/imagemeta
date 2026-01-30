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

/**
 * Lazily assembles and caches structured metadata derived from the aggregate.
 */
final class StructuredMetadataCache
{
    private ?StructuredMetadataFactory $structured = null;

    /**
     * @param ExifAssembler $assembler Assembler used to build structured metadata.
     */
    public function __construct(
        private readonly ExifAssembler $assembler = new ExifAssembler(),
    ) {
    }

    /**
     * Returns a cached structured metadata instance, assembling on first access.
     *
     * @param Metadata $metadata Source metadata aggregate.
     *
     * @return StructuredMetadataFactory Structured metadata container.
     */
    public function resolve(Metadata $metadata): StructuredMetadataFactory
    {
        if (!$this->structured instanceof StructuredMetadataFactory) {
            $this->structured = $this->assembler->assemble($metadata);
        }

        return $this->structured;
    }
}
