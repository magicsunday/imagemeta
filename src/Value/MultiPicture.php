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
 * Aggregates MPF information for clients interested in additional image frames.
 */
final readonly class MultiPicture
{
    /**
     * @param list<MultiPictureEntry>                          $entries
     * @param list<array{numerator:int, denominator:int}>|null $panoramaAngle
     * @param list<array{numerator:int, denominator:int}>|null $panoramaAxis
     */
    public function __construct(
        public ?string $version,
        public int $imageCount,
        public array $entries,
        public ?int $totalFrames,
        public ?int $individualImageNumber,
        public ?string $imageUidList,
        public ?array $panoramaAngle,
        public ?array $panoramaAxis,
    ) {
    }

    /**
     * Returns the MPF version string when available.
     */
    public function version(): ?string
    {
        return $this->version;
    }

    /**
     * Returns the number of images reported by the MPF header.
     */
    public function imageCount(): int
    {
        return $this->imageCount;
    }

    /**
     * Returns the list of MPF image entries.
     *
     * @return list<MultiPictureEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Returns the total frame count when available.
     */
    public function totalFrames(): ?int
    {
        return $this->totalFrames;
    }

    /**
     * Returns the individual image number within the MPF set.
     */
    public function individualImageNumber(): ?int
    {
        return $this->individualImageNumber;
    }

    /**
     * Returns the optional image UID list string.
     */
    public function imageUidList(): ?string
    {
        return $this->imageUidList;
    }

    /**
     * Returns the optional panorama angles as rational pairs.
     *
     * @return list<array{numerator:int, denominator:int}>|null
     */
    public function panoramaAngle(): ?array
    {
        return $this->panoramaAngle;
    }

    /**
     * Returns the optional panorama axis angles as rational pairs.
     *
     * @return list<array{numerator:int, denominator:int}>|null
     */
    public function panoramaAxis(): ?array
    {
        return $this->panoramaAxis;
    }
}
