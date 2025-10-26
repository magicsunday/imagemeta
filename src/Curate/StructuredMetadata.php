<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate;

use MagicSunday\ImageMeta\Value\Apple;
use MagicSunday\ImageMeta\Value\Audio;
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
use MagicSunday\ImageMeta\Value\Preview;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Uav;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;

/**
 * Aggregates curated metadata in a structured representation with typed sub objects.
 */
final readonly class StructuredMetadata
{
    /**
     * Creates a structured metadata aggregate populated with curated value objects for each domain.
     *
     * @param Interop             $interop             Interoperability metadata derived from EXIF tags.
     * @param TiffData            $tiff                TIFF-related imaging parameters.
     * @param CompositeImageInfo  $composite           Composite capture information for multi-image scenes.
     * @param Standards           $standards           Metadata standard identifiers and versions.
     * @param FlashPix            $flashPix            FlashPix extension streams extracted from APP2 markers.
     * @param Camera              $camera              Camera manufacturer and model information.
     * @param Lens                $lens                Lens identification and optical properties.
     * @param Image               $image               Image dimensions and orientation details.
     * @param Exposure            $exposure            Exposure settings and capture parameters.
     * @param Capture             $capture             Environmental capture metadata.
     * @param Gps                 $gps                 Geographic positioning information.
     * @param Device              $device              Host device information.
     * @param Apple               $apple               Apple-specific metadata aggregate.
     * @param Xmp                 $xmp                 Parsed XMP document wrapper.
     * @param File                $file                File level metadata and characteristics.
     * @param Container           $container           Container format metadata.
     * @param Preview             $preview             Embedded preview information.
     * @param Video               $video               Video track metadata when available.
     * @param Audio               $audio               Audio track metadata when available.
     * @param ColorProfile        $colorProfile        Colour profile information.
     * @param ProcessingSettings  $processing          Image processing metadata.
     * @param WhiteBalanceDetails $whiteBalanceDetails White balance analysis details.
     * @param Focus               $focus               Focus distance and autofocus data.
     * @param Motion              $motion              Motion and acceleration measurements.
     * @param Scene               $scene               Scene classification details.
     * @param Regions             $regions             Region and face annotations.
     * @param Keywords            $keywords            Keyword annotations.
     * @param Rights              $rights              Rights and licensing information.
     * @param Author              $author              Creator metadata values.
     * @param Temporal            $temporal            Temporal metadata beyond capture timestamps.
     * @param Derived             $derived             Derived asset references.
     * @param RelatedAssets       $related             Related asset references from metadata.
     * @param Sensor              $sensor              Sensor and detection metadata.
     * @param Uav                 $uav                 Unmanned aerial vehicle metadata.
     * @param Integrity           $integrity           Integrity and validation metadata.
     */
    public function __construct(
        public Interop $interop,
        public TiffData $tiff,
        public CompositeImageInfo $composite,
        public Standards $standards,
        public FlashPix $flashPix,
        public Camera $camera,
        public Lens $lens,
        public Image $image,
        public Exposure $exposure,
        public Capture $capture,
        public Gps $gps,
        public Device $device,
        public Apple $apple,
        public Xmp $xmp,
        public File $file,
        public Container $container,
        public Preview $preview,
        public Video $video,
        public Audio $audio,
        public ColorProfile $colorProfile,
        public ProcessingSettings $processing,
        public WhiteBalanceDetails $whiteBalanceDetails,
        public Focus $focus,
        public Motion $motion,
        public Scene $scene,
        public Regions $regions,
        public Keywords $keywords,
        public Rights $rights,
        public Author $author,
        public Temporal $temporal,
        public Derived $derived,
        public RelatedAssets $related,
        public Sensor $sensor,
        public Uav $uav,
        public Integrity $integrity,
    ) {
    }
}
