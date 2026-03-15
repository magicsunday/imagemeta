<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;

/**
 * Immutable result returned by {@see IsoBmffParserInterface::extract()}.
 *
 * Replaces the positional tuple with named properties for clarity.
 */
final readonly class IsoBmffParseResult
{
    /**
     * @param list<string>                $exifBlobs       Raw EXIF payloads extracted from the container.
     * @param list<string>                $xmpBlobs        Raw XMP packets extracted from the container.
     * @param ?QuickTimeMeta              $quickTimeMeta   QuickTime metadata keys and data atoms, if present.
     * @param ?IsoBmffItemReferenceMap    $itemReferences  Item reference map from iref boxes, if present.
     * @param ?IsoBmffDataReferenceMap    $dataReferences  Data reference map from dinf boxes, if present.
     * @param list<IsoBmffUnresolvedItem> $unresolvedItems Items that could not be resolved to payloads.
     * @param ?int                        $ispeWidth       Image width in pixels from the ispe box, if present.
     * @param ?int                        $ispeHeight      Image height in pixels from the ispe box, if present.
     * @param ?string                     $iccProfile      Binary ICC profile from the colr box, if present.
     * @param list<int>                   $tmapItemIds     Item IDs for tone-map images.
     */
    public function __construct(
        public array $exifBlobs,
        public array $xmpBlobs,
        public ?QuickTimeMeta $quickTimeMeta,
        public ?IsoBmffItemReferenceMap $itemReferences,
        public ?IsoBmffDataReferenceMap $dataReferences,
        public array $unresolvedItems,
        public ?int $ispeWidth,
        public ?int $ispeHeight,
        public ?string $iccProfile,
        public array $tmapItemIds,
    ) {
    }
}
