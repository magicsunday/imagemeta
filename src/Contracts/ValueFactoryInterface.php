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
use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\AudioClips;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\Thumbnail;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;

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
