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
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeDataAtom;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;

use function array_filter;
use function array_key_exists;
use function array_unique;
use function array_unshift;
use function array_values;
use function bin2hex;
use function count;
use function explode;
use function iconv;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function mb_check_encoding;
use function ord;
use function preg_match;
use function round;
use function rtrim;
use function sha1;
use function sprintf;
use function strcasecmp;
use function strlen;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

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
 * @phpstan-type QuickTimeRawDataAtom = array{type: int, locale: int, value: string|int|float}
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
     * FourCC for QuickTime metadata box.
     */
    private const string BOX_META = 'meta';

    /**
     * FourCC for file type box describing the major brand.
     */
    private const string BOX_FTYP = 'ftyp';

    /**
     * QuickTime-compatible file type brand.
     */
    private const string BRAND_QUICKTIME = 'qt  ';

    /**
     * FourCC for QuickTime movie box.
     */
    private const string BOX_MOOV = 'moov';

    /**
     * FourCC for movie fragment box.
     */
    private const string BOX_MOOF = 'moof';

    /**
     * FourCC for UUID box used to store custom payloads.
     */
    private const string BOX_UUID = 'uuid';

    /**
     * FourCC for embedded Exif box (EXIF 3.0 §4.8).
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
     * FourCC for item data box.
     */
    private const string BOX_IDAT = 'idat';

    /**
     * FourCC for primary item box.
     */
    private const string BOX_PITM = 'pitm';

    /**
     * FourCC for item reference box.
     */
    private const string BOX_IREF = 'iref';

    /**
     * FourCC for data information box.
     */
    private const string BOX_DINF = 'dinf';

    /**
     * FourCC for data reference box.
     */
    private const string BOX_DREF = 'dref';

    /**
     * FourCC for URL data references.
     */
    private const string BOX_URL = 'url ';

    /**
     * FourCC for URN data references.
     */
    private const string BOX_URN = 'urn ';

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
     * FourCC for QuickTime track name atom inside user data.
     */
    private const string BOX_NAME = 'name';

    /**
     * FourCC for QuickTime track container.
     */
    private const string BOX_TRAK = 'trak';

    /**
     * FourCC for track header box.
     */
    private const string BOX_TKHD = 'tkhd';

    /**
     * FourCC for media container box.
     */
    private const string BOX_MDIA = 'mdia';

    /**
     * FourCC for handler reference box.
     */
    private const string BOX_HDLR = 'hdlr';

    /**
     * FourCC for media information box.
     */
    private const string BOX_MINF = 'minf';

    /**
     * FourCC for sample table box.
     */
    private const string BOX_STBL = 'stbl';

    /**
     * FourCC for sample description box.
     */
    private const string BOX_STSD = 'stsd';

    /**
     * FourCC for video media header box.
     */
    private const string BOX_VMHD = 'vmhd';

    /**
     * FourCC for sound media header box.
     */
    private const string BOX_SMHD = 'smhd';

    /**
     * FourCC for null media header box.
     */
    private const string BOX_NMHD = 'nmhd';

    /**
     * FourCC for time-to-sample box.
     */
    private const string BOX_STTS = 'stts';

    /**
     * FourCC for sample-to-chunk box.
     */
    private const string BOX_STSC = 'stsc';

    /**
     * FourCC for sample size box.
     */
    private const string BOX_STSZ = 'stsz';

    /**
     * FourCC for compact sample size box.
     */
    private const string BOX_STZ2 = 'stz2';

    /**
     * FourCC for chunk offset box.
     */
    private const string BOX_STCO = 'stco';

    /**
     * FourCC for large chunk offset box.
     */
    private const string BOX_CO64 = 'co64';

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
     * FourCC for QuickTime metadata header atom.
     */
    private const string BOX_MHDR = 'mhdr';

    /**
     * FourCC for QuickTime item information atom inside metadata items.
     */
    private const string BOX_ITIF = 'itif';

    /**
     * FourCC for QuickTime country list atom.
     */
    private const string BOX_CTRY = 'ctry';

    /**
     * FourCC for QuickTime language list atom.
     */
    private const string BOX_LANG = 'lang';

    /**
     * FourCC for the movie header box.
     */
    private const string BOX_MVHD = 'mvhd';

    /**
     * FourCC for the media header box.
     */
    private const string BOX_MDHD = 'mdhd';

    /**
     * FourCC for QuickTime mean payload in free-form metadata.
     */
    private const string FREEFORM_MEAN = 'mean';

    /**
     * FourCC for QuickTime name payload in free-form metadata.
     */
    private const string FREEFORM_NAME = 'name';

    /**
     * Maximum cumulative payload size allowed when assembling item extents.
     */
    public const int MAX_ITEM_PAYLOAD_SIZE = 8 * 1024 * 1024;

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
     * Maximum number of keys in a keys box to prevent DoS attacks.
     */
    private const int MAX_KEYS_ENTRIES = 1000;

    /**
     * Maximum number of entries in a ctry/lang locale list atom.
     */
    private const int MAX_LOCALE_LIST_ENTRIES = 255;

    /**
     * QuickTime 'mdta' FourCC identifying the metadata type scheme.
     *
     * Used as (1) the handler reference type in the metadata hdlr box
     * (QuickTime File Format 2012, "Metadata Atom") and (2) the key namespace
     * in the keys atom ("Metadata item keys atom"), where the key_value is a
     * UTF-8 reverse-DNS string (e.g. 'com.apple.quicktime.content.identifier').
     */
    private const string QUICKTIME_MDTA = 'mdta';

    /**
     * Maximum number of sample entries in an stsd box to prevent DoS attacks.
     */
    private const int MAX_STSD_ENTRIES = 100;

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
     * @return array{0: list<string>, 1: list<string>, 2: ?QuickTimeMeta, 3: ?IsoBmffItemReferenceMap, 4: ?IsoBmffDataReferenceMap, 5: list<IsoBmffUnresolvedItem>}
     */
    public function extract(): array
    {
        $context       = new IsoBmffParseContext();
        $queuedUuidXmp = [];
        $moovCount     = 0;

        foreach ($this->walkTopLevelBoxes() as $box) {
            if ($box->type === self::BOX_FTYP) {
                $context->qtKeys = $this->mergeAssociative($context->qtKeys, $this->parseFtyp($box, $context));
            } elseif ($box->type === self::BOX_META) {
                $this->parseMetaBox($box, $context);
            } elseif ($box->type === self::BOX_MOOV) {
                ++$moovCount;

                if ($moovCount > 1) {
                    throw new ParseError('file must contain exactly one moov box', 1373);
                }

                $this->parseMoovBox($box, $context);
            } elseif ($box->type === self::BOX_MOOF) {
                $this->parseMoofBox($box, $context);
            } elseif ($box->type === self::BOX_UUID && $box->userType === self::XMP_UUID) {
                if ($box->contentSize > self::MAX_ITEM_PAYLOAD_SIZE) {
                    throw new ParseError('uuid XMP payload exceeds maximum allowed size', 1368);
                }

                $queuedUuidXmp[] = $this->readAll($box->window);
            }
        }

        foreach ($queuedUuidXmp as $blob) {
            $this->appendUniqueXmp($context->xmpBlobs, $context->xmpHashes, $blob);
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
            $box = $this->readBoxAt($offset, $fileSize, allowImplicitSize: true);
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

        foreach ($this->walkChildren($moov) as $child) {
            if ($child->type === self::BOX_META) {
                // QuickTime File Format 2012, "Metadata Structure": only one
                // metadata atom is allowed per container location.
                if ($metaSeen) {
                    throw new ParseError('duplicate meta box in moov', 1141);
                }

                $metaSeen = true;
                $this->parseMetaBox($child, $context);
            } elseif ($child->type === self::BOX_UDTA) {
                ++$udtaCount;
                if ($udtaCount > 1) {
                    throw new ParseError('duplicate udta box in moov', 1417);
                }

                $this->parseUdtaBox($child, $context);
            } elseif ($child->type === self::BOX_TRAK) {
                ++$trakCount;
                $this->parseTrak($child, $context);
            } elseif ($child->type === self::BOX_MVHD) {
                ++$mvhdCount;

                if ($mvhdCount > 1) {
                    throw new ParseError('moov must contain exactly one mvhd box', 1374);
                }

                $this->parseMvhd($child);
            }
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

        foreach ($this->walkChildren($moof) as $child) {
            if ($child->type === self::BOX_META) {
                if ($metaSeen) {
                    throw new ParseError('duplicate meta box in moof', 1416);
                }

                $metaSeen = true;
                $this->parseMetaBox($child, $context, $moof->offset);
            } elseif ($child->type === self::BOX_UDTA) {
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
        if (!$this->isPrintableFourcc($majorBrandRaw)) {
            throw new ParseError('ftyp major_brand must be a printable 4CC', 1476);
        }

        $majorBrand = $this->normaliseFourcc($majorBrandRaw);
        $minor      = $win->readU32BE();

        if (($ftyp->contentSize - 8) % 4 !== 0) {
            throw new ParseError('ftyp compatible_brands length is not a multiple of 4', 1142);
        }

        $brands = [];
        while ($win->tell() + 4 <= $ftyp->contentSize) {
            $brandRaw = $win->read(4);
            if (!$this->isPrintableFourcc($brandRaw)) {
                throw new ParseError('ftyp compatible_brand must be a printable 4CC', 1477);
            }

            $brands[] = $this->normaliseFourcc($brandRaw);
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

        foreach ($this->walkChildren($udta, allowTrailingTerminator: true) as $child) {
            if ($child->type === self::BOX_META) {
                // QuickTime File Format 2012, "Metadata Structure": only one
                // metadata atom is allowed per container location.
                if ($metaSeen) {
                    throw new ParseError('duplicate meta box in udta', 1143);
                }

                $metaSeen = true;
                $this->parseMetaBox($child, $context, $fileOffsetOrigin);
            } elseif ($child->type === self::BOX_NAME) {
                $this->parseUdtaNameAtom($child, $context);
            } else {
                $this->parseUdtaTextAtom($child, $context);
            }
        }
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
    private function parseUdtaNameAtom(BoxDescriptor $name, IsoBmffParseContext $context): void
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
    private function parseUdtaTextAtom(BoxDescriptor $atom, IsoBmffParseContext $context): void
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
     * Parses a track box and surfaces relevant video/audio details as QuickTime keys.
     *
     * QuickTime File Format 2012, "Track Atoms": a track atom may contain
     * a user data atom (`udta`) carrying track-level metadata.
     *
     * @param BoxDescriptor       $trak    Box descriptor for the track container.
     * @param IsoBmffParseContext $context Shared parse-state context.
     */
    private function parseTrak(BoxDescriptor $trak, IsoBmffParseContext $context): void
    {
        $tkhdWidth   = null;
        $tkhdHeight  = null;
        $handler     = null;
        $handlerName = null;
        $sampleInfo  = [];
        $tkhdCount   = 0;
        $mdiaCount   = 0;
        $udtaCount   = 0;

        foreach ($this->walkChildren($trak) as $child) {
            if ($child->type === self::BOX_TKHD) {
                ++$tkhdCount;

                if ($tkhdCount > 1) {
                    throw new ParseError('trak must contain exactly one tkhd box', 1376);
                }

                [$tkhdWidth, $tkhdHeight] = $this->parseTkhd($child);
            } elseif ($child->type === self::BOX_MDIA) {
                ++$mdiaCount;

                if ($mdiaCount > 1) {
                    throw new ParseError('trak must contain exactly one mdia box', 1377);
                }

                [$handler, $handlerName, $sampleInfo] = $this->parseMdia($child, $context);
            } elseif ($child->type === self::BOX_UDTA) {
                ++$udtaCount;
                if ($udtaCount > 1) {
                    throw new ParseError('duplicate udta box in trak', 1418);
                }

                $this->parseUdtaBox($child, $context);
            }
        }

        if ($tkhdCount === 0) {
            throw new ParseError('trak must contain exactly one tkhd box', 1376);
        }

        if ($mdiaCount === 0) {
            throw new ParseError('trak must contain exactly one mdia box', 1377);
        }

        if ($handler === null) {
            return;
        }

        if (
            $handlerName !== null
            && $handlerName !== ''
            && !array_key_exists(QuickTimeMeta::HANDLER_DESCRIPTION_KEY, $context->qtKeys)
        ) {
            $context->qtKeys[QuickTimeMeta::HANDLER_DESCRIPTION_KEY] = $handlerName;
        }

        if ($handler === 'vide') {
            $width  = $sampleInfo['width'] ?? $tkhdWidth;
            $height = $sampleInfo['height'] ?? $tkhdHeight;

            if (
                $width !== null
                && $width > 0
                && !array_key_exists(QuickTimeMeta::VIDEO_WIDTH_KEY, $context->qtKeys)
            ) {
                $context->qtKeys[QuickTimeMeta::VIDEO_WIDTH_KEY] = $width;
            }

            if (
                $height !== null
                && $height > 0
                && !array_key_exists(QuickTimeMeta::VIDEO_HEIGHT_KEY, $context->qtKeys)
            ) {
                $context->qtKeys[QuickTimeMeta::VIDEO_HEIGHT_KEY] = $height;
            }

            if (
                isset($sampleInfo['format'])
                && $sampleInfo['format'] !== ''
                && !array_key_exists(QuickTimeMeta::VIDEO_CODEC_KEY, $context->qtKeys)
            ) {
                $context->qtKeys[QuickTimeMeta::VIDEO_CODEC_KEY] = $sampleInfo['format'];
            }

            if (
                isset($sampleInfo['compressorName'])
                && $sampleInfo['compressorName'] !== ''
                && !array_key_exists(QuickTimeMeta::COMPRESSOR_NAME_KEY, $context->qtKeys)
            ) {
                $context->qtKeys[QuickTimeMeta::COMPRESSOR_NAME_KEY] = $sampleInfo['compressorName'];
            }
        } elseif ($handler === 'soun') {
            if (isset($sampleInfo['format']) && $sampleInfo['format'] !== '') {
                if (!array_key_exists(QuickTimeMeta::AUDIO_FORMAT_KEY, $context->qtKeys)) {
                    $context->qtKeys[QuickTimeMeta::AUDIO_FORMAT_KEY] = $sampleInfo['format'];
                }

                if (!array_key_exists(QuickTimeMeta::AUDIO_CODEC_KEY, $context->qtKeys)) {
                    $context->qtKeys[QuickTimeMeta::AUDIO_CODEC_KEY] = $sampleInfo['format'];
                }
            }

            if (
                isset($sampleInfo['channels'])
                && !array_key_exists(QuickTimeMeta::AUDIO_CHANNELS_KEY, $context->qtKeys)
            ) {
                $context->qtKeys[QuickTimeMeta::AUDIO_CHANNELS_KEY] = $sampleInfo['channels'];
            }

            if (
                isset($sampleInfo['bitsPerSample'])
                && !array_key_exists(QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY, $context->qtKeys)
            ) {
                $context->qtKeys[QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY] = $sampleInfo['bitsPerSample'];
            }

            if (
                isset($sampleInfo['sampleRate'])
                && !array_key_exists(QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY, $context->qtKeys)
            ) {
                $context->qtKeys[QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY] = $sampleInfo['sampleRate'];
            }
        }
    }

    /**
     * Parses the track header box (`tkhd`) and extracts display width/height.
     *
     * @param BoxDescriptor $tkhd Track header descriptor.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function parseTkhd(BoxDescriptor $tkhd): array
    {
        $win = $tkhd->window;
        $win->seek(0);

        if ($tkhd->contentSize < 84) {
            throw new ParseError('tkhd box truncated', 1144);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported tkhd box version', 1145);
        }

        // ISO/IEC 14496-12 §8.3.2: version 0 uses 32-bit timestamps, version 1 uses 64-bit
        if ($version === 1) {
            if ($tkhd->contentSize < 96) {
                throw new ParseError('tkhd version 1 box truncated', 1146);
            }

            $win->read(8 + 8); // creation(64), modification(64)
            $trackId    = $win->readU32BE();
            $reserved32 = $win->read(4);
            $win->read(8); // duration(64)
        } else {
            $win->read(4 + 4); // creation(32), modification(32)
            $trackId    = $win->readU32BE();
            $reserved32 = $win->read(4);
            $win->read(4); // duration(32)
        }

        // ISO/IEC 14496-12 §8.3.2: track_ID must be non-zero
        if ($trackId === 0) {
            throw new ParseError('tkhd track_ID must not be zero', 1369);
        }

        // ISO/IEC 14496-12 §8.3.2: reserved field after track_ID must be zero
        if ($reserved32 !== "\0\0\0\0") {
            throw new ParseError('tkhd reserved field after track_ID must be zero', 1370);
        }

        $reserved64 = $win->read(8); // reserved

        if ($reserved64 !== "\0\0\0\0\0\0\0\0") {
            throw new ParseError('tkhd reserved 8-byte field must be zero', 1371);
        }

        $win->read(2); // layer
        $win->read(2); // alternate group
        $win->read(2); // volume

        $reserved16 = $win->read(2); // reserved

        if ($reserved16 !== "\0\0") {
            throw new ParseError('tkhd reserved 2-byte field must be zero', 1372);
        }

        $win->read(36); // matrix

        $widthFixed  = $win->readU32BE();
        $heightFixed = $win->readU32BE();

        // ISO/IEC 14496-12 §8.3.2: when track_size_is_aspect_ratio flag is set,
        // width/height represent aspect ratio, not pixel dimensions
        $isAspectRatio = ($flags & 0x000008) !== 0;

        // GH-890: decode 16.16 fixed-point with rounding instead of truncation
        $width  = ($widthFixed > 0 && !$isAspectRatio) ? (int) round($widthFixed / 65536) : null;
        $height = ($heightFixed > 0 && !$isAspectRatio) ? (int) round($heightFixed / 65536) : null;

        return [$width, $height];
    }

    /**
     * Validates the media header box (`mdhd`).
     *
     * ISO/IEC 14496-12 §8.4.2: the mdhd box is a FullBox with version 0 (32-bit
     * timestamps) or 1 (64-bit timestamps). The timescale field must be non-zero.
     *
     * @param BoxDescriptor $mdhd Media header box descriptor.
     */
    private function parseMdhd(BoxDescriptor $mdhd): void
    {
        $win = $mdhd->window;
        $win->seek(0);

        if ($mdhd->contentSize < 4) {
            throw new ParseError('mdhd box truncated', 1400);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported mdhd box version', 1401);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported mdhd box flags', 1402);
        }

        // version 0: 4+4+4+4+2+2 = 20 bytes after header; version 1: 8+8+4+8+2+2 = 32 bytes
        $minPayload = $version === 1 ? 36 : 24;
        if ($mdhd->contentSize < $minPayload) {
            throw new ParseError('mdhd box truncated', 1400);
        }

        if ($version === 1) {
            $win->read(8 + 8); // creation_time(64), modification_time(64)
        } else {
            $win->read(4 + 4); // creation_time(32), modification_time(32)
        }

        $timescale = $win->readU32BE();

        if ($timescale === 0) {
            throw new ParseError('mdhd timescale must not be zero', 1403);
        }
    }

    /**
     * Validates the movie header box (`mvhd`).
     *
     * ISO/IEC 14496-12 §8.2.2: the mvhd box is a FullBox with version 0 (32-bit
     * timestamps) or 1 (64-bit timestamps). The timescale and next_track_ID fields
     * must be non-zero.
     *
     * @param BoxDescriptor $mvhd Movie header box descriptor.
     */
    private function parseMvhd(BoxDescriptor $mvhd): void
    {
        $win = $mvhd->window;
        $win->seek(0);

        if ($mvhd->contentSize < 4) {
            throw new ParseError('mvhd box truncated', 1405);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported mvhd box version', 1406);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported mvhd box flags', 1407);
        }

        // version 0: 4+4+4+4 + 76 = 96 after FullBox header (including rate, volume, matrix, etc.)
        // version 1: 8+8+4+8 + 76 = 108 after FullBox header
        $minPayload = $version === 1 ? 112 : 100;
        if ($mvhd->contentSize < $minPayload) {
            throw new ParseError('mvhd box truncated', 1405);
        }

        if ($version === 1) {
            $win->read(8 + 8); // creation_time(64), modification_time(64)
        } else {
            $win->read(4 + 4); // creation_time(32), modification_time(32)
        }

        $timescale = $win->readU32BE();

        if ($timescale === 0) {
            throw new ParseError('mvhd timescale must not be zero', 1408);
        }

        // Skip duration
        if ($version === 1) {
            $win->read(8); // duration(64)
        } else {
            $win->read(4); // duration(32)
        }

        // Skip rate(4), volume(2), reserved(10), matrix(36), pre_defined(24) = 76 bytes
        $win->read(76);

        $nextTrackId = $win->readU32BE();

        if ($nextTrackId === 0) {
            throw new ParseError('mvhd next_track_ID must not be zero', 1409);
        }
    }

    /**
     * Parses the media box (`mdia`) and returns handler/sample information.
     *
     * QuickTime File Format (2012), "User Data Atoms": udta may appear as a
     * child of mdia in addition to moov and trak. Media-level user data is
     * parsed with the same strategy as other udta paths (GH-1004).
     *
     * @param BoxDescriptor       $mdia    Media box descriptor.
     * @param IsoBmffParseContext $context Shared parse-state context.
     *
     * @return array{0: ?string, 1: ?string, 2: array<string, int|string>}
     */
    private function parseMdia(BoxDescriptor $mdia, IsoBmffParseContext $context): array
    {
        $handler     = null;
        $handlerName = null;
        $sampleInfo  = [];
        $hdlrCount   = 0;
        $minfCount   = 0;
        $mdhdCount   = 0;
        $udtaCount   = 0;

        // GH-881: collect children first so hdlr/minf order does not matter
        $children = [];

        foreach ($this->walkChildren($mdia) as $child) {
            if ($child->type === self::BOX_HDLR) {
                ++$hdlrCount;

                if ($hdlrCount > 1) {
                    throw new ParseError('mdia must contain exactly one hdlr box', 1378);
                }

                [$handler, $handlerName] = $this->parseHdlr($child);
            } elseif ($child->type === self::BOX_MINF) {
                ++$minfCount;

                if ($minfCount > 1) {
                    throw new ParseError('mdia must contain exactly one minf box', 1379);
                }

                $children[] = $child;
            } elseif ($child->type === self::BOX_MDHD) {
                ++$mdhdCount;

                if ($mdhdCount > 1) {
                    throw new ParseError('mdia must contain exactly one mdhd box', 1380);
                }

                $this->parseMdhd($child);
            } elseif ($child->type === self::BOX_UDTA) {
                ++$udtaCount;

                if ($udtaCount > 1) {
                    throw new ParseError('duplicate udta box in mdia', 1463);
                }

                $this->parseUdtaBox($child, $context);
            }
        }

        if ($hdlrCount === 0) {
            throw new ParseError('mdia must contain exactly one hdlr box', 1378);
        }

        if ($minfCount === 0) {
            throw new ParseError('mdia must contain exactly one minf box', 1379);
        }

        if ($mdhdCount === 0) {
            throw new ParseError('mdia must contain exactly one mdhd box', 1380);
        }

        // Parse minf after hdlr so handler type is always available (GH-881)
        foreach ($children as $child) {
            $sampleInfo = $this->parseMinf($child, $handler);
        }

        return [$handler, $handlerName, $sampleInfo];
    }

    /**
     * Parses the handler reference box (`hdlr`).
     *
     * @param BoxDescriptor $hdlr Handler box descriptor.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parseHdlr(BoxDescriptor $hdlr): array
    {
        $win = $hdlr->window;
        $win->seek(0);

        if ($hdlr->contentSize < 24) {
            throw new ParseError('hdlr box truncated', 1147);
        }

        $versionFlags = $win->read(4);
        $version      = ord($versionFlags[0]);
        $flags        = (ord($versionFlags[1]) << 16) | (ord($versionFlags[2]) << 8) | ord($versionFlags[3]);

        if ($version !== 0) {
            throw new ParseError('unsupported hdlr box version', 1148);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported hdlr box flags', 1149);
        }

        $preDefined = $win->readU32BE();

        if ($preDefined !== 0) {
            throw new ParseError('hdlr pre_defined must be 0', 1150);
        }

        $handler  = $win->read(4);
        $reserved = $win->read(12);

        if ($reserved !== "\0\0\0\0\0\0\0\0\0\0\0\0") {
            throw new ParseError('hdlr reserved fields must be 0', 1151);
        }

        $handlerType = $this->normaliseFourcc($handler);
        $remaining   = $hdlr->contentSize - $win->tell();
        $name        = null;

        if ($remaining > 0) {
            $nameBytes  = $win->read($remaining);
            $countedLen = ord($nameBytes[0]);

            if ($countedLen <= $remaining - 1) {
                // QuickTime File Format 2012, "Handler Reference Atom" (p. 85):
                // component name is a counted string (first byte = length).
                $name = $countedLen > 0 ? substr($nameBytes, 1, $countedLen) : null;
            } elseif ($nameBytes[$remaining - 1] === "\0") {
                // ISO/IEC 14496-12 §8.4.3: NUL-terminated UTF-8 handler name.
                $trimmed = rtrim($nameBytes, "\0");

                if ($trimmed !== '' && !mb_check_encoding($trimmed, 'UTF-8')) {
                    throw new ParseError('hdlr handler name contains invalid UTF-8', 1384);
                }

                $name = $trimmed !== '' ? $trimmed : null;
            } else {
                throw new ParseError(sprintf(
                    'hdlr handler name missing NUL terminator (counted length %d exceeds remaining %d bytes)',
                    $countedLen,
                    $remaining - 1,
                ), 1152);
            }
        }

        return [$handlerType === '' ? null : $handlerType, $name];
    }

    /**
     * Parses the media information box (`minf`) to find sample table details.
     *
     * @param BoxDescriptor $minf        Media information descriptor.
     * @param string|null   $handlerType Declared handler type for the media.
     *
     * @return array<string, int|string>
     */
    private function parseMinf(BoxDescriptor $minf, ?string $handlerType): array
    {
        if ($handlerType === null) {
            return [];
        }

        $stblCount    = 0;
        $dinfCount    = 0;
        $mediaHdrType = null;
        $result       = [];

        // GH-877: determine expected media header box from handler type
        $expectedMediaHdr = match ($handlerType) {
            'vide'  => self::BOX_VMHD,
            'soun'  => self::BOX_SMHD,
            default => self::BOX_NMHD,
        };

        foreach ($this->walkChildren($minf) as $child) {
            if ($child->type === self::BOX_STBL) {
                ++$stblCount;

                if ($stblCount > 1) {
                    throw new ParseError('minf must contain exactly one stbl box', 1381);
                }

                $result = $this->parseStbl($child, $handlerType);
            } elseif ($child->type === self::BOX_DINF) {
                ++$dinfCount;

                if ($dinfCount > 1) {
                    throw new ParseError('minf must contain exactly one dinf box', 1382);
                }

                $this->parseDinf($child);
            } elseif (in_array($child->type, [self::BOX_VMHD, self::BOX_SMHD, self::BOX_NMHD], true)) {
                // GH-877: enforce exactly one handler-matching media header
                if ($mediaHdrType !== null) {
                    throw new ParseError('minf must contain exactly one media header box', 1421);
                }

                $mediaHdrType = $child->type;
            }
        }

        if ($stblCount === 0) {
            throw new ParseError('minf must contain exactly one stbl box', 1381);
        }

        if ($dinfCount === 0) {
            throw new ParseError('minf must contain exactly one dinf box', 1382);
        }

        // GH-877: validate media header presence and handler match
        if ($mediaHdrType === null) {
            throw new ParseError(sprintf('minf missing required media header box %s for handler %s', $expectedMediaHdr, $handlerType), 1422);
        }

        if ($mediaHdrType !== $expectedMediaHdr) {
            throw new ParseError(sprintf('minf media header %s does not match handler %s (expected %s)', $mediaHdrType, $handlerType, $expectedMediaHdr), 1423);
        }

        return $result;
    }

    /**
     * Parses the sample table box (`stbl`).
     *
     * @param BoxDescriptor $stbl        Sample table descriptor.
     * @param string        $handlerType Media handler type.
     *
     * @return array<string, int|string>
     */
    private function parseStbl(BoxDescriptor $stbl, string $handlerType): array
    {
        $stsdCount = 0;
        $sttsCount = 0;
        $stscCount = 0;
        $stszCount = 0;
        $stcoCount = 0;
        $result    = [];

        foreach ($this->walkChildren($stbl) as $child) {
            if ($child->type === self::BOX_STSD) {
                ++$stsdCount;

                if ($stsdCount > 1) {
                    throw new ParseError('stbl must contain exactly one stsd box', 1383);
                }

                $result = $this->parseStsd($child, $handlerType);
            } elseif ($child->type === self::BOX_STTS) {
                ++$sttsCount;

                if ($sttsCount > 1) {
                    throw new ParseError('stbl must contain exactly one stts box', 1424);
                }
            } elseif ($child->type === self::BOX_STSC) {
                ++$stscCount;

                if ($stscCount > 1) {
                    throw new ParseError('stbl must contain exactly one stsc box', 1425);
                }
            } elseif ($child->type === self::BOX_STSZ || $child->type === self::BOX_STZ2) {
                ++$stszCount;

                if ($stszCount > 1) {
                    throw new ParseError('stbl must contain exactly one stsz or stz2 box', 1426);
                }
            } elseif ($child->type === self::BOX_STCO || $child->type === self::BOX_CO64) {
                ++$stcoCount;

                if ($stcoCount > 1) {
                    throw new ParseError('stbl must contain exactly one stco or co64 box', 1427);
                }
            }
        }

        if ($stsdCount === 0) {
            throw new ParseError('stbl must contain exactly one stsd box', 1383);
        }

        // GH-878: enforce mandatory core sample-table boxes
        if ($sttsCount === 0) {
            throw new ParseError('stbl must contain exactly one stts box', 1424);
        }

        if ($stscCount === 0) {
            throw new ParseError('stbl must contain exactly one stsc box', 1425);
        }

        if ($stszCount === 0) {
            throw new ParseError('stbl must contain exactly one stsz or stz2 box', 1426);
        }

        if ($stcoCount === 0) {
            throw new ParseError('stbl must contain exactly one stco or co64 box', 1427);
        }

        return $result;
    }

    /**
     * Parses the sample description box (`stsd`).
     *
     * @param BoxDescriptor $stsd        Sample description descriptor.
     * @param string        $handlerType Handler type describing the media kind.
     *
     * @return array<string, int|string>
     */
    private function parseStsd(BoxDescriptor $stsd, string $handlerType): array
    {
        $win = $stsd->window;
        $win->seek(0);

        if ($stsd->contentSize < 8) {
            throw new ParseError('stsd box truncated', 1153);
        }

        $versionFlags = $win->read(4);
        $version      = ord($versionFlags[0]);
        $flags        = (ord($versionFlags[1]) << 16) | (ord($versionFlags[2]) << 8) | ord($versionFlags[3]);

        // ISO/IEC 14496-12 §8.5.2.2/§8.5.2.3: stsd is a FullBox with flags=0.
        // Version 1 is only valid in audio sample-description context.
        if (($version !== 0) && ($version !== 1)) {
            throw new ParseError('unsupported stsd box version', 1154);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported stsd box flags', 1155);
        }

        if (($version === 1) && ($handlerType !== 'soun')) {
            throw new ParseError('stsd version 1 requires audio handler context', 1465);
        }

        $entryCount = $win->readU32BE();

        // ISO/IEC 14496-12 §8.5.2: Sample Description Box must contain at least one entry.
        if ($entryCount === 0) {
            throw new ParseError('stsd entry count must be at least 1', 1466);
        }

        if ($entryCount > self::MAX_STSD_ENTRIES) {
            throw new ParseError('stsd entry count exceeds maximum allowed', 1156);
        }

        $result = [];
        $pos    = $win->tell();

        for ($i = 0; $i < $entryCount; ++$i) {
            if ($pos + 8 > $stsd->contentSize) {
                throw new ParseError('stsd entry truncated', 1157);
            }

            $win->seek($pos);
            $entrySize = $win->readU32BE();
            $format    = $win->read(4);

            if (($entrySize < 16) || (($pos + $entrySize) > $stsd->contentSize)) {
                throw new ParseError('invalid stsd entry size', 1158);
            }

            $entryStart = $win->tell();
            $entryEnd   = $pos + $entrySize;

            // GH-860: ISO 14496-12 §8.5.2.2: the 6-byte reserved field must be all zeros
            $reserved6 = $win->read(6);
            if ($reserved6 !== "\0\0\0\0\0\0") {
                throw new ParseError('stsd sample entry reserved field must be zero', 1398);
            }

            // GH-865: ISO 14496-12 §8.5.2.2: data_reference_index is 1-based
            $dataRefIndex = $win->readU16BE();
            if ($dataRefIndex === 0) {
                throw new ParseError('stsd sample entry data_reference_index must be >= 1', 1399);
            }

            // GH-835: use first entry only; skip parsing subsequent entries to
            // avoid implicit 'last entry wins' when entry_count > 1
            if ($result === [] && $handlerType === 'vide') {
                if ($win->tell() + 70 > $entryEnd) {
                    throw new ParseError('video sample entry truncated', 1159);
                }

                $win->read(16); // pre-defined/reserved
                $width  = $win->readU16BE();
                $height = $win->readU16BE();
                $win->readU32BE(); // horiz resolution
                $win->readU32BE(); // vert resolution
                $win->read(4); // reserved
                $win->readU16BE(); // frame count

                // GH-836: decode compressorName as strict 32-byte Pascal string
                $nameLength = $win->readU8();
                $nameData   = $win->read(31);

                if ($nameLength > 31) {
                    throw new ParseError('compressorName Pascal string length exceeds 31', 1428);
                }

                $compressor = $nameLength > 0 ? substr($nameData, 0, $nameLength) : '';

                $win->readU16BE(); // depth
                $win->readU16BE(); // pre-defined

                $result = [
                    'format'         => $this->normaliseFourcc($format),
                    'width'          => $width,
                    'height'         => $height,
                    'compressorName' => $compressor,
                ];
            } elseif ($result === [] && $handlerType === 'soun') {
                $result = $this->parseSoundSampleEntry($win, $entryStart, $entryEnd, $entrySize, $format);
            }

            $pos += $entrySize;
        }

        if ($pos !== $stsd->contentSize) {
            throw new ParseError('stsd entries do not fill container', 1161);
        }

        return $result;
    }

    /**
     * Parses an audio sample entry from `stsd`, handling sound description versions 0, 1, and 2.
     *
     * QuickTime File Format 2012, "Sound Sample Description" (v0/v1/v2):
     * - v0 uses the legacy channel/sample-size/16.16 rate layout.
     * - v1 appends four 32-bit packet/frame sizing fields.
     * - v2 repurposes the legacy words as required constants and appends
     *   Core Audio-style channel/rate fields.
     *
     * @param StreamWindow $win        Reader positioned at the beginning of sample-entry specific fields.
     * @param int          $entryStart Absolute offset of the sample-entry specific fields.
     * @param int          $entryEnd   Absolute offset where this sample entry ends.
     * @param int          $entrySize  Declared sample entry size (including size+type header).
     * @param string       $format     Raw fourcc format code.
     *
     * @return array{format: string, channels: int, bitsPerSample: int, sampleRate: int}
     */
    private function parseSoundSampleEntry(StreamWindow $win, int $entryStart, int $entryEnd, int $entrySize, string $format): array
    {
        if ($win->tell() + 8 > $entryEnd) {
            throw new ParseError('audio sample entry truncated', 1160);
        }

        $version       = $win->readU16BE();
        $revisionLevel = $win->readU16BE();
        $vendor        = $win->readU32BE();

        if ($revisionLevel !== 0) {
            throw new ParseError('audio sample entry revision level must be 0', 1455);
        }

        if ($vendor !== 0) {
            throw new ParseError('audio sample entry vendor must be 0', 1456);
        }

        if ($version === 2) {
            return $this->parseSoundSampleEntryVersion2($win, $entryStart, $entryEnd, $entrySize, $format);
        }

        if ($version !== 0 && $version !== 1) {
            throw new ParseError(sprintf('unsupported audio sample entry version %d', $version), 1457);
        }

        if ($win->tell() + 12 > $entryEnd) {
            throw new ParseError('audio sample entry truncated', 1160);
        }

        $channels   = $win->readU16BE();
        $sampleSize = $win->readU16BE();
        // Compression ID and packet size are retained for container compatibility
        // but are not currently surfaced in the extracted metadata model.
        $win->readU16BE();
        $win->readU16BE();

        $sampleRate = $win->readU32BE();

        if ($version === 1) {
            if ($win->tell() + 16 > $entryEnd) {
                throw new ParseError('audio sample entry version 1 extension truncated', 1458);
            }

            // QuickTime File Format 2012, "Sound Sample Description (Version 1)":
            // samplesPerPacket, bytesPerPacket, bytesPerFrame, bytesPerSample.
            $win->readU32BE();
            $win->readU32BE();
            $win->readU32BE();
            $win->readU32BE();
        }

        return [
            'format'        => $this->normaliseFourcc($format),
            'channels'      => $channels,
            'bitsPerSample' => $sampleSize,
            'sampleRate'    => (int) round($sampleRate / 65536),
        ];
    }

    /**
     * Parses sound sample description version 2 and validates required constant fields.
     *
     * QuickTime File Format 2012, "Sound Sample Description (Version 2)":
     * - always3 = 3
     * - always16 = 16
     * - alwaysMinus2 = -2
     * - always0 = 0
     * - always65536 = 65536
     * - always7F000000 = 0x7F000000
     * - sizeOfStructOnly points to the start of extension atoms.
     *
     * @param StreamWindow $win        Reader positioned after version/revision/vendor.
     * @param int          $entryStart Absolute offset of sample-entry fields (after size+type).
     * @param int          $entryEnd   Absolute offset where this sample entry ends.
     * @param int          $entrySize  Declared sample entry size (including size+type header).
     * @param string       $format     Raw fourcc format code.
     *
     * @return array{format: string, channels: int, bitsPerSample: int, sampleRate: int}
     */
    private function parseSoundSampleEntryVersion2(StreamWindow $win, int $entryStart, int $entryEnd, int $entrySize, string $format): array
    {
        if ($win->tell() + 48 > $entryEnd) {
            throw new ParseError('audio sample entry version 2 truncated', 1459);
        }

        $always3          = $win->readU16BE();
        $always16         = $win->readU16BE();
        $alwaysMinus2     = $win->readU16BE();
        $always0          = $win->readU16BE();
        $always65536      = $win->readU32BE();
        $sizeOfStructOnly = $win->readU32BE();
        $audioSampleRate  = Unpack::float('E', $win->read(8), 'audio sample entry version 2 sample rate');
        $numChannels      = $win->readU32BE();
        $always7F000000   = $win->readU32BE();
        $bitsPerChannel   = $win->readU32BE();
        $win->readU32BE(); // formatSpecificFlags
        $win->readU32BE(); // constBytesPerAudioPacket
        $win->readU32BE(); // constLPCMFramesPerAudioPacket

        if (
            $always3 !== 3
            || $always16 !== 16
            || $alwaysMinus2 !== 0xFFFE
            || $always0 !== 0
            || $always65536 !== 65536
            || $always7F000000 !== 0x7F000000
        ) {
            throw new ParseError('audio sample entry version 2 constants are invalid', 1460);
        }

        // sizeOfStructOnly points from sample entry start to extension atoms.
        if (($sizeOfStructOnly < 72) || ($sizeOfStructOnly > $entrySize)) {
            throw new ParseError('audio sample entry version 2 sizeOfStructOnly is invalid', 1461);
        }

        $entryBodySize = $entryEnd - $entryStart;
        if ($sizeOfStructOnly - 8 > $entryBodySize) {
            throw new ParseError('audio sample entry version 2 sizeOfStructOnly exceeds entry bounds', 1462);
        }

        return [
            'format'        => $this->normaliseFourcc($format),
            'channels'      => $numChannels,
            'bitsPerSample' => $bitsPerChannel > 0 ? $bitsPerChannel : $always16,
            'sampleRate'    => (int) round($audioSampleRate),
        ];
    }

    /**
     * Normalises a four-character code into a printable identifier.
     *
     * @param string $fourcc Raw four-character code bytes.
     */
    private function normaliseFourcc(string $fourcc): string
    {
        if ($this->isPrintableFourcc($fourcc)) {
            return $fourcc;
        }

        return strtoupper(bin2hex($fourcc));
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
        $context->itemReferences = $this->mergeItemReferencesByContext($context->itemReferences, $meta->offset, $payloads['itemReferences']);
        $context->dataReferences = $this->mergeDataReferencesByContext($context->dataReferences, $meta->offset, $payloads['dataReferences']);

        $idatPayload = $payloads['idatPayload'];

        [$exifItemIds, $xmpItemIds] = $this->gatherItemIds($payloads['itemInfos'], $payloads['primaryItemId']);

        // Resolve EXIF item payloads and normalize leading headers.
        // EXIF 3.0 §4.8 notes that item payloads omit the APP1 signature; some
        // encoders still include it, so we normalise accordingly.
        foreach ($this->resolveQueuedItems($exifItemIds, $payloads['locations'], $payloads['itemReferences'], $this->normalizeExifBlob(...), $payloads['dataReferences'], $idatPayload, $context->unresolvedItems, $meta->offset) as $blob) {
            $context->exifBlobs[] = $blob;
        }

        // EXIF 3.0 Annex A item metadata: when both item-based and direct Exif payloads
        // are present, keep item-based payloads first so pitm-driven default selection
        // remains deterministic for MetadataReader::fromIsoBmff().
        foreach ($payloads['directExif'] as $blob) {
            $context->exifBlobs[] = $blob;
        }

        // Resolve referenced XMP payloads in declared priority order.
        foreach ($this->resolveQueuedItems($xmpItemIds, $payloads['locations'], $payloads['itemReferences'], null, $payloads['dataReferences'], $idatPayload, $context->unresolvedItems, $meta->offset) as $blob) {
            $this->appendUniqueXmp($context->xmpBlobs, $context->xmpHashes, $blob);
        }

        foreach ($payloads['directXmp'] as $blob) {
            $this->appendUniqueXmp($context->xmpBlobs, $context->xmpHashes, $blob);
        }

        foreach ($payloads['uuidXmp'] as $blob) {
            $this->appendUniqueXmp($context->xmpBlobs, $context->xmpHashes, $blob);
        }

        [$mergedQtKeys, $mergedQtDataAtoms] = $this->mergeQuickTimeKeys(
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
     *     locations: array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}>,
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
        /** @var array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, fileOffsetOrigin:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations */
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
        foreach ($this->walkChildren($meta, $childOffset) as $child) {
            switch ($child->type) {
                case self::BOX_HDLR:
                    ++$hdlrCount;
                    if ($hdlrCount > 1) {
                        throw new ParseError('meta must contain exactly one hdlr box', 1478);
                    }

                    [$handlerType] = $this->parseHdlr($child);
                    break;
                case self::BOX_EXIF:
                    // GH-859: enforce payload cap before reading direct Exif box
                    if ($child->contentSize > self::MAX_ITEM_PAYLOAD_SIZE) {
                        throw new ParseError('direct Exif box payload exceeds maximum allowed size', 1396);
                    }

                    // The EXIF 3.0 §4.8 Exif box must expose the TIFF header directly; normalise deviations.
                    $blob         = $this->readAll($child->window);
                    $directExif[] = $this->normalizeExifBlob($blob);
                    break;
                case self::BOX_IINF:
                    foreach ($this->parseIinf($child) as $info) {
                        $itemInfos[$info['id']] = $info;
                    }

                    break;
                case self::BOX_ILOC:
                    if ($locations !== []) {
                        throw new ParseError('meta context must contain at most one iloc box', 1413);
                    }

                    $locations = $this->parseIloc($child, $fileOffsetOrigin);
                    break;
                case self::BOX_IDAT:
                    // ISO/IEC 14496-12 §8.11.11.2: aligned(8) class ItemDataBox
                    if ($child->offset % 8 !== 0) {
                        throw new ParseError(sprintf(
                            'idat box at offset %d is not 8-byte aligned per ISO/IEC 14496-12 §8.11.11.2.',
                            $child->offset,
                        ), 1163);
                    }

                    if ($idatPayload !== null) {
                        throw new ParseError('meta context must contain at most one idat box', 1414);
                    }

                    if ($child->contentSize > self::MAX_ITEM_PAYLOAD_SIZE) {
                        throw new ParseError('idat payload exceeds configured limit', 1164);
                    }

                    $idatPayload = $this->readAll($child->window);

                    break;
                case self::BOX_PITM:
                    $primaryItemId = $this->parsePitm($child);
                    break;
                case self::BOX_IREF:
                    $itemReferences = $this->mergeItemReferences($itemReferences, $this->parseIref($child));
                    break;
                case self::BOX_DINF:
                    $dataReferences = $this->mergeDataReferences($dataReferences, $this->parseDinf($child));
                    break;
                case self::BOX_XMP:
                    // GH-859: enforce payload cap before reading direct XMP box
                    if ($child->contentSize > self::MAX_ITEM_PAYLOAD_SIZE) {
                        throw new ParseError('direct XMP box payload exceeds maximum allowed size', 1397);
                    }

                    $directXmp[] = $this->readAll($child->window);
                    break;
                case self::BOX_UUID:
                    if ($child->userType === self::XMP_UUID) {
                        // GH-859: enforce payload cap before reading uuid XMP box
                        if ($child->contentSize > self::MAX_ITEM_PAYLOAD_SIZE) {
                            throw new ParseError('uuid XMP box payload exceeds maximum allowed size', 1397);
                        }

                        $uuidXmp[] = $this->readAll($child->window);
                    }

                    break;
                case self::BOX_MHDR:
                    $this->parseMhdr($child);
                    $requiresHdlr = true;
                    $hasMhdr      = true;
                    break;
                case self::BOX_KEYS:
                    $requiresHdlr = true;
                    $keysMaps[]   = $this->parseKeys($child);
                    break;
                case self::BOX_ILST:
                    $ilstBoxes[] = $child;
                    break;
                case self::BOX_CTRY:
                    $requiresHdlr = true;
                    $countryLists = $this->parseLocaleListAtom($child, 'ctry');
                    break;
                case self::BOX_LANG:
                    $requiresHdlr  = true;
                    $languageLists = $this->parseLocaleListAtom($child, 'lang');
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
        if (($handlerType !== null) && ($handlerType !== self::QUICKTIME_MDTA)) {
            $keysMaps      = [];
            $ilstBoxes     = [];
            $countryLists  = [];
            $languageLists = [];
        }

        // QuickTime File Format 2012, "Metadata Structure": a metadata atom with
        // handler type 'mdta' must contain keys and ilst subatoms.
        if ($handlerType === self::QUICKTIME_MDTA) {
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
            'isMdta'         => $handlerType === self::QUICKTIME_MDTA,
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

        $peekLength = $meta->contentSize < 12 ? $meta->contentSize : 12;
        $peek       = $window->read($peekLength);
        $peekSize   = strlen($peek);

        if ($peekSize >= 12) {
            $size = $this->readU32FromBytes($peek, 4, 'meta child size');
            $type = substr($peek, 8, 4);

            if ($this->isPrintableFourcc($type) && $this->isPlausibleBoxSize($size, $meta->contentSize - 4)) {
                $this->validateMetaFullBoxHeader($peek);

                return 4;
            }
        }

        if ($peekSize >= 8) {
            $size = $this->readU32FromBytes($peek, 0, 'meta child size');
            $type = substr($peek, 4, 4);

            if ($this->isPrintableFourcc($type) && $this->isPlausibleBoxSize($size, $meta->contentSize)) {
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

        // GH-945: reject ambiguous meta header layout instead of defaulting
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
     * Checks if a box size value is plausible for the provided container size.
     */
    private function isPlausibleBoxSize(int $size, int $limit): bool
    {
        if ($size === 0 || $size === 1) {
            return true;
        }

        if ($size < 8) {
            return false;
        }

        return $size <= $limit;
    }

    /**
     * Gathers item IDs from item info structures, separated by primary status.
     *
     * @param array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}> $itemInfos     Item information structures.
     * @param int|null                                                                           $primaryItemId Primary item ID if known.
     *
     * @return array{0: list<int>, 1: list<int>} Tuple of [primary item IDs, other item IDs].
     */
    private function gatherItemIds(array $itemInfos, ?int $primaryItemId): array
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
    private function resolveQueuedItems(array $itemIds, array $locations, array $itemReferences, ?callable $transform, array $dataReferences, ?string $idatPayload, array &$unresolvedItems, int $metaContextOffset): array
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
    private function appendUniqueXmp(array &$xmpBlobs, array &$xmpHashes, string $blob): void
    {
        $hash = sha1($blob);

        if (array_key_exists($hash, $xmpHashes)) {
            return;
        }

        $xmpHashes[$hash] = true;
        $xmpBlobs[]       = $blob;
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
    private function mergeQuickTimeKeys(array $existing, array $keysMaps, array $ilstBoxes, array $existingAtoms = [], bool $hasMhdr = false, array $countryLists = [], array $languageLists = [], bool $isMdta = false): array
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
    private function mergeItemReferencesByContext(array $existing, int $contextOffset, array $incoming): array
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
     * Merges ISO BMFF item reference mappings.
     *
     * @param array<int, list<IsoBmffItemReference>> $existing
     * @param array<int, list<IsoBmffItemReference>> $incoming
     *
     * @return array<int, list<IsoBmffItemReference>>
     */
    private function mergeItemReferences(array $existing, array $incoming): array
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
    private function mergeDataReferencesByContext(array $existing, int $contextOffset, array $incoming): array
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
     * Merges ISO BMFF data reference mappings.
     *
     * @param array<int, IsoBmffDataReference> $existing
     * @param array<int, IsoBmffDataReference> $incoming
     *
     * @return array<int, IsoBmffDataReference>
     */
    private function mergeDataReferences(array $existing, array $incoming): array
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
    private function parseDinf(BoxDescriptor $dinf): array
    {
        $references = [];
        $drefCount  = 0;

        foreach ($this->walkChildren($dinf) as $child) {
            if ($child->type === self::BOX_DREF) {
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
        if ($entry->type !== self::BOX_URL && $entry->type !== self::BOX_URN) {
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

            if ($trimmed !== '' && !mb_check_encoding($trimmed, 'UTF-8')) {
                throw new ParseError('dref entry URL/URN payload contains invalid UTF-8', 1390);
            }

            $uri = $trimmed !== '' ? $trimmed : null;
        }

        return new IsoBmffDataReference(
            $index,
            $this->normaliseFourcc($entry->type),
            $uri,
            $selfContained,
        );
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
    private function normalizeExifBlob(string $blob): string
    {
        // GH-842: strict 4-byte TIFF-header offset validation
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
            // GH-912: data_reference_index gating applies only to file_offset (method 0).
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

                // GH-1000: implied extent_length semantics for single-extent items
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

                if ($length > self::MAX_ITEM_PAYLOAD_SIZE - $total) {
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

                // GH-1000: implied extent_length semantics for single-extent items
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

                if ($length > self::MAX_ITEM_PAYLOAD_SIZE - $total) {
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
            // GH-910: ISO/IEC 14496-12 §8.11.3.2 — only 'iloc' references are
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

                if ($length > self::MAX_ITEM_PAYLOAD_SIZE - $total) {
                    throw new ParseError('iloc item payload exceeds configured limit', 1188);
                }

                // extent_index is optional; treat it as zero-based with a one-based fallback.
                $referencePosition = $extent['index'] ?? 0;
                if (!isset($references[$referencePosition]) && ($referencePosition > 0) && isset($references[$referencePosition - 1])) {
                    --$referencePosition;
                }

                if (!isset($references[$referencePosition])) {
                    $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems, $metaContextOffset);

                    return null;
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

                // GH-1000: implied extent_length semantics for single-extent items
                if ($length === 0) {
                    if ($extentCount !== 1) {
                        continue;
                    }

                    if ($offset > $referenceSize) {
                        throw new ParseError('iloc extent outside referenced item', 1191);
                    }

                    $length = $referenceSize - $offset;

                    if ($length > self::MAX_ITEM_PAYLOAD_SIZE - $total) {
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
        if ($dataReferenceIndex > 0 && isset($dataReferences[$dataReferenceIndex])) {
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
     * Parses the item information box and returns descriptors for each entry.
     *
     * ISO/IEC 14496-12:2015 §8.11.6 defines `entry_count` as the number of
     * item information entries (`infe`) in the box.
     * EXIF 3.0 Annex A.2.2 defines the `iinf` container layout for Exif-in-ISO BMFF
     * metadata collections.
     *
     * @param BoxDescriptor $iinf Box descriptor containing the item information payload.
     *
     * @return list<array{id: int, itemType: ?string, name: ?string, contentType: ?string, contentEncoding: ?string, extensionType: ?string, itemUriType?: string}>
     */
    private function parseIinf(BoxDescriptor $iinf): array
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
            if ($child->type !== self::BOX_INFE) {
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

        // GH-967: reject duplicate item_ID values across infe entries
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
     * Parses a single item information entry (`infe`).
     *
     * EXIF 3.0 Annex A.2.3 describes the item entry fields and the recommended
     * item type/content type combinations for Exif payloads.
     *
     * @param BoxDescriptor $infe Box descriptor for the entry being parsed.
     *
     * @return array{id: int, itemType: ?string, name: ?string, contentType: ?string, contentEncoding: ?string, extensionType: ?string, itemUriType?: string}
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

        if ($flags !== 0) {
            throw new ParseError('unsupported infe box flags', 1200);
        }

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

        // GH-832: ISO 14496-12 §8.11.6: if item_type == 'uri ', the post-name
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
            ];
        }

        // GH-820: ISO 14496-12 §8.11.6: when item_type == 'mime', content_type
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
        ];
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
    private function parseIloc(BoxDescriptor $iloc, int $fileOffsetOrigin = 0): array
    {
        $win = $iloc->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        // ISO/IEC 14496-12 §8.11.3: only versions 0, 1 and 2 are defined
        if ($version > 2) {
            throw new ParseError('unsupported iloc box version', 1202);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported iloc box flags', 1203);
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

            // GH-893: enforce maximum extent_count per item
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
     * @return int
     */
    private function parsePitm(BoxDescriptor $pitm): int
    {
        $win = $pitm->window;
        $win->seek(0);

        // FullBox header (4 bytes) + item_ID (2 for v0, 4 for v1)
        if ($pitm->contentSize < 6) {
            throw new ParseError('pitm box truncated', 1210);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported pitm box version', 1211);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported pitm box flags', 1212);
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
    private function parseIref(BoxDescriptor $iref): array
    {
        $win = $iref->window;
        $win->seek(0);

        if ($iref->contentSize < 4) {
            throw new ParseError('iref box truncated', 1214);
        }

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported iref box version', 1215);
        }

        if ($flags !== 0) {
            throw new ParseError('unsupported iref box flags', 1216);
        }

        $references = [];
        $entryCount = 0;

        foreach ($this->walkChildren($iref, 4) as $child) {
            ++$entryCount;

            // GH-894: enforce maximum number of reference entry boxes
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
     * Parses the QuickTime keys box into an index of structured key entries.
     *
     * QuickTime File Format 2012, "Metadata item keys atom": each entry contains
     * a 4-byte key_namespace and a variable-length key_value whose structure depends
     * on the namespace.
     *
     * @param BoxDescriptor $keys Box descriptor for the QuickTime `keys` box.
     *
     * @return array<int, QuickTimeKeyEntry>
     */
    private function parseKeys(BoxDescriptor $keys): array
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

        if ($entryCount > self::MAX_KEYS_ENTRIES) {
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

            // GH-991: validate key_namespace as proper 4CC code (printable ASCII)
            if (preg_match('/^[\x20-\x7E]{4}$/', $namespace) !== 1) {
                throw new ParseError('keys entry key_namespace is not a valid 4CC code', 1416);
            }

            // GH-977: reject empty key_value entries (size <= 8 means no actual key data)
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
            $keyName  = null;
            $itemName = null;
            $index    = $this->fourccToIndex($entry->type);

            if (($index !== null) && isset($keyIndex[$index])) {
                $keyName = $this->resolveKeyName($keyIndex[$index]);
            } elseif ($entry->type === self::BOX_FREEFORM) {
                $keyName = $this->parseFreeformKey($entry);
            } elseif ($isMdta) {
                // GH-814: in mdta mode, ilst entries must reference keys by index
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
            $isFreeform = ($entry->type === self::BOX_FREEFORM);
            $entryAtoms = [];

            foreach ($this->walkChildren($entry) as $sub) {
                if ($sub->type === self::BOX_DATA) {
                    $structured = $this->parseDataBoxStructured($sub);
                    $this->validateLocaleIndicator($structured['locale'], $countryLists, $languageLists);
                    $entryAtoms[] = $structured;

                    $effectiveKey = $keyName ?? $itemName;
                    if ($effectiveKey === null) {
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
                } elseif ($sub->type === self::BOX_NAME && !$isFreeform) {
                    $itemName = $this->parseIlstNameAtom($sub, $seenNames);

                    // Use name as fallback key when no key index or fourcc is available
                    if ($keyName === null) {
                        $keyName = $itemName;
                    }
                } elseif ($sub->type === self::BOX_ITIF) {
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
     * Parses and validates a metadata header atom (`mhdr`).
     *
     * QuickTime File Format 2012, "Metadata Header Atom": the mhdr atom is
     * a full atom with version 0 and flags 0, containing a uint32 nextItemID.
     *
     * @param BoxDescriptor $mhdr Box descriptor for the mhdr atom.
     */
    private function parseMhdr(BoxDescriptor $mhdr): void
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
    private function parseLocaleListAtom(BoxDescriptor $box, string $label): array
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
        if ($type === self::DATA_TYPE_UTF8) {
            if (!mb_check_encoding($payload, 'UTF-8')) {
                throw new ParseError('data box UTF-8 payload contains invalid byte sequence.', 1253);
            }

            // QuickTime File Format 2012, Table 3-5: UTF-8 "without NULL
            // terminator".  Tolerate a single trailing NUL that real-world
            // encoders append, but nothing more.
            return rtrim($payload, "\0");
        }

        if ($type === self::DATA_TYPE_UTF16) {
            if ($payloadSize % 2 !== 0) {
                throw new ParseError('data box UTF-16BE payload has odd byte count.', 1254);
            }

            $converted = iconv('UTF-16BE', 'UTF-8', $payload);

            if ($converted === false) {
                throw new ParseError('data box UTF-16BE payload contains malformed sequence.', 1255);
            }

            return rtrim($converted, "\0");
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
            // GH-987: reject truncated float32 payloads
            if ($payloadSize < 4) {
                throw new ParseError('data box float32 payload truncated', 1418);
            }

            if ($payloadSize > 4) {
                throw new ParseError('data box float32 payload must be exactly 4 bytes', 1418);
            }

            return Unpack::float('G', substr($payload, 0, 4), 'QuickTime float32 payload');
        }

        if ($type === self::DATA_TYPE_FLOAT64) {
            // GH-987: reject truncated float64 payloads
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
     * Merges two associative arrays while keeping values from the right-hand side.
     *
     * @param QuickTimeKeyMap $left
     * @param QuickTimeKeyMap $right
     *
     * @return QuickTimeKeyMap
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
        $itemType = $info['itemType'] ?? null;
        if (is_string($itemType) && strcasecmp($itemType, self::BOX_EXIF) === 0) {
            return true;
        }

        $name = $info['name'] ?? null;
        if (is_string($name) && strcasecmp($name, self::BOX_EXIF) === 0) {
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
        if ($type === self::BOX_UUID) {
            // GH-1011: uuid box must be at least 24 bytes (8-byte header + 16-byte userType)
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
            throw new ParseError(
                sprintf('box %s exceeds container bounds', $type), 1262);
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
}
