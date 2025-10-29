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
 * Assembles structured metadata aggregates from normalised value objects.
 */
final readonly class ExifAssembler
{
    private ValueFactory $valueFactory;

    public function __construct(?ValueFactory $valueFactory = null)
    {
        $this->valueFactory = $valueFactory ?? new ValueFactory();
    }

    /**
     * Assembles the structured metadata aggregate from the supplied metadata container.
     */
    public function assemble(Metadata $metadata): StructuredMetadata
    {
        $components = $this->valueFactory->createComponents(
            metadata: $metadata,
        );

        return new StructuredMetadata(
            file: $components['file'],
            camera: $components['camera'],
            lens: $components['lens'],
            media: $components['media'],
            exposure: $components['exposure'],
            capture: $components['capture'],
            gps: $components['gps'],
            sensor: $components['sensor'],
            processing: $components['processing'],
            technical: $components['technical'],
            rights: $components['rights'],
            makerNotes: $components['makerNotes'],
        );
    }
}
