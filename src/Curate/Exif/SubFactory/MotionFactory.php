<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Motion;

use function is_array;

/**
 * Factory for creating Motion value objects from EXIF and Apple metadata.
 */
final readonly class MotionFactory
{
    /**
     * Creates a Motion value object from EXIF and Apple metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Motion Motion metadata aggregate with camera orientation and per-axis acceleration.
     */
    public function create(Metadata $metadata): Motion
    {
        $exif  = $metadata->exifDoc;
        $apple = $metadata->makerNotes?->apple;

        if (!$apple instanceof AppleMakerNotes) {
            $apple = AppleMakerNotes::empty();
        }

        return $this->buildMotion($exif, $apple);
    }

    /**
     * Builds the motion metadata aggregate from EXIF and Apple motion sources.
     *
     * @param ParsedExif|null $exif  Resolver exposing EXIF camera orientation measurements.
     * @param AppleMakerNotes $apple Aggregated Apple metadata composed from maker notes and QuickTime sources.
     *
     * @return Motion Motion metadata aggregate with camera orientation and per-axis acceleration.
     */
    private function buildMotion(?ParsedExif $exif, AppleMakerNotes $apple): Motion
    {
        $vector = $apple->accelerationVector;

        if (!is_array($vector)) {
            $vector = $exif?->accelerationVector();
        }

        $accelX = null;
        $accelY = null;
        $accelZ = null;

        if (is_array($vector)) {
            $accelX = $vector[0] ?? null;
            $accelY = $vector[1] ?? null;
            $accelZ = $vector[2] ?? null;
        }

        return new Motion(
            $accelX,
            $accelY,
            $accelZ,
        );
    }
}
