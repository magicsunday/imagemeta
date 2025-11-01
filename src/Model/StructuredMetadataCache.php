<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

use MagicSunday\ImageMeta\Curate\ExifAssembler;
use MagicSunday\ImageMeta\Curate\StructuredMetadata;
use MagicSunday\ImageMeta\Extensions;

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
            $structured = $this->assembler->assemble($metadata);

            foreach (Extensions::registry()->enrichers() as $enricher) {
                $structured = $enricher->enrich($metadata, $structured);
            }

            $this->structured = $structured;
        }

        return $this->structured;
    }
}
