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
final class ExifAssembler
{
    private readonly ValueFactory $valueFactory;

    public function __construct(
        ?ValueFactory $valueFactory = null,
    ) {
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
            interop: $components['interop'],
            tiff: $components['tiff'],
            composite: $components['composite'],
            standards: $components['standards'],
            flashPix: $components['flashPix'],
            multiPicture: $components['multiPicture'],
            camera: $components['camera'],
            lens: $components['lens'],
            image: $components['image'],
            exposure: $components['exposure'],
            capture: $components['capture'],
            gps: $components['gps'],
            device: $components['device'],
            apple: $components['apple'],
            xmp: $components['xmp'],
            file: $components['file'],
            container: $components['container'],
            preview: $components['preview'],
            video: $components['video'],
            audio: $components['audio'],
            embeddedAudio: $components['embeddedAudio'],
            colorProfile: $components['colorProfile'],
            processing: $components['processing'],
            whiteBalanceDetails: $components['whiteBalanceDetails'],
            focus: $components['focus'],
            motion: $components['motion'],
            scene: $components['scene'],
            regions: $components['regions'],
            keywords: $components['keywords'],
            rights: $components['rights'],
            author: $components['author'],
            temporal: $components['temporal'],
            derived: $components['derived'],
            related: $components['related'],
            sensor: $components['sensor'],
            uav: $components['uav'],
            integrity: $components['integrity'],
        );
    }
}
