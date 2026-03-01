<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use Closure;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemResolveResult;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function rtrim;
use function sprintf;
use function strcasecmp;
use function strlen;
use function strtolower;
use function substr;

/**
 * Resolves item payloads described by iloc extent structures.
 *
 * Handles reading extent data from the raw stream, idat payloads,
 * and item-offset construction methods per ISO/IEC 14496-12 §8.11.3.
 *
 * @phpstan-type ExtentErrorCodes = array{
 *     negativeOffset:   int,
 *     offsetOverflow:   int,
 *     originOverflow:   int,
 *     outsideContainer: int,
 *     payloadLimit:     int,
 *     lengthExceeds:    int,
 *     extentOutside:    int,
 * }
 */
final readonly class ItemPayloadResolver
{
    /**
     * @param Stream       $stream             Stream positioned at the beginning of the media file to parse.
     * @param BoxNavigator $boxNavigator       Shared box navigation infrastructure.
     * @param int          $maxItemPayloadSize Maximum cumulative payload size in bytes.
     */
    public function __construct(
        private Stream $stream,
        private BoxNavigator $boxNavigator,
        private int $maxItemPayloadSize,
    ) {
    }

    /**
     * Resolves metadata item references described by an `iloc` box.
     *
     * @param int                                                                                                                                                                           $itemId            Identifier of the item to resolve.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations         Item location metadata.
     * @param array<int, list<IsoBmffItemReference>>                                                                                                                                        $itemReferences    Parsed item references.
     * @param array<int, IsoBmffDataReference>                                                                                                                                              $dataReferences    Parsed data references.
     * @param ?string                                                                                                                                                                       $idatPayload       Cached idat payload for construction_method=1 extents.
     * @param int                                                                                                                                                                           $metaContextOffset Absolute file offset of the owning meta box.
     * @param list<int>                                                                                                                                                                     $visitedItemIds    Item IDs already visited for cycle detection.
     *
     * @return IsoBmffItemResolveResult Resolved payload data and any unresolved item descriptors.
     */
    public function resolveItemData(int $itemId, array $locations, array $itemReferences, array $dataReferences, ?string $idatPayload, int $metaContextOffset, array $visitedItemIds = []): IsoBmffItemResolveResult
    {
        if (!isset($locations[$itemId])) {
            return new IsoBmffItemResolveResult(null, []);
        }

        $location = $locations[$itemId];
        if (in_array($itemId, $visitedItemIds, true)) {
            return new IsoBmffItemResolveResult(null, [$this->createUnresolvedItem($itemId, $location, $dataReferences, $metaContextOffset)]);
        }

        if ($location['constructionMethod'] === ConstructionMethod::FileOffset) {
            return $this->resolveFileOffsetItemData($itemId, $location, $dataReferences, $metaContextOffset);
        }

        if ($location['constructionMethod'] === ConstructionMethod::IdatOffset) {
            return $this->resolveIdatOffsetItemData($itemId, $location, $dataReferences, $idatPayload, $metaContextOffset);
        }

        return $this->resolveItemOffsetItemData(
            $itemId,
            $location,
            $locations,
            $itemReferences,
            $dataReferences,
            $idatPayload,
            $metaContextOffset,
            $visitedItemIds,
        );
    }

    /**
     * Resolves method-0 file_offset items against the primary file stream.
     *
     * @param int                                                                                                                                                               $itemId            Identifier of the item being resolved.
     * @param array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>} $location          Item location metadata.
     * @param array<int, IsoBmffDataReference>                                                                                                                                  $dataReferences    Parsed data references.
     * @param int                                                                                                                                                               $metaContextOffset Absolute file offset of the owning meta box.
     *
     * @return IsoBmffItemResolveResult Resolved file-offset payload or unresolved item descriptor.
     */
    private function resolveFileOffsetItemData(int $itemId, array $location, array $dataReferences, int $metaContextOffset): IsoBmffItemResolveResult
    {
        // data_reference_index gating applies only to file_offset (method 0).
        // ISO/IEC 14496-12 §8.11.3.2: methods 1 and 2 do not use data_reference_index.
        if ($location['dataReferenceIndex'] !== 0) {
            return new IsoBmffItemResolveResult(null, [$this->createUnresolvedItem($itemId, $location, $dataReferences, $metaContextOffset)]);
        }

        $blob = $this->walkLinearExtents(
            $location['extents'],
            $location['baseOffset'],
            $location['fileOffsetOrigin'],
            $this->stream->size(),
            fn (int $offset, int $length): string => $this->boxNavigator->readAll($this->stream->window($offset, $length)),
            'iloc extent outside file',
            'iloc extent length exceeds file size',
            [
                'negativeOffset'   => 1180,
                'offsetOverflow'   => 1181,
                'originOverflow'   => 1874,
                'outsideContainer' => 1182,
                'payloadLimit'     => 1178,
                'lengthExceeds'    => 1179,
                'extentOutside'    => 1877,
            ],
        );

        return new IsoBmffItemResolveResult($blob === '' ? null : $blob, []);
    }

    /**
     * Resolves method-1 idat_offset items against the idat payload.
     *
     * @param int                                                                                                                                                               $itemId            Identifier of the item being resolved.
     * @param array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>} $location          Item location metadata.
     * @param array<int, IsoBmffDataReference>                                                                                                                                  $dataReferences    Parsed data references.
     * @param ?string                                                                                                                                                           $idatPayload       Cached idat payload bytes.
     * @param int                                                                                                                                                               $metaContextOffset Absolute file offset of the owning meta box.
     *
     * @return IsoBmffItemResolveResult Resolved idat-offset payload or unresolved item descriptor.
     */
    private function resolveIdatOffsetItemData(int $itemId, array $location, array $dataReferences, ?string $idatPayload, int $metaContextOffset): IsoBmffItemResolveResult
    {
        if ($idatPayload === null) {
            return new IsoBmffItemResolveResult(null, [$this->createUnresolvedItem($itemId, $location, $dataReferences, $metaContextOffset)]);
        }

        // ISO/IEC 14496-12 §8.11.3.2 defines construction_method=1 offsets as idat-relative.
        $blob = $this->walkLinearExtents(
            $location['extents'],
            $location['baseOffset'],
            0,
            strlen($idatPayload),
            static fn (int $offset, int $length): string => substr($idatPayload, $offset, $length),
            'iloc extent outside idat payload',
            'iloc extent length exceeds idat payload',
            [
                'negativeOffset'   => 1185,
                'offsetOverflow'   => 1186,
                'originOverflow'   => 0,
                'outsideContainer' => 1187,
                'payloadLimit'     => 1183,
                'lengthExceeds'    => 1184,
                'extentOutside'    => 1880,
            ],
        );

        return new IsoBmffItemResolveResult($blob === '' ? null : $blob, []);
    }

    /**
     * Resolves method-2 item_offset items via iloc item references.
     *
     * @param int                                                                                                                                                                           $itemId            Identifier of the item being resolved.
     * @param array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}             $location          Item location metadata.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations         All item locations.
     * @param array<int, list<IsoBmffItemReference>>                                                                                                                                        $itemReferences    Parsed item references.
     * @param array<int, IsoBmffDataReference>                                                                                                                                              $dataReferences    Parsed data references.
     * @param ?string                                                                                                                                                                       $idatPayload       Cached idat payload bytes.
     * @param int                                                                                                                                                                           $metaContextOffset Absolute file offset of the owning meta box.
     * @param list<int>                                                                                                                                                                     $visitedItemIds    Item IDs already visited for cycle detection.
     *
     * @return IsoBmffItemResolveResult Resolved item-offset payload or unresolved item descriptor.
     */
    private function resolveItemOffsetItemData(
        int $itemId,
        array $location,
        array $locations,
        array $itemReferences,
        array $dataReferences,
        ?string $idatPayload,
        int $metaContextOffset,
        array $visitedItemIds,
    ): IsoBmffItemResolveResult {
        // ISO/IEC 14496-12 §8.11.3.2 — only 'iloc' references are
        // valid lookup targets for item-offset construction.
        $allRefs    = $itemReferences[$itemId] ?? [];
        $references = array_values(array_filter(
            $allRefs,
            static fn (IsoBmffItemReference $ref): bool => $ref->relation === 'iloc',
        ));

        if ($references === []) {
            return new IsoBmffItemResolveResult(null, [$this->createUnresolvedItem($itemId, $location, $dataReferences, $metaContextOffset)]);
        }

        // ISO/IEC 14496-12 §8.11.3.2 ties construction_method=2 extents to item references.
        $blob            = '';
        $total           = 0;
        $unresolvedItems = [];
        $extentCount     = count($location['extents']);
        foreach ($location['extents'] as $extent) {
            $length = $extent['length'];

            if ($length > $this->maxItemPayloadSize - $total) {
                throw new ParseError('iloc item payload exceeds configured limit', 1188);
            }

            $extentIndex       = $extent['index'];
            $referencePosition = $this->resolveItemOffsetReferencePosition($extentIndex);

            if (!isset($references[$referencePosition])) {
                throw new ParseError(sprintf(
                    'iloc extent_index %d out of range for %d references',
                    $extentIndex ?? 1,
                    count($references),
                ), 1607);
            }

            $referenceItemId = $references[$referencePosition]->toItemId;
            $result          = $this->resolveReferencedItemData(
                $itemId,
                $referenceItemId,
                $location,
                $locations,
                $itemReferences,
                $dataReferences,
                $idatPayload,
                $metaContextOffset,
                $visitedItemIds,
            );
            $unresolvedItems = [...$unresolvedItems, ...$result->unresolvedItems];

            if ($result->data === null) {
                return new IsoBmffItemResolveResult(null, $unresolvedItems);
            }

            $referenceData = $result->data;
            $referenceSize = strlen($referenceData);
            $offset        = $this->computeSafeOffset($location['baseOffset'], $extent['offset'], 0, 1189, 1190, 0);

            // Implied extent_length semantics for single-extent items
            if ($length === 0) {
                if ($extentCount !== 1) {
                    continue;
                }

                if ($offset > $referenceSize) {
                    throw new ParseError('iloc extent outside referenced item', 1191);
                }

                $length = $referenceSize - $offset;

                if ($length > $this->maxItemPayloadSize - $total) {
                    throw new ParseError('iloc item payload exceeds configured limit', 1881);
                }
            }

            if (($length > $referenceSize) || ($offset > ($referenceSize - $length))) {
                throw new ParseError('iloc extent outside referenced item', 1882);
            }

            $blob .= substr($referenceData, $offset, $length);
            $total += $length;
        }

        return new IsoBmffItemResolveResult($blob === '' ? null : $blob, $unresolvedItems);
    }

    /**
     * Resolves a referenced item for construction_method=2 while preserving cycle/unresolved semantics.
     *
     * @param int                                                                                                                                                                           $itemId            Identifier of the referring item.
     * @param int                                                                                                                                                                           $referenceItemId   Identifier of the referenced item to resolve.
     * @param array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}             $location          Location metadata for the referring item.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations         All item locations.
     * @param array<int, list<IsoBmffItemReference>>                                                                                                                                        $itemReferences    Parsed item references.
     * @param array<int, IsoBmffDataReference>                                                                                                                                              $dataReferences    Parsed data references.
     * @param ?string                                                                                                                                                                       $idatPayload       Cached idat payload bytes.
     * @param int                                                                                                                                                                           $metaContextOffset Absolute file offset of the owning meta box.
     * @param list<int>                                                                                                                                                                     $visitedItemIds    Item IDs already visited for cycle detection.
     *
     * @return IsoBmffItemResolveResult Resolved referenced item payload or unresolved item descriptor.
     */
    private function resolveReferencedItemData(
        int $itemId,
        int $referenceItemId,
        array $location,
        array $locations,
        array $itemReferences,
        array $dataReferences,
        ?string $idatPayload,
        int $metaContextOffset,
        array $visitedItemIds,
    ): IsoBmffItemResolveResult {
        $nextVisited   = $visitedItemIds;
        $nextVisited[] = $itemId;
        if (in_array($referenceItemId, $nextVisited, true)) {
            return new IsoBmffItemResolveResult(null, [$this->createUnresolvedItem($itemId, $location, $dataReferences, $metaContextOffset)]);
        }

        $result = $this->resolveItemData(
            $referenceItemId,
            $locations,
            $itemReferences,
            $dataReferences,
            $idatPayload,
            $metaContextOffset,
            $nextVisited,
        );

        if ($result->data !== null) {
            return $result;
        }

        $unresolvedItems   = $result->unresolvedItems;
        $unresolvedItems[] = $this->createUnresolvedItem($itemId, $location, $dataReferences, $metaContextOffset);

        return new IsoBmffItemResolveResult(null, $unresolvedItems);
    }

    /**
     * Maps iloc extent_index values to zero-based reference positions.
     *
     * @param ?int $extentIndex One-based extent index or null when index_size is zero.
     *
     * @return int Zero-based reference position.
     */
    private function resolveItemOffsetReferencePosition(?int $extentIndex): int
    {
        if ($extentIndex === null) {
            // ISO/IEC 14496-12 §8.11.3.2: when index_size==0, extent_index=1 is implied.
            return 0;
        }

        // ISO/IEC 14496-12 §8.11.3.2: extent_index is 1-based.
        return $extentIndex - 1;
    }

    /**
     * Strips HEIF/HEIC Exif offset prefix and redundant signatures so downstream parsers accept the blob.
     *
     * ISO 14496-12 / HEIF: Exif items in ISO BMFF containers start with a 4-byte
     * unsigned integer indicating the offset to the TIFF header (relative to the
     * end of this 4-byte field). This offset typically skips an "Exif\0\0" signature.
     *
     * @param string $blob Raw EXIF payload from ISO BMFF container.
     *
     * @return string EXIF payload trimmed to the TIFF header.
     */
    public function normalizeExifBlob(string $blob): string
    {
        return PayloadGuard::normalizeExifBlob($blob, 'Exif item', 1394, 1395, 1899);
    }

    /**
     * Determines whether the given item descriptor represents EXIF content.
     *
     * @param array{id: int, itemType: ?string, name: ?string, contentType: ?string} $info Item descriptor to check.
     *
     * @return bool True when the descriptor advertises EXIF content, otherwise false.
     */
    public function isExifItem(array $info): bool
    {
        $itemType = $info['itemType'] ?? null;
        if (is_string($itemType) && (strcasecmp($itemType, BoxType::EXIF->value) === 0)) {
            return true;
        }

        $name = $info['name'] ?? null;
        if (is_string($name) && (strcasecmp($name, BoxType::EXIF->value) === 0)) {
            return true;
        }

        $contentType = $info['contentType'] ?? null;
        if (is_string($contentType)) {
            $ct = strtolower($contentType);

            return $ct === 'application/exif' || $ct === 'image/tiff';
        }

        return false;
    }

    /**
     * Determines whether the given item descriptor represents XMP content.
     *
     * @param array{id: int, itemType: ?string, name: ?string, contentType: ?string} $info Item descriptor to check.
     *
     * @return bool True when the descriptor advertises XMP content, otherwise false.
     */
    public function isXmpItem(array $info): bool
    {
        $itemType = $info['itemType'] ?? null;
        if (is_string($itemType)) {
            // EXIF 3.0 Annex A.2.3 allows explicit XMP item typing in the item_type field.
            $normalizedType = strtolower(rtrim($itemType, " \0"));
            if ($normalizedType === 'xmp') {
                return true;
            }
        }

        $contentType = $info['contentType'] ?? null;
        if (!is_string($contentType)) {
            return false;
        }

        return strtolower($contentType) === 'application/rdf+xml';
    }

    /**
     * Determines whether the given item descriptor represents a tone map (tmap) item.
     *
     * @param array{id: int, itemType: ?string, name: ?string, contentType: ?string} $info Item descriptor to check.
     *
     * @return bool True when the descriptor advertises a tmap item, otherwise false.
     */
    public function isTmapItem(array $info): bool
    {
        $itemType = $info['itemType'] ?? null;

        return is_string($itemType) && (strcasecmp($itemType, 'tmap') === 0);
    }

    /**
     * Walks extents for construction methods with a fixed-size container (file or idat).
     *
     * Handles implied extent_length semantics, payload size limits, container bounds
     * validation, and safe offset arithmetic per ISO/IEC 14496-12 §8.11.3.
     *
     * @param array<int, array<string, int|null>> $extents        Extent definitions from the iloc entry.
     * @param int                                 $baseOffset     Base offset from the iloc entry.
     * @param int                                 $originOffset   File offset origin for the extent calculations.
     * @param int                                 $containerSize  Total size of the data container in bytes.
     * @param Closure(int, int): string           $readData       Reads $length bytes at $offset from the container.
     * @param string                              $outsideMessage Error message when an extent falls outside the container.
     * @param string                              $lengthMessage  Error message when extent length exceeds container size.
     * @param array<string, int>                  $errorCodes     Per-construction-method error codes for each validation step.
     *
     * @phpstan-param list<array{offset:int,length:int,index:?int}> $extents
     * @phpstan-param ExtentErrorCodes                              $errorCodes
     *
     * @return string Concatenated extent data read from the container.
     */
    private function walkLinearExtents(
        array $extents,
        int $baseOffset,
        int $originOffset,
        int $containerSize,
        Closure $readData,
        string $outsideMessage,
        string $lengthMessage,
        array $errorCodes,
    ): string {
        $blob        = '';
        $total       = 0;
        $extentCount = count($extents);

        foreach ($extents as $extent) {
            $length = $extent['length'];
            $offset = $this->computeSafeOffset(
                $baseOffset,
                $extent['offset'],
                $originOffset,
                $errorCodes['negativeOffset'],
                $errorCodes['offsetOverflow'],
                $errorCodes['originOverflow'],
            );

            // Implied extent_length semantics for single-extent items
            if ($length === 0) {
                if ($extentCount !== 1) {
                    continue;
                }

                if ($offset > $containerSize) {
                    throw new ParseError($outsideMessage, $errorCodes['outsideContainer']);
                }

                $length = $containerSize - $offset;
            }

            if ($length > $this->maxItemPayloadSize - $total) {
                throw new ParseError('iloc item payload exceeds configured limit', $errorCodes['payloadLimit']);
            }

            if (($total > $containerSize) || ($length > ($containerSize - $total))) {
                throw new ParseError($lengthMessage, $errorCodes['lengthExceeds']);
            }

            $total += $length;

            if (($length > $containerSize) || ($offset > ($containerSize - $length))) {
                throw new ParseError($outsideMessage, $errorCodes['extentOutside']);
            }

            $blob .= $readData($offset, $length);
        }

        return $blob;
    }

    /**
     * Computes a safe effective offset from base, extent, and optional origin components.
     *
     * Validates all components are non-negative and checks for integer overflow
     * at each addition step.
     *
     * @param int $baseOffset         Base offset component.
     * @param int $extentOffset       Extent offset component.
     * @param int $originOffset       Origin offset component.
     * @param int $negativeCode       Error code for negative offset components.
     * @param int $overflowCode       Error code for base+extent overflow.
     * @param int $originOverflowCode Error code for +origin overflow (unused when $originOffset is 0).
     *
     * @return int Computed effective offset.
     */
    private function computeSafeOffset(
        int $baseOffset,
        int $extentOffset,
        int $originOffset,
        int $negativeCode,
        int $overflowCode,
        int $originOverflowCode,
    ): int {
        if ($baseOffset < 0 || $extentOffset < 0 || $originOffset < 0) {
            throw new ParseError('iloc negative offset', $negativeCode);
        }

        if ($baseOffset > PHP_INT_MAX - $extentOffset) {
            throw new ParseError('iloc offset overflow', $overflowCode);
        }

        $offset = $baseOffset + $extentOffset;

        if ($originOffset !== 0) {
            if ($originOffset > PHP_INT_MAX - $offset) {
                throw new ParseError('iloc offset overflow', $originOverflowCode);
            }

            $offset += $originOffset;
        }

        return $offset;
    }

    /**
     * Creates an unresolved item descriptor for external references.
     *
     * @param int                                                                                                                                                               $itemId            Identifier of the unresolved item.
     * @param array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>} $location          Item location metadata.
     * @param array<int, IsoBmffDataReference>                                                                                                                                  $dataReferences    Parsed data references.
     * @param int                                                                                                                                                               $metaContextOffset Absolute file offset of the owning meta box.
     *
     * @return IsoBmffUnresolvedItem Unresolved item descriptor for deferred resolution.
     */
    private function createUnresolvedItem(int $itemId, array $location, array $dataReferences, int $metaContextOffset): IsoBmffUnresolvedItem
    {
        $dataReference      = null;
        $dataReferenceIndex = $location['dataReferenceIndex'];
        if ($dataReferenceIndex > 0) {
            if (!isset($dataReferences[$dataReferenceIndex])) {
                throw new ParseError(sprintf('iloc data_reference_index %d out of range', $dataReferenceIndex), 1497);
            }

            $dataReference = $dataReferences[$dataReferenceIndex];
        }

        return new IsoBmffUnresolvedItem(
            $itemId,
            $dataReferenceIndex,
            $location['constructionMethod'],
            $dataReference,
            $metaContextOffset,
        );
    }
}
