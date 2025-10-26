<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\Mpf\MpfEntry;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\MultiPictureEntry;

/**
 * Converts MPF documents into curated multi-picture value objects.
 */
final readonly class MultiPictureResolver
{
    public function resolve(?MpfDocument $document): MultiPicture
    {
        if (!$document instanceof MpfDocument) {
            return new MultiPicture(null, 0, [], null, null, null, null, null);
        }

        $entries = [];
        foreach ($document->entries as $entry) {
            if (!$entry instanceof MpfEntry) {
                continue;
            }

            $entries[] = new MultiPictureEntry(
                attributes: $entry->attributes,
                imageSize: $entry->imageSize,
                dataOffset: $entry->dataOffset,
                dependentImage1: $entry->dependentImage1,
                dependentImage2: $entry->dependentImage2,
            );
        }

        $attributes = $document->attributes;

        return new MultiPicture(
            version: $document->version,
            imageCount: $document->imageCount,
            entries: $entries,
            totalFrames: $attributes?->totalFrames,
            individualImageNumber: $attributes?->individualImageNumber,
            imageUidList: $attributes?->imageUidList,
            panoramaAngle: $attributes?->panoramaAngle,
            panoramaAxis: $attributes?->panoramaAxis,
        );
    }
}
