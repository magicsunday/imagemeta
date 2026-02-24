<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
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
 * and item-offset construction methods.
 */
final readonly class ItemPayloadResolver
{
    /**
     * @param Stream       $stream       Stream positioned at the beginning of the media file to parse.
     * @param BoxNavigator $boxNavigator Shared box navigation infrastructure.
     */
    public function __construct(
        private Stream $stream,
        private BoxNavigator $boxNavigator,
    ) {
    }

    /**
     * Resolves metadata item references described by an `iloc` box.
     *
     * @param int                                                                                                                                                            $itemId            Identifier of the item to resolve.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations
     * @param array<int, list<IsoBmffItemReference>>                                                                                                                         $itemReferences
     * @param array<int, IsoBmffDataReference>                                                                                                                               $dataReferences
     * @param string|null                                                                                                                                                    $idatPayload
     * @param list<IsoBmffUnresolvedItem>                                                                                                                                    $unresolvedItems
     * @param int                                                                                                                                                            $metaContextOffset
     * @param list<int>                                                                                                                                                      $visitedItemIds
     *
     * @return string|null
     */
    public function resolveItemData(int $itemId, array $locations, array $itemReferences, array $dataReferences, ?string $idatPayload, array &$unresolvedItems, int $metaContextOffset, array $visitedItemIds = []): ?string
    {
        if (!isset($locations[$itemId])) {
            return null;
        }

        $location = $locations[$itemId];
        if (in_array($itemId, $visitedItemIds, true)) {
            $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems, $metaContextOffset);

            return null;
        }

        if ($location['constructionMethod'] === ConstructionMethod::FILE_OFFSET->value) {
            // data_reference_index gating applies only to file_offset (method 0).
            // ISO/IEC 14496-12 §8.11.3.2: methods 1 and 2 do not use data_reference_index.
            if ($location['dataReferenceIndex'] !== 0) {
                $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems, $metaContextOffset);

                return null;
            }

            $blob        = '';
            $total       = 0;
            $fileSize    = $this->stream->size();
            $extentCount = count($location['extents']);
            foreach ($location['extents'] as $extent) {
                $length = $extent['length'];

                // Implied extent_length semantics for single-extent items
                if ($length === 0) {
                    if ($extentCount !== 1) {
                        continue;
                    }

                    $baseOffset   = $location['baseOffset'];
                    $extentOffset = $extent['offset'];
                    $originOffset = $location['fileOffsetOrigin'];
                    if ($baseOffset < 0 || $extentOffset < 0 || $originOffset < 0) {
                        throw new ParseError('iloc negative offset', 1180);
                    }

                    if ($baseOffset > PHP_INT_MAX - $extentOffset) {
                        throw new ParseError('iloc offset overflow', 1181);
                    }

                    $effectiveOffset = $baseOffset + $extentOffset;
                    if ($originOffset > PHP_INT_MAX - $effectiveOffset) {
                        throw new ParseError('iloc offset overflow', 1181);
                    }

                    $effectiveOffset += $originOffset;
                    if ($effectiveOffset > $fileSize) {
                        throw new ParseError('iloc extent outside file', 1182);
                    }

                    $length = $fileSize - $effectiveOffset;
                }

                if ($length > IsoBmffParser::MAX_ITEM_PAYLOAD_SIZE - $total) {
                    throw new ParseError('iloc item payload exceeds configured limit', 1178);
                }

                if (($total > $fileSize) || ($length > ($fileSize - $total))) {
                    throw new ParseError('iloc extent length exceeds file size', 1179);
                }

                $total += $length;

                $baseOffset   = $location['baseOffset'];
                $extentOffset = $extent['offset'];
                $originOffset = $location['fileOffsetOrigin'];
                if ($baseOffset < 0 || $extentOffset < 0 || $originOffset < 0) {
                    throw new ParseError('iloc negative offset', 1180);
                }

                if ($baseOffset > PHP_INT_MAX - $extentOffset) {
                    throw new ParseError('iloc offset overflow', 1181);
                }

                $offset = $baseOffset + $extentOffset;
                if ($originOffset > PHP_INT_MAX - $offset) {
                    throw new ParseError('iloc offset overflow', 1181);
                }

                $offset += $originOffset;
                if (($length > $fileSize) || ($offset > ($fileSize - $length))) {
                    throw new ParseError('iloc extent outside file', 1182);
                }

                $blob .= $this->boxNavigator->readAll($this->stream->window($offset, $length));
            }

            return $blob === '' ? null : $blob;
        }

        if ($location['constructionMethod'] === ConstructionMethod::IDAT_OFFSET->value) {
            if ($idatPayload === null) {
                $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems, $metaContextOffset);

                return null;
            }

            // ISO/IEC 14496-12 §8.11.3.2 defines construction_method=1 offsets as idat-relative.
            $blob        = '';
            $total       = 0;
            $idatSize    = strlen($idatPayload);
            $extentCount = count($location['extents']);
            foreach ($location['extents'] as $extent) {
                $length = $extent['length'];

                // Implied extent_length semantics for single-extent items
                if ($length === 0) {
                    if ($extentCount !== 1) {
                        continue;
                    }

                    $baseOffset   = $location['baseOffset'];
                    $extentOffset = $extent['offset'];
                    if ($baseOffset < 0 || $extentOffset < 0) {
                        throw new ParseError('iloc negative offset', 1185);
                    }

                    if ($baseOffset > PHP_INT_MAX - $extentOffset) {
                        throw new ParseError('iloc offset overflow', 1186);
                    }

                    $effectiveOffset = $baseOffset + $extentOffset;
                    if ($effectiveOffset > $idatSize) {
                        throw new ParseError('iloc extent outside idat payload', 1187);
                    }

                    $length = $idatSize - $effectiveOffset;
                }

                if ($length > IsoBmffParser::MAX_ITEM_PAYLOAD_SIZE - $total) {
                    throw new ParseError('iloc item payload exceeds configured limit', 1183);
                }

                if (($total > $idatSize) || ($length > ($idatSize - $total))) {
                    throw new ParseError('iloc extent length exceeds idat payload', 1184);
                }

                $total += $length;

                $baseOffset   = $location['baseOffset'];
                $extentOffset = $extent['offset'];
                if ($baseOffset < 0 || $extentOffset < 0) {
                    throw new ParseError('iloc negative offset', 1185);
                }

                if ($baseOffset > PHP_INT_MAX - $extentOffset) {
                    throw new ParseError('iloc offset overflow', 1186);
                }

                $offset = $baseOffset + $extentOffset;
                if (($length > $idatSize) || ($offset > ($idatSize - $length))) {
                    throw new ParseError('iloc extent outside idat payload', 1187);
                }

                $blob .= substr($idatPayload, $offset, $length);
            }

            return $blob === '' ? null : $blob;
        }

        if ($location['constructionMethod'] === ConstructionMethod::ITEM_OFFSET->value) {
            // ISO/IEC 14496-12 §8.11.3.2 — only 'iloc' references are
            // valid lookup targets for item-offset construction.
            $allRefs    = $itemReferences[$itemId] ?? [];
            $references = array_values(array_filter(
                $allRefs,
                static fn (IsoBmffItemReference $ref): bool => $ref->relation === 'iloc',
            ));

            if ($references === []) {
                $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems, $metaContextOffset);

                return null;
            }

            // ISO/IEC 14496-12 §8.11.3.2 ties construction_method=2 extents to item references.
            $blob        = '';
            $total       = 0;
            $extentCount = count($location['extents']);
            foreach ($location['extents'] as $extent) {
                $length = $extent['length'];

                if ($length > IsoBmffParser::MAX_ITEM_PAYLOAD_SIZE - $total) {
                    throw new ParseError('iloc item payload exceeds configured limit', 1188);
                }

                $extentIndex = $extent['index'];
                if ($extentIndex === null) {
                    // ISO/IEC 14496-12 §8.11.3.2: when index_size==0, extent_index=1 is implied.
                    $referencePosition = 0;
                } else {
                    // ISO/IEC 14496-12 §8.11.3.2: extent_index is 1-based.
                    $referencePosition = $extentIndex - 1;
                }

                if (!isset($references[$referencePosition])) {
                    throw new ParseError(sprintf(
                        'iloc extent_index %d out of range for %d references',
                        $extentIndex ?? 1,
                        count($references),
                    ), 1607);
                }

                $referenceItemId = $references[$referencePosition]->toItemId;
                $nextVisited     = $visitedItemIds;
                $nextVisited[]   = $itemId;
                if (in_array($referenceItemId, $nextVisited, true)) {
                    $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems, $metaContextOffset);

                    return null;
                }

                $referenceData = $this->resolveItemData($referenceItemId, $locations, $itemReferences, $dataReferences, $idatPayload, $unresolvedItems, $metaContextOffset, $nextVisited);
                if ($referenceData === null) {
                    $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems, $metaContextOffset);

                    return null;
                }

                $referenceSize = strlen($referenceData);
                $baseOffset    = $location['baseOffset'];
                $extentOffset  = $extent['offset'];
                if ($baseOffset < 0 || $extentOffset < 0) {
                    throw new ParseError('iloc negative offset', 1189);
                }

                if ($baseOffset > PHP_INT_MAX - $extentOffset) {
                    throw new ParseError('iloc offset overflow', 1190);
                }

                $offset = $baseOffset + $extentOffset;

                // Implied extent_length semantics for single-extent items
                if ($length === 0) {
                    if ($extentCount !== 1) {
                        continue;
                    }

                    if ($offset > $referenceSize) {
                        throw new ParseError('iloc extent outside referenced item', 1191);
                    }

                    $length = $referenceSize - $offset;

                    if ($length > IsoBmffParser::MAX_ITEM_PAYLOAD_SIZE - $total) {
                        throw new ParseError('iloc item payload exceeds configured limit', 1188);
                    }
                }

                if (($length > $referenceSize) || ($offset > ($referenceSize - $length))) {
                    throw new ParseError('iloc extent outside referenced item', 1191);
                }

                $blob .= substr($referenceData, $offset, $length);
                $total += $length;
            }

            return $blob === '' ? null : $blob;
        }

        $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems, $metaContextOffset);

        return null;
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
        // Strict 4-byte TIFF-header offset validation
        if (strlen($blob) < 4) {
            throw new ParseError('Exif item payload too short for TIFF-header offset prefix', 1394);
        }

        // ISO 14496-12: Exif items start with a 4-byte big-endian offset to the TIFF header
        $offset = Unpack::int('N', substr($blob, 0, 4), 'Exif item TIFF-header offset');

        // Validate the offset does not exceed the payload bounds
        if ($offset < 0 || (4 + $offset + 2) > strlen($blob)) {
            throw new ParseError('Exif item TIFF-header offset out of range', 1395);
        }

        // Validate the data at the pointed offset starts with a valid TIFF header (II or MM)
        $tiffSig = substr($blob, 4 + $offset, 2);
        if ($tiffSig !== 'II' && $tiffSig !== 'MM') {
            throw new ParseError('Exif item TIFF-header offset does not point to valid TIFF signature', 1395);
        }

        return substr($blob, 4 + $offset);
    }

    /**
     * Determines whether the given item descriptor represents EXIF content.
     *
     * @param array{id: int, itemType: ?string, name: ?string, contentType: ?string} $info
     */
    public function isExifItem(array $info): bool
    {
        $itemType = $info['itemType'] ?? null;
        if (is_string($itemType) && strcasecmp($itemType, BoxType::EXIF->value) === 0) {
            return true;
        }

        $name = $info['name'] ?? null;
        if (is_string($name) && strcasecmp($name, BoxType::EXIF->value) === 0) {
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
     * @param array{id: int, itemType: ?string, name: ?string, contentType: ?string} $info
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
     * Records an unresolved item payload for external references.
     *
     * @param int                                                                                                                                                $itemId
     * @param array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>} $location
     * @param array<int, IsoBmffDataReference>                                                                                                                   $dataReferences
     * @param list<IsoBmffUnresolvedItem>                                                                                                                        $unresolvedItems
     * @param int                                                                                                                                                $metaContextOffset
     */
    private function registerUnresolvedItem(int $itemId, array $location, array $dataReferences, array &$unresolvedItems, int $metaContextOffset): void
    {
        $dataReference      = null;
        $dataReferenceIndex = $location['dataReferenceIndex'];
        if ($dataReferenceIndex > 0) {
            if (!isset($dataReferences[$dataReferenceIndex])) {
                throw new ParseError(sprintf('iloc data_reference_index %d out of range', $dataReferenceIndex), 1497);
            }

            $dataReference = $dataReferences[$dataReferenceIndex];
        }

        $constructionMethod = ConstructionMethod::tryFrom($location['constructionMethod']);

        $unresolvedItems[] = new IsoBmffUnresolvedItem(
            $itemId,
            $dataReferenceIndex,
            $constructionMethod,
            $dataReference,
            $metaContextOffset,
        );
    }
}
