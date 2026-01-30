<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Factory;

use MagicSunday\ImageMeta\Factory\Exif\ValueFactory;
use MagicSunday\ImageMeta\Model\Metadata;

/**
 * Assembles structured metadata aggregates from normalised value objects.
 */
final readonly class ExifAssembler implements StructuredAssemblerInterface
{
    /**
     * @param ValueFactory $valueFactory Factory used to build structured components.
     */
    public function __construct(private ValueFactory $valueFactory = new ValueFactory())
    {
    }

    /**
     * Assembles the structured metadata aggregate from the supplied metadata container.
     */
    public function assemble(Metadata $metadata): StructuredMetadataFactory
    {
        $components = $this->valueFactory->createComponents(
            metadata: $metadata,
        );

        return new StructuredMetadataFactory(
            audio: $components['audio'],
            embeddedAudio: $components['embeddedAudio'],
            author: $components['author'],
            camera: $components['camera'],
            capture: $components['capture'],
            colorProfile: $components['colorProfile'],
            composite: $components['composite'],
            container: $components['container'],
            derived: $components['derived'],
            depthMap: $components['depthMap'],
            device: $components['device'],
            exposure: $components['exposure'],
            file: $components['file'],
            flashPix: $components['flashPix'],
            focus: $components['focus'],
            gps: $components['gps'],
            image: $components['image'],
            integrity: $components['integrity'],
            interop: $components['interop'],
            iptc: $components['iptc'],
            keywords: $components['keywords'],
            lens: $components['lens'],
            motion: $components['motion'],
            multiPicture: $components['multiPicture'],
            processing: $components['processing'],
            regions: $components['regions'],
            related: $components['related'],
            rights: $components['rights'],
            scene: $components['scene'],
            sensor: $components['sensor'],
            standards: $components['standards'],
            temporal: $components['temporal'],
            thumbnail: $components['thumbnail'],
            tiff: $components['tiff'],
            video: $components['video'],
            whiteBalance: $components['whiteBalance'],
            xmp: $components['xmp'],
            makerNotesApple: $components['makerNotesApple'],
        );
    }
}
