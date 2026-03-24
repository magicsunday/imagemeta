<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Riff;

/**
 * Last-resort fallback helper for RIFF metadata in StructuredMetadata factories.
 *
 * Analogous to {@see \MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup}
 * but wraps RIFF INFO key-value pairs and RIFF-native EXIF sub-chunk fields.
 */
final readonly class RiffInfoLookup
{
    public function __construct(
        private ?RiffInfo $info,
        private ?RiffExifChunk $riffExif,
    ) {
    }

    /**
     * Returns the first non-null value for the given INFO tags, or null if none match.
     */
    public function string(string ...$infoTags): ?string
    {
        if ($this->info instanceof RiffInfo) {
            foreach ($infoTags as $tag) {
                $value = $this->info->get($tag);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Returns the camera make from the RIFF EXIF ecor sub-chunk.
     */
    public function exifMake(): ?string
    {
        return $this->riffExif?->make;
    }

    /**
     * Returns the camera model from the RIFF EXIF emdl sub-chunk.
     */
    public function exifModel(): ?string
    {
        return $this->riffExif?->model;
    }

    /**
     * Returns the time created from the RIFF EXIF etim sub-chunk.
     */
    public function exifTimeCreated(): ?string
    {
        return $this->riffExif?->timeCreated;
    }
}
