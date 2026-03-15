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

use function in_array;
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
     * @param int                      $maxItemPayloadSize   Maximum cumulative payload size in bytes.
     * @param BoxNavigator             $boxNavigator         Navigator used to walk and read BMFF box payloads.
     * @param TrackMediaParser         $trackMediaParser     Parser used for handler references
     *                                                       in media-related boxes.
     * @param IlocBoxParser            $ilocBoxParser        Parser used for item-location-related child
     *                                                       boxes.
     * @param QuickTimeMetadataDecoder $quickTimeDecoder     Decoder for QuickTime-specific
     *                                                       metadata atoms.
     * @param QuickTimeKeyResolver     $quickTimeKeyResolver Resolver for QuickTime key tables.
     * @param ItemPayloadResolver      $itemPayloadResolver  Resolver for item payload
     *                                                       normalization and extraction.
     */
    public function __construct(
        private BoxNavigator $boxNavigator,
        private TrackMediaParser $trackMediaParser,
        private IlocBoxParser $ilocBoxParser,
        private QuickTimeMetadataDecoder $quickTimeDecoder,
        private QuickTimeKeyResolver $quickTimeKeyResolver,
        private ItemPayloadResolver $itemPayloadResolver,
        private int $maxItemPayloadSize,
    ) {
    }

    /**
     * Walks all children of a `meta` box and collects payloads grouped by type.
     *
     * @param BoxDescriptor $meta                             Meta box descriptor whose children should be collected.
     * @param bool          $allowQuickTimeMetaWithoutFullBox Whether to accept QuickTime-compatible
     *                                                        meta boxes without a FullBox header.
     * @param int           $fileOffsetOrigin                 Absolute file offset used as origin for iloc-derived
     *                                                        extents.
     *
     * @return BoxPayloadCollection Aggregated payloads and child-box-derived metadata extracted from the meta box.
     */
    public function collect(BoxDescriptor $meta, bool $allowQuickTimeMetaWithoutFullBox, int $fileOffsetOrigin = 0): BoxPayloadCollection
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
        $ispeWidth     = null;
        $ispeHeight    = null;
        $iccProfile    = null;

        $childOffset = $this->detectMetaChildOffset($meta, $allowQuickTimeMetaWithoutFullBox);

        foreach ($this->boxNavigator->walkChildren($meta, $childOffset) as $child) {
            switch ($child->type) {
                case BoxType::HDLR->value:
                    $this->collectHdlr($child, $hdlrCount, $handlerType);

                    break;
                case BoxType::EXIF->value:
                    $this->collectDirectExifPayload($child, $directExif);

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
                    $this->collectIdatPayload($child, $idatPayload);

                    break;
                case BoxType::PITM->value:
                    $primaryItemId = $this->ilocBoxParser->parsePitm($child);

                    break;
                case BoxType::IREF->value:
                    $itemReferences = ItemLocationResolver::mergeItemReferences($itemReferences, $this->ilocBoxParser->parseIref($child));

                    break;
                case BoxType::DINF->value:
                    $dataReferences = ItemLocationResolver::mergeDataReferences($dataReferences, $this->ilocBoxParser->parseDinf($child));

                    break;
                case BoxType::XMP->value:
                    $this->collectDirectXmpPayload($child, $directXmp);

                    break;
                case BoxType::UUID->value:
                    $this->collectUuidXmpPayload($child, $uuidXmp);

                    break;
                case BoxType::MHDR->value:
                    $this->collectMhdr($child, $requiresHdlr, $hasMhdr);

                    break;
                case BoxType::KEYS->value:
                    $this->collectKeysAtom($child, $requiresHdlr, $keysMaps);

                    break;
                case BoxType::ILST->value:
                    $this->collectIlstAtom($child, $ilstBoxes);

                    break;
                case BoxType::CTRY->value:
                    $this->collectLocaleListAtom($child, 'ctry', 1959, $requiresHdlr, $countryLists);

                    break;
                case BoxType::LANG->value:
                    $this->collectLocaleListAtom($child, 'lang', 1960, $requiresHdlr, $languageLists);

                    break;
                case BoxType::IPRP->value:
                    if (($ispeWidth === null) || ($iccProfile === null)) {
                        $iprpResult = $this->parseIprp($child);

                        if (($ispeWidth === null) && ($iprpResult['width'] !== null)) {
                            $ispeWidth  = $iprpResult['width'];
                            $ispeHeight = $iprpResult['height'];
                        }

                        if (($iccProfile === null) && ($iprpResult['iccProfile'] !== null)) {
                            $iccProfile = $iprpResult['iccProfile'];
                        }
                    }

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
        if (!in_array($handlerType, [null, QuickTimeKeyResolver::QUICKTIME_MDTA, QuickTimeKeyResolver::QUICKTIME_MDIR], true)) {
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
        if (($primaryItemId !== null) && !isset($locations[$primaryItemId]) && !isset($itemInfos[$primaryItemId])) {
            throw new ParseError(sprintf('pitm references non-existent item %d', $primaryItemId), 1167);
        }

        return new BoxPayloadCollection(
            $itemInfos,
            $locations,
            $itemReferences,
            $dataReferences,
            $primaryItemId,
            $directXmp,
            $uuidXmp,
            $directExif,
            $idatPayload,
            $keysMaps,
            $ilstBoxes,
            $hasMhdr,
            $countryLists,
            $languageLists,
            $handlerType === QuickTimeKeyResolver::QUICKTIME_MDTA,
            $handlerType === QuickTimeKeyResolver::QUICKTIME_MDIR,
            $ispeWidth,
            $ispeHeight,
            $iccProfile,
        );
    }

    /**
     * Collects and validates the single required handler box.
     */
    private function collectHdlr(BoxDescriptor $child, int &$hdlrCount, ?string &$handlerType): void
    {
        ++$hdlrCount;

        if ($hdlrCount > 1) {
            throw new ParseError('meta must contain exactly one hdlr box', 1478);
        }

        [$handlerType] = $this->trackMediaParser->parseHdlr($child);
    }

    /**
     * Collects direct Exif payloads from `Exif` boxes.
     *
     * @param BoxDescriptor $child      Exif child box whose payload should be normalized and collected.
     * @param list<string>  $directExif Collected direct Exif payloads from sibling boxes.
     */
    private function collectDirectExifPayload(BoxDescriptor $child, array &$directExif): void
    {
        $this->assertPayloadWithinLimit($child, 'direct Exif box payload exceeds maximum allowed size', 1396);

        // The EXIF 3.0 §4.8 Exif box must expose the TIFF header directly; normalize deviations.
        $blob         = $this->boxNavigator->readAll($child->window);
        $directExif[] = $this->itemPayloadResolver->normalizeExifBlob($blob);
    }

    /**
     * Collects direct XMP payloads from `XMP ` boxes.
     *
     * @param BoxDescriptor $child     XMP child box whose payload should be collected.
     * @param list<string>  $directXmp Collected direct XMP payloads from sibling boxes.
     */
    private function collectDirectXmpPayload(BoxDescriptor $child, array &$directXmp): void
    {
        $this->assertPayloadWithinLimit($child, 'direct XMP box payload exceeds maximum allowed size', 1397);
        $directXmp[] = $this->boxNavigator->readAll($child->window);
    }

    /**
     * Collects XMP payloads from UUID boxes with the XMP UUID signature.
     *
     * @param BoxDescriptor $child   UUID child box whose payload may contain XMP data.
     * @param list<string>  $uuidXmp Collected UUID-based XMP payloads from sibling boxes.
     */
    private function collectUuidXmpPayload(BoxDescriptor $child, array &$uuidXmp): void
    {
        if ($child->userType !== self::XMP_UUID) {
            return;
        }

        $this->assertPayloadWithinLimit($child, 'uuid XMP box payload exceeds maximum allowed size', 1900);
        $uuidXmp[] = $this->boxNavigator->readAll($child->window);
    }

    /**
     * Collects and validates a single `idat` payload.
     *
     * @param BoxDescriptor $child `idat` child box whose payload should be collected.
     *
     * @param-out string       $idatPayload Captured `idat` payload bytes when the box is present.
     */
    private function collectIdatPayload(BoxDescriptor $child, ?string &$idatPayload): void
    {
        // ISO/IEC 14496-12 §8.11.11.2 specifies aligned(8), but
        // virtually all Apple and Android encoders produce idat
        // boxes at non-aligned offsets. Skip the alignment check.
        if ($idatPayload !== null) {
            throw new ParseError('meta context must contain at most one idat box', 1414);
        }

        $this->assertPayloadWithinLimit($child, 'idat payload exceeds configured limit', 1164);
        $idatPayload = $this->boxNavigator->readAll($child->window);
    }

    /**
     * Collects and validates a single `keys` atom.
     *
     * @param BoxDescriptor                       $child        `keys` child box to parse.
     * @param bool                                $requiresHdlr Flag marking that an `hdlr` box is required for the
     *                                                          current meta context.
     * @param list<array<int, QuickTimeKeyEntry>> $keysMaps     Parsed QuickTime key-entry
     *                                                          maps collected from the meta box.
     */
    private function collectKeysAtom(BoxDescriptor $child, bool &$requiresHdlr, array &$keysMaps): void
    {
        $requiresHdlr = true;

        if ($keysMaps !== []) {
            throw new ParseError('meta must contain at most one keys atom', 1964);
        }

        $keysMaps[] = $this->quickTimeKeyResolver->parseKeys($child);
    }

    /**
     * Collects and validates a single `ilst` atom.
     *
     * @param BoxDescriptor       $child     `ilst` child box to retain for later decoding.
     * @param list<BoxDescriptor> $ilstBoxes Collected `ilst` box descriptors for the current meta box.
     */
    private function collectIlstAtom(BoxDescriptor $child, array &$ilstBoxes): void
    {
        if ($ilstBoxes !== []) {
            throw new ParseError('meta must contain at most one ilst atom', 1504);
        }

        $ilstBoxes[] = $child;
    }

    /**
     * Collects and validates QuickTime locale list atoms (`ctry`/`lang`).
     *
     * @param BoxDescriptor   $child         Locale-list child box to decode.
     * @param 'ctry'|'lang'   $atomType      Expected locale-list atom type.
     * @param int             $duplicateCode ParseError code used when a duplicate locale-list atom
     *                                       is encountered.
     * @param bool            $requiresHdlr  Flag marking that an `hdlr` box is required for the
     *                                       current meta context.
     * @param list<list<int>> $lists         Parsed locale lists grouped by contained entry
     *                                       order.
     */
    private function collectLocaleListAtom(BoxDescriptor $child, string $atomType, int $duplicateCode, bool &$requiresHdlr, array &$lists): void
    {
        $requiresHdlr = true;

        if ($lists !== []) {
            throw new ParseError(sprintf('meta must contain at most one %s atom', $atomType), $duplicateCode);
        }

        $lists = $this->quickTimeDecoder->parseLocaleListAtom($child, $atomType);
    }

    /**
     * Collects metadata header presence and marks hdlr requirement.
     */
    private function collectMhdr(BoxDescriptor $child, bool &$requiresHdlr, bool &$hasMhdr): void
    {
        $this->quickTimeDecoder->parseMhdr($child);
        $requiresHdlr = true;
        $hasMhdr      = true;
    }

    /**
     * Enforces the configured payload size limit before reading a box body.
     *
     * @param BoxDescriptor $child        Box whose payload size should be validated.
     * @param string        $errorMessage ParseError message used when the configured limit is exceeded.
     * @param int           $errorCode    ParseError code used when the configured limit is exceeded.
     */
    private function assertPayloadWithinLimit(BoxDescriptor $child, string $errorMessage, int $errorCode): void
    {
        if ($child->contentSize > $this->maxItemPayloadSize) {
            throw new ParseError($errorMessage, $errorCode);
        }
    }

    /**
     * Determines whether the meta box includes a full box header (version/flags).
     *
     * @param BoxDescriptor $meta                             Meta box descriptor whose child layout should be
     *                                                        inspected.
     * @param bool          $allowQuickTimeMetaWithoutFullBox Whether to accept a QuickTime-style
     *                                                        meta box without a FullBox header.
     *
     * @return int Child-content offset within the meta box payload.
     */
    private function detectMetaChildOffset(BoxDescriptor $meta, bool $allowQuickTimeMetaWithoutFullBox): int
    {
        // ISO/IEC 14496-12 §8.11.1 defines `meta` as FullBox(version=0, flags=0).
        // QuickTime compatibility mode may omit this 4-byte header.
        $window = $meta->window;
        $window->seek(0);

        $peekLength = min($meta->contentSize, 20);
        $peek       = $window->read($peekLength);
        $peekSize   = strlen($peek);

        if ($peekSize >= 12) {
            $this->readU32FromBytes($peek, 4, 'meta child size');
            $type = substr($peek, 8, 4);

            if ($this->boxNavigator->isPrintableFourcc($type) && $this->isPlausibleBoxSize($peek, 4, $meta->contentSize - 4)) {
                $this->validateMetaFullBoxHeader($peek);

                return 4;
            }
        }

        if ($peekSize >= 8) {
            $this->readU32FromBytes($peek, 0, 'meta child size');
            $type = substr($peek, 4, 4);

            if ($this->boxNavigator->isPrintableFourcc($type) && $this->isPlausibleBoxSize($peek, 0, $meta->contentSize)) {
                return 0;
            }
        }

        if (($allowQuickTimeMetaWithoutFullBox === false) && ($peekSize >= 4)) {
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

        if ($version !== 0) {
            throw new ParseError('unsupported meta box version', 1168);
        }
    }

    /**
     * Reads a big-endian 32-bit unsigned integer from a byte sequence.
     *
     * @param string $bytes   Byte sequence containing the unsigned integer.
     * @param int    $offset  Offset into the byte sequence where the integer starts.
     * @param string $context Human-readable context included in error messages.
     *
     * @return int Parsed 32-bit unsigned integer value.
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
     *
     * @param string $bytes   Byte sequence containing the unsigned integer.
     * @param int    $offset  Offset into the byte sequence where the integer starts.
     * @param string $context Human-readable context included in error messages.
     *
     * @return int Parsed 64-bit unsigned integer value as a PHP integer.
     */
    private function readU64FromBytes(string $bytes, int $offset, string $context): int
    {
        if ($offset < 0 || ($offset + 8) > strlen($bytes)) {
            throw new ParseError('Insufficient bytes for ' . $context . '.', 1872);
        }

        return Unpack::uint64(substr($bytes, $offset, 8), false, $context)->toInt($context);
    }

    /**
     * Walks the item properties box (`iprp`) looking for an `ipco` child that contains an `ispe` box.
     *
     * ISO/IEC 14496-12 §8.11.14: iprp contains ipco (item property container) and ipma
     * (item property association). We only need ipco to extract ispe dimensions.
     *
     * @param BoxDescriptor $iprp Box descriptor for the item properties box.
     *
     * @return array{width: ?int, height: ?int, iccProfile: ?string} Properties from ipco, or null values.
     */
    private function parseIprp(BoxDescriptor $iprp): array
    {
        foreach ($this->boxNavigator->walkChildren($iprp) as $child) {
            if ($child->type === BoxType::IPCO->value) {
                return $this->parseIpco($child);
            }
        }

        return ['width' => null, 'height' => null, 'iccProfile' => null];
    }

    /**
     * Walks the item property container (`ipco`) looking for `ispe` and `colr` boxes.
     *
     * @param BoxDescriptor $ipco Box descriptor for the item property container box.
     *
     * @return array{width: ?int, height: ?int, iccProfile: ?string} Extracted properties, or null values.
     */
    private function parseIpco(BoxDescriptor $ipco): array
    {
        $width      = null;
        $height     = null;
        $iccProfile = null;

        foreach ($this->boxNavigator->walkChildren($ipco) as $child) {
            if (($child->type === BoxType::ISPE->value) && ($width === null)) {
                ['width' => $width, 'height' => $height] = $this->parseIspe($child);
            } elseif (($child->type === BoxType::COLR->value) && ($iccProfile === null)) {
                $iccProfile = $this->parseColr($child);
            }
        }

        return ['width' => $width, 'height' => $height, 'iccProfile' => $iccProfile];
    }

    /**
     * Parses an Image Spatial Extents (`ispe`) FullBox.
     *
     * ISO/IEC 14496-12 §12.1.4: ispe is FullBox(version=0, flags=0) containing
     * unsigned int(32) display_width and unsigned int(32) display_height.
     *
     * @param BoxDescriptor $ispe Box descriptor for the image spatial extents box.
     *
     * @return array{width: ?int, height: ?int} Width/height or null pair if the box is malformed.
     */
    private function parseIspe(BoxDescriptor $ispe): array
    {
        // FullBox header (4 bytes) + display_width (4 bytes) + display_height (4 bytes) = 12 bytes
        if ($ispe->contentSize < 12) {
            return ['width' => null, 'height' => null];
        }

        $win = $ispe->window;
        $win->seek(0);

        $header = $this->boxNavigator->readFullBoxHeader($win);

        if ($header->version !== 0) {
            return ['width' => null, 'height' => null];
        }

        $width  = $this->readU32FromBytes($win->read(4), 0, 'ispe display_width');
        $height = $this->readU32FromBytes($win->read(4), 0, 'ispe display_height');

        return ['width' => $width, 'height' => $height];
    }

    /**
     * Parses a Colour Information (`colr`) box and returns the ICC profile when present.
     *
     * ISO/IEC 14496-12 §12.1.5: the colr box starts with a 4-byte colour_type FourCC.
     * For 'prof' (unrestricted) or 'rICC' (restricted), the remaining bytes are the
     * ICC profile. For 'nclx' or unknown types, no ICC profile is extracted.
     *
     * @param BoxDescriptor $colr Box descriptor for the colour information box.
     *
     * @return string|null Binary ICC profile or null when unavailable.
     */
    private function parseColr(BoxDescriptor $colr): ?string
    {
        // 4 bytes minimum for colour_type
        if ($colr->contentSize < 4) {
            return null;
        }

        $win = $colr->window;
        $win->seek(0);

        $colourType  = $win->read(4);
        $profileSize = $colr->contentSize - 4;

        if (($colourType !== 'prof') && ($colourType !== 'rICC')) {
            return null;
        }

        if ($profileSize < 1) {
            return null;
        }

        return $win->read($profileSize);
    }

    /**
     * Checks if a box size value is plausible for the provided container size.
     *
     * @param string $peek   Byte sequence containing a candidate box header.
     * @param int    $offset Offset to the box size field within the byte sequence.
     * @param int    $limit  Maximum allowed size relative to the current container.
     *
     * @return bool True when the encoded size fits within the container, otherwise false.
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

            if ($largeSize < 16) {
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
