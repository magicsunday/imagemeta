<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;

/**
 * Aggregates curated metadata as immutable value objects that can be accessed fluently.
 */
final readonly class StructuredMetadata
{
    /**
     * @param CaptureHardware      $hardware        Camera body, lens, sensor, device, and focus.
     * @param MediaContent         $content         Image, audio, video, thumbnail, depth map, multi-picture, and regions.
     * @param CaptureSettings      $settings        Exposure, white balance, scene, motion, and processing.
     * @param Provenance           $provenance      Author, rights, IPTC, keywords, file, container, standards, and related.
     * @param LocationTime         $locationTime    GPS, temporal timestamps, and capture context.
     * @param TechnicalData        $technical       Derived data, colour profiles, composites, interop, integrity, TIFF, XMP, and FlashPix.
     * @param AppleMakerNotes|null $makerNotesApple Apple maker notes when available.
     */
    public function __construct(
        public CaptureHardware $hardware,
        public MediaContent $content,
        public CaptureSettings $settings,
        public Provenance $provenance,
        public LocationTime $locationTime,
        public TechnicalData $technical,
        public ?AppleMakerNotes $makerNotesApple,
    ) {
    }
}
