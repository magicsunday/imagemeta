<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

use MagicSunday\ImageMeta\Factory\ExifAssembler;
use MagicSunday\ImageMeta\Factory\StructuredMetadata;

/**
 * Lazily assembles and caches structured metadata derived from the aggregate.
 */
final class StructuredMetadataCache
{
    private ?StructuredMetadata $structured = null;

    public function __construct(
        private readonly ExifAssembler $assembler = new ExifAssembler(),
    ) {
    }

    public function resolve(Metadata $metadata): StructuredMetadata
    {
        if (!$this->structured instanceof StructuredMetadata) {
            $this->structured = $this->assembler->assemble($metadata);
        }

        return $this->structured;
    }
}
