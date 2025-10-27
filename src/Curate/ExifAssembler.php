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
use MagicSunday\ImageMeta\Curate\Resolver\GpsResolver;
use MagicSunday\ImageMeta\Curate\Resolver\MultiPictureResolver;
use MagicSunday\ImageMeta\Curate\Resolver\RegionsResolver;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Gps;

/**
 * Assembles structured metadata aggregates from normalised value objects.
 */
final class ExifAssembler
{
    private readonly ValueFactory $valueFactory;

    private readonly GpsResolver $gpsResolver;

    private readonly RegionsResolver $regionsResolver;

    private readonly MultiPictureResolver $multiPictureResolver;

    public function __construct(
        ?ValueFactory $valueFactory = null,
        ?GpsResolver $gpsResolver = null,
        ?RegionsResolver $regionsResolver = null,
        ?MultiPictureResolver $multiPictureResolver = null,
    ) {
        $this->valueFactory         = $valueFactory ?? new ValueFactory();
        $this->gpsResolver          = $gpsResolver ?? new GpsResolver();
        $this->regionsResolver      = $regionsResolver ?? new RegionsResolver();
        $this->multiPictureResolver = $multiPictureResolver ?? new MultiPictureResolver();
    }

    /**
     * Assembles the structured metadata aggregate from the supplied metadata container.
     */
    public function assemble(Metadata $metadata): StructuredMetadata
    {
        $xmpDocument = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();

        $gps = $this->gpsResolver->resolve($metadata->exifDoc, $xmpDocument) ?? new Gps();

        $components = $this->valueFactory->createComponents(
            metadata: $metadata,
            gps: $gps,
            regions: $this->regionsResolver->resolve($xmpDocument),
            multiPicture: $this->multiPictureResolver->resolve($metadata->mpfDocument),
            xmpDocument: $xmpDocument,
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
