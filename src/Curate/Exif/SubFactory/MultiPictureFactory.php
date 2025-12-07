<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\MultiPictureEntry;

/**
 * Factory for creating MultiPicture value objects from MPF metadata.
 */
final readonly class MultiPictureFactory
{
    /**
     * Creates a MultiPicture value object from MPF metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return MultiPicture MultiPicture metadata aggregate.
     */
    public function create(Metadata $metadata): MultiPicture
    {
        return $this->resolveMultiPicture($metadata->mpfDocument);
    }

    /**
     * Resolves a MultiPicture value object from the MPF document.
     *
     * @param MpfDocument|null $document MPF document containing multi-picture format data.
     *
     * @return MultiPicture MultiPicture value object.
     */
    private function resolveMultiPicture(?MpfDocument $document): MultiPicture
    {
        if (!$document instanceof MpfDocument) {
            return new MultiPicture(
                null,
                0,
                [],
                null,
                null,
                null,
                null,
                null
            );
        }

        $entries = [];
        foreach ($document->entries as $entry) {
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
