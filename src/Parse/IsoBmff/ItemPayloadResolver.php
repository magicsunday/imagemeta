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
use MagicSunday\ImageMeta\Core\Util\Unpack;
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
 * @phpstan-type ExtentErrorCodes array{
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
     * @param int                                                                                                                                                                           $itemId         Identifier of the item to resolve.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations
     * @param array<int, list<IsoBmffItemReference>>                                                                                                                                        $itemReferences
     * @param array<int, IsoBmffDataReference>                                                                                                                                              $dataReferences
     * @param list<int>                                                                                                                                                                     $visitedItemIds
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

        if ($location['constructionMethod'] === ConstructionMethod::IdatOffset) {
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

        // ConstructionMethod::ItemOffset
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
                $unresolvedItems[] = $this->createUnresolvedItem($itemId, $location, $dataReferences, $metaContextOffset);

                return new IsoBmffItemResolveResult(null, $unresolvedItems);
            }

            $result          = $this->resolveItemData($referenceItemId, $locations, $itemReferences, $dataReferences, $idatPayload, $metaContextOffset, $nextVisited);
            $unresolvedItems = [...$unresolvedItems, ...$result->unresolvedItems];

            if ($result->data === null) {
                $unresolvedItems[] = $this->createUnresolvedItem($itemId, $location, $dataReferences, $metaContextOffset);

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
        PayloadGuard::ensureMinimumLength($blob, 4, 'Exif item payload', 1394);

        // ISO 14496-12: Exif items start with a 4-byte big-endian offset to the TIFF header
        $offset = Unpack::int('N', substr($blob, 0, 4), 'Exif item TIFF-header offset');

        // Validate the offset does not exceed the payload bounds
        if ($offset < 0 || (4 + $offset + 2) > strlen($blob)) {
            throw new ParseError('Exif item TIFF-header offset out of range', 1395);
        }

        // Validate the data at the pointed offset starts with a valid TIFF header (II or MM)
        $tiffSig = substr($blob, 4 + $offset, 2);
        if ($tiffSig !== 'II' && $tiffSig !== 'MM') {
            throw new ParseError('Exif item TIFF-header offset does not point to valid TIFF signature', 1899);
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
     * Walks extents for construction methods with a fixed-size container (file or idat).
     *
     * Handles implied extent_length semantics, payload size limits, container bounds
     * validation, and safe offset arithmetic per ISO/IEC 14496-12 §8.11.3.
     *
     * @param list<array{offset:int,length:int,index:?int}> $extents
     * @param Closure(int, int): string                     $readData       Reads $length bytes at $offset from the container.
     * @param ExtentErrorCodes                              $errorCodes     Per-construction-method error codes for each validation step.
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
     * @param int $negativeCode     Error code for negative offset components.
     * @param int $overflowCode     Error code for base+extent overflow.
     * @param int $originOverflowCode Error code for +origin overflow (unused when $originOffset is 0).
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
     * @param array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>} $location
     * @param array<int, IsoBmffDataReference>                                                                                                                                  $dataReferences
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
