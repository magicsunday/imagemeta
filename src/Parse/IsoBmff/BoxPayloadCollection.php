<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;

/**
 * Immutable collection of box payloads extracted from a `meta` box.
 *
 * @phpstan-type QuickTimeKeyEntry = array{namespace: string, name: string}
 */
final readonly class BoxPayloadCollection
{
    /**
     * @param array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}>                                                                                            $itemInfos      Item information entries keyed by item ID.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations      Item locations keyed by item ID.
     * @param array<int, list<IsoBmffItemReference>>                                                                                                                                        $itemReferences Item references keyed by source item ID.
     * @param array<int, IsoBmffDataReference>                                                                                                                                              $dataReferences Data references keyed by index.
     * @param ?int                                                                                                                                                                          $primaryItemId  Primary item identifier.
     * @param list<string>                                                                                                                                                                  $directXmp      Direct XMP payloads.
     * @param list<string>                                                                                                                                                                  $uuidXmp        UUID-based XMP payloads.
     * @param list<string>                                                                                                                                                                  $directExif     Direct EXIF payloads.
     * @param ?string                                                                                                                                                                       $idatPayload    Cached idat payload bytes.
     * @param list<array<int, QuickTimeKeyEntry>>                                                                                                                                           $keysMaps       Parsed QuickTime key-entry maps.
     * @param list<BoxDescriptor>                                                                                                                                                           $ilstBoxes      Collected ilst box descriptors.
     * @param bool                                                                                                                                                                          $hasMhdr        Whether a metadata header was present.
     * @param list<list<int>>                                                                                                                                                               $countryLists   Parsed country locale lists.
     * @param list<list<int>>                                                                                                                                                               $languageLists  Parsed language locale lists.
     * @param bool                                                                                                                                                                          $isMdta         Whether the handler type is mdta.
     * @param bool                                                                                                                                                                          $isMdir         Whether the handler type is mdir.
     * @param ?int                                                                                                                                                                          $ispeWidth      Image spatial extents width.
     * @param ?int                                                                                                                                                                          $ispeHeight     Image spatial extents height.
     * @param ?string                                                                                                                                                                       $iccProfile     Binary ICC profile from colr box.
     */
    public function __construct(
        public array $itemInfos,
        public array $locations,
        public array $itemReferences,
        public array $dataReferences,
        public ?int $primaryItemId,
        public array $directXmp,
        public array $uuidXmp,
        public array $directExif,
        public ?string $idatPayload,
        public array $keysMaps,
        public array $ilstBoxes,
        public bool $hasMhdr,
        public array $countryLists,
        public array $languageLists,
        public bool $isMdta,
        public bool $isMdir,
        public ?int $ispeWidth = null,
        public ?int $ispeHeight = null,
        public ?string $iccProfile = null,
    ) {
    }
}
