<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Factory;

use MagicSunday\ImageMeta\Exif\Factory\ValueFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\CaptureHardware;
use MagicSunday\ImageMeta\Value\CaptureSettings;
use MagicSunday\ImageMeta\Value\LocationTime;
use MagicSunday\ImageMeta\Value\MediaContent;
use MagicSunday\ImageMeta\Value\Provenance;
use MagicSunday\ImageMeta\Value\StructuredMetadata;
use MagicSunday\ImageMeta\Value\TechnicalData;

/**
 * Assembles structured metadata aggregates from normalised value objects.
 */
final readonly class StructuredMetadataBuilder
{
    /**
     * @param ValueFactory $valueFactory Factory used to build structured components.
     */
    public function __construct(private ValueFactory $valueFactory)
    {
    }

    /**
     * Creates a builder with default concrete dependencies.
     */
    public static function createDefault(): self
    {
        return new self(ValueFactory::createDefault());
    }

    /**
     * Assembles the structured metadata aggregate from the supplied metadata container.
     */
    public function assemble(Metadata $metadata): StructuredMetadata
    {
        $components = $this->valueFactory->createComponents(
            metadata: $metadata,
        );

        $hardware = new CaptureHardware(
            camera: $components['camera'],
            lens: $components['lens'],
            sensor: $components['sensor'],
            device: $components['device'],
            focus: $components['focus'],
        );

        $content = new MediaContent(
            image: $components['image'],
            audio: $components['audio'],
            embeddedAudio: $components['embeddedAudio'],
            video: $components['video'],
            thumbnail: $components['thumbnail'],
            depthMap: $components['depthMap'],
            multiPicture: $components['multiPicture'],
            regions: $components['regions'],
        );

        $settings = new CaptureSettings(
            exposure: $components['exposure'],
            whiteBalance: $components['whiteBalance'],
            scene: $components['scene'],
            motion: $components['motion'],
            processing: $components['processing'],
        );

        $provenance = new Provenance(
            author: $components['author'],
            rights: $components['rights'],
            iptc: $components['iptc'],
            keywords: $components['keywords'],
            file: $components['file'],
            container: $components['container'],
            standards: $components['standards'],
            related: $components['related'],
        );

        $locationTime = new LocationTime(
            gps: $components['gps'],
            temporal: $components['temporal'],
            capture: $components['capture'],
        );

        $technical = new TechnicalData(
            derived: $components['derived'],
            colorProfile: $components['colorProfile'],
            composite: $components['composite'],
            interop: $components['interop'],
            integrity: $components['integrity'],
            tiff: $components['tiff'],
            xmp: $components['xmp'],
            flashPix: $components['flashPix'],
        );

        return new StructuredMetadata(
            hardware: $hardware,
            content: $content,
            settings: $settings,
            provenance: $provenance,
            locationTime: $locationTime,
            technical: $technical,
            makerNotesApple: $components['makerNotesApple'],
        );
    }
}
