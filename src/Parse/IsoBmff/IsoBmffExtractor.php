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
use MagicSunday\ImageMeta\Model\QuickTimeMeta;

use function array_key_exists;
use function array_unique;
use function array_unshift;
use function array_values;
use function bin2hex;
use function explode;
use function iconv;
use function implode;
use function is_string;
use function preg_match;
use function round;
use function rtrim;
use function sha1;
use function sprintf;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

/**
 * Streaming ISOBMFF reader for HEIC/AVIF/MP4/MOV.
 * Extracts EXIF/XMP payloads and QuickTime metadata.
 *
 * EXIF 3.0 §4.8 outlines embedding Exif items in ISO BMFF containers through
 * the `Exif` box and item metadata; EXIF 2.32 §4.8 describes the legacy rules
 * retained for backwards compatibility, while EXIF 2.1 §2.7.3 set the groundwork
 * by defining the FlashPix APP2 interoperability streams used by early containers.
 *
 * @phpstan-type QuickTimeValue = string|int|float|bool
 * @phpstan-type QuickTimeKeyMap = array<string, QuickTimeValue>
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
     * FourCC for file type box describing the major brand.
     */
    private const string BOX_FTYP = 'ftyp';

    /**
     * FourCC for QuickTime movie box.
     */
    private const string BOX_MOOV = 'moov';

    /**
     * FourCC for UUID box used to store custom payloads.
     */
    private const string BOX_UUID = 'uuid';

    /**
     * FourCC for embedded Exif box (EXIF 3.0 §4.8 / EXIF 2.32 §4.8).
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
     * Maximum cumulative payload size allowed when assembling item extents.
     */
    public const int MAX_ITEM_PAYLOAD_SIZE = 8 * 1024 * 1024;

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
        $xmpHashes     = [];

        foreach ($this->walkTopLevelBoxes() as $box) {
            if ($box->type === self::BOX_FTYP) {
                $qtKeys = $this->mergeAssociative($qtKeys, $this->parseFtyp($box));
            } elseif ($box->type === self::BOX_META) {
                $this->parseMetaBox($box, $exifBlobs, $xmpBlobs, $qtKeys, $xmpHashes);
            } elseif ($box->type === self::BOX_MOOV) {
                $this->parseMoovBox($box, $exifBlobs, $xmpBlobs, $qtKeys, $xmpHashes);
            } elseif ($box->type === self::BOX_UUID && $box->userType === self::XMP_UUID) {
                $queuedUuidXmp[] = $this->readAll($box->window);
            }
        }

        foreach ($queuedUuidXmp as $blob) {
            $this->appendUniqueXmp($xmpBlobs, $xmpHashes, $blob);
        }

        $qt = $qtKeys === [] ? null : new QuickTimeMeta($qtKeys);

        return [$exifBlobs, $xmpBlobs, $qt];
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
     * @param BoxDescriptor       $moov      Box descriptor for the movie box.
     * @param list<string>        $exifBlobs
     * @param list<string>        $xmpBlobs
     * @param array<string, bool> $xmpHashes
     * @param QuickTimeKeyMap     $qtKeys
     */
    private function parseMoovBox(BoxDescriptor $moov, array &$exifBlobs, array &$xmpBlobs, array &$qtKeys, array &$xmpHashes): void
    {
        foreach ($this->walkChildren($moov) as $child) {
            if ($child->type === self::BOX_META) {
                $this->parseMetaBox($child, $exifBlobs, $xmpBlobs, $qtKeys, $xmpHashes);
            } elseif ($child->type === self::BOX_UDTA) {
                $this->parseUdtaBox($child, $exifBlobs, $xmpBlobs, $qtKeys, $xmpHashes);
            } elseif ($child->type === self::BOX_TRAK) {
                $qtKeys = $this->mergeAssociative($qtKeys, $this->parseTrak($child));
            }
        }
    }

    /**
     * Parses the file type box (`ftyp`) and exposes container brands as metadata keys.
     *
     * @param BoxDescriptor $ftyp Box descriptor representing the file type declaration.
     *
     * @return QuickTimeKeyMap
     */
    private function parseFtyp(BoxDescriptor $ftyp): array
    {
        $win = $ftyp->window;
        $win->seek(0);

        if ($ftyp->contentSize < 8) {
            return [];
        }

        $majorBrand = $this->normaliseFourcc($win->read(4));
        $minor      = $win->readU32BE();

        $brands = [];
        while ($win->tell() + 4 <= $ftyp->contentSize) {
            $brands[] = $this->normaliseFourcc($win->read(4));
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
     * @param BoxDescriptor       $udta      Box descriptor for the user data box.
     * @param list<string>        $exifBlobs
     * @param list<string>        $xmpBlobs
     * @param array<string, bool> $xmpHashes
     * @param QuickTimeKeyMap     $qtKeys
     */
    private function parseUdtaBox(BoxDescriptor $udta, array &$exifBlobs, array &$xmpBlobs, array &$qtKeys, array &$xmpHashes): void
    {
        foreach ($this->walkChildren($udta) as $child) {
            if ($child->type === self::BOX_META) {
                $this->parseMetaBox($child, $exifBlobs, $xmpBlobs, $qtKeys, $xmpHashes);
            }
        }
    }

    /**
     * Parses a track box and surfaces relevant video/audio details as QuickTime keys.
     *
     * @param BoxDescriptor $trak Box descriptor for the track container.
     *
     * @return QuickTimeKeyMap
     */
    private function parseTrak(BoxDescriptor $trak): array
    {
        $tkhdWidth   = null;
        $tkhdHeight  = null;
        $handler     = null;
        $handlerName = null;
        $sampleInfo  = [];

        foreach ($this->walkChildren($trak) as $child) {
            if ($child->type === self::BOX_TKHD) {
                [$tkhdWidth, $tkhdHeight] = $this->parseTkhd($child);
            } elseif ($child->type === self::BOX_MDIA) {
                [$handler, $handlerName, $sampleInfo] = $this->parseMdia($child);
            }
        }

        if ($handler === null) {
            return [];
        }

        $result = [];

        if ($handlerName !== null && $handlerName !== '') {
            $result[QuickTimeMeta::HANDLER_DESCRIPTION_KEY] = $handlerName;
        }

        if ($handler === 'vide') {
            $width  = $sampleInfo['width'] ?? $tkhdWidth;
            $height = $sampleInfo['height'] ?? $tkhdHeight;

            if ($width !== null && $width > 0) {
                $result[QuickTimeMeta::VIDEO_WIDTH_KEY] = $width;
            }

            if ($height !== null && $height > 0) {
                $result[QuickTimeMeta::VIDEO_HEIGHT_KEY] = $height;
            }

            if (isset($sampleInfo['format']) && $sampleInfo['format'] !== '') {
                $result[QuickTimeMeta::VIDEO_CODEC_KEY] = $sampleInfo['format'];
            }

            if (isset($sampleInfo['compressorName']) && $sampleInfo['compressorName'] !== '') {
                $result[QuickTimeMeta::COMPRESSOR_NAME_KEY] = $sampleInfo['compressorName'];
            }
        } elseif ($handler === 'soun') {
            if (isset($sampleInfo['format']) && $sampleInfo['format'] !== '') {
                $result[QuickTimeMeta::AUDIO_FORMAT_KEY] = $sampleInfo['format'];
                $result[QuickTimeMeta::AUDIO_CODEC_KEY]  = $sampleInfo['format'];
            }

            if (isset($sampleInfo['channels'])) {
                $result[QuickTimeMeta::AUDIO_CHANNELS_KEY] = $sampleInfo['channels'];
            }

            if (isset($sampleInfo['bitsPerSample'])) {
                $result[QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY] = $sampleInfo['bitsPerSample'];
            }

            if (isset($sampleInfo['sampleRate'])) {
                $result[QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY] = $sampleInfo['sampleRate'];
            }
        }

        return $result;
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
            throw new ParseError('tkhd box truncated');
        }

        $version = $win->readU8();
        $this->readUInt24($win); // flags

        if ($version === 1) {
            if ($tkhd->contentSize < 96) {
                throw new ParseError('tkhd version 1 box truncated');
            }

            $win->read(8 + 8 + 4 + 4 + 8); // creation, modification, track id, reserved, duration
        } else {
            $win->read(4 + 4 + 4 + 4 + 4); // creation, modification, track id, reserved, duration
        }

        $win->read(8); // reserved
        $win->read(2); // layer
        $win->read(2); // alternate group
        $win->read(2); // volume
        $win->read(2); // reserved
        $win->read(36); // matrix

        $widthFixed  = $win->readU32BE();
        $heightFixed = $win->readU32BE();

        $width  = $widthFixed > 0 ? intdiv($widthFixed, 1 << 16) : null;
        $height = $heightFixed > 0 ? intdiv($heightFixed, 1 << 16) : null;

        return [$width, $height];
    }

    /**
     * Parses the media box (`mdia`) and returns handler/sample information.
     *
     * @param BoxDescriptor $mdia Media box descriptor.
     *
     * @return array{0: ?string, 1: ?string, 2: array<string, int|string>}
     */
    private function parseMdia(BoxDescriptor $mdia): array
    {
        $handler     = null;
        $handlerName = null;
        $sampleInfo  = [];

        foreach ($this->walkChildren($mdia) as $child) {
            if ($child->type === self::BOX_HDLR) {
                [$handler, $handlerName] = $this->parseHdlr($child);
            } elseif ($child->type === self::BOX_MINF) {
                $sampleInfo = $this->parseMinf($child, $handler);
            }
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
            throw new ParseError('hdlr box truncated');
        }

        $win->read(4); // version/flags
        $win->read(4); // pre-defined

        $handler = $win->read(4);
        $win->read(12); // reserved

        $nameBytes = '';
        if ($win->tell() < $hdlr->contentSize) {
            $nameBytes = $win->read($hdlr->contentSize - $win->tell());
        }

        $handlerType = $this->normaliseFourcc($handler);
        $name        = trim($nameBytes, "\0");

        return [$handlerType === '' ? null : $handlerType, $name === '' ? null : $name];
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

        foreach ($this->walkChildren($minf) as $child) {
            if ($child->type === self::BOX_STBL) {
                return $this->parseStbl($child, $handlerType);
            }
        }

        return [];
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
        foreach ($this->walkChildren($stbl) as $child) {
            if ($child->type === self::BOX_STSD) {
                return $this->parseStsd($child, $handlerType);
            }
        }

        return [];
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
            throw new ParseError('stsd box truncated');
        }

        $win->read(4); // version/flags
        $entryCount = $win->readU32BE();
        $result     = [];
        $pos        = $win->tell();

        for ($i = 0; $i < $entryCount; ++$i) {
            if ($pos + 8 > $stsd->contentSize) {
                throw new ParseError('stsd entry truncated');
            }

            $win->seek($pos);
            $entrySize = $win->readU32BE();
            $format    = $win->read(4);

            if ($entrySize < 16 || $pos + $entrySize > $stsd->contentSize) {
                throw new ParseError('invalid stsd entry size');
            }

            $entryStart = $win->tell();
            $entryEnd   = $pos + $entrySize;

            $win->read(6); // reserved
            $win->readU16BE(); // data reference index

            if ($handlerType === 'vide') {
                if ($win->tell() + 70 > $entryEnd) {
                    throw new ParseError('video sample entry truncated');
                }

                $win->read(16); // pre-defined/reserved
                $width  = $win->readU16BE();
                $height = $win->readU16BE();
                $win->readU32BE(); // horiz resolution
                $win->readU32BE(); // vert resolution
                $win->read(4); // reserved
                $win->readU16BE(); // frame count

                $nameLength = $win->readU8();
                $nameData   = $win->read(31);
                $compressor = substr($nameData, 0, min($nameLength, 31));
                $compressor = trim($compressor);

                $win->readU16BE(); // depth
                $win->readU16BE(); // pre-defined

                $result = [
                    'format'         => $this->normaliseFourcc($format),
                    'width'          => $width,
                    'height'         => $height,
                    'compressorName' => $compressor,
                ];
            } elseif ($handlerType === 'soun') {
                if ($win->tell() + 20 > $entryEnd) {
                    throw new ParseError('audio sample entry truncated');
                }

                $win->read(8); // reserved
                $channels   = $win->readU16BE();
                $sampleSize = $win->readU16BE();
                $win->readU16BE(); // pre-defined
                $win->readU16BE(); // reserved
                $sampleRate = $win->readU32BE();

                $result = [
                    'format'        => $this->normaliseFourcc($format),
                    'channels'      => $channels,
                    'bitsPerSample' => $sampleSize,
                    'sampleRate'    => (int) round($sampleRate / 65536),
                ];
            }

            $pos += $entrySize;
        }

        if ($pos !== $stsd->contentSize) {
            throw new ParseError('stsd entries do not fill container');
        }

        return $result;
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
     * @param BoxDescriptor       $meta      Box descriptor for the metadata box.
     * @param list<string>        $exifBlobs
     * @param list<string>        $xmpBlobs
     * @param array<string, bool> $xmpHashes
     * @param QuickTimeKeyMap     $qtKeys
     */
    private function parseMetaBox(BoxDescriptor $meta, array &$exifBlobs, array &$xmpBlobs, array &$qtKeys, array &$xmpHashes): void
    {
        if ($meta->contentSize < 4) {
            throw new ParseError('meta box truncated');
        }

        // EXIF 3.0 Annex A.2 and EXIF 2.32 Annex A.2 describe how the `meta` box aggregates
        // direct Exif boxes, UUID-wrapped payloads and item references, so we collect each
        // channel before normalising the referenced data.
        $payloads = $this->collectDirectPayloads($meta);

        foreach ($payloads['directExif'] as $blob) {
            $exifBlobs[] = $blob;
        }

        [$exifItemIds, $xmpItemIds] = $this->gatherItemIds($payloads['itemInfos'], $payloads['primaryItemId']);

        // Resolve EXIF item payloads and normalize leading headers.
        // EXIF 3.0 §4.8 notes that item payloads omit the APP1 signature; some
        // encoders still include it, so we normalise per the EXIF 2.32 guidance.
        foreach ($this->resolveQueuedItems($exifItemIds, $payloads['locations'], $this->normalizeExifBlob(...)) as $blob) {
            $exifBlobs[] = $blob;
        }

        // Resolve referenced XMP payloads in declared priority order.
        foreach ($this->resolveQueuedItems($xmpItemIds, $payloads['locations'], null) as $blob) {
            $this->appendUniqueXmp($xmpBlobs, $xmpHashes, $blob);
        }

        foreach ($payloads['directXmp'] as $blob) {
            $this->appendUniqueXmp($xmpBlobs, $xmpHashes, $blob);
        }

        foreach ($payloads['uuidXmp'] as $blob) {
            $this->appendUniqueXmp($xmpBlobs, $xmpHashes, $blob);
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
     *     ilstBoxes: list<BoxDescriptor>
     * }
     */
    private function collectDirectPayloads(BoxDescriptor $meta): array
    {
        /** @var array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}> $itemInfos */
        $itemInfos = [];
        /** @var array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int}>}> $locations */
        $locations     = [];
        $primaryItemId = null;
        $directXmp     = [];
        $uuidXmp       = [];
        $directExif    = [];
        /** @var list<array<int, string>> $keysMaps */
        $keysMaps = [];
        /** @var list<BoxDescriptor> $ilstBoxes */
        $ilstBoxes = [];

        foreach ($this->walkChildren($meta, 4) as $child) {
            switch ($child->type) {
                case self::BOX_EXIF:
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
     * Gathers item IDs from item info structures, separated by primary status.
     *
     * @param array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}> $itemInfos Item information structures.
     * @param int|null                                                                            $primaryItemId Primary item ID if known.
     *
     * @return array{0: list<int>, 1: list<int>} Tuple of [primary item IDs, other item IDs].
     */
    private function gatherItemIds(array $itemInfos, ?int $primaryItemId): array
    {
        $exifItemIds = [];
        $xmpItemIds  = [];

        // Collect item IDs that advertise EXIF/XMP payloads via their metadata descriptors.
        // EXIF 3.0 Annex A.2.3 and EXIF 2.32 Annex A.2.3 map item types and MIME hints that
        // flag Exif or XMP payloads, so we treat those descriptors as authoritative signals.
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
     * Resolves queued item IDs to their payload data.
     *
     * @param list<int>                                                                                                                     $itemIds   Item IDs to resolve.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int}>}> $locations Item location metadata.
     * @param (callable(string):string)|null                                                                                                $transform Optional transform function.
     *
     * @return list<string> List of resolved item payloads.
     */
    private function resolveQueuedItems(array $itemIds, array $locations, ?callable $transform): array
    {
        /** @var list<string> $resolved */
        $resolved = [];

        // Pull data for each referenced item and optionally transform the payload.
        foreach ($itemIds as $itemId) {
            $data = $this->resolveItemData($itemId, $locations);
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
     * @param QuickTimeKeyMap          $existing  Existing key mappings.
     * @param list<array<int, string>> $keysMaps  Key map data from 'keys' boxes.
     * @param list<BoxDescriptor>      $ilstBoxes Item list box descriptors.
     *
     * @return QuickTimeKeyMap Merged key mappings.
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
     * Strips redundant Exif signatures so downstream parsers accept the blob.
     *
     * EXIF 3.0 §4.8 requires that ISO BMFF Exif items expose the TIFF header
     * without the JPEG APP1 "Exif\0\0" signature; EXIF 2.32 §4.8 highlights
     * this interoperability rule for earlier encoders. Some writers still
     * prefix the signature, therefore the normaliser trims it.
     *
     * @param string $blob Raw EXIF payload that may still include the "Exif\0\0" signature prefix.
     *
     * @return string EXIF payload trimmed to the TIFF header when a redundant signature is detected.
     */
    private function normalizeExifBlob(string $blob): string
    {
        // EXIF 3.0 §4.5.2 and EXIF 2.32 §4.5.2 retain the APP1 "Exif\0\0" signature when the
        // payload is repackaged into ISO BMFF boxes; the TIFF parser expects the stream to start
        // at the byte-order marker, so we strip the redundant header if present.
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
        // EXIF 3.0 Annex A.2.4 and EXIF 2.32 Annex A.2.4 confine Exif item data to
        // self-contained payloads (`construction_method = 0`, `data_reference_index = 0`),
        // so we discard references that require external resolution.
        if ($location['constructionMethod'] !== 0) {
            return null;
        }

        if ($location['dataReferenceIndex'] !== 0) {
            return null;
        }

        $blob     = '';
        $total    = 0;
        $fileSize = $this->stream->size();
        foreach ($location['extents'] as $extent) {
            $length = $extent['length'];
            if ($length === 0) {
                continue;
            }

            if ($length > self::MAX_ITEM_PAYLOAD_SIZE - $total) {
                throw new ParseError('iloc item payload exceeds configured limit');
            }

            if ($length > $fileSize - $total) {
                throw new ParseError('iloc extent length exceeds file size');
            }

            $total += $length;

            $baseOffset   = $location['baseOffset'];
            $extentOffset = $extent['offset'];
            if ($baseOffset < 0 || $extentOffset < 0) {
                throw new ParseError('iloc negative offset');
            }

            if ($baseOffset > PHP_INT_MAX - $extentOffset) {
                throw new ParseError('iloc offset overflow');
            }

            $offset = $baseOffset + $extentOffset;
            if ($offset > $fileSize - $length) {
                throw new ParseError('iloc extent outside file');
            }

            $blob .= $this->readAll($this->stream->window($offset, $length));
        }

        return $blob === '' ? null : $blob;
    }

    /**
     * Parses the item information box and returns descriptors for each entry.
     *
     * EXIF 3.0 Annex A.2.2 and EXIF 2.32 Annex A.2.2 define the `iinf` container
     * layout for Exif-in-ISO BMFF metadata collections and retain the same child
     * sequencing requirements across both revisions.
     *
     * @param BoxDescriptor $iinf Box descriptor containing the item information payload.
     *
     * @return list<array{id: int, itemType: ?string, name: ?string, contentType: ?string}>
     */
    private function parseIinf(BoxDescriptor $iinf): array
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
     * EXIF 3.0 Annex A.2.3 and EXIF 2.32 Annex A.2.3 describe the item entry fields
     * and the recommended item type/content type combinations for Exif payloads,
     * with EXIF 3.0 merely clarifying the existing `Exif` and MIME label guidance.
     *
     * @param BoxDescriptor $infe Box descriptor for the entry being parsed.
     *
     * @return array{id: int, itemType: ?string, name: ?string, contentType: ?string}
     */
    private function parseInfe(BoxDescriptor $infe): array
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

        $id = ($flags & BitMask::BIT_0) !== 0 ? $win->readU32BE() : $win->readU16BE();
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
     * EXIF 3.0 Annex A.2.4 and EXIF 2.32 Annex A.2.4 mandate how `iloc` describes
     * Exif item offsets; EXIF 3.0 adds version 2 support while retaining the 2.32
     * constraints on base offsets and extent sizing.
     *
     * @param BoxDescriptor $iloc Box descriptor representing the `iloc` payload.
     *
     * @return array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int}>}>
     */
    private function parseIloc(BoxDescriptor $iloc): array
    {
        $win = $iloc->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        $offsetLengthSizes = $win->readU8();
        $offsetSize        = $this->validateSizeNibble(($offsetLengthSizes >> 4) & BitMask::LOW_NIBBLE);
        $lengthSize        = $this->validateSizeNibble($offsetLengthSizes & BitMask::LOW_NIBBLE);

        $baseField      = $win->readU8();
        $baseOffsetSize = $this->validateSizeNibble(($baseField >> 4) & BitMask::LOW_NIBBLE);
        $indexSize      = 0;
        if ($version === 1 || $version === 2) {
            $indexSize = $this->validateSizeNibble($win->readU8() & BitMask::LOW_NIBBLE);
        }

        $itemCount = $version < 2 ? $win->readU16BE() : $win->readU32BE();
        $locations = [];

        for ($i = 0; $i < $itemCount; ++$i) {
            if ($version < 2) {
                $itemId = $win->readU16BE();
            } elseif (($flags & BitMask::BIT_0) !== 0) {
                $itemId = $win->readU32BE();
            } else {
                $itemId = $win->readU16BE();
            }

            $constructionMethod = 0;
            if ($version === 1 || $version === 2) {
                $tmp                = $win->readU16BE();
                $constructionMethod = ($tmp >> 12) & BitMask::LOW_NIBBLE;
            }

            $dataReferenceIndex = $win->readU16BE();
            $baseOffset         = $baseOffsetSize > 0 ? $this->readUInt($win, $baseOffsetSize) : 0;
            $extentCount        = $win->readU16BE();
            /** @var list<array{offset:int,length:int}> $extents */
            $extents = [];

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
     * EXIF 3.0 Annex A.2.5 and EXIF 2.32 Annex A.2.5 define how the primary item
     * identifies the default metadata payload; EXIF 3.0 extends the item identifier
     * width to 32 bits for version 1 boxes.
     *
     * @param BoxDescriptor $pitm Box descriptor containing the primary item payload.
     *
     * @return int
     */
    private function parsePitm(BoxDescriptor $pitm): int
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
     * @param BoxDescriptor $keys Box descriptor for the QuickTime `keys` box.
     *
     * @return array<int, string>
     */
    private function parseKeys(BoxDescriptor $keys): array
    {
        $win = $keys->window;
        $win->seek(0);
        // version/flags
        $win->read(4);

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
     * @param BoxDescriptor      $ilst     Box descriptor for the `ilst` container.
     * @param array<int, string> $keyIndex
     *
     * @return QuickTimeKeyMap
     */
    private function parseIlst(BoxDescriptor $ilst, array $keyIndex): array
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
                    $result[$keyName] = $this->parseDataBox($sub);
                }
            }
        }

        return $result;
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
                $mean = $this->parseDataBox($child);
            } elseif ($child->type === self::FREEFORM_NAME) {
                $name = $this->parseDataBox($child);
            }
        }

        if (!is_string($mean) || $mean === '' || !is_string($name) || $name === '') {
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
     * @param BoxDescriptor $data Box descriptor for the `data` box.
     *
     * @return string
     */
    private function parseDataBox(BoxDescriptor $data): string
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

        $trimmed = trim($payload, "\0");

        if ($type === self::DATA_TYPE_UTF8) {
            return $trimmed;
        }

        if ($type === self::DATA_TYPE_UTF16) {
            $normalised = rtrim($payload, "\0");
            $converted  = iconv('UTF-16BE', 'UTF-8//IGNORE', $normalised);

            if ($converted !== false) {
                return trim($converted, "\0");
            }

            return $trimmed;
        }

        if ($type === self::DATA_TYPE_MAC_ROMAN) {
            $converted = iconv('macintosh', 'UTF-8//IGNORE', $trimmed);

            if ($converted !== false) {
                return trim($converted, "\0");
            }

            return $trimmed;
        }

        return $payload;
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
            default => throw new ParseError('unsupported integer size ' . $bytes),
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

        $value = Unpack::int('N', $fourcc, 'four-character code');

        return $value > 0 ? $value : null;
    }

    /**
     * Iterates through child boxes within a container, yielding descriptors.
     *
     * @param BoxDescriptor $parent Parent box descriptor whose content is iterated.
     * @param int           $offset Optional relative byte offset where iteration begins.
     *
     * @return iterable<BoxDescriptor>
     */
    private function walkChildren(BoxDescriptor $parent, int $offset = 0): iterable
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
     * @return BoxDescriptor
     */
    private function readBoxAt(int $offset, int $limit): BoxDescriptor
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
            $size = $this->stream->readU64BE()->toInt('extended box size');
            $headerSize += 8;
        }

        $userType = null;
        if ($type === self::BOX_UUID) {
            $userType = $this->stream->read(16);
            $headerSize += 16;
        }

        if ($size < $headerSize) {
            throw new ParseError('invalid box size for ' . $type);
        }

        if ($offset + $size > $limit) {
            throw new ParseError(
                sprintf('box %s exceeds container bounds', $type));
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
