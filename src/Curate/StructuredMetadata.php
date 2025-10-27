<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate;

use MagicSunday\ImageMeta\Curate\Structured\CameraMetadata;
use MagicSunday\ImageMeta\Curate\Structured\CaptureMetadata;
use MagicSunday\ImageMeta\Curate\Structured\ExposureMetadata;
use MagicSunday\ImageMeta\Curate\Structured\FileMetadata;
use MagicSunday\ImageMeta\Curate\Structured\GpsMetadata;
use MagicSunday\ImageMeta\Curate\Structured\LensMetadata;
use MagicSunday\ImageMeta\Curate\Structured\MakerNotesMetadata;
use MagicSunday\ImageMeta\Curate\Structured\MediaMetadata;
use MagicSunday\ImageMeta\Curate\Structured\ProcessingMetadata;
use MagicSunday\ImageMeta\Curate\Structured\RightsMetadata;
use MagicSunday\ImageMeta\Curate\Structured\SensorMetadata;
use MagicSunday\ImageMeta\Curate\Structured\TechnicalMetadata;

/**
 * Aggregates curated metadata in a grouped, chainable representation.
 */
final readonly class StructuredMetadata
{
    public function __construct(
        public FileMetadata $file,
        public CameraMetadata $camera,
        public LensMetadata $lens,
        public MediaMetadata $media,
        public ExposureMetadata $exposure,
        public CaptureMetadata $capture,
        public GpsMetadata $gps,
        public SensorMetadata $sensor,
        public ProcessingMetadata $processing,
        public TechnicalMetadata $technical,
        public RightsMetadata $rights,
        public MakerNotesMetadata $makerNotes,
    ) {
    }
}
