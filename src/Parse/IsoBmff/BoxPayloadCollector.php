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
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;

use function ord;
use function sprintf;
use function strlen;
use function substr;

/**
 * Collects box-type-specific payloads from a `meta` box and its children.
 *
 * Extracted from IsoBmffParser to encapsulate the per-box-type dispatch logic
 * for EXIF, XMP, ICC, QuickTime metadata, and item-location structures.
 *
 * @phpstan-type QuickTimeKeyEntry = array{namespace: string, name: string}
 */
final readonly class BoxPayloadCollector
{
    /**
     * UUID identifying XMP payload boxes within ISO BMFF containers.
     */
    public const string XMP_UUID = "\xBE\x7A\xCF\xCB\x97\xA9\x42\xE8\x9C\x71\x99\x94\x91\xE3\xAF\xAC";

    /**
     * @param int $maxItemPayloadSize Maximum cumulative payload size in bytes.
     */
    public function __construct(
        private BoxNavigator $boxNavigator,
        private TrackMediaParser $trackMediaParser,
        private IlocBoxParser $ilocBoxParser,
        private ItemLocationResolver $itemLocationResolver,
        private QuickTimeMetadataDecoder $quickTimeDecoder,
        private QuickTimeKeyResolver $quickTimeKeyResolver,
        private ItemPayloadResolver $itemPayloadResolver,
        private int $maxItemPayloadSize,
    ) {
    }

    /**
     * Walks all children of a `meta` box and collects payloads grouped by type.
     *
     * @return array{
     *     itemInfos: array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}>,
     *     locations: array<int, array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}>,
     *     itemReferences: array<int, list<IsoBmffItemReference>>,
     *     dataReferences: array<int, IsoBmffDataReference>,
     *     primaryItemId: ?int,
     *     directXmp: list<string>,
     *     uuidXmp: list<string>,
     *     directExif: list<string>,
     *     idatPayload: ?string,
     *     keysMaps: list<array<int, QuickTimeKeyEntry>>,
     *     ilstBoxes: list<BoxDescriptor>,
     *     hasMhdr: bool,
     *     countryLists: list<list<int>>,
     *     languageLists: list<list<int>>,
     *     isMdta: bool
     * }
     */
    public function collect(BoxDescriptor $meta, bool $allowQuickTimeMetaWithoutFullBox, int $fileOffsetOrigin = 0): array
    {
        /** @var array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}> $itemInfos */
        $itemInfos = [];
        /** @var array<int, array{dataReferenceIndex:int, constructionMethod:ConstructionMethod, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations */
        $locations = [];
        /** @var array<int, list<IsoBmffItemReference>> $itemReferences */
        $itemReferences = [];
        /** @var array<int, IsoBmffDataReference> $dataReferences */
        $dataReferences = [];
        $primaryItemId  = null;
        $directXmp      = [];
        $uuidXmp        = [];
        $directExif     = [];
        $idatPayload    = null;
        /** @var list<array<int, QuickTimeKeyEntry>> $keysMaps */
        $keysMaps = [];
        /** @var list<BoxDescriptor> $ilstBoxes */
        $ilstBoxes    = [];
        $handlerType  = null;
        $hdlrCount    = 0;
        $requiresHdlr = false;
        $hasMhdr      = false;
        /** @var list<list<int>> $countryLists */
        $countryLists = [];
        /** @var list<list<int>> $languageLists */
        $languageLists = [];

        $childOffset = $this->detectMetaChildOffset($meta, $allowQuickTimeMetaWithoutFullBox);
        foreach ($this->boxNavigator->walkChildren($meta, $childOffset) as $child) {
            switch ($child->type) {
                case BoxType::HDLR->value:
                    ++$hdlrCount;
                    if ($hdlrCount > 1) {
                        throw new ParseError('meta must contain exactly one hdlr box', 1478);
                    }

                    [$handlerType] = $this->trackMediaParser->parseHdlr($child);
                    break;
                case BoxType::EXIF->value:
                    // Enforce payload cap before reading direct Exif box
                    if ($child->contentSize > $this->maxItemPayloadSize) {
                        throw new ParseError('direct Exif box payload exceeds maximum allowed size', 1396);
                    }

                    // The EXIF 3.0 §4.8 Exif box must expose the TIFF header directly; normalize deviations.
                    $blob         = $this->boxNavigator->readAll($child->window);
                    $directExif[] = $this->itemPayloadResolver->normalizeExifBlob($blob);
                    break;
                case BoxType::IINF->value:
                    foreach ($this->ilocBoxParser->parseIinf($child) as $info) {
                        $itemInfos[$info['id']] = $info;
                    }

                    break;
                case BoxType::ILOC->value:
                    if ($locations !== []) {
                        throw new ParseError('meta context must contain at most one iloc box', 1413);
                    }

                    $locations = $this->ilocBoxParser->parseIloc($child, $fileOffsetOrigin);
                    break;
                case BoxType::IDAT->value:
                    // ISO/IEC 14496-12 §8.11.11.2 specifies aligned(8), but
                    // virtually all Apple and Android encoders produce idat
                    // boxes at non-aligned offsets.  Skip the alignment check.

                    if ($idatPayload !== null) {
                        throw new ParseError('meta context must contain at most one idat box', 1414);
                    }

                    if ($child->contentSize > $this->maxItemPayloadSize) {
                        throw new ParseError('idat payload exceeds configured limit', 1164);
                    }

                    $idatPayload = $this->boxNavigator->readAll($child->window);

                    break;
                case BoxType::PITM->value:
                    $primaryItemId = $this->ilocBoxParser->parsePitm($child);
                    break;
                case BoxType::IREF->value:
                    $itemReferences = $this->itemLocationResolver->mergeItemReferences($itemReferences, $this->ilocBoxParser->parseIref($child));
                    break;
                case BoxType::DINF->value:
                    $dataReferences = $this->itemLocationResolver->mergeDataReferences($dataReferences, $this->ilocBoxParser->parseDinf($child));
                    break;
                case BoxType::XMP->value:
                    // Enforce payload cap before reading direct XMP box
                    if ($child->contentSize > $this->maxItemPayloadSize) {
                        throw new ParseError('direct XMP box payload exceeds maximum allowed size', 1397);
                    }

                    $directXmp[] = $this->boxNavigator->readAll($child->window);
                    break;
                case BoxType::UUID->value:
                    if ($child->userType === self::XMP_UUID) {
                        // Enforce payload cap before reading uuid XMP box
                        if ($child->contentSize > $this->maxItemPayloadSize) {
                            throw new ParseError('uuid XMP box payload exceeds maximum allowed size', 1900);
                        }

                        $uuidXmp[] = $this->boxNavigator->readAll($child->window);
                    }

                    break;
                case BoxType::MHDR->value:
                    $this->quickTimeDecoder->parseMhdr($child);
                    $requiresHdlr = true;
                    $hasMhdr      = true;
                    break;
                case BoxType::KEYS->value:
                    $requiresHdlr = true;

                    if ($keysMaps !== []) {
                        throw new ParseError('meta must contain at most one keys atom', 1503);
                    }

                    $keysMaps[] = $this->quickTimeKeyResolver->parseKeys($child);
                    break;
                case BoxType::ILST->value:
                    if ($ilstBoxes !== []) {
                        throw new ParseError('meta must contain at most one ilst atom', 1504);
                    }

                    $ilstBoxes[] = $child;
                    break;
                case BoxType::CTRY->value:
                    $requiresHdlr = true;

                    if ($countryLists !== []) {
                        throw new ParseError('meta must contain at most one ctry atom', 1959);
                    }

                    $countryLists = $this->quickTimeDecoder->parseLocaleListAtom($child, 'ctry');
                    break;
                case BoxType::LANG->value:
                    $requiresHdlr = true;

                    if ($languageLists !== []) {
                        throw new ParseError('meta must contain at most one lang atom', 1960);
                    }

                    $languageLists = $this->quickTimeDecoder->parseLocaleListAtom($child, 'lang');
                    break;
            }
        }

        if (($hdlrCount !== 1) && $requiresHdlr) {
            throw new ParseError('meta must contain exactly one hdlr box', 1927);
        }

        // QuickTime File Format 2012, "Metadata Atom": a reader should confirm the
        // handler reference type is 'mdta' before interpreting keys/ilst structures.
        // When hdlr is present but declares a different handler (e.g. 'pict' in
        // ISOBMFF), discard collected keys/ilst to prevent misinterpretation.
        if (($handlerType !== null) && ($handlerType !== QuickTimeKeyResolver::QUICKTIME_MDTA)) {
            $keysMaps      = [];
            $ilstBoxes     = [];
            $countryLists  = [];
            $languageLists = [];
        }

        // QuickTime File Format 2012, "Metadata Structure": a metadata atom with
        // handler type 'mdta' must contain keys and ilst subatoms.
        if ($handlerType === QuickTimeKeyResolver::QUICKTIME_MDTA) {
            if ($keysMaps === []) {
                throw new ParseError('mdta meta box missing required keys subatom', 1165);
            }

            if ($ilstBoxes === []) {
                throw new ParseError('mdta meta box missing required ilst subatom', 1166);
            }
        }

        // ISO/IEC 14496-12 §8.11.4: the primary item must reference an existing item.
        if ($primaryItemId !== null && !isset($locations[$primaryItemId]) && !isset($itemInfos[$primaryItemId])) {
            throw new ParseError(sprintf('pitm references non-existent item %d', $primaryItemId), 1167);
        }

        return [
            'itemInfos'      => $itemInfos,
            'locations'      => $locations,
            'itemReferences' => $itemReferences,
            'dataReferences' => $dataReferences,
            'primaryItemId'  => $primaryItemId,
            'directXmp'      => $directXmp,
            'uuidXmp'        => $uuidXmp,
            'directExif'     => $directExif,
            'idatPayload'    => $idatPayload,
            'keysMaps'       => $keysMaps,
            'ilstBoxes'      => $ilstBoxes,
            'hasMhdr'        => $hasMhdr,
            'countryLists'   => $countryLists,
            'languageLists'  => $languageLists,
            'isMdta'         => $handlerType === QuickTimeKeyResolver::QUICKTIME_MDTA,
        ];
    }

    /**
     * Determines whether the meta box includes a full box header (version/flags).
     */
    private function detectMetaChildOffset(BoxDescriptor $meta, bool $allowQuickTimeMetaWithoutFullBox): int
    {
        // ISO/IEC 14496-12 §8.11.1 defines `meta` as FullBox(version=0, flags=0).
        // QuickTime compatibility mode may omit this 4-byte header.
        $window = $meta->window;
        $window->seek(0);

        $peekLength = $meta->contentSize < 20 ? $meta->contentSize : 20;
        $peek       = $window->read($peekLength);
        $peekSize   = strlen($peek);

        if ($peekSize >= 12) {
            $size = $this->readU32FromBytes($peek, 4, 'meta child size');
            $type = substr($peek, 8, 4);

            if ($this->boxNavigator->isPrintableFourcc($type) && $this->isPlausibleBoxSize($peek, 4, $meta->contentSize - 4)) {
                $this->validateMetaFullBoxHeader($peek);

                return 4;
            }
        }

        if ($peekSize >= 8) {
            $size = $this->readU32FromBytes($peek, 0, 'meta child size');
            $type = substr($peek, 4, 4);

            if ($this->boxNavigator->isPrintableFourcc($type) && $this->isPlausibleBoxSize($peek, 0, $meta->contentSize)) {
                if ($allowQuickTimeMetaWithoutFullBox) {
                    return 0;
                }

                throw new ParseError('meta box missing required FullBox header', 1454);
            }
        }

        if ($allowQuickTimeMetaWithoutFullBox === false && $peekSize >= 4) {
            $this->validateMetaFullBoxHeader($peek);

            return 4;
        }

        // Reject ambiguous meta header layout instead of defaulting
        throw new ParseError(
            sprintf(
                'meta box has ambiguous header layout (contentSize=%d)',
                $meta->contentSize,
            ),
            1453,
        );
    }

    /**
     * Validates the FullBox version/flags header of a meta box.
     *
     * ISO/IEC 14496-12 §8.11.1: meta is FullBox('meta', version = 0, 0).
     *
     * @param string $peek First bytes of the meta box content.
     */
    private function validateMetaFullBoxHeader(string $peek): void
    {
        if (strlen($peek) < 4) {
            return;
        }

        $version = ord($peek[0]);
        $flags   = (ord($peek[1]) << 16) | (ord($peek[2]) << 8) | ord($peek[3]);

        if ($version !== 0) {
            throw new ParseError('unsupported meta box version', 1168);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported meta box flags', 1169);
        }
    }

    /**
     * Reads a big-endian 32-bit unsigned integer from a byte sequence.
     */
    private function readU32FromBytes(string $bytes, int $offset, string $context): int
    {
        if ($offset < 0 || ($offset + 4) > strlen($bytes)) {
            throw new ParseError('Insufficient bytes for ' . $context . '.', 1170);
        }

        return Unpack::int('N', substr($bytes, $offset, 4), $context);
    }

    /**
     * Reads a big-endian 64-bit unsigned integer from a byte sequence.
     */
    private function readU64FromBytes(string $bytes, int $offset, string $context): int
    {
        if ($offset < 0 || ($offset + 8) > strlen($bytes)) {
            throw new ParseError('Insufficient bytes for ' . $context . '.', 1872);
        }

        return Unpack::uint64(substr($bytes, $offset, 8), false, $context)->toInt($context);
    }

    /**
     * Checks if a box size value is plausible for the provided container size.
     */
    private function isPlausibleBoxSize(string $peek, int $offset, int $limit): bool
    {
        $size = $this->readU32FromBytes($peek, $offset, 'meta child size');

        if ($size === 0) {
            return true;
        }

        if ($size === 1) {
            $largeSizeOffset = $offset + 8;
            if (($largeSizeOffset + 8) > strlen($peek)) {
                return false;
            }

            try {
                $largeSize = $this->readU64FromBytes($peek, $largeSizeOffset, 'meta child large size');
            } catch (ParseError) {
                // Unparseable large-size field indicates an implausible box size.
                return false;
            }

            if ($largeSize === 0 || $largeSize < 16) {
                return false;
            }

            return $largeSize <= $limit;
        }

        if ($size < 8) {
            return false;
        }

        return $size <= $limit;
    }
}
