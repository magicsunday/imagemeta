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
 * @phpstan-import-type QuickTimeKeyEntry from BoxPayloadCollector
 */
final readonly class BoxPayloadCollection
{
    /**
     * @param array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}>                                                                                                        $itemInfos
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations
     * @param array<int, list<IsoBmffItemReference>>                                                                                                                                                     $itemReferences
     * @param array<int, IsoBmffDataReference>                                                                                                                                                           $dataReferences
     * @param ?int                                                                                                                                                                                       $primaryItemId
     * @param list<string>                                                                                                                                                                               $directXmp
     * @param list<string>                                                                                                                                                                               $uuidXmp
     * @param list<string>                                                                                                                                                                               $directExif
     * @param ?string                                                                                                                                                                                    $idatPayload
     * @param list<array<int, QuickTimeKeyEntry>>                                                                                                                                                        $keysMaps
     * @param list<BoxDescriptor>                                                                                                                                                                        $ilstBoxes
     * @param bool                                                                                                                                                                                       $hasMhdr
     * @param list<list<int>>                                                                                                                                                                            $countryLists
     * @param list<list<int>>                                                                                                                                                                            $languageLists
     * @param bool                                                                                                                                                                                       $isMdta
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
    ) {
    }
}
