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
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;

use function array_key_exists;
use function ksort;
use function mb_check_encoding;
use function rtrim;
use function sprintf;

/**
 * Orchestrates QuickTime metadata decoding (keys, ilst, data atoms, locale lists).
 *
 * Delegates key resolution to {@see QuickTimeKeyResolver}, value decoding to
 * {@see QuickTimeValueDecoder}, and box navigation to {@see BoxNavigator}.
 * Implements the metadata atom model defined in QuickTime File Format 2012 §9.
 *
 * @phpstan-import-type QuickTimeValue from QuickTimeValueDecoder
 * @phpstan-import-type QuickTimeKeyMap from QuickTimeValueDecoder
 * @phpstan-import-type QuickTimeKeyEntry from QuickTimeValueDecoder
 * @phpstan-import-type QuickTimeRawDataAtom from QuickTimeValueDecoder
 * @phpstan-import-type QuickTimeCoercedDataAtom from QuickTimeValueDecoder
 * @phpstan-import-type QuickTimeDataAtomList from QuickTimeValueDecoder
 */
final readonly class QuickTimeMetadataDecoder
{
    /**
     * QuickTime direct udta text atoms mapped to normalized metadata keys.
     *
     * QuickTime File Format 2012 §2 "User Data Atoms" defines direct movie/track/media-level
     * user-data atoms that carry textual metadata.
     *
     * @var array<string, string>
     */
    private const array UDTA_TEXT_KEYS   = [
        "\xA9nam" => 'com.apple.quicktime.title',
        "\xA9ART" => 'com.apple.quicktime.artist',
        "\xA9alb" => 'com.apple.quicktime.album',
        "\xA9cmt" => 'com.apple.quicktime.comment',
        "\xA9day" => 'com.apple.quicktime.creationDate',
        "\xA9cpy" => 'com.apple.quicktime.copyright',
        "\xA9too" => 'com.apple.quicktime.software',
        "\xA9gen" => 'com.apple.quicktime.genre',
        "\xA9mak" => 'com.apple.quicktime.make',
        "\xA9mod" => 'com.apple.quicktime.model',
        "\xA9swr" => 'com.apple.quicktime.software',
        "\xA9wrt" => 'com.apple.quicktime.author',
        "\xA9xyz" => 'com.apple.quicktime.location.ISO6709',
        "\xA9arg" => 'com.apple.quicktime.arranger',
        "\xA9ark" => 'com.apple.quicktime.arrangerKeywords',
        "\xA9cok" => 'com.apple.quicktime.composerKeywords',
        "\xA9com" => 'com.apple.quicktime.composer',
        "\xA9dir" => 'com.apple.quicktime.director',
        "\xA9ed1" => 'com.apple.quicktime.editDate1',
        "\xA9ed2" => 'com.apple.quicktime.editDate2',
        "\xA9ed3" => 'com.apple.quicktime.editDate3',
        "\xA9ed4" => 'com.apple.quicktime.editDate4',
        "\xA9ed5" => 'com.apple.quicktime.editDate5',
        "\xA9ed6" => 'com.apple.quicktime.editDate6',
        "\xA9ed7" => 'com.apple.quicktime.editDate7',
        "\xA9ed8" => 'com.apple.quicktime.editDate8',
        "\xA9ed9" => 'com.apple.quicktime.editDate9',
        "\xA9fmt" => 'com.apple.quicktime.format',
        "\xA9inf" => 'com.apple.quicktime.information',
        "\xA9isr" => 'com.apple.quicktime.ISRCCode',
        "\xA9lab" => 'com.apple.quicktime.recordLabel',
        "\xA9lal" => 'com.apple.quicktime.recordLabelURL',
        "\xA9mal" => 'com.apple.quicktime.makerURL',
        "\xA9nak" => 'com.apple.quicktime.titleKeywords',
        "\xA9pdk" => 'com.apple.quicktime.producerKeywords',
        "\xA9phg" => 'com.apple.quicktime.recordingCopyright',
        "\xA9prd" => 'com.apple.quicktime.producer',
        "\xA9prf" => 'com.apple.quicktime.performers',
        "\xA9prk" => 'com.apple.quicktime.mainArtistKeywords',
        "\xA9prl" => 'com.apple.quicktime.mainArtistURL',
        "\xA9req" => 'com.apple.quicktime.requirements',
        "\xA9snk" => 'com.apple.quicktime.subtitleKeywords',
        "\xA9snm" => 'com.apple.quicktime.subtitle',
        "\xA9src" => 'com.apple.quicktime.sourceCredits',
        "\xA9swf" => 'com.apple.quicktime.songwriter',
        "\xA9swk" => 'com.apple.quicktime.songwriterKeywords',
    ];

    /**
     * Recognized non-text user data atoms mapped to metadata keys.
     *
     * @var array<string, array{key: string, size: int, type: string}>
     */
    private const array UDTA_BINARY_KEYS = [
        'LOOP' => ['key' => 'com.apple.quicktime.loopStyle',         'size' => 4, 'type' => 'u32'],
        'SelO' => ['key' => 'com.apple.quicktime.playSelectionOnly', 'size' => 1, 'type' => 'u8'],
        'AllF' => ['key' => 'com.apple.quicktime.playAllFrames',     'size' => 1, 'type' => 'u8'],
        'WLOC' => ['key' => 'com.apple.quicktime.windowLocation',   'size' => 4, 'type' => 'u16pair'],
    ];

    /**
     * @param BoxNavigator          $boxNavigator Shared box navigation infrastructure.
     * @param QuickTimeKeyResolver  $keyResolver  Resolves key namespaces and free-form keys.
     * @param QuickTimeValueDecoder $valueDecoder Decodes type-specific values and coerces types.
     */
    public function __construct(
        private BoxNavigator $boxNavigator,
        private QuickTimeKeyResolver $keyResolver,
        private QuickTimeValueDecoder $valueDecoder,
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
     * @param bool                                $isMdta        Whether the handler type is mdta.
     * @param bool                                $isMdir        Whether the handler type is mdir.
     *
     * @return array{0: QuickTimeKeyMap, 1: QuickTimeDataAtomList}
     */
    public function mergeQuickTimeKeys(array $existing, array $keysMaps, array $ilstBoxes, array $existingAtoms = [], bool $hasMhdr = false, array $countryLists = [], array $languageLists = [], bool $isMdta = false, bool $isMdir = false): array
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
            [$ilstKeys, $ilstAtoms, $ilstHasItemIds] = $this->parseIlst($ilst, $keyIndex, $countryLists, $languageLists, $isMdta, $isMdir);
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

        $win   = $name->window;
        $win->seek(0);

        $raw   = $win->read($name->contentSize);

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
        $key   = self::UDTA_TEXT_KEYS[$atom->type] ?? null;

        if ($key === null || $atom->contentSize < 1) {
            return;
        }

        $win   = $atom->window;
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
     * Returns whether the given atom type is a recognized binary udta atom.
     *
     * @param string $type Four-character atom type.
     */
    public function isUdtaBinaryAtom(string $type): bool
    {
        return array_key_exists($type, self::UDTA_BINARY_KEYS);
    }

    /**
     * Parses a recognized non-text binary user-data atom inside udta containers.
     *
     * QuickTime File Format 2012 §2 "User Data Atoms": recognized binary atom types
     * are decoded into their respective metadata keys. Unknown atom types are ignored.
     *
     * @param BoxDescriptor       $atom    Box descriptor for a direct udta child atom.
     * @param IsoBmffParseContext $context Shared parse-state context.
     */
    public function parseUdtaBinaryAtom(BoxDescriptor $atom, IsoBmffParseContext $context): void
    {
        $spec  = self::UDTA_BINARY_KEYS[$atom->type] ?? null;

        if (($spec === null) || ($atom->contentSize < $spec['size'])) {
            return;
        }

        $win   = $atom->window;
        $win->seek(0);

        $value = match ($spec['type']) {
            'u32'     => $win->readU32BE(),
            'u8'      => $win->readU8(),
            'u16pair' => sprintf('%d %d', $win->readU16BE(), $win->readU16BE()),
        };

        $key   = $spec['key'];

        if (!array_key_exists($key, $context->qtKeys)) {
            $context->qtKeys[$key] = $value;
        }
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
        $this->validateFullAtomHeader($mhdr, 'mhdr', 8, 1237, 1238, 1239);

        // nextItemID — read for validation but not currently exposed
        $win = $mhdr->window;
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
        $this->validateFullAtomHeader($box, $label, 8, 1240, 1241, 1242);
        $win        = $box->window;
        $entryCount = $win->readU32BE();

        if ($entryCount > QuickTimeValueDecoder::MAX_LOCALE_LIST_ENTRIES) {
            throw new ParseError(sprintf('%s atom entry count %d exceeds maximum %d', $label, $entryCount, QuickTimeValueDecoder::MAX_LOCALE_LIST_ENTRIES), 1243);
        }

        $entries    = [];
        $consumed   = 8; // version(1) + flags(3) + entry_count(4)

        for ($i = 0; $i < $entryCount; ++$i) {
            if ($consumed + 2 > $box->contentSize) {
                throw new ParseError(sprintf('%s atom entry %d truncated (missing item count)', $label, $i + 1), 1244);
            }

            $itemCount = $win->readU16BE();
            $consumed += 2;

            $needed    = $itemCount * 2;

            if ($consumed + $needed > $box->contentSize) {
                throw new ParseError(sprintf('%s atom entry %d truncated (expected %d codes, only %d bytes remain)', $label, $i + 1, $itemCount, $box->contentSize - $consumed), 1245);
            }

            $codes     = [];

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
     * @param bool                          $isMdir        Whether the handler type is mdir.
     *
     * @return array{0: QuickTimeKeyMap, 1: QuickTimeDataAtomList, 2: bool}
     */
    private function parseIlst(BoxDescriptor $ilst, array $keyIndex, array $countryLists = [], array $languageLists = [], bool $isMdta = false, bool $isMdir = false): array
    {
        $result      = [];
        $atomsList   = [];
        $seenNames   = [];
        $seenItemIds = [];

        foreach ($this->boxNavigator->walkChildren($ilst) as $entry) {
            // QuickTime File Format 2012, Metadata Structure: "The free space atom
            // may not occur within any other subatom contained in the metadata atom."
            if ($entry->type === 'free' || $entry->type === 'skip') {
                throw new ParseError(
                    sprintf('free-space atom "%s" is not allowed inside ilst', $entry->type),
                    1501,
                );
            }

            $keyName    = null;
            $itemName   = null;
            $index      = $this->valueDecoder->fourccToIndex($entry->type);

            if (($index !== null) && isset($keyIndex[$index])) {
                $keyName = $this->keyResolver->resolveKeyName($keyIndex[$index]);
            } elseif ($entry->type === BoxType::FREEFORM->value) {
                $keyName = $this->keyResolver->parseFreeformKey($entry);
            } elseif ($isMdta) {
                // Tolerate out-of-range or non-numeric key indices in mdta mode
                continue;
            } elseif ($isMdir) {
                $keyName = $this->keyResolver->resolveMdirKey($entry->type);

                if ($keyName === null) {
                    if ($this->boxNavigator->isPrintableFourcc($entry->type)) {
                        $keyName = $entry->type;
                    } else {
                        continue;
                    }
                }
            } elseif ($this->boxNavigator->isPrintableFourcc($entry->type)) {
                $keyName = $entry->type;
            }

            // Freeform entries ('----') handle 'name' children in parseFreeformKey()
            // as data boxes; only non-freeform entries use the spec Name atom.
            $isFreeform = ($entry->type === BoxType::FREEFORM->value);
            $entryAtoms = [];

            foreach ($this->boxNavigator->walkChildren($entry) as $sub) {
                if ($sub->type === 'free' || $sub->type === 'skip') {
                    throw new ParseError(
                        sprintf('free-space atom "%s" is not allowed inside metadata item entry', $sub->type),
                        1502,
                    );
                }

                if ($sub->type === BoxType::DATA->value) {
                    $structured                 = $this->valueDecoder->parseDataBoxStructured($sub);
                    $this->valueDecoder->validateLocaleIndicator($structured['locale'], $countryLists, $languageLists);
                    $entryAtoms[]               = $structured;

                    $effectiveKey               = $keyName ?? $itemName;

                    if ($effectiveKey === null) {
                        continue;
                    }

                    if (($structured['type'] === QuickTimeDataType::NestedMetadata->value) && array_key_exists('nestedKeys', $structured) && array_key_exists('nestedAtoms', $structured)) {
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

                    $coerced                    = $this->valueDecoder->coerceQuickTimeValue($effectiveKey, $structured['value']);

                    if (!array_key_exists($effectiveKey, $result)) {
                        $result[$effectiveKey] = $coerced;
                    }

                    $atomsList[$effectiveKey][] = [
                        'type'   => $structured['type'],
                        'locale' => $structured['locale'],
                        'value'  => $coerced,
                    ];
                } elseif (($sub->type === BoxType::NAME->value) && !$isFreeform) {
                    $itemName = $this->parseIlstNameAtom($sub, $seenNames);

                    // Use name as fallback key when no key index or fourcc is available
                    if ($keyName === null) {
                        $keyName = $itemName;
                    }
                } elseif ($sub->type === BoxType::ITIF->value) {
                    $this->parseIlstItemInfo($sub, $seenItemIds);
                }
            }

            $this->valueDecoder->validateDataOrdering($entry->type, $entryAtoms);
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
     * By-ref accumulator: tracks seen names across multiple calls to detect duplicates
     * without returning auxiliary state alongside the validated name.
     *
     * @param BoxDescriptor       $name      Box descriptor for the name atom.
     * @param array<string, true> $seenNames Previously encountered names for uniqueness check.
     *
     * @return string The validated name string.
     */
    private function parseIlstNameAtom(BoxDescriptor $name, array &$seenNames): string
    {
        $this->validateFullAtomHeader($name, 'ilst name', 4, 1227, 1228, 1229);
        $win               = $name->window;

        $payloadSize       = $name->contentSize - 4;

        if ($payloadSize < 1) {
            throw new ParseError('ilst name atom has empty payload', 1230);
        }

        $value             = $win->read($payloadSize);

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
     * By-ref accumulator: tracks seen Item_IDs across multiple calls to detect duplicates.
     *
     * @param BoxDescriptor    $itif        Box descriptor for the itif atom.
     * @param array<int, true> $seenItemIds Previously encountered Item_IDs for uniqueness check.
     */
    private function parseIlstItemInfo(BoxDescriptor $itif, array &$seenItemIds): void
    {
        $this->validateFullAtomHeader($itif, 'itif', 8, 1233, 1234, 1235);
        $win                  = $itif->window;

        $itemId               = $win->readU32BE();

        if (array_key_exists($itemId, $seenItemIds)) {
            throw new ParseError(sprintf(
                'duplicate Item_ID %d in ilst itif atoms',
                $itemId,
            ), 1236);
        }

        $seenItemIds[$itemId] = true;
    }

    /**
     * Validates a FullAtom header (version/flags) and leaves the window at payload offset.
     *
     * QuickTime File Format 2012 FullAtom layout: version (8-bit) + flags (24-bit).
     */
    private function validateFullAtomHeader(
        BoxDescriptor $box,
        string $label,
        int $minimumContentSize,
        int $truncatedCode,
        int $versionCode,
        int $flagsCode,
    ): void {
        if ($box->contentSize < $minimumContentSize) {
            throw new ParseError(sprintf('%s atom truncated', $label), $truncatedCode);
        }

        $window = $box->window;
        $window->seek(0);

        $header = $this->boxNavigator->readFullBoxHeader($window);

        if ($header->version !== 0) {
            throw new ParseError(sprintf('%s atom version must be 0', $label), $versionCode);
        }

        if ($header->flags !== 0) {
            throw new ParseError(sprintf('%s atom flags must be 0', $label), $flagsCode);
        }
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
            $flattenedKey               = $parentKey . '.' . $nestedKey;
            $coerced                    = $this->valueDecoder->coerceQuickTimeValue($flattenedKey, $nestedValue);

            if (!array_key_exists($flattenedKey, $result)) {
                $result[$flattenedKey] = $coerced;
            }

            if (array_key_exists($nestedKey, $nestedAtoms)) {
                foreach ($nestedAtoms[$nestedKey] as $atom) {
                    $atomsList[$flattenedKey][] = [
                        'type'   => $atom['type'],
                        'locale' => $atom['locale'],
                        'value'  => $this->valueDecoder->coerceQuickTimeValue($flattenedKey, $atom['value']),
                    ];
                }

                continue;
            }

            $atomsList[$flattenedKey][] = [
                'type'   => QuickTimeDataType::NestedMetadata->value,
                'locale' => $parentLocale,
                'value'  => $coerced,
            ];
        }
    }
}
