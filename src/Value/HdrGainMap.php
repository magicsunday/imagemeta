<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Represents HDR gain map metadata extracted from ISO BMFF tmap items and Adobe hdrgm / Apple apdi XMP namespaces.
 */
final readonly class HdrGainMap
{
    /**
     * Creates an HDR gain map metadata value object.
     *
     * @param bool        $hasGainMap         Whether a gain map item (tmap) was detected in the container.
     * @param string|null $version            Gain map format version (hdrgm:Version).
     * @param bool|null   $baseRenditionIsHdr Whether the base image is the HDR rendition (hdrgm:BaseRenditionIsHDR).
     * @param float|null  $hdrCapacityMin     Minimum HDR capacity in stops (hdrgm:HDRCapacityMin).
     * @param float|null  $hdrCapacityMax     Maximum HDR capacity in stops (hdrgm:HDRCapacityMax).
     * @param float|null  $gainMapMin         Minimum gain map value (hdrgm:GainMapMin).
     * @param float|null  $gainMapMax         Maximum gain map value (hdrgm:GainMapMax).
     * @param float|null  $gamma              Gain map gamma (hdrgm:Gamma).
     * @param float|null  $offsetSdr          SDR offset value (hdrgm:OffsetSDR).
     * @param float|null  $offsetHdr          HDR offset value (hdrgm:OffsetHDR).
     * @param string|null $auxiliaryImageType Type of auxiliary image (apdi:AuxiliaryImageType).
     */
    public function __construct(
        public bool $hasGainMap,
        public ?string $version,
        public ?bool $baseRenditionIsHdr,
        public ?float $hdrCapacityMin,
        public ?float $hdrCapacityMax,
        public ?float $gainMapMin,
        public ?float $gainMapMax,
        public ?float $gamma,
        public ?float $offsetSdr,
        public ?float $offsetHdr,
        public ?string $auxiliaryImageType,
    ) {
    }
}
