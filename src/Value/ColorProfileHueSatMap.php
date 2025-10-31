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
        public ?int $hueDivisions,
        public ?int $saturationDivisions,
        public ?int $valueDivisions,
        public ?array $encodings,
        public ?array $mapData1,
        public ?array $mapData2,
        public ?array $mapData3,
    ) {
    }

    /**
     * Returns the number of hue slices stored in the map.
     */
    public function hueDivisions(): ?int
    {
        return $this->hueDivisions;
    }

    /**
     * Returns the number of saturation slices stored in the map.
     */
    public function saturationDivisions(): ?int
    {
        return $this->saturationDivisions;
    }

    /**
     * Returns the number of value/lightness slices stored in the map.
     */
    public function valueDivisions(): ?int
    {
        return $this->valueDivisions;
    }

    /**
     * Returns the primary hue/saturation/value adjustment table.
     *
     * @return list<float>|null
     */
    public function mapData1(): ?array
    {
        return $this->mapData1;
    }

    /**
     * Returns the encoding identifiers applied to the hue/saturation/value channels.
     *
     * @return list<int>|null
     */
    public function encodings(): ?array
    {
        return $this->encodings;
    }

    /**
     * Returns the secondary adjustment table when provided by the profile.
     *
     * @return list<float>|null
     */
    public function mapData2(): ?array
    {
        return $this->mapData2;
    }

    /**
     * Returns the tertiary adjustment table when present in the profile.
     *
     * @return list<float>|null
     */
    public function mapData3(): ?array
    {
        return $this->mapData3;
    }
}
