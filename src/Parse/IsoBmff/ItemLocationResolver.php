<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;

use function array_filter;
use function array_key_exists;
use function array_unique;
use function array_unshift;
use function array_values;
use function bin2hex;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function mb_check_encoding;
use function preg_match;
use function rtrim;
use function sha1;
use function sprintf;
use function strcasecmp;
use function strlen;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function unpack;

/**
 * Resolves item locations and references within ISO BMFF metadata containers.
 *
 * Handles parsing of iloc, iinf, pitm, iref, dinf/dref boxes and resolving
 * item payloads described by those structures.
 */
final readonly class ItemLocationResolver
{
    /**
     * Maximum number of items allowed in an iloc box to prevent DoS attacks.
     */
    private const int MAX_ILOC_ITEMS = 10000;

    /**
     * Maximum number of extents per item in an iloc box to prevent DoS attacks.
     */
    private const int MAX_ILOC_EXTENTS = 10000;

    /**
     * Maximum number of entries in an iinf box to prevent DoS attacks.
     */
    private const int MAX_IINF_ENTRIES = 10000;

    /**
     * Maximum number of item references allowed per iref entry.
     */
    private const int MAX_IREF_REFERENCES = 10000;

    /**
     * Maximum number of reference entry boxes allowed in an iref box.
     */
    private const int MAX_IREF_ENTRIES = 10000;

    /**
     * Maximum number of data references allowed per dref entry.
     */
    private const int MAX_DREF_ENTRIES = 1000;

    /**
     * Initialises the resolver with the source stream that contains the ISO BMFF structure.
     *
     * @param Stream $stream Stream positioned at the beginning of the media file to parse.
     */
    public function __construct(
        private Stream $stream,
    ) {
    }

    /**
     * Gathers item IDs from item info structures, separated by primary status.
     *
     * @param array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}> $itemInfos     Item information structures.
     * @param int|null                                                                           $primaryItemId Primary item ID if known.
     *
     * @return array{0: list<int>, 1: list<int>} Tuple of [primary item IDs, other item IDs].
     */
    public function gatherItemIds(array $itemInfos, ?int $primaryItemId): array
    {
        $exifItemIds = [];
        $xmpItemIds  = [];

        // Collect item IDs that advertise EXIF/XMP payloads via their metadata descriptors.
        // EXIF 3.0 Annex A.2.3 maps item types and MIME hints that flag Exif or XMP payloads,
        // so we treat those descriptors as authoritative signals.
        foreach ($itemInfos as $info) {
            if ($this->isExifItem($info)) {
                $exifItemIds[] = $info['id'];
            }

            if ($this->isXmpItem($info)) {
                $xmpItemIds[] = $info['id'];
            }
        }

        // Deduplicate while preserving encounter order to avoid processing the same item twice.
        $exifItemIds = array_values(array_unique($exifItemIds));
        $xmpItemIds  = array_values(array_unique($xmpItemIds));

        if (
            ($primaryItemId !== null)
            && isset($itemInfos[$primaryItemId])
            && $this->isExifItem($itemInfos[$primaryItemId])
        ) {
            // EXIF 3.0 Annex A.2.5: pitm marks the default metadata item; prioritize
            // the primary item for EXIF candidate resolution when it is EXIF-typed.
            array_unshift($exifItemIds, $primaryItemId);
            $exifItemIds = array_values(array_unique($exifItemIds));
        }

        if (
            ($primaryItemId !== null)
            && isset($itemInfos[$primaryItemId])
            && $this->isXmpItem($itemInfos[$primaryItemId])
        ) {
            // ISO/IEC 14496-12 §8.11.4: pitm identifies the primary item, not its metadata type.
            // Prioritize only when item descriptors (EXIF 3.0 Annex A.2.3) explicitly mark XMP.
            array_unshift($xmpItemIds, $primaryItemId);
            $xmpItemIds = array_values(array_unique($xmpItemIds));
        }

        return [$exifItemIds, $xmpItemIds];
    }

    /**
     * Resolves queued item IDs to their payload data.
     *
     * @param list<int>                                                                                                                                                      $itemIds           Item IDs to resolve.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations         Item location metadata.
     * @param array<int, list<IsoBmffItemReference>>                                                                                                                         $itemReferences    Parsed item references for construction_method=2 extents.
     * @param (callable(string):string)|null                                                                                                                                 $transform         Optional transform function.
     * @param array<int, IsoBmffDataReference>                                                                                                                               $dataReferences    Parsed data references for the current meta box.
     * @param string|null                                                                                                                                                    $idatPayload       Cached idat payload for construction_method=1 extents.
     * @param list<IsoBmffUnresolvedItem>                                                                                                                                    $unresolvedItems   Accumulator for unresolved item payloads.
     * @param int                                                                                                                                                            $metaContextOffset Absolute file offset of the owning meta box.
     *
     * @return list<string> List of resolved item payloads.
     */
    public function resolveQueuedItems(array $itemIds, array $locations, array $itemReferences, ?callable $transform, array $dataReferences, ?string $idatPayload, array &$unresolvedItems, int $metaContextOffset): array
    {
        /** @var list<string> $resolved */
        $resolved = [];

        // Pull data for each referenced item and optionally transform the payload.
        foreach ($itemIds as $itemId) {
            $data = $this->resolveItemData($itemId, $locations, $itemReferences, $dataReferences, $idatPayload, $unresolvedItems, $metaContextOffset);
            if ($data !== null) {
                $resolved[] = $transform !== null ? $transform($data) : $data;
            }
        }

        return $resolved;
    }

    /**
     * Adds an XMP blob if it was not previously encountered.
     *
     * @param list<string>        $xmpBlobs
     * @param array<string, bool> $xmpHashes
     * @param string              $blob
     */
    public function appendUniqueXmp(array &$xmpBlobs, array &$xmpHashes, string $blob): void
    {
        $hash = sha1($blob);

        if (array_key_exists($hash, $xmpHashes)) {
            return;
        }

        $xmpHashes[$hash] = true;
        $xmpBlobs[]       = $blob;
    }

    /**
     * Merges ISO BMFF item reference mappings while preserving metadata context scope.
     *
     * ISO/IEC 14496-12 scopes iref item identifiers to their owning metadata
     * context, so identical numeric item IDs from separate meta boxes must not
     * be merged into one global bucket.
     *
     * @param array<int, array<int, list<IsoBmffItemReference>>> $existing
     * @param int                                                $contextOffset
     * @param array<int, list<IsoBmffItemReference>>             $incoming
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
     * @param array<int, array<int, IsoBmffDataReference>> $existing
     * @param int                                          $contextOffset
     * @param array<int, IsoBmffDataReference>             $incoming
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
    public function mergeItemReferences(array $existing, array $incoming): array
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
    public function mergeDataReferences(array $existing, array $incoming): array
    {
        foreach ($incoming as $index => $reference) {
            $existing[$index] = $reference;
        }

        return $existing;
    }

    /**
     * Parses the data information box (`dinf`) for data reference entries.
     *
     * ISO/IEC 14496-12 §8.7.1 defines the data information box and its
     * contained data reference box.
     *
     * @param BoxDescriptor $dinf Box descriptor representing the data information box.
     *
     * @return array<int, IsoBmffDataReference>
     */
    public function parseDinf(BoxDescriptor $dinf): array
    {
        $references = [];
        $drefCount  = 0;

        foreach ($this->walkChildren($dinf) as $child) {
            if ($child->type === BoxType::DREF->value) {
                ++$drefCount;

                if ($drefCount > 1) {
                    throw new ParseError('dinf must contain exactly one dref box', 1366);
                }

                $references = $this->mergeDataReferences($references, $this->parseDref($child));
            }
        }

        if ($drefCount === 0) {
            throw new ParseError('dinf must contain exactly one dref box', 1366);
        }

        return $references;
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
        $unpacked = @unpack('Noffset', substr($blob, 0, 4));
        if (!is_array($unpacked) || !isset($unpacked['offset']) || !is_int($unpacked['offset'])) {
            throw new ParseError('Exif item TIFF-header offset unreadable', 1395);
        }

        $offset = $unpacked['offset'];

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
     * Parses the item information box and returns descriptors for each entry.
     *
     * ISO/IEC 14496-12:2015 §8.11.6 defines `entry_count` as the number of
     * item information entries (`infe`) in the box.
     * EXIF 3.0 Annex A.2.2 defines the `iinf` container layout for Exif-in-ISO BMFF
     * metadata collections.
     *
     * @param BoxDescriptor $iinf Box descriptor containing the item information payload.
     *
     * @return list<array{id: int, itemType: ?string, name: ?string, contentType: ?string, contentEncoding: ?string, extensionType: ?string, itemUriType?: string, hidden: bool}>
     */
    public function parseIinf(BoxDescriptor $iinf): array
    {
        $win = $iinf->window;
        $win->seek(0);

        // FullBox header (4 bytes) + entry_count (2 for v0, 4 for v1)
        if ($iinf->contentSize < 6) {
            throw new ParseError('iinf box truncated', 1192);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported iinf box version', 1193);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported iinf box flags', 1194);
        }

        if ($version === 1 && $iinf->contentSize < 8) {
            throw new ParseError('iinf box truncated', 1195);
        }

        $entryCount = $version === 0 ? $win->readU16BE() : $win->readU32BE();

        if ($entryCount > self::MAX_IINF_ENTRIES) {
            throw new ParseError('iinf entry count exceeds maximum allowed', 1196);
        }

        $start = $win->tell();
        $items = [];
        $index = 0;

        foreach ($this->walkChildren($iinf, $start) as $child) {
            if ($child->type !== BoxType::INFE->value) {
                continue;
            }

            ++$index;

            if ($index > $entryCount) {
                throw new ParseError('iinf contains infe entries beyond declared entry_count', 1364);
            }

            $items[] = $this->parseInfe($child);
        }

        if ($index !== $entryCount) {
            throw new ParseError('iinf entry count mismatch', 1197);
        }

        // Reject duplicate item_ID values across infe entries
        $seenIds = [];
        foreach ($items as $item) {
            if (isset($seenIds[$item['id']])) {
                throw new ParseError(sprintf('duplicate infe item_ID %d', $item['id']), 1415);
            }

            $seenIds[$item['id']] = true;
        }

        return $items;
    }

    /**
     * Parses item locations and returns extent definitions keyed by item id.
     *
     * EXIF 3.0 Annex A.2.4 mandates how `iloc` describes Exif item offsets; EXIF 3.0
     * adds version 2 support and constrains base offsets and extent sizing.
     *
     * @param BoxDescriptor $iloc             Box descriptor representing the `iloc` payload.
     * @param int           $fileOffsetOrigin Absolute data origin for file-offset construction method.
     *
     * @return array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}>
     */
    public function parseIloc(BoxDescriptor $iloc, int $fileOffsetOrigin = 0): array
    {
        $win = $iloc->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        // ISO/IEC 14496-12 §8.11.3: only versions 0, 1 and 2 are defined.
        // Skip unsupported versions gracefully instead of failing the
        // entire parse — the spec requires readers to ignore boxes with
        // unrecognized versions.
        if ($version > 2 || $flags !== 0) {
            return [];
        }

        // ISO/IEC 14496-12 §8.11.3: offset_size and length_size are packed in 4-bit nibbles
        $offsetLengthSizes = $win->readU8();
        $offsetSize        = $this->validateSizeNibble(($offsetLengthSizes >> 4) & BitMask::LOW_NIBBLE); // High nibble
        $lengthSize        = $this->validateSizeNibble($offsetLengthSizes & BitMask::LOW_NIBBLE);         // Low nibble

        // ISO/IEC 14496-12 §8.11.3: base_offset_size in high nibble, index_size in low nibble (v1/v2)
        $baseField      = $win->readU8();
        $baseOffsetSize = $this->validateSizeNibble(($baseField >> 4) & BitMask::LOW_NIBBLE);
        $indexSize      = 0;

        if ($version === 0) {
            // ISO/IEC 14496-12 §8.11.3: for version 0 the low nibble is reserved and must be 0
            if (($baseField & BitMask::LOW_NIBBLE) !== 0) {
                throw new ParseError('iloc version 0 reserved nibble must be zero', 1204);
            }
        } else {
            $indexSize = $this->validateSizeNibble($baseField & BitMask::LOW_NIBBLE);
        }

        $itemCount = $version < 2 ? $win->readU16BE() : $win->readU32BE();

        if ($itemCount > self::MAX_ILOC_ITEMS) {
            throw new ParseError('iloc item count exceeds maximum allowed', 1205);
        }

        $locations = [];

        for ($i = 0; $i < $itemCount; ++$i) {
            // ISO/IEC 14496-12 §8.11.3.2: item_ID is 16-bit for version < 2 and 32-bit for version 2.
            // Note: flags bit 0 indicates hidden_item and does not affect item_ID width.
            $itemId = $version < 2 ? $win->readU16BE() : $win->readU32BE();

            $constructionMethod = 0;
            if ($version === 1 || $version === 2) {
                // ISO/IEC 14496-12 §8.11.3: 12-bit reserved (must be 0) followed by 4-bit construction_method
                $tmp = $win->readU16BE();

                if (($tmp >> 4) !== 0) {
                    throw new ParseError('iloc construction_method reserved bits must be zero', 1206);
                }

                $constructionMethod = $tmp & BitMask::LOW_NIBBLE;

                if (ConstructionMethod::tryFrom($constructionMethod) === null) {
                    throw new ParseError('iloc construction_method value out of range', 1207);
                }
            }

            $dataReferenceIndex = $win->readU16BE();
            $baseOffset         = $baseOffsetSize > 0 ? $this->readUInt($win, $baseOffsetSize) : 0;
            $extentCount        = $win->readU16BE();

            // Enforce maximum extent_count per item
            if ($extentCount > self::MAX_ILOC_EXTENTS) {
                throw new ParseError('iloc extent count exceeds maximum allowed', 1411);
            }

            /** @var list<array{offset:int,length:int,index:?int}> $extents */
            $extents = [];

            for ($j = 0; $j < $extentCount; ++$j) {
                $extentIndex = null;
                if ($indexSize > 0) {
                    $extentIndex = $this->readUInt($win, $indexSize);

                    // ISO/IEC 14496-12 §8.11.3.2: extent_index is 1-based and 0 is
                    // reserved. This only applies to construction_method=2 (item_offset).
                    if ($constructionMethod === ConstructionMethod::ItemOffset->value && $extentIndex === 0) {
                        throw new ParseError('iloc extent_index 0 is reserved', 1208);
                    }
                }

                $extentOffset = $offsetSize > 0 ? $this->readUInt($win, $offsetSize) : 0;
                $extentLength = $lengthSize > 0 ? $this->readUInt($win, $lengthSize) : 0;
                $extents[]    = ['offset' => $extentOffset, 'length' => $extentLength, 'index' => $extentIndex];
            }

            // ISO/IEC 14496-12 §8.11.3: item_ID values must be unique within one iloc box.
            if (isset($locations[$itemId])) {
                throw new ParseError(sprintf('duplicate iloc item_ID %d', $itemId), 1209);
            }

            $locations[$itemId] = [
                'dataReferenceIndex' => $dataReferenceIndex,
                'constructionMethod' => $constructionMethod,
                'baseOffset'         => $baseOffset,
                'fileOffsetOrigin'   => $fileOffsetOrigin,
                'extents'            => $extents,
            ];
        }

        if ($win->tell() !== $iloc->contentSize) {
            throw new ParseError('iloc payload has trailing bytes after declared items', 1387);
        }

        return $locations;
    }

    /**
     * Parses the primary item box (`pitm`) and returns the referenced item id.
     *
     * EXIF 3.0 Annex A.2.5 defines how the primary item identifies the default
     * metadata payload and extends the item identifier width to 32 bits for
     * version 1 boxes.
     *
     * @param BoxDescriptor $pitm Box descriptor containing the primary item payload.
     *
     * @return int|null Primary item ID or null when the box uses an unsupported version.
     */
    public function parsePitm(BoxDescriptor $pitm): ?int
    {
        $win = $pitm->window;
        $win->seek(0);

        // FullBox header (4 bytes) + item_ID (2 for v0, 4 for v1)
        if ($pitm->contentSize < 6) {
            throw new ParseError('pitm box truncated', 1210);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        // Skip unsupported versions/flags gracefully — the spec requires
        // readers to ignore boxes with unrecognized versions.
        if (($version !== 0 && $version !== 1) || $flags !== 0) {
            return null;
        }

        if ($version === 0) {
            return $win->readU16BE();
        }

        // v1 requires 4-byte item_ID → 8 bytes total
        if ($pitm->contentSize < 8) {
            throw new ParseError('pitm box truncated', 1213);
        }

        return $win->readU32BE();
    }

    /**
     * Parses an item reference box (`iref`) and returns the referenced item ids.
     *
     * ISO/IEC 14496-12 §8.11.12 defines the structure of item reference
     * collections and their single-item reference entries.
     *
     * @param BoxDescriptor $iref Box descriptor containing item references.
     *
     * @return array<int, list<IsoBmffItemReference>>
     */
    public function parseIref(BoxDescriptor $iref): array
    {
        $win = $iref->window;
        $win->seek(0);

        if ($iref->contentSize < 4) {
            throw new ParseError('iref box truncated', 1214);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        // Skip unsupported versions/flags gracefully — the spec requires
        // readers to ignore boxes with unrecognized versions.
        if (($version !== 0 && $version !== 1) || $flags !== 0) {
            return [];
        }

        $references = [];
        $entryCount = 0;

        foreach ($this->walkChildren($iref, 4) as $child) {
            ++$entryCount;

            // Enforce maximum number of reference entry boxes
            if ($entryCount > self::MAX_IREF_ENTRIES) {
                throw new ParseError('iref entry count exceeds maximum allowed', 1412);
            }

            $entry      = $this->parseSingleItemReference($child, $version);
            $references = $this->mergeItemReferences($references, [
                $entry['fromItemId'] => $entry['references'],
            ]);
        }

        return $references;
    }

    /**
     * Parses a data reference box (`dref`) into data reference entries.
     *
     * ISO/IEC 14496-12 §8.7.2 defines the dref structure and entry indexing.
     *
     * @param BoxDescriptor $dref Box descriptor representing the data reference box.
     *
     * @return array<int, IsoBmffDataReference>
     */
    private function parseDref(BoxDescriptor $dref): array
    {
        $win = $dref->window;
        $win->seek(0);

        if ($dref->contentSize < 8) {
            throw new ParseError('dref box truncated', 1172);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0) {
            throw new ParseError('unsupported dref box version', 1173);
        }

        if ($flags !== 0) {
            throw new ParseError('dref FullBox flags must be 0 per ISO/IEC 14496-12', 1360);
        }

        $entryCount = $win->readU32BE();

        if ($entryCount === 0) {
            throw new ParseError('dref must contain at least one data reference entry', 1365);
        }

        if ($entryCount > self::MAX_DREF_ENTRIES) {
            throw new ParseError('dref entry count exceeds maximum allowed', 1174);
        }

        $references = [];
        $index      = 0;

        foreach ($this->walkChildren($dref, 8) as $child) {
            ++$index;

            if ($index > $entryCount) {
                throw new ParseError('dref contains entries beyond declared entry_count', 1363);
            }

            $references[$index] = $this->parseDataReferenceEntry($child, $index);
        }

        if ($index !== $entryCount) {
            throw new ParseError('dref entry count mismatch', 1175);
        }

        return $references;
    }

    /**
     * Parses a single data reference entry from a dref container.
     *
     * @param BoxDescriptor $entry Data reference entry descriptor.
     * @param int           $index One-based index of the reference.
     */
    private function parseDataReferenceEntry(BoxDescriptor $entry, int $index): IsoBmffDataReference
    {
        if ($entry->type !== BoxType::URL->value && $entry->type !== BoxType::URN->value) {
            throw new ParseError(sprintf('unsupported dref entry type "%s"', $entry->type), 1367);
        }

        $win = $entry->window;
        $win->seek(0);

        if ($entry->contentSize < 4) {
            throw new ParseError('dref entry truncated', 1176);
        }

        $version = $win->readU8();

        if ($version !== 0) {
            throw new ParseError('unsupported dref entry version', 1177);
        }

        $flags = $this->readUInt24($win);

        $payloadSize   = $entry->contentSize - 4;
        $selfContained = ($flags & BitMask::BIT_0) !== 0;
        $payload       = $payloadSize > 0 ? $win->read($payloadSize) : '';
        $uri           = null;
        $urlLocation   = null;
        $urnName       = null;
        $urnLocation   = null;

        if ($selfContained) {
            // ISO/IEC 14496-12 §8.7.2: self-contained entries must have no payload
            if ($payloadSize > 0) {
                throw new ParseError('self-contained dref entry must have empty payload', 1388);
            }
        } elseif ($payload !== '') {
            // Validate NUL-terminated UTF-8 string for URL/URN payloads
            if ($payload[strlen($payload) - 1] !== "\0") {
                throw new ParseError('dref entry URL/URN payload missing NUL terminator', 1389);
            }

            $trimmed = rtrim($payload, "\0");

            if (($trimmed !== '') && !mb_check_encoding($trimmed, 'UTF-8')) {
                throw new ParseError('dref entry URL/URN payload contains invalid UTF-8', 1390);
            }

            if ($entry->type === BoxType::URL->value) {
                $urlLocation = $trimmed !== '' ? $trimmed : null;
                $uri         = $urlLocation;
            } else {
                // ISO/IEC 14496-12:2015 §8.7.2.2 (`DataEntryUrnBox`) separates
                // required `name` and optional `location` string fields.
                $parts    = explode("\0", $trimmed, 2);
                $urnName  = $parts[0] !== '' ? $parts[0] : null;
                $location = $parts[1] ?? null;

                if ($urnName === null) {
                    throw new ParseError('dref urn entry requires non-empty name field', 1603);
                }

                $urnLocation = (($location !== null) && ($location !== '')) ? $location : null;
                $uri         = $urnLocation !== null ? $urnName . "\0" . $urnLocation : $urnName;
            }
        }

        if ((!$selfContained) && ($entry->type === BoxType::URN->value) && ($urnName === null)) {
            throw new ParseError('dref urn entry requires non-empty name field', 1603);
        }

        return new IsoBmffDataReference(
            $index,
            $this->normaliseFourcc($entry->type),
            $uri,
            $selfContained,
            $urlLocation,
            $urnName,
            $urnLocation,
        );
    }

    /**
     * Parses a single item information entry (`infe`).
     *
     * EXIF 3.0 Annex A.2.3 describes the item entry fields and the recommended
     * item type/content type combinations for Exif payloads.
     *
     * @param BoxDescriptor $infe Box descriptor for the entry being parsed.
     *
     * @return array{id: int, itemType: ?string, name: ?string, contentType: ?string, contentEncoding: ?string, extensionType: ?string, itemUriType?: string, hidden: bool}
     */
    private function parseInfe(BoxDescriptor $infe): array
    {
        $win = $infe->window;
        $win->seek(0);

        if ($infe->contentSize < 8) {
            throw new ParseError('infe box truncated', 1198);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version > 3) {
            throw new ParseError('unsupported infe box version', 1199);
        }

        // Bit 0 is the hidden_item flag (ISO/IEC 14496-12 Amd.2:2018 §8.11.6).
        // Apple HEIC files set this on tile and metadata items that compose a
        // grid image.  Bits 1–23 are reserved and must be zero.
        if (($flags & ~0x01) !== 0) {
            throw new ParseError('unsupported infe box flags', 1200);
        }

        $hidden = ($flags & 0x01) !== 0;

        if ($version === 0 || $version === 1) {
            $itemId = $win->readU16BE();
            $win->readU16BE(); // protection index
            $remaining = $infe->contentSize - $win->tell();
            $payload   = $remaining > 0 ? $win->read($remaining) : '';
            $parts     = $payload === '' ? [] : explode("\0", $payload);

            // ISO/IEC 14496-12 §8.11.6: v0/v1 payload is item_name\0content_type\0[content_encoding\0]
            $name            = $parts[0] ?? null;
            $contentType     = (isset($parts[1]) && ($parts[1] !== '')) ? $parts[1] : null;
            $contentEncoding = (isset($parts[2]) && ($parts[2] !== '')) ? $parts[2] : null;

            return [
                'id'              => $itemId,
                'itemType'        => null,
                'name'            => ($name !== '') ? $name : null,
                'contentType'     => $contentType,
                'contentEncoding' => $contentEncoding,
                'extensionType'   => null,
                'hidden'          => $hidden,
            ];
        }

        // ISO 14496-12: Version 2 uses 16-bit item_ID, version 3 uses 32-bit.
        // Note: flags bit 0 indicates hidden_item, not item_ID size.
        // v2: 4 header + 2 item_ID + 2 protection_index + 4 item_type = 12
        // v3: 4 header + 4 item_ID + 2 protection_index + 4 item_type = 14
        $minSize = $version === 3 ? 14 : 12;
        if ($infe->contentSize < $minSize) {
            throw new ParseError('infe box truncated', 1201);
        }

        $id = $version === 3 ? $win->readU32BE() : $win->readU16BE();
        $win->readU16BE(); // protection index
        $itemType  = $win->read(4);
        $remaining = $infe->contentSize - $win->tell();
        $payload   = $remaining > 0 ? $win->read($remaining) : '';

        // ISO 14496-12: remaining payload is item_name\0content_type\0[content_encoding\0]
        $cursor          = 0;
        $name            = $this->readNulString($payload, $cursor);
        $contentType     = $this->readNulString($payload, $cursor);
        $contentEncoding = $this->readNulString($payload, $cursor);

        // ISO 14496-12 §8.11.6: if item_type == 'uri ', the post-name
        // payload is a single NUL-terminated item_uri_type (no content_type/content_encoding)
        if ($itemType === 'uri ') {
            $itemUriType = $this->readNulString($payload, $cursor);

            if ($itemUriType === null || $itemUriType === '') {
                throw new ParseError('infe uri item_uri_type must be non-empty', 1392);
            }

            return [
                'id'              => $id,
                'itemType'        => $itemType,
                'name'            => $name,
                'contentType'     => null,
                'contentEncoding' => null,
                'extensionType'   => null,
                'itemUriType'     => $itemUriType,
                'hidden'          => $hidden,
            ];
        }

        // ISO 14496-12 §8.11.6: when item_type == 'mime', content_type
        // is mandatory and must be non-empty
        if ($itemType === 'mime' && ($contentType === null || $contentType === '')) {
            throw new ParseError('infe mime item requires non-empty content_type', 1391);
        }

        // ISO 14496-12: if item_type == 'mime' and 4+ bytes remain after the
        // NUL-terminated strings, a 4-byte extension_type follows
        $extensionType = null;
        if ($itemType === 'mime' && (strlen($payload) - $cursor) >= 4) {
            $extensionType = substr($payload, $cursor, 4);
        }

        return [
            'id'              => $id,
            'itemType'        => $itemType !== '' ? $itemType : null,
            'name'            => $name,
            'contentType'     => $contentType,
            'contentEncoding' => $contentEncoding,
            'extensionType'   => $extensionType,
            'hidden'          => $hidden,
        ];
    }

    /**
     * Parses a single item reference box inside an `iref` container.
     *
     * ISO 14496-12: SingleItemTypeReferenceBox (iref v0) uses 16-bit IDs,
     * SingleItemTypeReferenceBoxLarge (iref v1) uses 32-bit IDs.
     * These are plain Boxes, not FullBoxes (no version/flags).
     *
     * @param BoxDescriptor $entry       Box descriptor describing the reference entry.
     * @param int           $irefVersion Version of the parent iref box (0 or 1).
     *
     * @return array{fromItemId:int, references:list<IsoBmffItemReference>}
     */
    private function parseSingleItemReference(BoxDescriptor $entry, int $irefVersion): array
    {
        $win = $entry->window;
        $win->seek(0);

        // ID size is determined by iref version, not by each child box
        // iref v0: 16-bit IDs, iref v1: 32-bit IDs
        $idSize = $irefVersion === 0 ? 2 : 4;

        if ($entry->contentSize < $idSize + 2) {
            throw new ParseError('iref entry truncated', 1217);
        }

        $fromItemId     = $idSize === 2 ? $win->readU16BE() : $win->readU32BE();
        $referenceCount = $win->readU16BE();

        if ($referenceCount > self::MAX_IREF_REFERENCES) {
            throw new ParseError('iref reference count exceeds maximum allowed', 1218);
        }

        $remaining = $entry->contentSize - $win->tell();
        $expected  = $referenceCount * $idSize;
        if ($remaining < $expected) {
            throw new ParseError('iref entry truncated', 1219);
        }

        $relation   = $this->normaliseFourcc($entry->type);
        $references = [];
        for ($i = 0; $i < $referenceCount; ++$i) {
            $toItemId     = $idSize === 2 ? $win->readU16BE() : $win->readU32BE();
            $references[] = new IsoBmffItemReference($relation, $toItemId);
        }

        if ($win->tell() !== $entry->contentSize) {
            throw new ParseError('iref entry size mismatch', 1220);
        }

        return [
            'fromItemId' => $fromItemId,
            'references' => $references,
        ];
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
    private function resolveItemData(int $itemId, array $locations, array $itemReferences, array $dataReferences, ?string $idatPayload, array &$unresolvedItems, int $metaContextOffset, array $visitedItemIds = []): ?string
    {
        if (!isset($locations[$itemId])) {
            return null;
        }

        $location = $locations[$itemId];
        if (in_array($itemId, $visitedItemIds, true)) {
            $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems, $metaContextOffset);

            return null;
        }

        if ($location['constructionMethod'] === ConstructionMethod::FileOffset->value) {
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

                $blob .= $this->readAll($this->stream->window($offset, $length));
            }

            return $blob === '' ? null : $blob;
        }

        if ($location['constructionMethod'] === ConstructionMethod::IdatOffset->value) {
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

        if ($location['constructionMethod'] === ConstructionMethod::ItemOffset->value) {
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

    /**
     * Determines whether the given item descriptor represents EXIF content.
     *
     * @param array{id: int, itemType: ?string, name: ?string, contentType: ?string} $info
     */
    private function isExifItem(array $info): bool
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
    private function isXmpItem(array $info): bool
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
     * Reads the entire payload of a stream window.
     *
     * @param StreamWindow $window Window to consume.
     *
     * @return string
     */
    private function readAll(StreamWindow $window): string
    {
        $window->seek(0);
        $size = $window->size();

        return $size > 0 ? $window->read($size) : '';
    }

    /**
     * Reads an unsigned 24-bit integer from the provided window.
     *
     * @param StreamWindow $window Window to read from.
     *
     * @return int
     */
    private function readUInt24(StreamWindow $window): int
    {
        return $this->readUInt($window, 3);
    }

    /**
     * Reads an unsigned integer using the specified byte width.
     *
     * @param StreamWindow $window Window to read from.
     * @param int          $bytes  Number of bytes representing the integer.
     *
     * @return int
     */
    private function readUInt(StreamWindow $window, int $bytes): int
    {
        return match ($bytes) {
            0       => 0,
            1       => $window->readU8(),
            2       => $window->readU16BE(),
            3       => Unpack::int('N', "\0" . $window->read(3), '24-bit integer value'),
            4       => $window->readU32BE(),
            8       => $window->readU64BE()->toInt('64-bit integer value'),
            default => throw new ParseError('unsupported integer size ' . $bytes, 1256),
        };
    }

    /**
     * Validates ISO BMFF length-size nibbles and returns the byte width.
     *
     * ISO/IEC 14496-12 §8.11.3.3 limits size nibbles to 0, 4, or 8 bytes.
     *
     * @param int $nibble Raw nibble extracted from the length-size field.
     *
     * @return int
     */
    private function validateSizeNibble(int $nibble): int
    {
        return match ($nibble) {
            0 => 0,
            4, 8 => $nibble,
            default => throw new ParseError('invalid length field size', 1257),
        };
    }

    /**
     * Reads a NUL-terminated string from a payload at the given cursor position.
     *
     * @param string $payload Binary payload to read from.
     * @param int    &$cursor Current read position; advanced past the NUL terminator.
     */
    private function readNulString(string $payload, int &$cursor): ?string
    {
        if ($cursor >= strlen($payload)) {
            return null;
        }

        $nul = strpos($payload, "\0", $cursor);
        if ($nul === false) {
            $value  = substr($payload, $cursor);
            $cursor = strlen($payload);

            return $value !== '' ? $value : null;
        }

        $value  = substr($payload, $cursor, $nul - $cursor);
        $cursor = $nul + 1;

        return $value !== '' ? $value : null;
    }

    /**
     * Checks whether a four-character code contains printable ASCII.
     *
     * @param string $fourcc Four-character code to test.
     *
     * @return bool
     */
    private function isPrintableFourcc(string $fourcc): bool
    {
        if (strlen($fourcc) !== 4) {
            return false;
        }

        if (preg_match('/^[\x20-\x7E]{4}$/', $fourcc) === 1) {
            return true;
        }

        return preg_match('/^\xA9[\x20-\x7E]{3}$/', $fourcc) === 1;
    }

    /**
     * Normalises a four-character code for consistent comparison.
     *
     * @param string $fourcc Four-character code to normalise.
     *
     * @return string
     */
    private function normaliseFourcc(string $fourcc): string
    {
        if ($this->isPrintableFourcc($fourcc)) {
            return $fourcc;
        }

        return strtoupper(bin2hex($fourcc));
    }

    /**
     * Iterates through child boxes within a container, yielding descriptors.
     *
     * @param BoxDescriptor $parent                  Parent box descriptor whose content is iterated.
     * @param int           $offset                  Optional relative byte offset where iteration begins.
     * @param bool          $allowTrailingTerminator When true, tolerates a trailing 4-byte zero terminator
     *                                               at the end of the child list. QuickTime File Format 2012
     *                                               §2 "User Data Atoms" specifies that a udta list may
     *                                               optionally end with a 32-bit integer set to 0.
     *
     * @return iterable<BoxDescriptor>
     */
    private function walkChildren(BoxDescriptor $parent, int $offset = 0, bool $allowTrailingTerminator = false): iterable
    {
        if ($offset < 0 || $offset > $parent->contentSize) {
            throw new ParseError('child offset outside container', 1258);
        }

        $limit  = $parent->contentOffset + $parent->contentSize;
        $cursor = $parent->contentOffset + $offset;
        $end    = $parent->contentOffset + $parent->contentSize;

        while ($cursor + 8 <= $end) {
            $box = $this->readBoxAt($cursor, $limit);
            yield $box;
            $cursor += $box->size;
        }

        if ($cursor !== $end) {
            // QuickTime File Format 2012 §2 "User Data Atoms": a udta child
            // list may optionally end with a 32-bit zero terminator.
            if ($allowTrailingTerminator && (($end - $cursor) === 4)) {
                $this->stream->seek($cursor);
                if ($this->stream->readU32BE() === 0) {
                    return;
                }
            }

            throw new ParseError('child boxes do not align with parent', 1259);
        }
    }

    /**
     * Reads a box header at the given offset and returns a descriptor object.
     *
     * @param int $offset Absolute byte offset of the box within the stream.
     * @param int $limit  Limit offset that bounds the container.
     *
     * @return BoxDescriptor
     */
    private function readBoxAt(int $offset, int $limit, bool $allowImplicitSize = false): BoxDescriptor
    {
        if ($offset < 0 || $offset > $limit) {
            throw new ParseError('box offset outside container', 1260);
        }

        $this->stream->seek($offset);
        $size32     = $this->stream->readU32BE();
        $type       = $this->stream->read(4);
        $headerSize = 8;
        $size       = $size32;

        if ($size32 === 0) {
            if (!$allowImplicitSize) {
                throw new ParseError('nested box size==0 is only valid at top level', 1362);
            }

            $size = $limit - $offset;
        } elseif ($size32 === 1) {
            $size = $this->stream->readU64BE()->toInt('extended box size');
            $headerSize += 8;
        }

        $userType = null;
        if ($type === BoxType::UUID->value) {
            // uuid box must be at least 24 bytes (8-byte header + 16-byte userType)
            if ($size < 24) {
                throw new ParseError('uuid box size must be at least 24 bytes', 1420);
            }

            $userType = $this->stream->read(16);
            $headerSize += 16;
        }

        if ($size < $headerSize) {
            throw new ParseError('invalid box size for ' . $type, 1261);
        }

        if ($offset + $size > $limit) {
            // Truncated recordings (e.g. interrupted drone/camera captures)
            // commonly have an mdat header written with the intended full
            // recording size while the file ends mid-stream.  Clamping the
            // effective size lets the parser continue scanning for metadata
            // boxes that may follow (or precede) the mdat.
            if ($type === 'mdat' && $allowImplicitSize) {
                $size = $limit - $offset;
            } else {
                throw new ParseError(
                    sprintf('box %s exceeds container bounds', $type), 1262);
            }
        }

        $contentOffset = $offset + $headerSize;
        $contentSize   = $size - $headerSize;
        $window        = $this->stream->window($contentOffset, $contentSize);

        return new BoxDescriptor(
            $type,
            $size,
            $offset,
            $contentOffset,
            $contentSize,
            $window,
            $userType,
        );
    }
}
