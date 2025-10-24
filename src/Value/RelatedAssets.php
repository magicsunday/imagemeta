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
 * References associated assets such as live photo pairs or burst sets.
 */
final readonly class RelatedAssets
{
    /**
     * @param string|null $livePhotoPairId   Identifier of the paired live photo asset.
     * @param string|null $burstId           Identifier for the burst set.
     * @param bool|null   $isPrimaryInBurst  Indicates whether this asset is the selected burst frame.
     * @param string|null $panoramaId        Panorama identifier when part of a panorama sequence.
     * @param string|null $depthDataId       Identifier of an associated depth data asset.
     * @param string|null $relatedSoundFile  Name of a related sound file attached to the capture.
     */
    public function __construct(
        public ?string $livePhotoPairId,
        public ?string $burstId,
        public ?bool $isPrimaryInBurst,
        public ?string $panoramaId,
        public ?string $depthDataId,
        public ?string $relatedSoundFile,
    ) {
    }
}
