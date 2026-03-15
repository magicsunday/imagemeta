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
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeDataAtom;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;

use function array_key_exists;
use function fopen;
use function fwrite;
use function implode;
use function in_array;
use function is_int;
use function pack;
use function rewind;
use function sprintf;
use function strlen;

/**
 * Streaming ISOBMFF reader for HEIC/AVIF/MP4/MOV.
 * Extracts EXIF/XMP payloads and QuickTime metadata.
 *
 * EXIF 3.0 §4.8 outlines embedding Exif items in ISO BMFF containers through
 * the `Exif` box and item metadata.
 *
 * @phpstan-import-type QuickTimeValue from QuickTimeValueDecoder
 * @phpstan-import-type QuickTimeKeyMap from QuickTimeValueDecoder
 * @phpstan-import-type QuickTimeKeyEntry from QuickTimeValueDecoder
 * @phpstan-import-type QuickTimeRawDataAtom from QuickTimeValueDecoder
 * @phpstan-import-type QuickTimeCoercedDataAtom from QuickTimeValueDecoder
 * @phpstan-import-type QuickTimeDataAtomList from QuickTimeValueDecoder
 */
final readonly class IsoBmffParser implements IsoBmffParserInterface
{
    /**
     * QuickTime-compatible file type brand.
     */
    private const string BRAND_QUICKTIME = 'qt  ';

    private BoxNavigator $boxNavigator;

    private ItemPayloadResolver $itemPayloadResolver;

    private QuickTimeMetadataDecoder $quickTimeDecoder;

    private ItemLocationResolver $itemLocationResolver;

    private TrackMediaParser $trackMediaParser;

    private BoxPayloadCollector $boxPayloadCollector;

    /**
     * Initialises the extractor with the source stream that contains the ISO BMFF structure.
     *
     * @param Stream              $stream              Stream positioned at the beginning of the media file to parse.
     * @param IsoBmffParserConfig $config              Guard limits for ISO BMFF parsing.
     * @param int                 $nestedMetadataDepth Current nesting depth for type-28 metadata payloads.
     */
    public function __construct(
        private Stream $stream,
        private IsoBmffParserConfig $config = new IsoBmffParserConfig(),
        private int $nestedMetadataDepth = 0,
    ) {
        $boxNavigator = new BoxNavigator($stream);

        $this->boxNavigator = $boxNavigator;

        $quickTimeKeyResolver       = new QuickTimeKeyResolver($boxNavigator);
        $ilocBoxParser              = new IlocBoxParser($boxNavigator);
        $this->itemPayloadResolver  = new ItemPayloadResolver($stream, $boxNavigator, $this->config->maxItemPayloadSize);
        $this->itemLocationResolver = new ItemLocationResolver($this->itemPayloadResolver);

        $this->quickTimeDecoder = new QuickTimeMetadataDecoder(
            $boxNavigator,
            $quickTimeKeyResolver,
            new QuickTimeValueDecoder($this->parseNestedMetadataPayload(...)),
        );
        $this->trackMediaParser = new TrackMediaParser(
            $boxNavigator,
            $this->parseUdtaBox(...),
            $ilocBoxParser->parseDinf(...),
        );

        $this->boxPayloadCollector = new BoxPayloadCollector(
            $boxNavigator,
            $this->trackMediaParser,
            $ilocBoxParser,
            $this->quickTimeDecoder,
            $quickTimeKeyResolver,
            $this->itemPayloadResolver,
            $this->config->maxItemPayloadSize,
        );
    }

    /**
     * Extracts EXIF blobs, XMP packets, and QuickTime metadata from the stream.
     */
    public function extract(): IsoBmffParseResult
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
            } elseif (($box->type === BoxType::UUID->value) && ($box->userType === BoxPayloadCollector::XMP_UUID)) {
                if ($box->contentSize > $this->config->maxItemPayloadSize) {
                    throw new ParseError('uuid XMP payload exceeds maximum allowed size', 1368);
                }

                $context->queuedUuidXmp[] = $this->boxNavigator->readAll($box->window);
            }
        }

        foreach ($context->queuedUuidXmp as $blob) {
            $this->appendUniqueXmpToContext($context, $blob);
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

        return new IsoBmffParseResult(
            $context->exifBlobs,
            $context->xmpBlobs,
            $qt,
            $itemReferenceMap,
            $dataReferenceMap,
            $context->unresolvedItems,
            $context->ispeWidth,
            $context->ispeHeight,
            $context->iccProfile,
            $context->tmapItemIds,
        );
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

        // Tolerate trailing bytes that are too short to form a valid box header.
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
                $this->guardDuplicateMetaBox($metaSeen, 'moov', 1870);
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

                $mvhdKeys = $this->trackMediaParser->parseMvhd($child);
                $this->mergeTrackKeysIfMissing($context->qtKeys, $mvhdKeys);
            }
        }

        if ($selectedVideoTrack !== null) {
            $this->mergeTrackKeysIfMissing($context->qtKeys, $selectedVideoTrack);
        }

        if ($selectedAudioTrack !== null) {
            $this->mergeTrackKeysIfMissing($context->qtKeys, $selectedAudioTrack);
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
                $this->guardDuplicateMetaBox($metaSeen, 'moof', 1416);
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

        $majorBrand = $this->boxNavigator->normalizeFourcc($majorBrandRaw);
        $minor      = $win->readU32BE();

        if (($ftyp->contentSize - 8) % 4 !== 0) {
            throw new ParseError('ftyp compatible_brands length is not a multiple of 4', 1142);
        }

        $brands = [];

        while ($win->tell() + 4 <= $ftyp->contentSize) {
            $brandRaw = $win->read(4);

            if (!$this->boxNavigator->isPrintableFourcc($brandRaw)) {
                continue;
            }

            $brands[] = $this->boxNavigator->normalizeFourcc($brandRaw);
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
                $this->guardDuplicateMetaBox($metaSeen, 'udta', 1143);
                $this->parseMetaBox($child, $context, $fileOffsetOrigin);
            } elseif ($child->type === BoxType::NAME->value) {
                $this->quickTimeDecoder->parseUdtaNameAtom($child, $context);
            } elseif ($child->type === 'XMP_') {
                if ($child->contentSize > $this->config->maxItemPayloadSize) {
                    throw new ParseError('udta XMP_ payload exceeds maximum allowed size', 2100);
                }

                $this->appendUniqueXmpToContext($context, $this->boxNavigator->readAll($child->window));
            } else {
                $this->quickTimeDecoder->parseUdtaTextAtom($child, $context);
            }
        }
    }

    /**
     * Guards against duplicate meta boxes within a single container.
     *
     * QuickTime File Format 2012, "Metadata Structure": only one
     * metadata atom is allowed per container location.
     *
     * @param bool   $metaSeen  Whether a meta box has already been encountered (updated by reference).
     * @param string $container Container name for the error message.
     * @param int    $errorCode Error code for the ParseError.
     *
     * @throws ParseError When a duplicate meta box is detected.
     */
    private function guardDuplicateMetaBox(bool &$metaSeen, string $container, int $errorCode): void
    {
        if ($metaSeen) {
            throw new ParseError(sprintf('duplicate meta box in %s', $container), $errorCode);
        }

        $metaSeen = true;
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
     * By-ref accumulator: the target map is mutated in-place as a lightweight merge
     * without copying the entire map on each call.
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
        $context->itemReferences = $this->itemLocationResolver->mergeItemReferencesByContext($context->itemReferences, $meta->offset, $payloads->itemReferences);
        $context->dataReferences = $this->itemLocationResolver->mergeDataReferencesByContext($context->dataReferences, $meta->offset, $payloads->dataReferences);

        $idatPayload = $payloads->idatPayload;

        [$exifItemIds, $xmpItemIds, $tmapItemIds] = $this->itemLocationResolver->gatherItemIds($payloads->itemInfos, $payloads->primaryItemId);

        foreach ($tmapItemIds as $tmapItemId) {
            $context->tmapItemIds[] = $tmapItemId;
        }

        // Resolve EXIF item payloads and normalize leading headers.
        // EXIF 3.0 §4.8 notes that item payloads omit the APP1 signature; some
        // encoders still include it, so we normalize accordingly.
        $exifResult = $this->itemLocationResolver->resolveQueuedItems($exifItemIds, $payloads->locations, $payloads->itemReferences, $this->itemPayloadResolver->normalizeExifBlob(...), $payloads->dataReferences, $idatPayload, $meta->offset);

        foreach ($exifResult->unresolvedItems as $unresolvedItem) {
            $context->unresolvedItems[] = $unresolvedItem;
        }

        foreach ($exifResult->resolved as $blob) {
            $context->exifBlobs[] = $blob;
        }

        // EXIF 3.0 Annex A item metadata: when both item-based and direct Exif payloads
        // are present, keep item-based payloads first so pitm-driven default selection
        // remains deterministic for MetadataReader::fromIsoBmff().
        foreach ($payloads->directExif as $blob) {
            $context->exifBlobs[] = $blob;
        }

        // Resolve referenced XMP payloads in declared priority order.
        $xmpResult = $this->itemLocationResolver->resolveQueuedItems($xmpItemIds, $payloads->locations, $payloads->itemReferences, null, $payloads->dataReferences, $idatPayload, $meta->offset);

        foreach ($xmpResult->unresolvedItems as $unresolvedItem) {
            $context->unresolvedItems[] = $unresolvedItem;
        }

        foreach ($xmpResult->resolved as $blob) {
            $this->appendUniqueXmpToContext($context, $blob);
        }

        foreach ($payloads->directXmp as $blob) {
            $this->appendUniqueXmpToContext($context, $blob);
        }

        foreach ($payloads->uuidXmp as $blob) {
            $this->appendUniqueXmpToContext($context, $blob);
        }

        [$mergedQtKeys, $mergedQtDataAtoms] = $this->quickTimeDecoder->mergeQuickTimeKeys(
            $context->qtKeys,
            $payloads->keysMaps,
            $payloads->ilstBoxes,
            $context->qtDataAtoms,
            $payloads->hasMhdr,
            $payloads->countryLists,
            $payloads->languageLists,
            $payloads->isMdta,
            $payloads->isMdir,
        );
        $context->qtKeys      = $mergedQtKeys;
        $context->qtDataAtoms = $mergedQtDataAtoms;

        if (($context->ispeWidth === null) && ($payloads->ispeWidth !== null)) {
            $context->ispeWidth  = $payloads->ispeWidth;
            $context->ispeHeight = $payloads->ispeHeight;
        }

        if (($context->iccProfile === null) && ($payloads->iccProfile !== null)) {
            $context->iccProfile = $payloads->iccProfile;
        }
    }

    /**
     * Delegates payload collection to BoxPayloadCollector.
     */
    private function collectDirectPayloads(BoxDescriptor $meta, IsoBmffParseContext $context, int $fileOffsetOrigin = 0): BoxPayloadCollection
    {
        return $this->boxPayloadCollector->collect($meta, $context->allowQuickTimeMetaWithoutFullBox, $fileOffsetOrigin);
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
        if ($this->nestedMetadataDepth >= $this->config->maxNestedMetadataDepth) {
            throw new ParseError('data box nested metadata depth exceeds maximum allowed.', 1458);
        }

        if (strlen($payload) > $this->config->maxItemPayloadSize) {
            throw new ParseError('data box nested metadata payload exceeds maximum allowed size.', 1459);
        }

        $metaSize = 8 + strlen($payload);

        $handle = fopen('php://temp', 'w+b');

        if ($handle === false) {
            throw new ParseError('unable to create nested metadata stream.', 1992);
        }

        $written = fwrite($handle, pack('N', $metaSize) . BoxType::META->value . $payload);

        if (!is_int($written) || ($written !== $metaSize)) {
            throw new ParseError('unable to write nested metadata payload.', 1465);
        }

        rewind($handle);

        $nestedStream = new Stream($handle, $metaSize);
        $nestedParser = new self($nestedStream, $this->config, $this->nestedMetadataDepth + 1);
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

    /**
     * Appends an XMP blob to the context if it has not been seen before.
     */
    private function appendUniqueXmpToContext(IsoBmffParseContext $context, string $blob): void
    {
        $hash = $this->itemLocationResolver->uniqueXmpHash($context->xmpHashes, $blob);

        if ($hash !== null) {
            $context->xmpHashes[$hash] = true;
            $context->xmpBlobs[]       = $blob;
        }
    }
}
