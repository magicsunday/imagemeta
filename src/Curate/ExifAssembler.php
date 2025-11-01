<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate;

use MagicSunday\ImageMeta\Curate\Exif\ValueFactory;
use MagicSunday\ImageMeta\Model\Metadata;

/**
 * Assembles structured metadata aggregates from normalised EXIF value objects.
 */
final readonly class ExifAssembler
{
    public function __construct(private ValueFactory $valueFactory = new ValueFactory())
    {
    }

    /**
     * Assembles the structured metadata aggregate from the supplied metadata container.
     */
    public function assemble(Metadata $metadata): StructuredMetadata
    {
        $components = $this->valueFactory->createComponents($metadata);

        return new StructuredMetadata(
            exif: $components['exif'],
            file: $components['file'],
            container: $components['container'],
            integrity: $components['integrity'],
            image: $components['image'],
            exposure: $components['exposure'],
            gps: $components['gps'],
        );
    }
}
