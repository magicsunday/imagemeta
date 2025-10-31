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
use MagicSunday\ImageMeta\Curate\Structured\MakerNotesView;
use MagicSunday\ImageMeta\Curate\Structured\MediaMetadata;
use MagicSunday\ImageMeta\Curate\Structured\ProcessingMetadata;
use MagicSunday\ImageMeta\Curate\Structured\RightsMetadata;
use MagicSunday\ImageMeta\Curate\Structured\SensorMetadata;
use MagicSunday\ImageMeta\Curate\Structured\TechnicalMetadata;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Preview;
use MagicSunday\ImageMeta\Value\Standards;

/**
 * Aggregates curated metadata in a grouped, chainable representation.
 */
final readonly class StructuredMetadata
{
    public function __construct(
        private FileMetadata $file,
        private CameraMetadata $camera,
        private LensMetadata $lens,
        private MediaMetadata $media,
        private ExposureMetadata $exposure,
        private CaptureMetadata $capture,
        private GpsMetadata $gps,
        private SensorMetadata $sensor,
        private ProcessingMetadata $processing,
        private TechnicalMetadata $technical,
        private RightsMetadata $rights,
        private MakerNotesView $makerNotes,
    ) {
    }

    public function file(): FileMetadata
    {
        return $this->file;
    }

    public function camera(): CameraMetadata
    {
        return $this->camera;
    }

    public function lens(): LensMetadata
    {
        return $this->lens;
    }

    public function media(): MediaMetadata
    {
        return $this->media;
    }

    public function exposure(): ExposureMetadata
    {
        return $this->exposure;
    }

    public function capture(): CaptureMetadata
    {
        return $this->capture;
    }

    public function gps(): GpsMetadata
    {
        return $this->gps;
    }

    public function sensor(): SensorMetadata
    {
        return $this->sensor;
    }

    public function processing(): ProcessingMetadata
    {
        return $this->processing;
    }

    public function technical(): TechnicalMetadata
    {
        return $this->technical;
    }

    public function rights(): RightsMetadata
    {
        return $this->rights;
    }

    public function makerNotes(): MakerNotesView
    {
        return $this->makerNotes;
    }

    public function image(): Image
    {
        return $this->media->image;
    }

    public function preview(): Preview
    {
        return $this->media->preview;
    }

    public function interop(): Interop
    {
        return $this->technical->interop;
    }

    public function standards(): Standards
    {
        return $this->technical->standards;
    }

    public function derived(): Derived
    {
        return $this->lens->derived();
    }
}
