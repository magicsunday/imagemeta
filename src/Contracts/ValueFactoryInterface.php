<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Contracts;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\{
    Audio,
    AudioClips,
    Author,
    Camera,
    Capture,
    ColorProfile,
    CompositeImageInfo,
    Container,
    Derived,
    Device,
    Exposure,
    File,
    FlashPix,
    Focus,
    Gps,
    Image,
    Integrity,
    Interop,
    Keywords,
    Lens,
    Motion,
    MultiPicture,
    ProcessingSettings,
    Regions,
    RelatedAssets,
    Rights,
    Scene,
    Sensor,
    Standards,
    Temporal,
    Thumbnail,
    TiffData,
    Video,
    WhiteBalanceDetails,
    Xmp,
};

/**
 * Factory contract for building value-object aggregates from metadata structures.
 */
interface ValueFactoryInterface
{
    /**
     * Creates metadata value objects grouped by category.
     *
     * @param Metadata $metadata Aggregated metadata extracted from the source image.
     *
     * @return array{audio: Audio, author: Author, camera: Camera, capture: Capture, colorProfile: ColorProfile, composite: CompositeImageInfo, container: Container, derived: Derived, device: Device, embeddedAudio: AudioClips, exposure: Exposure, file: File, flashPix: FlashPix, focus: Focus, gps: Gps, image: Image, integrity: Integrity, interop: Interop, keywords: Keywords, lens: Lens, motion: Motion, multiPicture: MultiPicture, processing: ProcessingSettings, regions: Regions, related: RelatedAssets, rights: Rights, scene: Scene, sensor: Sensor, standards: Standards, temporal: Temporal, thumbnail: Thumbnail, tiff: TiffData, video: Video, whiteBalance: WhiteBalanceDetails, xmp: Xmp, makerNotesApple: AppleMakerNotes|null} Associative map of component identifiers to their value objects.
     */
    public function createComponents(Metadata $metadata): array;
}
