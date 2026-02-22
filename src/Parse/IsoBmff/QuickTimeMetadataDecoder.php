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
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\ParserLimits;

use function array_key_exists;
use function count;
use function iconv;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function ksort;
use function mb_check_encoding;
use function ord;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Decodes QuickTime metadata structures (keys, ilst, data atoms, locale lists)
 * extracted from ISO BMFF containers.
 *
 * @phpstan-type QuickTimeValue = string|int|float|bool
 * @phpstan-type QuickTimeKeyMap = array<string, QuickTimeValue>
 * @phpstan-type QuickTimeKeyEntry = array{namespace: string, name: string}
 * @phpstan-type QuickTimeRawDataAtom = array{type: int, locale: int, value: string|int|float, nestedKeys?: QuickTimeKeyMap, nestedAtoms?: QuickTimeDataAtomList}
 * @phpstan-type QuickTimeCoercedDataAtom = array{type: int, locale: int, value: string|int|float|bool}
 * @phpstan-type QuickTimeDataAtomList = array<string, list<QuickTimeCoercedDataAtom>>
 */
final readonly class QuickTimeMetadataDecoder
{
    /**
     * QuickTime `data` box type code for UTF-8 encoded text payloads.
     */
    private const int DATA_TYPE_UTF8 = 1;

    /**
     * QuickTime `data` box type code for UTF-16 (big-endian) encoded text payloads.
     */
    private const int DATA_TYPE_UTF16 = 2;

    /**
     * QuickTime `data` box type code for Shift-JIS encoded text payloads.
     * QuickTime File Format 2012, Table 3-5, type code 3.
     */
    private const int DATA_TYPE_SHIFT_JIS = 3;

    /**
     * QuickTime `data` box type code for UTF-8 sort-string text payloads.
     * QuickTime File Format 2012, Table 3-5, type code 4.
     */
    private const int DATA_TYPE_UTF8_SORT = 4;

    /**
     * QuickTime `data` box type code for UTF-16BE sort-string text payloads.
     * QuickTime File Format 2012, Table 3-5, type code 5.
     */
    private const int DATA_TYPE_UTF16_SORT = 5;

    /**
     * QuickTime `data` box type code for classic MacRoman encoded text payloads.
     */
    private const int DATA_TYPE_MAC_ROMAN = 7;

    /**
     * QuickTime `data` box type code for JPEG payloads in JFIF-compatible wrapper.
     * QuickTime File Format 2012, Table 3-5, type code 13.
     */
    private const int DATA_TYPE_JPEG_WRAPPER = 0x0D;

    /**
     * QuickTime `data` box type code for PNG payloads in PNG wrapper.
     * QuickTime File Format 2012, Table 3-5, type code 14.
     */
    private const int DATA_TYPE_PNG_WRAPPER = 0x0E;

    /**
     * QuickTime `data` box type code for BMP payloads in Windows bitmap wrapper.
     * QuickTime File Format 2012, Table 3-5, type code 27.
     */
    private const int DATA_TYPE_BMP_WRAPPER = 0x1B;

    /**
     * QuickTime `data` box type code for signed big-endian integer payloads.
     * QuickTime File Format 2012, Table 3-5, type code 21.
     */
    private const int DATA_TYPE_SIGNED_INT = 0x15;

    /**
     * QuickTime `data` box type code for unsigned big-endian integer payloads.
     * QuickTime File Format 2012, Table 3-5, type code 22.
     */
    private const int DATA_TYPE_UNSIGNED_INT = 0x16;

    /**
     * QuickTime `data` box type code for 32-bit big-endian floating point payloads.
     * QuickTime File Format 2012, Table 3-5, type code 23.
     */
    private const int DATA_TYPE_FLOAT32 = 0x17;

    /**
     * QuickTime `data` box type code for 64-bit big-endian floating point payloads.
     * QuickTime File Format 2012, Table 3-5, type code 24.
     */
    private const int DATA_TYPE_FLOAT64 = 0x18;

    /**
     * QuickTime `data` box type code for nested metadata atom payloads.
     * QuickTime File Format 2012, Table 3-5, type code 28.
     */
    private const int DATA_TYPE_NESTED_METADATA = 0x1C;

    /**
     * FourCC for QuickTime mean payload in free-form metadata.
     */
    private const string FREEFORM_MEAN = 'mean';

    /**
     * FourCC for QuickTime name payload in free-form metadata.
     */
    private const string FREEFORM_NAME = 'name';

    /**
     * QuickTime 'mdta' FourCC identifying the metadata type scheme.
     *
     * Used as (1) the handler reference type in the metadata hdlr box
     * (QuickTime File Format 2012, "Metadata Atom") and (2) the key namespace
     * in the keys atom ("Metadata item keys atom"), where the key_value is a
     * UTF-8 reverse-DNS string (e.g. 'com.apple.quicktime.content.identifier').
     */
    public const string QUICKTIME_MDTA = 'mdta';

    /**
     * Maximum number of entries in a ctry/lang locale list atom.
     *
     * Protocol-defined ceiling (fits in a single byte).
     */
    private const int MAX_LOCALE_LIST_ENTRIES = 255;

    /**
     * QuickTime metadata keys that should be coerced into expected value types.
     *
     * @var array<string, 'int'|'float'|'bool'|'string'>
     */
    private const array QUICKTIME_KEY_TYPES = [
        'com.apple.quicktime.videoOrientation'             => 'int',
        'com.apple.quicktime.location.accuracy.horizontal' => 'float',
        'com.apple.quicktime.location.accuracy.vertical'   => 'float',
        'com.apple.quicktime.isHDRVideo'                   => 'bool',
        'com.apple.quicktime.make'                         => 'string',
        'com.apple.quicktime.software'                     => 'string',
    ];

    /**
     * QuickTime direct udta text atoms mapped to normalized metadata keys.
     *
     * QuickTime File Format 2012 §2 "User Data Atoms" defines direct movie/track/media-level
     * user-data atoms that carry textual metadata.
     *
     * @var array<string, string>
     */
    private const array UDTA_TEXT_KEYS = [
        "\xA9nam" => 'com.apple.quicktime.title',
        "\xA9ART" => 'com.apple.quicktime.artist',
        "\xA9alb" => 'com.apple.quicktime.album',
        "\xA9cmt" => 'com.apple.quicktime.comment',
        "\xA9day" => 'com.apple.quicktime.creationDate',
    ];

    /**
     * @param Stream                                                                      $stream               Stream positioned at the beginning of the media file to parse.
     * @param Closure(string): array{keys: QuickTimeKeyMap, atoms: QuickTimeDataAtomList} $nestedMetadataParser Closure that parses nested type-28 metadata payloads.
     */
    public function __construct(
        private Stream $stream,
        private Closure $nestedMetadataParser,
    ) {
    }

    /**
     * Merges QuickTime key mappings from multiple sources.
     *
     * @param QuickTimeKeyMap                     $existing      Existing key mappings.
     * @param list<array<int, QuickTimeKeyEntry>> $keysMaps      Key map data from 'keys' boxes.
     * @param list<BoxDescriptor>                 $ilstBoxes     Item list box descriptors.
     * @param QuickTimeDataAtomList               $existingAtoms Existing data atom list.
     * @param bool                                $hasMhdr       Whether a metadata header atom was found.
     * @param list<list<int>>                     $countryLists  Parsed country list arrays from ctry atom.
     * @param list<list<int>>                     $languageLists Parsed language list arrays from lang atom.
     *
     * @return array{0: QuickTimeKeyMap, 1: QuickTimeDataAtomList}
     */
    public function mergeQuickTimeKeys(array $existing, array $keysMaps, array $ilstBoxes, array $existingAtoms = [], bool $hasMhdr = false, array $countryLists = [], array $languageLists = [], bool $isMdta = false): array
    {
        /** @var array<int, QuickTimeKeyEntry> $keyIndex */
        $keyIndex   = [];
        $hasItemIds = false;

        // Flatten key maps so later entries override duplicate indexes.
        foreach ($keysMaps as $map) {
            foreach ($map as $idx => $entry) {
                $keyIndex[$idx] = $entry;
            }
        }

        // Merge all ilst entries into the cumulative QuickTime metadata set.
        foreach ($ilstBoxes as $ilst) {
            [$ilstKeys, $ilstAtoms, $ilstHasItemIds] = $this->parseIlst($ilst, $keyIndex, $countryLists, $languageLists, $isMdta);
            $existing                                = $this->mergeAssociative($existing, $ilstKeys);
            $existingAtoms                           = $this->mergeAtomLists($existingAtoms, $ilstAtoms);

            if ($ilstHasItemIds) {
                $hasItemIds = true;
            }
        }

        // QuickTime File Format 2012, "Metadata Header Atom": metadata header
        // atom must exist if metadata items contain item information atoms.
        if ($hasItemIds && !$hasMhdr) {
            throw new ParseError('metadata header atom (mhdr) required when ilst items have itif atoms', 1171);
        }

        return [$existing, $existingAtoms];
    }

    /**
     * Merges two associative arrays while keeping values from the right-hand side.
     *
     * @param QuickTimeKeyMap $left
     * @param QuickTimeKeyMap $right
     *
     * @return QuickTimeKeyMap
     */
    public function mergeAssociative(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $left[$key] = $value;
        }

        return $left;
    }

    /**
     * Parses a track name atom (`name`) inside a user data container.
     *
     * QuickTime File Format 2012, "Track Name": the name atom contains
     * a NULL-terminated UTF-8 string representing the track name.
     *
     * @param BoxDescriptor       $name    Box descriptor for the name atom.
     * @param IsoBmffParseContext $context Shared parse-state context.
     */
    public function parseUdtaNameAtom(BoxDescriptor $name, IsoBmffParseContext $context): void
    {
        if ($name->contentSize < 1) {
            return;
        }

        $win = $name->window;
        $win->seek(0);

        $raw = $win->read($name->contentSize);

        // Strip NULL terminator if present
        $value = rtrim($raw, "\0");

        if ($value === '') {
            return;
        }

        // Only set track name if not already present (movie-level takes precedence)
        if (!array_key_exists(QuickTimeMeta::TRACK_NAME_KEY, $context->qtKeys)) {
            $context->qtKeys[QuickTimeMeta::TRACK_NAME_KEY] = $value;
        }
    }

    /**
     * Parses recognized direct user-data text atoms inside udta containers.
     *
     * QuickTime File Format 2012 §2 "User Data Atoms": recognized atom types are
     * decoded, unknown atom types are ignored without failing the container parse.
     *
     * @param BoxDescriptor       $atom    Box descriptor for a direct udta child atom.
     * @param IsoBmffParseContext $context Shared parse-state context.
     */
    public function parseUdtaTextAtom(BoxDescriptor $atom, IsoBmffParseContext $context): void
    {
        $key = self::UDTA_TEXT_KEYS[$atom->type] ?? null;
        if ($key === null || $atom->contentSize < 1) {
            return;
        }

        $win = $atom->window;
        $win->seek(0);

        $raw   = $win->read($atom->contentSize);
        $value = rtrim($raw, "\0");

        if ($value === '') {
            return;
        }

        if (!array_key_exists($key, $context->qtKeys)) {
            $context->qtKeys[$key] = $value;
        }
    }

    /**
     * Parses the QuickTime metadata keys atom.
     *
     * QuickTime File Format 2012, "Metadata item keys atom": the keys atom contains
     * a 4-byte key_namespace and a variable-length key_value whose structure depends
     * on the namespace.
     *
     * @param BoxDescriptor $keys Box descriptor for the QuickTime `keys` box.
     *
     * @return array<int, QuickTimeKeyEntry>
     */
    public function parseKeys(BoxDescriptor $keys): array
    {
        $win = $keys->window;
        $win->seek(0);

        if ($keys->contentSize < 8) {
            throw new ParseError('keys box truncated', 1221);
        }

        // QuickTime File Format 2012, "Metadata item keys atom": the keys atom
        // is a FullAtom; version must be 0 and flags must be 0 for the defined
        // structure.
        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if (($version !== 0) || ($flags !== 0)) {
            throw new ParseError('keys box version/flags must be 0', 1222);
        }

        $entryCount = $win->readU32BE();

        if ($entryCount > ParserLimits::MAX_KEYS_ENTRIES) {
            throw new ParseError('keys entry count exceeds maximum allowed', 1223);
        }

        $map = [];
        $pos = $win->tell();

        for ($i = 1; $i <= $entryCount; ++$i) {
            if ($pos + 8 > $keys->contentSize) {
                throw new ParseError('keys entry truncated', 1224);
            }

            $win->seek($pos);
            $size      = $win->readU32BE();
            $namespace = $win->read(4);
            if (($size < 8) || (($pos + $size) > $keys->contentSize)) {
                throw new ParseError('invalid keys entry size', 1225);
            }

            // Validate key_namespace as proper 4CC code (printable ASCII)
            if (preg_match('/^[\x20-\x7E]{4}$/', $namespace) !== 1) {
                throw new ParseError('keys entry key_namespace is not a valid 4CC code', 1416);
            }

            // Reject empty key_value entries (size <= 8 means no actual key data)
            if ($size <= 8) {
                throw new ParseError('keys entry has empty key_value', 1417);
            }

            $name = $win->read($size - 8);

            if ($namespace === self::QUICKTIME_MDTA) {
                // QuickTime File Format 2012, "Metadata item keys atom": mdta key_value is
                // a NUL-terminated UTF-8 string; strip terminator before storing key names.
                if (!str_ends_with($name, "\0")) {
                    throw new ParseError('keys mdta key_value missing NUL terminator', 1455);
                }

                $name = substr($name, 0, -1);

                if ($name === '') {
                    throw new ParseError('keys mdta key_value is empty after NUL terminator removal', 1456);
                }

                if (str_contains($name, "\0")) {
                    throw new ParseError('keys mdta key_value contains embedded NUL bytes', 1457);
                }

                if (!mb_check_encoding($name, 'UTF-8')) {
                    throw new ParseError('keys mdta key_value contains invalid UTF-8', 1385);
                }
            }

            $map[$i] = [
                'namespace' => $namespace,
                'name'      => $name,
            ];
            $pos += $size;
        }

        if ($pos !== $keys->contentSize) {
            throw new ParseError('keys entries do not fill container', 1226);
        }

        return $map;
    }

    /**
     * Parses and validates a metadata header atom (`mhdr`).
     *
     * QuickTime File Format 2012, "Metadata Header Atom": the mhdr atom is
     * a full atom with version 0 and flags 0, containing a uint32 nextItemID.
     *
     * @param BoxDescriptor $mhdr Box descriptor for the mhdr atom.
     */
    public function parseMhdr(BoxDescriptor $mhdr): void
    {
        if ($mhdr->contentSize < 8) {
            throw new ParseError('mhdr atom truncated', 1237);
        }

        $win = $mhdr->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = ($win->readU8() << 16) | ($win->readU8() << 8) | $win->readU8();

        if ($version !== 0) {
            throw new ParseError('mhdr atom version must be 0', 1238);
        }

        if ($flags !== 0) {
            throw new ParseError('mhdr atom flags must be 0', 1239);
        }

        // nextItemID — read for validation but not currently exposed
        $win->readU32BE();
    }

    /**
     * Parses a locale list atom (ctry or lang) inside a QuickTime metadata container.
     *
     * QuickTime File Format 2012, "Country List Atom" (p. 133) / "Language List Atom"
     * (p. 134): both are FullAtoms (version=0, flags=0) containing a 32-bit entry_count
     * followed by entry_count arrays. Each array starts with a 16-bit item count followed
     * by that many 16-bit ISO codes (ISO 3166 for countries, packed ISO 639-2/T for languages).
     *
     * @param BoxDescriptor $box   Box descriptor for the ctry or lang atom.
     * @param string        $label Human-readable label for error messages ('ctry' or 'lang').
     *
     * @return list<list<int>> List of locale code arrays.
     */
    public function parseLocaleListAtom(BoxDescriptor $box, string $label): array
    {
        if ($box->contentSize < 8) {
            throw new ParseError(sprintf('%s atom truncated', $label), 1240);
        }

        $win = $box->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = ($win->readU8() << 16) | ($win->readU8() << 8) | $win->readU8();

        if ($version !== 0) {
            throw new ParseError(sprintf('%s atom version must be 0', $label), 1241);
        }

        if ($flags !== 0) {
            throw new ParseError(sprintf('%s atom flags must be 0', $label), 1242);
        }

        $entryCount = $win->readU32BE();

        if ($entryCount > self::MAX_LOCALE_LIST_ENTRIES) {
            throw new ParseError(sprintf('%s atom entry count %d exceeds maximum %d', $label, $entryCount, self::MAX_LOCALE_LIST_ENTRIES), 1243);
        }

        $entries  = [];
        $consumed = 8; // version(1) + flags(3) + entry_count(4)

        for ($i = 0; $i < $entryCount; ++$i) {
            if ($consumed + 2 > $box->contentSize) {
                throw new ParseError(sprintf('%s atom entry %d truncated (missing item count)', $label, $i + 1), 1244);
            }

            $itemCount = $win->readU16BE();
            $consumed += 2;

            $needed = $itemCount * 2;
            if ($consumed + $needed > $box->contentSize) {
                throw new ParseError(sprintf('%s atom entry %d truncated (expected %d codes, only %d bytes remain)', $label, $i + 1, $itemCount, $box->contentSize - $consumed), 1245);
            }

            $codes = [];
            for ($j = 0; $j < $itemCount; ++$j) {
                $codes[] = $win->readU16BE();
            }

            $entries[] = $codes;
            $consumed += $needed;
        }

        if ($consumed !== $box->contentSize) {
            throw new ParseError(sprintf('%s atom has %d trailing bytes after entries', $label, $box->contentSize - $consumed), 1246);
        }

        return $entries;
    }

    /**
     * Parses the iTunes-style list (`ilst`) box using the discovered key index.
     *
     * QuickTime File Format 2012, "Metadata item keys atom": the key_namespace determines
     * how the key_value is interpreted. For 'mdta' namespace, the key_value is used directly
     * as a reverse-DNS identifier. For other namespaces, the key is prefixed with the
     * namespace to prevent collisions.
     *
     * Returns the scalar key map (first value per key for backward compatibility) and a
     * structured atom list preserving all data atoms with their type and locale indicators.
     *
     * @param BoxDescriptor                 $ilst          Box descriptor for the `ilst` container.
     * @param array<int, QuickTimeKeyEntry> $keyIndex      Structured key entries from parseKeys().
     * @param list<list<int>>               $countryLists  Parsed country list arrays from ctry atom.
     * @param list<list<int>>               $languageLists Parsed language list arrays from lang atom.
     * @param bool                          $isMdta        Whether the handler type is mdta.
     *
     * @return array{0: QuickTimeKeyMap, 1: QuickTimeDataAtomList, 2: bool}
     */
    private function parseIlst(BoxDescriptor $ilst, array $keyIndex, array $countryLists = [], array $languageLists = [], bool $isMdta = false): array
    {
        $result      = [];
        $atomsList   = [];
        $seenNames   = [];
        $seenItemIds = [];

        foreach ($this->walkChildren($ilst) as $entry) {
            // QuickTime File Format 2012, Metadata Structure: "The free space atom
            // may not occur within any other subatom contained in the metadata atom."
            if ($entry->type === 'free' || $entry->type === 'skip') {
                throw new ParseError(
                    sprintf('free-space atom "%s" is not allowed inside ilst', $entry->type),
                    1501,
                );
            }

            $keyName  = null;
            $itemName = null;
            $index    = $this->fourccToIndex($entry->type);

            if (($index !== null) && isset($keyIndex[$index])) {
                $keyName = $this->resolveKeyName($keyIndex[$index]);
            } elseif ($entry->type === BoxType::FREEFORM->value) {
                $keyName = $this->parseFreeformKey($entry);
            } elseif ($isMdta) {
                // In mdta mode, ilst entries must reference keys by index
                if ($index !== null) {
                    throw new ParseError(sprintf(
                        'mdta ilst entry key index %d out of range',
                        $index,
                    ), 1386);
                }

                throw new ParseError(sprintf(
                    'mdta ilst entry type "%s" is not a valid key index',
                    $entry->type,
                ), 1386);
            } elseif ($this->isPrintableFourcc($entry->type)) {
                $keyName = $entry->type;
            }

            // Freeform entries ('----') handle 'name' children in parseFreeformKey()
            // as data boxes; only non-freeform entries use the spec Name atom.
            $isFreeform = ($entry->type === BoxType::FREEFORM->value);
            $entryAtoms = [];

            foreach ($this->walkChildren($entry) as $sub) {
                if ($sub->type === 'free' || $sub->type === 'skip') {
                    throw new ParseError(
                        sprintf('free-space atom "%s" is not allowed inside metadata item entry', $sub->type),
                        1502,
                    );
                }

                if ($sub->type === BoxType::DATA->value) {
                    $structured = $this->parseDataBoxStructured($sub);
                    $this->validateLocaleIndicator($structured['locale'], $countryLists, $languageLists);
                    $entryAtoms[] = $structured;

                    $effectiveKey = $keyName ?? $itemName;
                    if ($effectiveKey === null) {
                        continue;
                    }

                    if (
                        ($structured['type'] === self::DATA_TYPE_NESTED_METADATA)
                        && array_key_exists('nestedKeys', $structured)
                        && array_key_exists('nestedAtoms', $structured)
                    ) {
                        $this->mergeNestedType28Values(
                            $effectiveKey,
                            $structured['nestedKeys'],
                            $structured['nestedAtoms'],
                            $result,
                            $atomsList,
                            $structured['locale'],
                        );

                        continue;
                    }

                    $coerced = $this->coerceQuickTimeValue($effectiveKey, $structured['value']);

                    if (!array_key_exists($effectiveKey, $result)) {
                        $result[$effectiveKey] = $coerced;
                    }

                    $atomsList[$effectiveKey][] = [
                        'type'   => $structured['type'],
                        'locale' => $structured['locale'],
                        'value'  => $coerced,
                    ];
                } elseif ($sub->type === BoxType::NAME->value && !$isFreeform) {
                    $itemName = $this->parseIlstNameAtom($sub, $seenNames);

                    // Use name as fallback key when no key index or fourcc is available
                    if ($keyName === null) {
                        $keyName = $itemName;
                    }
                } elseif ($sub->type === BoxType::ITIF->value) {
                    $this->parseIlstItemInfo($sub, $seenItemIds);
                }
            }

            $this->validateDataOrdering($entry->type, $entryAtoms);
        }

        return [$result, $atomsList, $seenItemIds !== []];
    }

    /**
     * Parses a metadata item Name atom inside an ilst entry.
     *
     * QuickTime File Format 2012, "Metadata Item Atom / Name": the name atom
     * is a full atom with version 0 and flags 0. The payload is a UTF-8 string.
     * No two metadata items may share the same name.
     *
     * @param BoxDescriptor       $name      Box descriptor for the name atom.
     * @param array<string, true> $seenNames Previously encountered names for uniqueness check.
     *
     * @return string The validated name string.
     */
    private function parseIlstNameAtom(BoxDescriptor $name, array &$seenNames): string
    {
        if ($name->contentSize < 4) {
            throw new ParseError('ilst name atom truncated', 1227);
        }

        $win = $name->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = ($win->readU8() << 16) | ($win->readU8() << 8) | $win->readU8();

        if ($version !== 0) {
            throw new ParseError('ilst name atom version must be 0', 1228);
        }

        if ($flags !== 0) {
            throw new ParseError('ilst name atom flags must be 0', 1229);
        }

        $payloadSize = $name->contentSize - 4;
        if ($payloadSize < 1) {
            throw new ParseError('ilst name atom has empty payload', 1230);
        }

        $value = $win->read($payloadSize);

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new ParseError('ilst name atom contains invalid UTF-8', 1231);
        }

        if (array_key_exists($value, $seenNames)) {
            throw new ParseError(sprintf(
                'duplicate ilst name atom value "%s"',
                $value,
            ), 1232);
        }

        $seenNames[$value] = true;

        return $value;
    }

    /**
     * Parses an item information atom (`itif`) inside an ilst metadata item.
     *
     * QuickTime File Format 2012, "Metadata Item Atom / Item Information Atom":
     * the itif atom is a full atom with version 0 and flags 0, containing an
     * unsigned 32-bit Item_ID that must be unique within the metadata atom.
     *
     * @param BoxDescriptor    $itif        Box descriptor for the itif atom.
     * @param array<int, true> $seenItemIds Previously encountered Item_IDs for uniqueness check.
     */
    private function parseIlstItemInfo(BoxDescriptor $itif, array &$seenItemIds): void
    {
        if ($itif->contentSize < 8) {
            throw new ParseError('itif atom truncated', 1233);
        }

        $win = $itif->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = ($win->readU8() << 16) | ($win->readU8() << 8) | $win->readU8();

        if ($version !== 0) {
            throw new ParseError('itif atom version must be 0', 1234);
        }

        if ($flags !== 0) {
            throw new ParseError('itif atom flags must be 0', 1235);
        }

        $itemId = $win->readU32BE();

        if (array_key_exists($itemId, $seenItemIds)) {
            throw new ParseError(sprintf(
                'duplicate Item_ID %d in ilst itif atoms',
                $itemId,
            ), 1236);
        }

        $seenItemIds[$itemId] = true;
    }

    /**
     * Validates a locale indicator from a data atom against the available locale lists.
     *
     * QuickTime File Format 2012, "Locale Indicator" (p. 139): country and language
     * indicator values 1–255 are 1-based indices into the ctry/lang list atoms.
     * Values > 255 are direct ISO codes. Value 0 means default/any.
     *
     * @param int             $locale        32-bit locale indicator (country << 16 | language).
     * @param list<list<int>> $countryLists  Country list arrays from ctry atom.
     * @param list<list<int>> $languageLists Language list arrays from lang atom.
     */
    private function validateLocaleIndicator(int $locale, array $countryLists, array $languageLists): void
    {
        $country  = ($locale >> 16) & 0xFFFF;
        $language = $locale & 0xFFFF;

        if ($country >= 1 && $country <= 255) {
            if ($countryLists === []) {
                throw new ParseError(sprintf('data atom locale country index %d requires a ctry list atom', $country), 1247);
            }

            if ($country > count($countryLists)) {
                throw new ParseError(sprintf('data atom locale country index %d exceeds ctry list entry count %d', $country, count($countryLists)), 1248);
            }
        }

        if ($language >= 1 && $language <= 255) {
            if ($languageLists === []) {
                throw new ParseError(sprintf('data atom locale language index %d requires a lang list atom', $language), 1249);
            }

            if ($language > count($languageLists)) {
                throw new ParseError(sprintf('data atom locale language index %d exceeds lang list entry count %d', $language, count($languageLists)), 1250);
            }
        }
    }

    /**
     * Validates that metadata item data atoms are ordered from most-specific to most-general.
     *
     * QuickTime File Format 2012, "Data Ordering" (p. 142): applications may
     * stop searching once they encounter an acceptable locale/type pair, which
     * requires deterministic ordering from specific locale variants to defaults.
     *
     * @param string                     $entryType  Item entry type for diagnostics.
     * @param list<QuickTimeRawDataAtom> $entryAtoms Parsed data atoms in encounter order.
     */
    private function validateDataOrdering(string $entryType, array $entryAtoms): void
    {
        $previousSpecificity = null;

        foreach ($entryAtoms as $atom) {
            $specificity = $this->localeSpecificityScore($atom['locale']);

            if (($previousSpecificity !== null) && ($specificity > $previousSpecificity)) {
                throw new ParseError(sprintf(
                    'metadata item "%s" data values must be ordered from most-specific to most-general per QuickTime File Format 2012 Data Ordering (p. 142)',
                    $entryType,
                ), 1420);
            }

            $previousSpecificity = $specificity;
        }
    }

    /**
     * Computes the locale specificity score for ordering checks.
     *
     * Country and language indicators contribute one specificity point each.
     * A default locale (country=0, language=0) therefore ranks lowest.
     *
     * @param int $locale 32-bit locale indicator (country << 16 | language).
     */
    private function localeSpecificityScore(int $locale): int
    {
        $country  = ($locale >> 16) & 0xFFFF;
        $language = $locale & 0xFFFF;
        $score    = 0;

        if ($country !== 0) {
            ++$score;
        }

        if ($language !== 0) {
            ++$score;
        }

        return $score;
    }

    /**
     * Resolves a structured key entry into a string key name for the QuickTimeKeyMap.
     *
     * QuickTime File Format 2012, "Metadata item keys atom": for the 'mdta' namespace
     * the key_value is a reverse-DNS string used directly. For other namespaces the key
     * is prefixed with the 4-byte namespace to prevent collisions between naming schemes.
     *
     * @param QuickTimeKeyEntry $entry Structured key entry with namespace and name.
     */
    private function resolveKeyName(array $entry): string
    {
        if ($entry['namespace'] === self::QUICKTIME_MDTA) {
            return $entry['name'];
        }

        return $entry['namespace'] . ':' . $entry['name'];
    }

    /**
     * Parses a free-form metadata key (----) into a dotted namespace string.
     *
     * @param BoxDescriptor $entry Box descriptor representing the free-form entry.
     *
     * @return string|null
     */
    private function parseFreeformKey(BoxDescriptor $entry): ?string
    {
        $mean = null;
        $name = null;
        foreach ($this->walkChildren($entry) as $child) {
            if ($child->type === self::FREEFORM_MEAN) {
                $mean = $this->parseFreeformMean($child);
            } elseif ($child->type === self::FREEFORM_NAME) {
                $name = $this->parseFreeformName($child);
            }
        }

        if ($mean === null || $mean === '' || $name === null || $name === '') {
            return null;
        }

        return $mean . '.' . $name;
    }

    /**
     * Parses a free-form metadata Mean atom as a FullAtom.
     *
     * QuickTime File Format 2012: the mean atom is a FullAtom with version 0
     * and flags 0. The remaining payload is a UTF-8 namespace string.
     *
     * @param BoxDescriptor $mean Box descriptor for the mean atom.
     *
     * @return string The decoded namespace string.
     */
    private function parseFreeformMean(BoxDescriptor $mean): string
    {
        if ($mean->contentSize < 4) {
            throw new ParseError('mean atom truncated', 1429);
        }

        $win = $mean->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0) {
            throw new ParseError('mean atom version must be 0', 1430);
        }

        if ($flags !== 0) {
            throw new ParseError('mean atom flags must be 0', 1431);
        }

        $payloadSize = $mean->contentSize - 4;
        if ($payloadSize < 1) {
            throw new ParseError('mean atom has empty payload', 1432);
        }

        $value = $win->read($payloadSize);

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new ParseError('mean atom contains invalid UTF-8', 1433);
        }

        return $value;
    }

    /**
     * Parses a free-form metadata Name atom as a FullAtom.
     *
     * QuickTime File Format 2012: the name atom is a FullAtom with version 0
     * and flags 0. The remaining payload is a UTF-8 key name string.
     *
     * @param BoxDescriptor $name Box descriptor for the name atom.
     *
     * @return string The decoded key name string.
     */
    private function parseFreeformName(BoxDescriptor $name): string
    {
        if ($name->contentSize < 4) {
            throw new ParseError('name atom truncated', 1434);
        }

        $win = $name->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0) {
            throw new ParseError('name atom version must be 0', 1435);
        }

        if ($flags !== 0) {
            throw new ParseError('name atom flags must be 0', 1436);
        }

        $payloadSize = $name->contentSize - 4;
        if ($payloadSize < 1) {
            throw new ParseError('name atom has empty payload', 1437);
        }

        $value = $win->read($payloadSize);

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new ParseError('name atom contains invalid UTF-8', 1438);
        }

        return $value;
    }

    /**
     * Parses a `data` box into a structured array preserving the type indicator and locale.
     *
     * QuickTime File Format 2012, "Value Atom" (p. 139): the data atom header
     * contains a 32-bit type indicator and a 32-bit locale indicator. The type
     * indicator byte (bits 24–31) must be 0; the lower 24 bits identify the
     * well-known type. The locale indicator encodes country (upper 16 bits) and
     * language (lower 16 bits).
     *
     * @param BoxDescriptor $data Box descriptor for the `data` box.
     *
     * @return QuickTimeRawDataAtom
     */
    private function parseDataBoxStructured(BoxDescriptor $data): array
    {
        $win = $data->window;
        $win->seek(0);
        if ($data->contentSize < 8) {
            throw new ParseError('data box too small', 1251);
        }

        $type = $win->readU32BE();

        // QuickTime File Format 2012, "Type Indicator" (p. 139): the indicator
        // byte (bits 24–31) must be 0, meaning the type is drawn from the
        // well-known set. All other values are reserved.
        if (($type >> 24) !== 0) {
            throw new ParseError('data box type indicator byte must be 0', 1252);
        }

        $locale      = $win->readU32BE();
        $payloadSize = $data->contentSize - 8;
        $payload     = $payloadSize > 0 ? $win->read($payloadSize) : '';

        if ($type === self::DATA_TYPE_NESTED_METADATA) {
            $nested = ($this->nestedMetadataParser)($payload);

            return [
                'type'        => $type,
                'locale'      => $locale,
                'value'       => '',
                'nestedKeys'  => $nested['keys'],
                'nestedAtoms' => $nested['atoms'],
            ];
        }

        return [
            'type'   => $type,
            'locale' => $locale,
            'value'  => $this->decodeDataPayload($type, $payload, $payloadSize),
        ];
    }

    /**
     * Decodes a data box payload according to its well-known type code.
     *
     * @param int    $type        Well-known type code (24-bit).
     * @param string $payload     Raw payload bytes.
     * @param int    $payloadSize Length of the payload in bytes.
     *
     * @return string|int|float
     */
    private function decodeDataPayload(int $type, string $payload, int $payloadSize): string|int|float
    {
        if (($type === self::DATA_TYPE_UTF8) || ($type === self::DATA_TYPE_UTF8_SORT)) {
            if (!mb_check_encoding($payload, 'UTF-8')) {
                throw new ParseError('data box UTF-8 payload contains invalid byte sequence.', 1253);
            }

            // QuickTime File Format 2012, Table 3-5: UTF-8 variants are stored
            // as raw UTF-8 bytes without count/terminator metadata.
            return $payload;
        }

        if ($type === self::DATA_TYPE_SHIFT_JIS) {
            if (!mb_check_encoding($payload, 'SJIS')) {
                throw new ParseError('data box Shift-JIS payload contains malformed sequence.', 1450);
            }

            $converted = iconv('SJIS', 'UTF-8', $payload);
            if ($converted === false) {
                throw new ParseError('data box Shift-JIS payload contains malformed sequence.', 1450);
            }

            return rtrim($converted, "\0");
        }

        if (($type === self::DATA_TYPE_UTF16) || ($type === self::DATA_TYPE_UTF16_SORT)) {
            if (($payloadSize % 2) !== 0) {
                throw new ParseError('data box UTF-16BE payload has odd byte count.', 1254);
            }

            $converted = iconv('UTF-16BE', 'UTF-8', $payload);

            if ($converted === false) {
                throw new ParseError('data box UTF-16BE payload contains malformed sequence.', 1255);
            }

            return rtrim($converted, "\0");
        }

        if ($type === self::DATA_TYPE_JPEG_WRAPPER) {
            if (($payloadSize < 2) || (!str_starts_with($payload, "\xFF\xD8"))) {
                throw new ParseError('data box type 13 payload does not match JPEG/JFIF signature.', 1467);
            }

            return $payload;
        }

        if ($type === self::DATA_TYPE_PNG_WRAPPER) {
            if (($payloadSize < 8) || (!str_starts_with($payload, "\x89PNG\x0D\x0A\x1A\x0A"))) {
                throw new ParseError('data box type 14 payload does not match PNG signature.', 1468);
            }

            return $payload;
        }

        if ($type === self::DATA_TYPE_BMP_WRAPPER) {
            if (($payloadSize < 2) || (!str_starts_with($payload, 'BM'))) {
                throw new ParseError('data box type 27 payload does not match BMP signature.', 1469);
            }

            return $payload;
        }

        $trimmed = trim($payload, "\0");

        if ($type === self::DATA_TYPE_MAC_ROMAN) {
            $converted = iconv('macintosh', 'UTF-8//IGNORE', $trimmed);

            if ($converted !== false) {
                return trim($converted, "\0");
            }

            return $trimmed;
        }

        // QuickTime File Format 2012 Table 3-5: type 21/22 encode integers
        // in 1, 2, 3, or 4 bytes (big-endian).
        if ($type === self::DATA_TYPE_SIGNED_INT) {
            return $this->decodeQuickTimeSignedInt($payload, $payloadSize);
        }

        if ($type === self::DATA_TYPE_UNSIGNED_INT) {
            return $this->decodeQuickTimeUnsignedInt($payload, $payloadSize);
        }

        if ($type === self::DATA_TYPE_FLOAT32) {
            // Reject truncated float32 payloads
            if ($payloadSize < 4) {
                throw new ParseError('data box float32 payload truncated', 1418);
            }

            if ($payloadSize > 4) {
                throw new ParseError('data box float32 payload must be exactly 4 bytes', 1418);
            }

            return Unpack::float('G', substr($payload, 0, 4), 'QuickTime float32 payload');
        }

        if ($type === self::DATA_TYPE_FLOAT64) {
            // Reject truncated float64 payloads
            if ($payloadSize < 8) {
                throw new ParseError('data box float64 payload truncated', 1419);
            }

            if ($payloadSize > 8) {
                throw new ParseError('data box float64 payload must be exactly 8 bytes', 1419);
            }

            return Unpack::float('E', substr($payload, 0, 8), 'QuickTime float64 payload');
        }

        return $payload;
    }

    /**
     * Decodes a variable-width big-endian signed integer from a QuickTime data box.
     *
     * QuickTime File Format 2012, Table 3-5: type 21 supports 1, 2, 3, or 4 byte payloads.
     *
     * @param string $payload     Raw payload bytes.
     * @param int    $payloadSize Length of the payload in bytes.
     *
     * @return int Decoded signed integer value.
     */
    private function decodeQuickTimeSignedInt(string $payload, int $payloadSize): int
    {
        $unsigned = $this->decodeQuickTimeUnsignedInt($payload, $payloadSize);
        $signBit  = 1 << (($payloadSize * 8) - 1);

        return ($unsigned >= $signBit) ? ($unsigned - ($signBit << 1)) : $unsigned;
    }

    /**
     * Decodes a variable-width big-endian unsigned integer from a QuickTime data box.
     *
     * QuickTime File Format 2012, Table 3-5: type 22 supports 1, 2, 3, or 4 byte payloads.
     *
     * @param string $payload     Raw payload bytes.
     * @param int    $payloadSize Length of the payload in bytes.
     *
     * @return int Decoded unsigned integer value.
     */
    private function decodeQuickTimeUnsignedInt(string $payload, int $payloadSize): int
    {
        if ($payloadSize < 1 || $payloadSize > 4) {
            throw new ParseError(
                sprintf('QuickTime integer payload must be 1–4 bytes, got %d', $payloadSize),
                1464,
            );
        }

        $value = 0;
        for ($i = 0; $i < $payloadSize; ++$i) {
            $value = ($value << 8) | ord($payload[$i]);
        }

        return $value;
    }

    /**
     * Coerces QuickTime metadata values into expected value types when possible.
     *
     * @param string         $key
     * @param QuickTimeValue $value
     *
     * @return QuickTimeValue
     */
    private function coerceQuickTimeValue(string $key, string|int|float|bool $value): string|int|float|bool
    {
        /** @var 'int'|'float'|'bool'|'string'|null $targetType */
        $targetType = self::QUICKTIME_KEY_TYPES[$key] ?? null;
        if ($targetType === null) {
            return $value;
        }

        return match ($targetType) {
            'int'   => $this->parseQuickTimeInt($value) ?? $value,
            'float' => $this->parseQuickTimeFloat($value) ?? $value,
            'bool'  => $this->parseQuickTimeBool($value) ?? $value,
            default => is_string($value) ? $value : (string) $value,
        };
    }

    /**
     * Converts QuickTime metadata values into integers when possible.
     *
     * @param QuickTimeValue $value
     *
     * @return int|null
     */
    private function parseQuickTimeInt(string|int|float|bool $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value)) {
            $intValue = (int) $value;

            return (float) $intValue === $value ? $intValue : null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Converts QuickTime metadata values into floats when possible.
     *
     * @param QuickTimeValue $value
     *
     * @return float|null
     */
    private function parseQuickTimeFloat(string|int|float|bool $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Converts QuickTime metadata values into booleans when possible.
     *
     * @param QuickTimeValue $value
     *
     * @return bool|null
     */
    private function parseQuickTimeBool(string|int|float|bool $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    /**
     * Appends data atom entries from the incoming list into the existing list.
     *
     * @param QuickTimeDataAtomList $existing Accumulated atom lists.
     * @param QuickTimeDataAtomList $incoming New atom lists to merge.
     *
     * @return QuickTimeDataAtomList
     */
    private function mergeAtomLists(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $atoms) {
            foreach ($atoms as $atom) {
                $existing[$key][] = $atom;
            }
        }

        return $existing;
    }

    /**
     * Flattens nested type-28 metadata into deterministic parent-prefixed keys.
     *
     * @param string                $parentKey    Effective key name of the parent data atom.
     * @param QuickTimeKeyMap       $nestedKeys   Nested key/value pairs.
     * @param QuickTimeDataAtomList $nestedAtoms  Nested data atom entries.
     * @param QuickTimeKeyMap       $result       Accumulated top-level key map.
     * @param QuickTimeDataAtomList $atomsList    Accumulated top-level data atom list.
     * @param int                   $parentLocale Locale indicator of the parent data atom.
     */
    private function mergeNestedType28Values(
        string $parentKey,
        array $nestedKeys,
        array $nestedAtoms,
        array &$result,
        array &$atomsList,
        int $parentLocale,
    ): void {
        ksort($nestedKeys);

        foreach ($nestedKeys as $nestedKey => $nestedValue) {
            $flattenedKey = $parentKey . '.' . $nestedKey;
            $coerced      = $this->coerceQuickTimeValue($flattenedKey, $nestedValue);

            if (!array_key_exists($flattenedKey, $result)) {
                $result[$flattenedKey] = $coerced;
            }

            if (array_key_exists($nestedKey, $nestedAtoms)) {
                foreach ($nestedAtoms[$nestedKey] as $atom) {
                    $atomsList[$flattenedKey][] = [
                        'type'   => $atom['type'],
                        'locale' => $atom['locale'],
                        'value'  => $this->coerceQuickTimeValue($flattenedKey, $atom['value']),
                    ];
                }

                continue;
            }

            $atomsList[$flattenedKey][] = [
                'type'   => self::DATA_TYPE_NESTED_METADATA,
                'locale' => $parentLocale,
                'value'  => $coerced,
            ];
        }
    }

    // ── Box-reading infrastructure (duplicated from IsoBmffParser) ─────────

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

        $value = Unpack::int('N', $fourcc, 'four-character code');

        return $value > 0 ? $value : null;
    }
}
