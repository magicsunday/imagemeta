<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Regions;

/**
 * Represents a rectangular region annotation as defined by XMP metadata.
 */
final readonly class Region
{
    /**
     * @param RegionType|null $type        Semantic classification of the region (e.g. face, focus).
     * @param float           $x           Normalised X coordinate of the top left corner.
     * @param float           $y           Normalised Y coordinate of the top left corner.
     * @param float           $w           Normalised width of the region.
     * @param float           $h           Normalised height of the region.
     * @param string|null     $personName  Associated person name when the region marks a face.
     * @param float|null      $confidence  Detection confidence value if provided.
     * @param float|null      $rotationDeg Rotation angle in degrees, positive values rotate clockwise.
     * @param string|null     $faceId      Optional identifier emitted by face detection engines.
     */
    public function __construct(
        public ?RegionType $type,
        public float $x,
        public float $y,
        public float $w,
        public float $h,
        public ?string $personName = null,
        public ?float $confidence = null,
        public ?float $rotationDeg = null,
        public ?string $faceId = null,
    ) {
    }

    /**
     * Returns the semantic classification of the region.
     */
    public function type(): ?RegionType
    {
        return $this->type;
    }

    /**
     * Returns the normalised X coordinate of the top left corner.
     */
    public function x(): float
    {
        return $this->x;
    }

    /**
     * Returns the normalised Y coordinate of the top left corner.
     */
    public function y(): float
    {
        return $this->y;
    }

    /**
     * Returns the normalised region width.
     */
    public function w(): float
    {
        return $this->w;
    }

    /**
     * Returns the normalised region height.
     */
    public function h(): float
    {
        return $this->h;
    }

    /**
     * Returns the associated person name when the region marks a face.
     */
    public function personName(): ?string
    {
        return $this->personName;
    }

    /**
     * Returns the detection confidence value if provided.
     */
    public function confidence(): ?float
    {
        return $this->confidence;
    }

    /**
     * Returns the rotation angle in degrees, positive values rotate clockwise.
     */
    public function rotationDeg(): ?float
    {
        return $this->rotationDeg;
    }

    /**
     * Returns the optional identifier emitted by face detection engines.
     */
    public function faceId(): ?string
    {
        return $this->faceId;
    }
}
