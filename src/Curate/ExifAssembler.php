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
use MagicSunday\ImageMeta\Curate\Structured\CameraMetadata;
use MagicSunday\ImageMeta\Curate\Structured\CaptureMetadata;
use MagicSunday\ImageMeta\Curate\Structured\ExposureMetadata;
use MagicSunday\ImageMeta\Curate\Structured\FileMetadata;
use MagicSunday\ImageMeta\Curate\Structured\GpsMetadata;
use MagicSunday\ImageMeta\Curate\Structured\LensMetadata;
use MagicSunday\ImageMeta\Curate\Structured\MakerNotesView;
use MagicSunday\ImageMeta\Curate\Structured\MediaMetadata;
use MagicSunday\ImageMeta\Curate\Structured\ProcessingMetadata;
use MagicSunday\ImageMeta\Curate\Structured\RightsMetadata;
use MagicSunday\ImageMeta\Curate\Structured\SensorMetadata;
use MagicSunday\ImageMeta\Curate\Structured\TechnicalMetadata;
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
            file: new FileMetadata($components['file'], $components['container'], $components['integrity']),
            camera: new CameraMetadata($components['camera'], $components['device']),
            lens: new LensMetadata($components['lens'], $components['derived']),
            media: new MediaMetadata(
                $components['image'],
                $components['preview'],
                $components['video'],
                $components['audio'],
                $components['embeddedAudio'],
                $components['colorProfile'],
                $components['composite'],
                $components['multiPicture'],
            ),
            exposure: new ExposureMetadata($components['exposure'], $components['derived']),
            capture: new CaptureMetadata(
                $components['capture'],
                $components['scene'],
                $components['temporal'],
                $components['regions'],
                $components['keywords'],
            ),
            gps: new GpsMetadata($components['gps']),
            sensor: new SensorMetadata(
                $components['sensor'],
                $components['focus'],
                $components['motion'],
                $components['uav'],
            ),
            processing: new ProcessingMetadata($components['processing'], $components['whiteBalance']),
            technical: new TechnicalMetadata(
                $components['interop'],
                $components['tiff'],
                $components['standards'],
                $components['flashPix'],
                $components['xmp'],
            ),
            rights: new RightsMetadata($components['rights'], $components['author'], $components['related']),
            makerNotes: new MakerNotesView($components['makerNotesApple']),
        );
    }
}
