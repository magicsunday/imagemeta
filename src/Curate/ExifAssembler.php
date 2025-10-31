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
    public function __construct(private ValueFactory $valueFactory = new ValueFactory())
    {
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
            container: $components['container'],
            integrity: $components['integrity'],
            camera: $components['camera'],
            device: $components['device'],
            lens: $components['lens'],
            derived: $components['derived'],
            image: $components['image'],
            preview: $components['preview'],
            video: $components['video'],
            audio: $components['audio'],
            embeddedAudio: $components['embeddedAudio'],
            colorProfile: $components['colorProfile'],
            composite: $components['composite'],
            multiPicture: $components['multiPicture'],
            exposure: $components['exposure'],
            capture: $components['capture'],
            scene: $components['scene'],
            temporal: $components['temporal'],
            regions: $components['regions'],
            keywords: $components['keywords'],
            gps: $components['gps'],
            sensor: $components['sensor'],
            focus: $components['focus'],
            motion: $components['motion'],
            uav: $components['uav'],
            processing: $components['processing'],
            whiteBalance: $components['whiteBalance'],
            interop: $components['interop'],
            tiff: $components['tiff'],
            standards: $components['standards'],
            flashPix: $components['flashPix'],
            xmp: $components['xmp'],
            rights: $components['rights'],
            author: $components['author'],
            related: $components['related'],
            makerNotesApple: $components['makerNotesApple'],
        );
    }
}
