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
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;

use function array_merge;
use function array_unique;
use function array_unshift;
use function array_values;
use function explode;
use function preg_match;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strtolower;
use function substr;
use function unpack;

/**
 * Streaming ISOBMFF reader for HEIC/AVIF/MP4/MOV.
 * Extracts EXIF/XMP payloads and QuickTime metadata.
 */
final readonly class IsoBmffExtractor
{
    /**
     * UUID identifying XMP payload boxes within ISO BMFF containers.
     */
    private const string XMP_UUID = "\xBE\x7A\xCF\xCB\x97\xA9\x42\xE8\x9C\x71\x99\x94\x91\xE3\xAF\xAC";

    /**
     * FourCC for QuickTime metadata box.
     */
    private const string BOX_META = 'meta';

    /**
     * FourCC for QuickTime movie box.
     */
    private const string BOX_MOOV = 'moov';

    /**
     * FourCC for UUID box used to store custom payloads.
     */
    private const string BOX_UUID = 'uuid';

    /**
     * FourCC for embedded EXIF box.
     */
    private const string BOX_EXIF = 'Exif';

    /**
     * FourCC for item information box.
     */
    private const string BOX_IINF = 'iinf';

    /**
     * FourCC for item location box.
     */
    private const string BOX_ILOC = 'iloc';

    /**
     * FourCC for primary item box.
     */
    private const string BOX_PITM = 'pitm';

    /**
     * FourCC for embedded XMP metadata box.
     */
    private const string BOX_XMP = 'XMP ';

    /**
     * FourCC for QuickTime metadata keys box.
     */
    private const string BOX_KEYS = 'keys';

    /**
     * FourCC for QuickTime item list box.
     */
    private const string BOX_ILST = 'ilst';

    /**
     * FourCC for QuickTime user data box.
     */
    private const string BOX_UDTA = 'udta';

    /**
     * FourCC for item information entry box.
     */
    private const string BOX_INFE = 'infe';

    /**
     * FourCC for QuickTime free-form metadata box.
     */
    private const string BOX_FREEFORM = '----';

    /**
     * FourCC for QuickTime data box.
     */
    private const string BOX_DATA = 'data';

    /**
     * QuickTime `data` box type code for UTF-8 encoded text payloads.
     */
    private const int DATA_TYPE_UTF8 = 1;

    /**
     * QuickTime `data` box type code for UTF-16 (big-endian) encoded text payloads.
     */
    private const int DATA_TYPE_UTF16 = 2;

    /**
     * QuickTime `data` box type code for classic MacRoman encoded text payloads.
     */
    private const int DATA_TYPE_MAC_ROMAN = 7;

    /**
     * FourCC for QuickTime mean payload in free-form metadata.
     */
    private const string FREEFORM_MEAN = 'mean';

    /**
     * FourCC for QuickTime name payload in free-form metadata.
     */
    private const string FREEFORM_NAME = 'name';

    /**
     * Initialises the extractor with the source stream that contains the ISO BMFF structure.
     *
     * @param Stream $stream Stream positioned at the beginning of the media file to parse.
     */
    public function __construct(private Stream $stream)
    {
    }

    /**
     * Extracts EXIF blobs, XMP packets, and QuickTime metadata from the stream.
     *
     * @return array{0: list<string>, 1: list<string>, 2: ?QuickTimeMeta}
     */
    public function extract(): array
    {
        $exifBlobs     = [];
        $xmpBlobs      = [];
        $qtKeys        = [];
        $queuedUuidXmp = [];

        foreach ($this->walkTopLevelBoxes() as $box) {
            if ($box->type === self::BOX_META) {
                $this->parseMetaBox($box, $exifBlobs, $xmpBlobs, $qtKeys);
            } elseif ($box->type === self::BOX_MOOV) {
                $this->parseMoovBox($box, $exifBlobs, $xmpBlobs, $qtKeys);
            } elseif ($box->type === self::BOX_UUID && $box->userType === self::XMP_UUID) {
                $queuedUuidXmp[] = $this->readAll($box->window);
            }
        }

        if ($queuedUuidXmp !== []) {
            $xmpBlobs = array_merge($xmpBlobs, $queuedUuidXmp);
        }

        $qt = $qtKeys === [] ? null : new QuickTimeMeta($qtKeys);

        return [$exifBlobs, $xmpBlobs, $qt];
    }

    /**
     * Walks each top-level box in the file and yields a descriptor object.
     *
     * @return iterable<object>
     */
    private function walkTopLevelBoxes(): iterable
    {
        $fileSize = $this->stream->size();
        $offset   = 0;

        while ($offset + 8 <= $fileSize) {
            $box = $this->readBoxAt($offset, $fileSize);
            yield $box;
            $offset += $box->size;
        }

        if ($offset !== $fileSize) {
            throw new ParseError('Top-level boxes do not align with file size');
        }
    }

    /**
     * Parses the `moov` box, collecting nested metadata boxes of interest.
     *
     * @param object                $moov      Box descriptor for the movie box.
     * @param list<string>          $exifBlobs
     * @param list<string>          $xmpBlobs
     * @param array<string, string> $qtKeys
     */
    private function parseMoovBox(object $moov, array &$exifBlobs, array &$xmpBlobs, array &$qtKeys): void
    {
        foreach ($this->walkChildren($moov) as $child) {
            if ($child->type === self::BOX_META) {
                $this->parseMetaBox($child, $exifBlobs, $xmpBlobs, $qtKeys);
            } elseif ($child->type === self::BOX_UDTA) {
                $this->parseUdtaBox($child, $exifBlobs, $xmpBlobs, $qtKeys);
            }
        }
    }

    /**
     * Parses the `udta` user data box for embedded metadata containers.
     *
     * @param object                $udta      Box descriptor for the user data box.
     * @param list<string>          $exifBlobs
     * @param list<string>          $xmpBlobs
     * @param array<string, string> $qtKeys
     */
    private function parseUdtaBox(object $udta, array &$exifBlobs, array &$xmpBlobs, array &$qtKeys): void
    {
        foreach ($this->walkChildren($udta) as $child) {
            if ($child->type === self::BOX_META) {
                $this->parseMetaBox($child, $exifBlobs, $xmpBlobs, $qtKeys);
            }
        }
    }

    /**
     * Parses the ISO BMFF metadata box and resolves payload references.
     *
     * @param object                $meta      Box descriptor for the metadata box.
     * @param list<string>          $exifBlobs
     * @param list<string>          $xmpBlobs
     * @param array<string, string> $qtKeys
     */
    private function parseMetaBox(object $meta, array &$exifBlobs, array &$xmpBlobs, array &$qtKeys): void
    {
        if ($meta->contentSize < 4) {
            throw new ParseError('meta box truncated');
        }

        $payloads = $this->collectDirectPayloads($meta);

        foreach ($payloads['directExif'] as $blob) {
            $exifBlobs[] = $blob;
        }

        [$exifItemIds, $xmpItemIds] = $this->gatherItemIds($payloads['itemInfos'], $payloads['primaryItemId']);

        // Resolve EXIF item payloads and normalize leading headers.
        foreach ($this->resolveQueuedItems($exifItemIds, $payloads['locations'], fn (string $blob): string => $this->normalizeExifBlob($blob)) as $blob) {
            $exifBlobs[] = $blob;
        }

        // Resolve referenced XMP payloads in declared priority order.
        foreach ($this->resolveQueuedItems($xmpItemIds, $payloads['locations'], null) as $blob) {
            $xmpBlobs[] = $blob;
        }

        foreach ($payloads['directXmp'] as $blob) {
            $xmpBlobs[] = $blob;
        }
        foreach ($payloads['uuidXmp'] as $blob) {
            $xmpBlobs[] = $blob;
        }

        $qtKeys = $this->mergeQuickTimeKeys($qtKeys, $payloads['keysMaps'], $payloads['ilstBoxes']);
    }

    /**
     * @return array{
     *     itemInfos: array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}>,
     *     locations: array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int}>}>,
     *     primaryItemId: ?int,
     *     directXmp: list<string>,
     *     uuidXmp: list<string>,
     *     directExif: list<string>,
     *     keysMaps: list<array<int, string>>,
     *     ilstBoxes: list<object>
     * }
     */
    private function collectDirectPayloads(object $meta): array
    {
        $itemInfos     = [];
        $locations     = [];
        $primaryItemId = null;
        $directXmp     = [];
        $uuidXmp       = [];
        $directExif    = [];
        $keysMaps      = [];
        $ilstBoxes     = [];

        foreach ($this->walkChildren($meta, 4) as $child) {
            switch ($child->type) {
                case self::BOX_EXIF:
                    $blob         = $this->readAll($child->window);
                    $directExif[] = $this->normalizeExifBlob($blob);
                    break;
                case self::BOX_IINF:
                    foreach ($this->parseIinf($child) as $info) {
                        $itemInfos[$info['id']] = $info;
                    }
                    break;
                case self::BOX_ILOC:
                    $locations = $this->parseIloc($child);
                    break;
                case self::BOX_PITM:
                    $primaryItemId = $this->parsePitm($child);
                    break;
                case self::BOX_XMP:
                    $directXmp[] = $this->readAll($child->window);
                    break;
                case self::BOX_UUID:
                    if ($child->userType === self::XMP_UUID) {
                        $uuidXmp[] = $this->readAll($child->window);
                    }
                    break;
                case self::BOX_KEYS:
                    $keysMaps[] = $this->parseKeys($child);
                    break;
                case self::BOX_ILST:
                    $ilstBoxes[] = $child;
                    break;
            }
        }

        return [
            'itemInfos'     => $itemInfos,
            'locations'     => $locations,
            'primaryItemId' => $primaryItemId,
            'directXmp'     => $directXmp,
            'uuidXmp'       => $uuidXmp,
            'directExif'    => $directExif,
            'keysMaps'      => $keysMaps,
            'ilstBoxes'     => $ilstBoxes,
        ];
    }

    /**
     * @param array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}> $itemInfos
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function gatherItemIds(array $itemInfos, ?int $primaryItemId): array
    {
        $exifItemIds = [];
        $xmpItemIds  = [];

        // Collect item IDs that advertise EXIF/XMP payloads via their metadata descriptors.
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

        if ($primaryItemId !== null) {
            // Ensure the declared primary item is considered first for XMP resolution.
            array_unshift($xmpItemIds, $primaryItemId);
            $xmpItemIds = array_values(array_unique($xmpItemIds));
        }

        return [$exifItemIds, $xmpItemIds];
    }

    /**
     * @param list<int>                                                                                                                     $itemIds
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int}>}> $locations
     * @param (callable(string):string)|null                                                                                                $transform
     *
     * @return list<string>
     */
    private function resolveQueuedItems(array $itemIds, array $locations, ?callable $transform): array
    {
        $resolved = [];

        // Pull data for each referenced item and optionally transform the payload.
        foreach ($itemIds as $itemId) {
            $data = $this->resolveItemData($itemId, $locations);
            if ($data === null) {
                continue;
            }
            $resolved[] = $transform !== null ? $transform($data) : $data;
        }

        return $resolved;
    }

    /**
     * @param array<string, string>    $existing
     * @param list<array<int, string>> $keysMaps
     * @param list<object>             $ilstBoxes
     *
     * @return array<string, string>
     */
    private function mergeQuickTimeKeys(array $existing, array $keysMaps, array $ilstBoxes): array
    {
        $keyIndex = [];

        // Flatten key maps so later entries override duplicate indexes.
        foreach ($keysMaps as $map) {
            foreach ($map as $idx => $name) {
                $keyIndex[$idx] = $name;
            }
        }

        // Merge all ilst entries into the cumulative QuickTime metadata set.
        foreach ($ilstBoxes as $ilst) {
            $existing = $this->mergeAssociative($existing, $this->parseIlst($ilst, $keyIndex));
        }

        return $existing;
    }

    /**
     * Strips redundant EXIF signatures so downstream parsers accept the blob.
     *
     * @param string $blob Raw EXIF payload that may still include the "Exif\0\0" signature prefix.
     * @return string EXIF payload trimmed to the TIFF header when a redundant signature is detected.
     */
    private function normalizeExifBlob(string $blob): string
    {
        return str_starts_with($blob, "Exif\0\0") ? substr($blob, 6) : $blob;
    }

    /**
     * Resolves metadata item references described by an `iloc` box.
     *
     * @param int                                                                                                                           $itemId    Identifier of the item to resolve.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int}>}> $locations
     *
     * @return string|null
     */
    private function resolveItemData(int $itemId, array $locations): ?string
    {
        if (!isset($locations[$itemId])) {
            return null;
        }

        $location = $locations[$itemId];
        if ($location['constructionMethod'] !== 0) {
            return null;
        }
        if ($location['dataReferenceIndex'] !== 0) {
            return null;
        }

        $blob  = '';
        $total = 0;
        foreach ($location['extents'] as $extent) {
            $length = $extent['length'];
            if ($length === 0) {
                continue;
            }
            $total += $length;
            if ($total > $this->stream->size()) {
                throw new ParseError('iloc extent length exceeds file size');
            }

            $baseOffset   = $location['baseOffset'];
            $extentOffset = $extent['offset'];
            if ($baseOffset < 0 || $extentOffset < 0) {
                throw new ParseError('iloc negative offset');
            }
            if ($baseOffset > PHP_INT_MAX - $extentOffset) {
                throw new ParseError('iloc offset overflow');
            }
            $offset = $baseOffset + $extentOffset;
            if ($offset > $this->stream->size() - $length) {
                throw new ParseError('iloc extent outside file');
            }

            $blob .= $this->readAll($this->stream->window($offset, $length));
        }

        return $blob === '' ? null : $blob;
    }

    /**
     * Parses the item information box and returns descriptors for each entry.
     *
     * @param object $iinf Box descriptor containing the item information payload.
     *
     * @return list<array{id: int, itemType: ?string, name: ?string, contentType: ?string}>
     */
    private function parseIinf(object $iinf): array
    {
        $win = $iinf->window;
        $win->seek(0);
        $version = $win->readU8();
        $this->readUInt24($win); // flags

        $entryCount = $version === 0 ? $win->readU16BE() : $win->readU32BE();
        $start      = $win->tell();
        $items      = [];
        $index      = 0;
        foreach ($this->walkChildren($iinf, $start) as $child) {
            if ($child->type !== self::BOX_INFE) {
                continue;
            }
            $items[] = $this->parseInfe($child);
            ++$index;
            if ($index >= $entryCount) {
                break;
            }
        }

        return $items;
    }

    /**
     * Parses a single item information entry (`infe`).
     *
     * @param object $infe Box descriptor for the entry being parsed.
     *
     * @return array{id: int, itemType: ?string, name: ?string, contentType: ?string}
     */
    private function parseInfe(object $infe): array
    {
        $win = $infe->window;
        $win->seek(0);
        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version === 0 || $version === 1) {
            $itemId = $win->readU16BE();
            $win->readU16BE(); // protection index
            $remaining = $infe->contentSize - $win->tell();
            $payload   = $remaining > 0 ? $win->read($remaining) : '';
            $parts     = $payload === '' ? [] : explode("\0", $payload);

            $name        = $parts[0] ?? null;
            $contentType = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;

            return [
                'id'          => $itemId,
                'itemType'    => null,
                'name'        => $name !== '' ? $name : null,
                'contentType' => $contentType,
            ];
        }

        $id = ($flags & 0x0001) !== 0 ? $win->readU32BE() : $win->readU16BE();
        $win->readU16BE(); // protection index
        $itemType    = $win->read(4);
        $remaining   = $infe->contentSize - $win->tell();
        $payload     = $remaining > 0 ? $win->read($remaining) : '';
        $parts       = $payload === '' ? [] : explode("\0", $payload);
        $name        = $parts[0] ?? null;
        $contentType = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;

        return [
            'id'          => $id,
            'itemType'    => $itemType !== '' ? $itemType : null,
            'name'        => $name !== '' ? $name : null,
            'contentType' => $contentType,
        ];
    }

    /**
     * Parses item locations and returns extent definitions keyed by item id.
     *
     * @param object $iloc Box descriptor representing the `iloc` payload.
     *
     * @return array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int}>}>
     */
    private function parseIloc(object $iloc): array
    {
        $win = $iloc->window;
        $win->seek(0);
        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        $offsetLengthSizes = $win->readU8();
        $offsetSize        = $this->validateSizeNibble(($offsetLengthSizes >> 4) & 0x0F);
        $lengthSize        = $this->validateSizeNibble($offsetLengthSizes & 0x0F);

        $baseField      = $win->readU8();
        $baseOffsetSize = $this->validateSizeNibble(($baseField >> 4) & 0x0F);
        $indexSize      = 0;
        if ($version === 1 || $version === 2) {
            $indexSize = $this->validateSizeNibble($win->readU8() & 0x0F);
        }

        $itemCount = $version < 2 ? $win->readU16BE() : $win->readU32BE();
        $locations = [];

        for ($i = 0; $i < $itemCount; ++$i) {
            if ($version < 2) {
                $itemId = $win->readU16BE();
            } else {
                if (($flags & 0x0001) !== 0) {
                    $itemId = $win->readU32BE();
                } else {
                    $itemId = $win->readU16BE();
                }
            }
            $constructionMethod = 0;
            if ($version === 1 || $version === 2) {
                $tmp                = $win->readU16BE();
                $constructionMethod = ($tmp >> 12) & 0x0F;
            }
            $dataReferenceIndex = $win->readU16BE();
            $baseOffset         = $baseOffsetSize > 0 ? $this->readUInt($win, $baseOffsetSize) : 0;
            $extentCount        = $win->readU16BE();
            $extents            = [];

            for ($j = 0; $j < $extentCount; ++$j) {
                if ($indexSize > 0) {
                    $this->readUInt($win, $indexSize); // extent_index, ignored
                }
                $extentOffset = $offsetSize > 0 ? $this->readUInt($win, $offsetSize) : 0;
                $extentLength = $lengthSize > 0 ? $this->readUInt($win, $lengthSize) : 0;
                $extents[]    = ['offset' => $extentOffset, 'length' => $extentLength];
            }

            $locations[$itemId] = [
                'dataReferenceIndex' => $dataReferenceIndex,
                'constructionMethod' => $constructionMethod,
                'baseOffset'         => $baseOffset,
                'extents'            => $extents,
            ];
        }

        return $locations;
    }

    /**
     * Parses the primary item box (`pitm`) and returns the referenced item id.
     *
     * @param object $pitm Box descriptor containing the primary item payload.
     *
     * @return int|null
     */
    private function parsePitm(object $pitm): ?int
    {
        $win = $pitm->window;
        $win->seek(0);
        $version = $win->readU8();
        $this->readUInt24($win);

        return $version === 0 ? $win->readU16BE() : $win->readU32BE();
    }

    /**
     * Parses the QuickTime keys box into an index of identifier strings.
     *
     * @param object $keys Box descriptor for the QuickTime `keys` box.
     *
     * @return array<int, string>
     */
    private function parseKeys(object $keys): array
    {
        $win = $keys->window;
        $win->seek(0);
        $win->read(4); // version/flags
        $entryCount = $win->readU32BE();
        $map        = [];
        $pos        = $win->tell();

        for ($i = 1; $i <= $entryCount; ++$i) {
            if ($pos + 8 > $keys->contentSize) {
                throw new ParseError('keys entry truncated');
            }
            $win->seek($pos);
            $size      = $win->readU32BE();
            $namespace = $win->read(4);
            if ($size < 8 || $pos + $size > $keys->contentSize) {
                throw new ParseError('invalid keys entry size');
            }
            $name    = $win->read($size - 8);
            $map[$i] = $name;
            $pos += $size;
        }

        if ($pos !== $keys->contentSize) {
            throw new ParseError('keys entries do not fill container');
        }

        return $map;
    }

    /**
     * Parses the iTunes-style list (`ilst`) box using the discovered key index.
     *
     * @param object             $ilst     Box descriptor for the `ilst` container.
     * @param array<int, string> $keyIndex
     *
     * @return array<string, string>
     */
    private function parseIlst(object $ilst, array $keyIndex): array
    {
        $result = [];
        foreach ($this->walkChildren($ilst) as $entry) {
            $keyName = null;
            $index   = $this->fourccToIndex($entry->type);
            if ($index !== null && isset($keyIndex[$index])) {
                $keyName = $keyIndex[$index];
            } elseif ($entry->type === self::BOX_FREEFORM) {
                $keyName = $this->parseFreeformKey($entry);
            } elseif ($this->isPrintableFourcc($entry->type)) {
                $keyName = $entry->type;
            }

            if ($keyName === null) {
                continue;
            }

            foreach ($this->walkChildren($entry) as $sub) {
                if ($sub->type === self::BOX_DATA) {
                    $value = $this->parseDataBox($sub);
                    if ($value !== null) {
                        $result[$keyName] = $value;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Parses a free-form metadata key (----) into a dotted namespace string.
     *
     * @param object $entry Box descriptor representing the free-form entry.
     *
     * @return string|null
     */
    private function parseFreeformKey(object $entry): ?string
    {
        $mean = null;
        $name = null;
        foreach ($this->walkChildren($entry) as $child) {
            if ($child->type === self::FREEFORM_MEAN) {
                $mean = $this->parseDataBox($child);
            } elseif ($child->type === self::FREEFORM_NAME) {
                $name = $this->parseDataBox($child);
            }
        }

        if ($mean === null || $name === null) {
            return null;
        }

        return $mean . '.' . $name;
    }

    /**
     * Extracts the payload from a `data` box, normalising known text encodings.
     *
     * QuickTime metadata stores textual values using numeric type codes inside the
     * `data` box header. The parser treats UTF-8 (1), UTF-16 big-endian (2), and
     * MacRoman (7) values as text payloads with optional NUL-termination and
     * trims the trailing terminators so that callers receive clean strings.
     * Binary payload types are returned untouched.
     *
     * @param object $data Box descriptor for the `data` box.
     *
     * @return string|null
     */
    private function parseDataBox(object $data): ?string
    {
        $win = $data->window;
        $win->seek(0);
        if ($data->contentSize < 8) {
            throw new ParseError('data box too small');
        }
        $type = $win->readU32BE();
        $win->readU32BE(); // locale
        $payloadSize = $data->contentSize - 8;
        $payload     = $payloadSize > 0 ? $win->read($payloadSize) : '';

        if (
            $type === self::DATA_TYPE_UTF8
            || $type === self::DATA_TYPE_UTF16
            || $type === self::DATA_TYPE_MAC_ROMAN
        ) {
            return trim($payload, "\0");
        }

        return $payload;
    }

    /**
     * Merges two associative arrays while keeping values from the right-hand side.
     *
     * @param array<string, string> $left
     * @param array<string, string> $right
     *
     * @return array<string, string>
     */
    private function mergeAssociative(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $left[$key] = $value;
        }

        return $left;
    }

    /**
     * Determines whether the given item descriptor represents EXIF content.
     *
     * @param array{id: int, itemType: ?string, name: ?string, contentType: ?string} $info
     */
    private function isExifItem(array $info): bool
    {
        if (isset($info['itemType']) && strcasecmp((string) $info['itemType'], self::BOX_EXIF) === 0) {
            return true;
        }
        if (isset($info['name']) && strcasecmp((string) $info['name'], self::BOX_EXIF) === 0) {
            return true;
        }
        if (isset($info['contentType'])) {
            $ct = strtolower((string) $info['contentType']);

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
        if (!isset($info['contentType'])) {
            return false;
        }

        return strtolower((string) $info['contentType']) === 'application/rdf+xml';
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
            3       => unpack('N', "\0" . $window->read(3))[1],
            4       => $window->readU32BE(),
            8       => $window->readU64BE(),
            default => throw new ParseError("unsupported integer size $bytes"),
        };
    }

    /**
     * Validates ISO BMFF length-size nibbles and returns the byte width.
     *
     * @param int $nibble Raw nibble extracted from the length-size field.
     *
     * @return int
     */
    private function validateSizeNibble(int $nibble): int
    {
        return match ($nibble) {
            0 => 0,
            1, 2, 4, 8 => $nibble,
            default => throw new ParseError('invalid length field size'),
        };
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
        return strlen($fourcc) === 4 && preg_match('/^[\x20-\x7E]{4}$/', $fourcc) === 1;
    }

    /**
     * Converts a four-character code into its integer representation.
     *
     * @param string $fourcc Four-character code to convert.
     *
     * @return int|null
     */
    private function fourccToIndex(string $fourcc): ?int
    {
        if (strlen($fourcc) !== 4) {
            return null;
        }

        $value = unpack('N', $fourcc)[1];

        return $value > 0 ? $value : null;
    }

    /**
     * Iterates through child boxes within a container, yielding descriptors.
     *
     * @param object $parent Parent box descriptor whose content is iterated.
     * @param int    $offset Optional relative byte offset where iteration begins.
     *
     * @return iterable<object{type:string,size:int,offset:int,contentOffset:int,contentSize:int,window:StreamWindow,userType:?string}>
     */
    private function walkChildren(object $parent, int $offset = 0): iterable
    {
        if ($offset < 0 || $offset > $parent->contentSize) {
            throw new ParseError('child offset outside container');
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
            throw new ParseError('child boxes do not align with parent');
        }
    }

    /**
     * Reads a box header at the given offset and returns a descriptor object.
     *
     * @param int $offset Absolute byte offset of the box within the stream.
     * @param int $limit  Limit offset that bounds the container.
     *
     * @return object
     */
    private function readBoxAt(int $offset, int $limit): object
    {
        if ($offset < 0 || $offset > $limit) {
            throw new ParseError('box offset outside container');
        }

        $this->stream->seek($offset);
        $size32     = $this->stream->readU32BE();
        $type       = $this->stream->read(4);
        $headerSize = 8;
        $size       = $size32;

        if ($size32 === 0) {
            $size = $limit - $offset;
        } elseif ($size32 === 1) {
            $size = $this->stream->readU64BE();
            $headerSize += 8;
        }

        $userType = null;
        if ($type === self::BOX_UUID) {
            $userType = $this->stream->read(16);
            $headerSize += 16;
        }

        if ($size < $headerSize) {
            throw new ParseError("invalid box size for $type");
        }
        if ($offset + $size > $limit) {
            throw new ParseError("box $type exceeds container bounds");
        }

        $contentOffset = $offset + $headerSize;
        $contentSize   = $size - $headerSize;
        $window        = $this->stream->window($contentOffset, $contentSize);

        return (object) [
            'type'          => $type,
            'size'          => $size,
            'offset'        => $offset,
            'contentOffset' => $contentOffset,
            'contentSize'   => $contentSize,
            'window'        => $window,
            'userType'      => $userType,
        ];
    }
}
