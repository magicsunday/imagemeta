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
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeDataAtom;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;

use function array_key_exists;
use function fopen;
use function fwrite;
use function implode;
use function in_array;
use function is_int;
use function ord;
use function pack;
use function rewind;
use function sprintf;
use function strlen;
use function substr;

/**
 * Streaming ISOBMFF reader for HEIC/AVIF/MP4/MOV.
 * Extracts EXIF/XMP payloads and QuickTime metadata.
 *
 * EXIF 3.0 §4.8 outlines embedding Exif items in ISO BMFF containers through
 * the `Exif` box and item metadata.
 *
 * @phpstan-type QuickTimeValue = string|int|float|bool
 * @phpstan-type QuickTimeKeyMap = array<string, QuickTimeValue>
 * @phpstan-type QuickTimeKeyEntry = array{namespace: string, name: string}
 * @phpstan-type QuickTimeRawDataAtom = array{type: int, locale: int, value: string|int|float, nestedKeys?: QuickTimeKeyMap, nestedAtoms?: QuickTimeDataAtomList}
 * @phpstan-type QuickTimeCoercedDataAtom = array{type: int, locale: int, value: string|int|float|bool}
 * @phpstan-type QuickTimeDataAtomList = array<string, list<QuickTimeCoercedDataAtom>>
 */
final readonly class IsoBmffParser implements IsoBmffParserInterface
{
    /**
     * UUID identifying XMP payload boxes within ISO BMFF containers.
     */
    private const string XMP_UUID = "\xBE\x7A\xCF\xCB\x97\xA9\x42\xE8\x9C\x71\x99\x94\x91\xE3\xAF\xAC";

    /**
     * QuickTime-compatible file type brand.
     */
    private const string BRAND_QUICKTIME = 'qt  ';

    /**
     * Maximum supported nesting depth for data-type 28 metadata payloads.
     */
    private const int MAX_NESTED_METADATA_DEPTH = 1;

    /**
     * Maximum cumulative payload size allowed when assembling item extents.
     */
    public const int MAX_ITEM_PAYLOAD_SIZE = 8 * 1024 * 1024;

    private BoxNavigator $boxNavigator;

    private IlocBoxParser $ilocBoxParser;

    private ItemPayloadResolver $itemPayloadResolver;

    private QuickTimeKeyResolver $quickTimeKeyResolver;

    private QuickTimeMetadataDecoder $quickTimeDecoder;

    private ItemLocationResolver $itemLocationResolver;

    private TrackMediaParser $trackMediaParser;

    /**
     * Initialises the extractor with the source stream that contains the ISO BMFF structure.
     *
     * @param Stream $stream Stream positioned at the beginning of the media file to parse.
     */
    public function __construct(private Stream $stream, private int $nestedMetadataDepth = 0)
    {
        $boxNavigator = new BoxNavigator($stream);

        $this->boxNavigator = $boxNavigator;

        $this->quickTimeKeyResolver = new QuickTimeKeyResolver($boxNavigator);
        $this->ilocBoxParser        = new IlocBoxParser($boxNavigator);
        $this->itemPayloadResolver  = new ItemPayloadResolver($stream, $boxNavigator);
        $this->itemLocationResolver = new ItemLocationResolver($this->itemPayloadResolver);

        $this->quickTimeDecoder = new QuickTimeMetadataDecoder(
            $boxNavigator,
            $this->quickTimeKeyResolver,
            new QuickTimeValueDecoder($this->parseNestedMetadataPayload(...)),
        );
        $this->trackMediaParser = new TrackMediaParser(
            $boxNavigator,
            $this->parseUdtaBox(...),
            $this->ilocBoxParser->parseDinf(...),
        );
    }

    /**
     * Extracts EXIF blobs, XMP packets, and QuickTime metadata from the stream.
     *
     * @return array{0: list<string>, 1: list<string>, 2: ?QuickTimeMeta, 3: ?IsoBmffItemReferenceMap, 4: ?IsoBmffDataReferenceMap, 5: list<IsoBmffUnresolvedItem>}
     */
    public function extract(): array
    {
        $context = new IsoBmffParseContext();

        foreach ($this->walkTopLevelBoxes() as $box) {
            if ($box->type === BoxType::FTYP->value) {
                $context->qtKeys = $this->quickTimeDecoder->mergeAssociative($context->qtKeys, $this->parseFtyp($box, $context));
            } elseif ($box->type === BoxType::META->value) {
                $this->parseMetaBox($box, $context);
            } elseif ($box->type === BoxType::MOOV->value) {
                ++$context->moovCount;

                if ($context->moovCount > 1) {
                    throw new ParseError('file must contain exactly one moov box', 1373);
                }

                $this->parseMoovBox($box, $context);
            } elseif ($box->type === BoxType::MOOF->value) {
                $this->parseMoofBox($box, $context);
            } elseif ($box->type === BoxType::UUID->value && $box->userType === self::XMP_UUID) {
                if ($box->contentSize > self::MAX_ITEM_PAYLOAD_SIZE) {
                    throw new ParseError('uuid XMP payload exceeds maximum allowed size', 1368);
                }

                $context->queuedUuidXmp[] = $this->boxNavigator->readAll($box->window);
            }
        }

        foreach ($context->queuedUuidXmp as $blob) {
            $this->itemLocationResolver->appendUniqueXmp($context->xmpBlobs, $context->xmpHashes, $blob);
        }

        /** @var array<string, list<QuickTimeDataAtom>> $dataAtomVOs */
        $dataAtomVOs = [];
        foreach ($context->qtDataAtoms as $key => $rawAtoms) {
            foreach ($rawAtoms as $raw) {
                $dataAtomVOs[$key][] = new QuickTimeDataAtom($raw['type'], $raw['locale'], $raw['value']);
            }
        }

        $qt               = ($context->qtKeys === [] && $dataAtomVOs === []) ? null : new QuickTimeMeta($context->qtKeys, $dataAtomVOs);
        $itemReferenceMap = $context->itemReferences === [] ? null : new IsoBmffItemReferenceMap($context->itemReferences);
        $dataReferenceMap = $context->dataReferences === [] ? null : new IsoBmffDataReferenceMap($context->dataReferences);

        return [$context->exifBlobs, $context->xmpBlobs, $qt, $itemReferenceMap, $dataReferenceMap, $context->unresolvedItems];
    }

    /**
     * Walks each top-level box in the file and yields a descriptor object.
     *
     * @return iterable<BoxDescriptor>
     */
    private function walkTopLevelBoxes(): iterable
    {
        $fileSize = $this->stream->size();
        $offset   = 0;

        while ($offset + 8 <= $fileSize) {
            $box = $this->boxNavigator->readBoxAt($offset, $fileSize, allowImplicitSize: true);
            yield $box;
            $offset += $box->size;
        }

        if ($offset !== $fileSize) {
            throw new ParseError('Top-level boxes do not align with file size', 1140);
        }
    }

    /**
     * Parses the `moov` box, collecting nested metadata boxes of interest.
     *
     * @param BoxDescriptor       $moov    Box descriptor for the movie box.
     * @param IsoBmffParseContext $context Shared parse-state context.
     */
    private function parseMoovBox(BoxDescriptor $moov, IsoBmffParseContext $context): void
    {
        $metaSeen  = false;
        $udtaCount = 0;
        $mvhdCount = 0;
        $trakCount = 0;

        /** @var QuickTimeKeyMap|null $selectedVideoTrack */
        $selectedVideoTrack = null;
        /** @var QuickTimeKeyMap|null $selectedAudioTrack */
        $selectedAudioTrack    = null;
        $hasEligibleVideoTrack = false;
        $hasEligibleAudioTrack = false;

        foreach ($this->boxNavigator->walkChildren($moov) as $child) {
            if ($child->type === BoxType::META->value) {
                // QuickTime File Format 2012, "Metadata Structure": only one
                // metadata atom is allowed per container location.
                if ($metaSeen) {
                    throw new ParseError('duplicate meta box in moov', 1141);
                }

                $metaSeen = true;
                $this->parseMetaBox($child, $context);
            } elseif ($child->type === BoxType::UDTA->value) {
                ++$udtaCount;
                if ($udtaCount > 1) {
                    throw new ParseError('duplicate udta box in moov', 1417);
                }

                $this->parseUdtaBox($child, $context);
            } elseif ($child->type === BoxType::TRAK->value) {
                ++$trakCount;
                $trackSelection = $this->trackMediaParser->parseTrak($child, $context);

                if ($trackSelection['handler'] === 'vide') {
                    if (
                        $this->shouldSelectTrackCandidate(
                            $selectedVideoTrack,
                            $hasEligibleVideoTrack,
                            $trackSelection['keys'],
                            $trackSelection['isEnabledInMovie'],
                        )
                    ) {
                        $selectedVideoTrack    = $trackSelection['keys'];
                        $hasEligibleVideoTrack = $trackSelection['isEnabledInMovie'];
                    }
                } elseif ($trackSelection['handler'] === 'soun') {
                    if (
                        $this->shouldSelectTrackCandidate(
                            $selectedAudioTrack,
                            $hasEligibleAudioTrack,
                            $trackSelection['keys'],
                            $trackSelection['isEnabledInMovie'],
                        )
                    ) {
                        $selectedAudioTrack    = $trackSelection['keys'];
                        $hasEligibleAudioTrack = $trackSelection['isEnabledInMovie'];
                    }
                }
            } elseif ($child->type === BoxType::MVHD->value) {
                ++$mvhdCount;

                if ($mvhdCount > 1) {
                    throw new ParseError('moov must contain exactly one mvhd box', 1374);
                }

                $this->trackMediaParser->parseMvhd($child);
            }
        }

        if ($selectedVideoTrack !== null) {
            $this->mergeTrackKeysIfMissing($context->qtKeys, $selectedVideoTrack);
        }

        if ($selectedAudioTrack !== null) {
            $this->mergeTrackKeysIfMissing($context->qtKeys, $selectedAudioTrack);
        }

        if ($mvhdCount === 0) {
            throw new ParseError('moov must contain exactly one mvhd box', 1374);
        }

        if ($trakCount === 0) {
            throw new ParseError('moov must contain at least one trak box', 1375);
        }
    }

    /**
     * Parses a movie fragment box and extracts nested metadata/user-data containers.
     *
     * ISO/IEC 14496-12:2015 §8.8.17 allows metadata in movie fragments. For
     * `iloc` file-offset items in this context, §8.11.3 defines the origin as
     * the first byte of the enclosing `moof` box.
     *
     * @param BoxDescriptor       $moof    Box descriptor for the movie fragment.
     * @param IsoBmffParseContext $context Shared parse-state context.
     */
    private function parseMoofBox(BoxDescriptor $moof, IsoBmffParseContext $context): void
    {
        $metaSeen = false;

        foreach ($this->boxNavigator->walkChildren($moof) as $child) {
            if ($child->type === BoxType::META->value) {
                if ($metaSeen) {
                    throw new ParseError('duplicate meta box in moof', 1416);
                }

                $metaSeen = true;
                $this->parseMetaBox($child, $context, $moof->offset);
            } elseif ($child->type === BoxType::UDTA->value) {
                $this->parseUdtaBox($child, $context, $moof->offset);
            }
        }
    }

    /**
     * Parses the file type box (`ftyp`) and exposes container brands as metadata keys.
     *
     * @param BoxDescriptor       $ftyp    Box descriptor representing the file type declaration.
     * @param IsoBmffParseContext $context Shared parse-state context.
     *
     * @return QuickTimeKeyMap
     */
    private function parseFtyp(BoxDescriptor $ftyp, IsoBmffParseContext $context): array
    {
        $win = $ftyp->window;
        $win->seek(0);

        if ($ftyp->contentSize < 8) {
            throw new ParseError('ftyp box payload too small for mandatory fields', 1361);
        }

        $majorBrandRaw = $win->read(4);
        if (!$this->boxNavigator->isPrintableFourcc($majorBrandRaw)) {
            throw new ParseError('ftyp major_brand must be a printable 4CC', 1476);
        }

        $majorBrand = $this->boxNavigator->normaliseFourcc($majorBrandRaw);
        $minor      = $win->readU32BE();

        if (($ftyp->contentSize - 8) % 4 !== 0) {
            throw new ParseError('ftyp compatible_brands length is not a multiple of 4', 1142);
        }

        $brands = [];
        while ($win->tell() + 4 <= $ftyp->contentSize) {
            $brandRaw = $win->read(4);
            if (!$this->boxNavigator->isPrintableFourcc($brandRaw)) {
                throw new ParseError('ftyp compatible_brand must be a printable 4CC', 1477);
            }

            $brands[] = $this->boxNavigator->normaliseFourcc($brandRaw);
        }

        if (($majorBrand === self::BRAND_QUICKTIME) || in_array(self::BRAND_QUICKTIME, $brands, true)) {
            $context->allowQuickTimeMetaWithoutFullBox = true;
        }

        return [
            QuickTimeMeta::MAJOR_BRAND_KEY       => $majorBrand,
            QuickTimeMeta::MINOR_VERSION_KEY     => $minor,
            QuickTimeMeta::COMPATIBLE_BRANDS_KEY => implode(' ', $brands),
        ];
    }

    /**
     * Parses the `udta` user data box for embedded metadata containers.
     *
     * @param BoxDescriptor       $udta             Box descriptor for the user data box.
     * @param IsoBmffParseContext $context          Shared parse-state context.
     * @param int                 $fileOffsetOrigin Absolute file-offset origin for nested iloc metadata.
     */
    private function parseUdtaBox(BoxDescriptor $udta, IsoBmffParseContext $context, int $fileOffsetOrigin = 0): void
    {
        $metaSeen = false;

        foreach ($this->boxNavigator->walkChildren($udta, allowTrailingTerminator: true) as $child) {
            if ($child->type === BoxType::META->value) {
                // QuickTime File Format 2012, "Metadata Structure": only one
                // metadata atom is allowed per container location.
                if ($metaSeen) {
                    throw new ParseError('duplicate meta box in udta', 1143);
                }

                $metaSeen = true;
                $this->parseMetaBox($child, $context, $fileOffsetOrigin);
            } elseif ($child->type === BoxType::NAME->value) {
                $this->quickTimeDecoder->parseUdtaNameAtom($child, $context);
            } else {
                $this->quickTimeDecoder->parseUdtaTextAtom($child, $context);
            }
        }
    }

    /**
     * Determines whether a new track-derived metadata candidate should replace the current selection.
     *
     * @param QuickTimeKeyMap|null $currentKeys         Currently selected track-derived keys.
     * @param bool                 $currentIsEligible   Whether the current selection is enabled and in-movie.
     * @param QuickTimeKeyMap      $candidateKeys       Candidate keys extracted from the current track.
     * @param bool                 $candidateIsEligible Whether the candidate track is enabled and in-movie.
     */
    private function shouldSelectTrackCandidate(
        ?array $currentKeys,
        bool $currentIsEligible,
        array $candidateKeys,
        bool $candidateIsEligible,
    ): bool {
        if ($candidateKeys === []) {
            return false;
        }

        if ($currentKeys === null) {
            return true;
        }

        if ($currentIsEligible) {
            return false;
        }

        return $candidateIsEligible;
    }

    /**
     * Merges track-derived keys without overwriting already established metadata values.
     *
     * @param QuickTimeKeyMap $target
     * @param QuickTimeKeyMap $trackKeys
     */
    private function mergeTrackKeysIfMissing(array &$target, array $trackKeys): void
    {
        foreach ($trackKeys as $key => $value) {
            if (!array_key_exists($key, $target)) {
                $target[$key] = $value;
            }
        }
    }

    /**
     * Parses the ISO BMFF metadata box and resolves payload references.
     *
     * @param BoxDescriptor       $meta             Box descriptor for the metadata box.
     * @param IsoBmffParseContext $context          Shared parse-state context.
     * @param int                 $fileOffsetOrigin Absolute file-offset origin for iloc file-offset items.
     */
    private function parseMetaBox(BoxDescriptor $meta, IsoBmffParseContext $context, int $fileOffsetOrigin = 0): void
    {
        if ($meta->contentSize < 4) {
            throw new ParseError('meta box truncated', 1162);
        }

        // EXIF 3.0 Annex A.2 describes how the `meta` box aggregates
        // direct Exif boxes, UUID-wrapped payloads and item references, so we collect each
        // channel before normalising the referenced data.
        $payloads                = $this->collectDirectPayloads($meta, $context, $fileOffsetOrigin);
        $context->itemReferences = $this->itemLocationResolver->mergeItemReferencesByContext($context->itemReferences, $meta->offset, $payloads['itemReferences']);
        $context->dataReferences = $this->itemLocationResolver->mergeDataReferencesByContext($context->dataReferences, $meta->offset, $payloads['dataReferences']);

        $idatPayload = $payloads['idatPayload'];

        [$exifItemIds, $xmpItemIds] = $this->itemLocationResolver->gatherItemIds($payloads['itemInfos'], $payloads['primaryItemId']);

        // Resolve EXIF item payloads and normalize leading headers.
        // EXIF 3.0 §4.8 notes that item payloads omit the APP1 signature; some
        // encoders still include it, so we normalise accordingly.
        foreach ($this->itemLocationResolver->resolveQueuedItems($exifItemIds, $payloads['locations'], $payloads['itemReferences'], $this->itemPayloadResolver->normalizeExifBlob(...), $payloads['dataReferences'], $idatPayload, $context->unresolvedItems, $meta->offset) as $blob) {
            $context->exifBlobs[] = $blob;
        }

        // EXIF 3.0 Annex A item metadata: when both item-based and direct Exif payloads
        // are present, keep item-based payloads first so pitm-driven default selection
        // remains deterministic for MetadataReader::fromIsoBmff().
        foreach ($payloads['directExif'] as $blob) {
            $context->exifBlobs[] = $blob;
        }

        // Resolve referenced XMP payloads in declared priority order.
        foreach ($this->itemLocationResolver->resolveQueuedItems($xmpItemIds, $payloads['locations'], $payloads['itemReferences'], null, $payloads['dataReferences'], $idatPayload, $context->unresolvedItems, $meta->offset) as $blob) {
            $this->itemLocationResolver->appendUniqueXmp($context->xmpBlobs, $context->xmpHashes, $blob);
        }

        foreach ($payloads['directXmp'] as $blob) {
            $this->itemLocationResolver->appendUniqueXmp($context->xmpBlobs, $context->xmpHashes, $blob);
        }

        foreach ($payloads['uuidXmp'] as $blob) {
            $this->itemLocationResolver->appendUniqueXmp($context->xmpBlobs, $context->xmpHashes, $blob);
        }

        [$mergedQtKeys, $mergedQtDataAtoms] = $this->quickTimeDecoder->mergeQuickTimeKeys(
            $context->qtKeys,
            $payloads['keysMaps'],
            $payloads['ilstBoxes'],
            $context->qtDataAtoms,
            $payloads['hasMhdr'],
            $payloads['countryLists'],
            $payloads['languageLists'],
            $payloads['isMdta'],
        );
        $context->qtKeys      = $mergedQtKeys;
        $context->qtDataAtoms = $mergedQtDataAtoms;
    }

    /**
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
    private function collectDirectPayloads(BoxDescriptor $meta, IsoBmffParseContext $context, int $fileOffsetOrigin = 0): array
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

        $childOffset = $this->detectMetaChildOffset($meta, $context->allowQuickTimeMetaWithoutFullBox);
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
                    if ($child->contentSize > self::MAX_ITEM_PAYLOAD_SIZE) {
                        throw new ParseError('direct Exif box payload exceeds maximum allowed size', 1396);
                    }

                    // The EXIF 3.0 §4.8 Exif box must expose the TIFF header directly; normalise deviations.
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

                    if ($child->contentSize > self::MAX_ITEM_PAYLOAD_SIZE) {
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
                    if ($child->contentSize > self::MAX_ITEM_PAYLOAD_SIZE) {
                        throw new ParseError('direct XMP box payload exceeds maximum allowed size', 1397);
                    }

                    $directXmp[] = $this->boxNavigator->readAll($child->window);
                    break;
                case BoxType::UUID->value:
                    if ($child->userType === self::XMP_UUID) {
                        // Enforce payload cap before reading uuid XMP box
                        if ($child->contentSize > self::MAX_ITEM_PAYLOAD_SIZE) {
                            throw new ParseError('uuid XMP box payload exceeds maximum allowed size', 1397);
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
                    $countryLists = $this->quickTimeDecoder->parseLocaleListAtom($child, 'ctry');
                    break;
                case BoxType::LANG->value:
                    $requiresHdlr  = true;
                    $languageLists = $this->quickTimeDecoder->parseLocaleListAtom($child, 'lang');
                    break;
            }
        }

        if (($hdlrCount !== 1) && $requiresHdlr) {
            throw new ParseError('meta must contain exactly one hdlr box', 1478);
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
            throw new ParseError('Insufficient bytes for ' . $context . '.', 1170);
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

    /**
     * Parses nested metadata atom content from QuickTime data type 28 payloads.
     *
     * QuickTime File Format 2012, Table 3-5 defines type 28 as a block containing
     * Metadata atom structure. Parse the nested structure using the existing meta
     * parsing flow on a bounded in-memory stream.
     *
     * @param string $payload Raw data box payload bytes for type 28.
     *
     * @return array{keys: QuickTimeKeyMap, atoms: QuickTimeDataAtomList}
     */
    private function parseNestedMetadataPayload(string $payload): array
    {
        if ($this->nestedMetadataDepth >= self::MAX_NESTED_METADATA_DEPTH) {
            throw new ParseError('data box nested metadata depth exceeds maximum allowed.', 1458);
        }

        if (strlen($payload) > self::MAX_ITEM_PAYLOAD_SIZE) {
            throw new ParseError('data box nested metadata payload exceeds maximum allowed size.', 1459);
        }

        $metaSize = 8 + strlen($payload);

        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new ParseError('unable to create nested metadata stream.', 1464);
        }

        $written = fwrite($handle, pack('N', $metaSize) . BoxType::META->value . $payload);
        if (!is_int($written) || ($written !== $metaSize)) {
            throw new ParseError('unable to write nested metadata payload.', 1465);
        }

        rewind($handle);

        $nestedStream = new Stream($handle, $metaSize);
        $nestedParser = new self($nestedStream, $this->nestedMetadataDepth + 1);
        $context      = new IsoBmffParseContext();

        $meta = $nestedParser->boxNavigator->readBoxAt(0, $metaSize);
        if ($meta->type !== BoxType::META->value) {
            throw new ParseError('nested metadata payload is not a valid meta atom.', 1466);
        }

        $nestedParser->parseMetaBox($meta, $context);

        return [
            'keys'  => $context->qtKeys,
            'atoms' => $context->qtDataAtoms,
        ];
    }
}
