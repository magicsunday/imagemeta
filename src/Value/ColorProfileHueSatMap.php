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
     * Creates a color profile hue/saturation map value object.
     *
     * @param int|null         $hueDivisions        Number of hue slices stored in the map.
     * @param int|null         $saturationDivisions Number of saturation slices stored in the map.
     * @param int|null         $valueDivisions      Number of value/lightness slices stored in the map.
     * @param list<int>|null   $encodings           Optional encoding identifiers for hue/saturation/value channels.
     * @param list<float>|null $mapData1            Primary hue/saturation/value adjustment table.
     * @param list<float>|null $mapData2            Secondary adjustment table when provided by the profile.
     * @param list<float>|null $mapData3            Tertiary adjustment table when present in the profile.
     */
    public function __construct(
        public ?int $hueDivisions,
        public ?int $saturationDivisions,
        public ?int $valueDivisions,
        public ?array $encodings,
        public ?array $mapData1,
        public ?array $mapData2,
        public ?array $mapData3,
    ) {
    }
}
