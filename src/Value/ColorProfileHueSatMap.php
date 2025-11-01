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
 * Describes a DNG hue/saturation/value adjustment map applied by a camera profile.
 */
final readonly class ColorProfileHueSatMap
{
    /**
     * @param int|null         $hueDivisions        Number of hue slices stored in the map.
     * @param int|null         $saturationDivisions Number of saturation slices stored in the map.
     * @param int|null         $valueDivisions      Number of value/lightness slices stored in the map.
     * @param list<int>|null   $encodings           Optional encoding identifiers for hue/saturation/value channels.
     * @param list<float>|null $mapData1            Primary hue/saturation/value adjustment table.
     * @param list<float>|null $mapData2            Secondary adjustment table when provided by the profile.
     * @param list<float>|null $mapData3            Tertiary adjustment table when present in the profile.
     */
    public function __construct(
        public readonly ?int $hueDivisions,
        public readonly ?int $saturationDivisions,
        public readonly ?int $valueDivisions,
        public readonly ?array $encodings,
        public readonly ?array $mapData1,
        public readonly ?array $mapData2,
        public readonly ?array $mapData3,
    ) {
    }
}
