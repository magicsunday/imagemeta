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
 * Provides device specific metadata extracted from container level sources.
 */
final readonly class Device
{
    /**
     * @param string|null $software                Software version or build identifier.
     * @param string|null $rawDevelopingSoftware   Raw developing software identifier.
     * @param string|null $imageEditingSoftware    Image editing software identifier.
     * @param string|null $metadataEditingSoftware Metadata editing software identifier.
     */
    public function __construct(
        public ?string $software,
        public ?string $rawDevelopingSoftware,
        public ?string $imageEditingSoftware,
        public ?string $metadataEditingSoftware,
    ) {
    }

    /**
     * Returns the software version or build identifier.
     */
    public function software(): ?string
    {
        return $this->software;
    }

    /**
     * Returns the raw developing software identifier when available.
     */
    public function rawDevelopingSoftware(): ?string
    {
        return $this->rawDevelopingSoftware;
    }

    /**
     * Returns the image editing software identifier when available.
     */
    public function imageEditingSoftware(): ?string
    {
        return $this->imageEditingSoftware;
    }

    /**
     * Returns the metadata editing software identifier when available.
     */
    public function metadataEditingSoftware(): ?string
    {
        return $this->metadataEditingSoftware;
    }
}
