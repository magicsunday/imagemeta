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
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Parse\ParserLimits;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;

use function explode;
use function mb_check_encoding;
use function rtrim;
use function sprintf;
use function strlen;
use function strpos;
use function substr;

/**
 * Parses ISO BMFF box structures related to item location and reference metadata.
 *
 * Handles parsing of iloc, iinf, pitm, iref, and dinf/dref boxes within
 * ISO BMFF containers as defined by ISO/IEC 14496-12 §8.11.
 *
 * @phpstan-type IlocExtent   = array{offset: int, length: int, index: ?int}
 * @phpstan-type IlocLocation = array{dataReferenceIndex: int, constructionMethod: ConstructionMethod, baseOffset: int, fileOffsetOrigin: int, extents: list<IlocExtent>}
 * @phpstan-type InfeItem     = array{id: int, itemType: ?string, name: ?string, contentType: ?string, contentEncoding: ?string, extensionType: ?string, itemUriType?: string, hidden: bool}
 */
final readonly class IlocBoxParser
{
    /**
     * @param BoxNavigator $boxNavigator Shared box navigation infrastructure.
     */
    public function __construct(
        private BoxNavigator $boxNavigator,
    ) {
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
     * @return array<int, IlocLocation>
     */
    public function parseIloc(BoxDescriptor $iloc, int $fileOffsetOrigin = 0): array
    {
        $win = $iloc->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = $this->boxNavigator->readUInt24($win);

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

        if ($itemCount > ParserLimits::MAX_ILOC_ITEMS) {
            throw new ParseError('iloc item count exceeds maximum allowed', 1205);
        }

        $locations = [];

        for ($i = 0; $i < $itemCount; ++$i) {
            // ISO/IEC 14496-12 §8.11.3.2: item_ID is 16-bit for version < 2 and 32-bit for version 2.
            // Note: flags bit 0 indicates hidden_item and does not affect item_ID width.
            $itemId = $version < 2 ? $win->readU16BE() : $win->readU32BE();

            $constructionMethod = ConstructionMethod::FileOffset;
            if ($version === 1 || $version === 2) {
                // ISO/IEC 14496-12 §8.11.3: 12-bit reserved (must be 0) followed by 4-bit construction_method
                $tmp = $win->readU16BE();

                $method = ConstructionMethod::tryFrom($tmp & BitMask::LOW_NIBBLE);

                if ($method === null) {
                    throw new ParseError('iloc construction_method value out of range', 1207);
                }

                $constructionMethod = $method;
            }

            $dataReferenceIndex = $win->readU16BE();
            $baseOffset         = $baseOffsetSize > 0 ? $this->boxNavigator->readUInt($win, $baseOffsetSize) : 0;
            $extentCount        = $win->readU16BE();

            // Enforce maximum extent_count per item
            if ($extentCount > ParserLimits::MAX_ILOC_EXTENTS) {
                throw new ParseError('iloc extent count exceeds maximum allowed', 1411);
            }

            /** @var list<array{offset:int,length:int,index:?int}> $extents */
            $extents = [];

            for ($j = 0; $j < $extentCount; ++$j) {
                $extentIndex = null;
                if ($indexSize > 0) {
                    $extentIndex = $this->boxNavigator->readUInt($win, $indexSize);

                    // ISO/IEC 14496-12 §8.11.3.2: extent_index is 1-based and 0 is
                    // reserved. This only applies to construction_method=2 (item_offset).
                    if ($constructionMethod === ConstructionMethod::ItemOffset && $extentIndex === 0) {
                        throw new ParseError('iloc extent_index 0 is reserved', 1208);
                    }
                }

                $extentOffset = $offsetSize > 0 ? $this->boxNavigator->readUInt($win, $offsetSize) : 0;
                $extentLength = $lengthSize > 0 ? $this->boxNavigator->readUInt($win, $lengthSize) : 0;
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
     * Parses the item information box and returns descriptors for each entry.
     *
     * ISO/IEC 14496-12:2015 §8.11.6 defines `entry_count` as the number of
     * item information entries (`infe`) in the box.
     * EXIF 3.0 Annex A.2.2 defines the `iinf` container layout for Exif-in-ISO BMFF
     * metadata collections.
     *
     * @param BoxDescriptor $iinf Box descriptor containing the item information payload.
     *
     * @return list<InfeItem>
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
        $flags   = $this->boxNavigator->readUInt24($win);

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

        if ($entryCount > ParserLimits::MAX_IINF_ENTRIES) {
            throw new ParseError('iinf entry count exceeds maximum allowed', 1196);
        }

        $start = $win->tell();
        $items = [];
        $index = 0;

        foreach ($this->boxNavigator->walkChildren($iinf, $start) as $child) {
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
        $flags   = $this->boxNavigator->readUInt24($win);

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
        $flags   = $this->boxNavigator->readUInt24($win);

        // Skip unsupported versions/flags gracefully — the spec requires
        // readers to ignore boxes with unrecognized versions.
        if (($version !== 0 && $version !== 1) || $flags !== 0) {
            return [];
        }

        $references = [];
        $entryCount = 0;

        foreach ($this->boxNavigator->walkChildren($iref, 4) as $child) {
            ++$entryCount;

            // Enforce maximum number of reference entry boxes
            if ($entryCount > ParserLimits::MAX_IREF_ENTRIES) {
                throw new ParseError('iref entry count exceeds maximum allowed', 1412);
            }

            $entry      = $this->parseSingleItemReference($child, $version);
            $references = ItemLocationResolver::mergeItemReferences($references, [
                $entry['fromItemId'] => $entry['references'],
            ]);
        }

        return $references;
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

        foreach ($this->boxNavigator->walkChildren($dinf) as $child) {
            if ($child->type === BoxType::DREF->value) {
                ++$drefCount;

                if ($drefCount > 1) {
                    throw new ParseError('dinf must contain exactly one dref box', 1366);
                }

                $references = ItemLocationResolver::mergeDataReferences($references, $this->parseDref($child));
            }
        }

        if ($drefCount === 0) {
            throw new ParseError('dinf must contain exactly one dref box', 1889);
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
        $this->boxNavigator->readUInt24($win);

        if ($version !== 0) {
            throw new ParseError('unsupported dref box version', 1173);
        }

        $entryCount = $win->readU32BE();

        if ($entryCount === 0) {
            throw new ParseError('dref must contain at least one data reference entry', 1365);
        }

        if ($entryCount > ParserLimits::MAX_DREF_ENTRIES) {
            throw new ParseError('dref entry count exceeds maximum allowed', 1174);
        }

        $references = [];
        $index      = 0;

        foreach ($this->boxNavigator->walkChildren($dref, 8) as $child) {
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
        // Postel's Law: skip unknown dref types (e.g. "alis", "rsrc").
        if ($entry->type !== BoxType::URL->value && $entry->type !== BoxType::URN->value) {
            return new IsoBmffDataReference(
                $index,
                $this->boxNavigator->normalizeFourcc($entry->type),
                null,
                false,
            );
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

        $flags = $this->boxNavigator->readUInt24($win);

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
            throw new ParseError('dref urn entry requires non-empty name field', 1937);
        }

        return new IsoBmffDataReference(
            $index,
            $this->boxNavigator->normalizeFourcc($entry->type),
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
        $flags   = $this->boxNavigator->readUInt24($win);

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
        $offset          = 0;
        $name            = $this->readNulString($payload, $offset);
        $contentType     = $this->readNulString($payload, $offset);
        $contentEncoding = $this->readNulString($payload, $offset);

        // ISO 14496-12 §8.11.6: if item_type == 'uri ', the post-name
        // payload is a single NUL-terminated item_uri_type (no content_type/content_encoding)
        if ($itemType === 'uri ') {
            $itemUriType = $this->readNulString($payload, $offset);

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
        if ($itemType === 'mime' && (strlen($payload) - $offset) >= 4) {
            $extensionType = substr($payload, $offset, 4);
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

        if ($referenceCount > ParserLimits::MAX_IREF_REFERENCES) {
            throw new ParseError('iref reference count exceeds maximum allowed', 1218);
        }

        $remaining = $entry->contentSize - $win->tell();
        $expected  = $referenceCount * $idSize;
        if ($remaining < $expected) {
            throw new ParseError('iref entry truncated', 1219);
        }

        $relation   = $this->boxNavigator->normalizeFourcc($entry->type);
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
     * Validates ISO BMFF length-size nibbles and returns the byte width.
     *
     * ISO/IEC 14496-12 §8.11.3.3 limits size nibbles to 0, 4, or 8 bytes.
     *
     * @param int $nibble Raw nibble extracted from the length-size field.
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
     * Reads a NUL-terminated string from a payload at the given read offset.
     *
     * By-ref offset advancement: standard stream-cursor pattern where the read position
     * must advance past consumed bytes for subsequent reads.
     *
     * @param string $payload Binary payload to read from.
     * @param int    &$offset Current read position; advanced past the NUL terminator.
     */
    private function readNulString(string $payload, int &$offset): ?string
    {
        if ($offset >= strlen($payload)) {
            return null;
        }

        $nul = strpos($payload, "\0", $offset);
        if ($nul === false) {
            $value  = substr($payload, $offset);
            $offset = strlen($payload);

            return $value !== '' ? $value : null;
        }

        $value  = substr($payload, $offset, $nul - $offset);
        $offset = $nul + 1;

        return $value !== '' ? $value : null;
    }
}
