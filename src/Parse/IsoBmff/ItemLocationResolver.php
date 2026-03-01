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
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffQueuedResolveResult;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;

use function array_key_exists;
use function array_unique;
use function array_unshift;
use function array_values;
use function sha1;

/**
 * Orchestrates item location resolution within ISO BMFF metadata containers.
 *
 * Delegates box parsing to {@see IlocBoxParser} and payload resolution to
 * {@see ItemPayloadResolver}, providing merge and gather operations that
 * combine results from multiple metadata contexts as specified by ISO/IEC 14496-12 §8.11.
 */
final readonly class ItemLocationResolver
{
    /**
     * @param ItemPayloadResolver $payloadResolver Resolves item payloads from extent structures.
     */
    public function __construct(
        private ItemPayloadResolver $payloadResolver,
    ) {
    }

    /**
     * Gathers item IDs from item info structures, separated by metadata type.
     *
     * @param array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}> $itemInfos     Item information structures.
     * @param int|null                                                                           $primaryItemId Primary item ID if known.
     *
     * @return array{0: list<int>, 1: list<int>, 2: list<int>} Tuple of [EXIF item IDs, XMP item IDs, tmap item IDs].
     */
    public function gatherItemIds(array $itemInfos, ?int $primaryItemId): array
    {
        $exifItemIds = [];
        $xmpItemIds  = [];
        $tmapItemIds = [];

        // Collect item IDs that advertise EXIF/XMP payloads via their metadata descriptors.
        // EXIF 3.0 Annex A.2.3 maps item types and MIME hints that flag Exif or XMP payloads,
        // so we treat those descriptors as authoritative signals.
        foreach ($itemInfos as $info) {
            if ($this->payloadResolver->isExifItem($info)) {
                $exifItemIds[] = $info['id'];
            }

            if ($this->payloadResolver->isXmpItem($info)) {
                $xmpItemIds[] = $info['id'];
            }

            if ($this->payloadResolver->isTmapItem($info)) {
                $tmapItemIds[] = $info['id'];
            }
        }

        // Deduplicate while preserving encounter order to avoid processing the same item twice.
        $exifItemIds = array_values(array_unique($exifItemIds));
        $xmpItemIds  = array_values(array_unique($xmpItemIds));
        $tmapItemIds = array_values(array_unique($tmapItemIds));

        if (($primaryItemId !== null) && isset($itemInfos[$primaryItemId]) && $this->payloadResolver->isExifItem($itemInfos[$primaryItemId])) {
            // EXIF 3.0 Annex A.2.5: pitm marks the default metadata item; prioritize
            // the primary item for EXIF candidate resolution when it is EXIF-typed.
            array_unshift($exifItemIds, $primaryItemId);
            $exifItemIds = array_values(array_unique($exifItemIds));
        }

        if (($primaryItemId !== null) && isset($itemInfos[$primaryItemId]) && $this->payloadResolver->isXmpItem($itemInfos[$primaryItemId])) {
            // ISO/IEC 14496-12 §8.11.4: pitm identifies the primary item, not its metadata type.
            // Prioritize only when item descriptors (EXIF 3.0 Annex A.2.3) explicitly mark XMP.
            array_unshift($xmpItemIds, $primaryItemId);
            $xmpItemIds = array_values(array_unique($xmpItemIds));
        }

        return [$exifItemIds, $xmpItemIds, $tmapItemIds];
    }

    /**
     * Resolves queued item IDs to their payload data.
     *
     * @param list<int>                                                                                                                                                                     $itemIds           Item IDs to resolve.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations         Item location metadata.
     * @param array<int, list<IsoBmffItemReference>>                                                                                                                                        $itemReferences    Parsed item references for construction_method=2 extents.
     * @param (callable(string):string)|null                                                                                                                                                $transform         Optional transform function.
     * @param array<int, IsoBmffDataReference>                                                                                                                                              $dataReferences    Parsed data references for the current meta box.
     * @param string|null                                                                                                                                                                   $idatPayload       Cached idat payload for construction_method=1 extents.
     * @param int                                                                                                                                                                           $metaContextOffset Absolute file offset of the owning meta box.
     *
     * @return IsoBmffQueuedResolveResult Resolved payloads and any unresolved item descriptors.
     */
    public function resolveQueuedItems(array $itemIds, array $locations, array $itemReferences, ?callable $transform, array $dataReferences, ?string $idatPayload, int $metaContextOffset): IsoBmffQueuedResolveResult
    {
        /** @var list<string> $resolved */
        $resolved = [];

        /** @var list<IsoBmffUnresolvedItem> $unresolvedItems */
        $unresolvedItems = [];

        // Pull data for each referenced item and optionally transform the payload.
        foreach ($itemIds as $itemId) {
            $result          = $this->payloadResolver->resolveItemData($itemId, $locations, $itemReferences, $dataReferences, $idatPayload, $metaContextOffset);
            $unresolvedItems = [...$unresolvedItems, ...$result->unresolvedItems];

            if ($result->data !== null) {
                $resolved[] = $transform !== null ? $transform($result->data) : $result->data;
            }
        }

        return new IsoBmffQueuedResolveResult($resolved, $unresolvedItems);
    }

    /**
     * Returns the SHA-1 hash of the blob when it has not been seen before, or null if duplicate.
     *
     * The caller is responsible for tracking the hash and accumulating the blob.
     *
     * @param array<string, bool> $xmpHashes Previously seen hashes.
     * @param string              $blob      XMP payload to hash.
     *
     * @return string|null SHA-1 hash when the blob is unique, null when already seen.
     */
    public function uniqueXmpHash(array $xmpHashes, string $blob): ?string
    {
        $hash = sha1($blob);

        return array_key_exists($hash, $xmpHashes) ? null : $hash;
    }

    /**
     * Merges ISO BMFF item reference mappings while preserving metadata context scope.
     *
     * ISO/IEC 14496-12 scopes iref item identifiers to their owning metadata
     * context, so identical numeric item IDs from separate meta boxes must not
     * be merged into one global bucket.
     *
     * @param array<int, array<int, list<IsoBmffItemReference>>> $existing      Previously merged references.
     * @param int                                                $contextOffset Absolute file offset of the owning meta box.
     * @param array<int, list<IsoBmffItemReference>>             $incoming      Incoming references to merge.
     *
     * @return array<int, array<int, list<IsoBmffItemReference>>>
     */
    public function mergeItemReferencesByContext(array $existing, int $contextOffset, array $incoming): array
    {
        if ($incoming === []) {
            return $existing;
        }

        if (!isset($existing[$contextOffset])) {
            $existing[$contextOffset] = [];
        }

        foreach ($incoming as $fromItemId => $references) {
            if (!isset($existing[$contextOffset][$fromItemId])) {
                $existing[$contextOffset][$fromItemId] = $references;
                continue;
            }

            foreach ($references as $reference) {
                $existing[$contextOffset][$fromItemId][] = $reference;
            }
        }

        return $existing;
    }

    /**
     * Merges ISO BMFF data references while preserving their metadata context scope.
     *
     * ISO/IEC 14496-12 §8.7.2 defines dref entry indexing within the owning
     * metadata context, so identical numeric indexes from different meta boxes
     * must remain separate.
     *
     * @param array<int, array<int, IsoBmffDataReference>> $existing      Previously merged data references.
     * @param int                                          $contextOffset Absolute file offset of the owning meta box.
     * @param array<int, IsoBmffDataReference>             $incoming      Incoming data references to merge.
     *
     * @return array<int, array<int, IsoBmffDataReference>>
     */
    public function mergeDataReferencesByContext(array $existing, int $contextOffset, array $incoming): array
    {
        if ($incoming === []) {
            return $existing;
        }

        if (!isset($existing[$contextOffset])) {
            $existing[$contextOffset] = [];
        }

        foreach ($incoming as $index => $reference) {
            $existing[$contextOffset][$index] = $reference;
        }

        return $existing;
    }

    /**
     * Merges ISO BMFF item reference mappings.
     *
     * @param array<int, list<IsoBmffItemReference>> $existing
     * @param array<int, list<IsoBmffItemReference>> $incoming
     *
     * @return array<int, list<IsoBmffItemReference>>
     */
    public static function mergeItemReferences(array $existing, array $incoming): array
    {
        foreach ($incoming as $fromId => $references) {
            if (!isset($existing[$fromId])) {
                $existing[$fromId] = $references;
                continue;
            }

            foreach ($references as $reference) {
                $existing[$fromId][] = $reference;
            }
        }

        return $existing;
    }

    /**
     * Merges ISO BMFF data reference mappings.
     *
     * @param array<int, IsoBmffDataReference> $existing
     * @param array<int, IsoBmffDataReference> $incoming
     *
     * @return array<int, IsoBmffDataReference>
     */
    public static function mergeDataReferences(array $existing, array $incoming): array
    {
        foreach ($incoming as $index => $reference) {
            $existing[$index] = $reference;
        }

        return $existing;
    }
}
