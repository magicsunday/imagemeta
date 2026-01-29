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
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;

use function array_key_exists;
use function array_unique;
use function array_unshift;
use function array_values;
use function bin2hex;
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
 * the `Exif` box and item metadata.
 *
 * @phpstan-type QuickTimeValue = string|int|float|bool
 * @phpstan-type QuickTimeKeyMap = array<string, QuickTimeValue>
 */
final readonly class IsoBmffParser
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
     * QuickTime `data` box type code for signed 32-bit integer payloads.
     */
    private const int DATA_TYPE_INT32 = 0x15;

    /**
     * QuickTime `data` box type code for 32-bit floating point payloads.
     */
    private const int DATA_TYPE_FLOAT32 = 0x16;

    /**
     * QuickTime `data` box type code for 64-bit floating point payloads.
     */
    private const int DATA_TYPE_FLOAT64 = 0x17;

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
     * Maximum number of entries in an iinf box to prevent DoS attacks.
     */
    private const int MAX_IINF_ENTRIES = 10000;

    /**
     * Maximum number of item references allowed per iref entry.
     */
    private const int MAX_IREF_REFERENCES = 10000;

    /**
     * Maximum number of data references allowed per dref entry.
     */
    private const int MAX_DREF_ENTRIES = 1000;

    /**
     * Maximum number of keys in a keys box to prevent DoS attacks.
     */
    private const int MAX_KEYS_ENTRIES = 1000;

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
        $exifBlobs       = [];
        $xmpBlobs        = [];
        $qtKeys          = [];
        $queuedUuidXmp   = [];
        $xmpHashes       = [];
        $itemReferences  = [];
        $dataReferences  = [];
        $unresolvedItems = [];

        foreach ($this->walkTopLevelBoxes() as $box) {
            if ($box->type === self::BOX_FTYP) {
                $qtKeys = $this->mergeAssociative($qtKeys, $this->parseFtyp($box));
            } elseif ($box->type === self::BOX_META) {
                $this->parseMetaBox($box, $exifBlobs, $xmpBlobs, $qtKeys, $itemReferences, $dataReferences, $unresolvedItems, $xmpHashes);
            } elseif ($box->type === self::BOX_MOOV) {
                $this->parseMoovBox($box, $exifBlobs, $xmpBlobs, $qtKeys, $itemReferences, $dataReferences, $unresolvedItems, $xmpHashes);
            } elseif ($box->type === self::BOX_UUID && $box->userType === self::XMP_UUID) {
                $queuedUuidXmp[] = $this->readAll($box->window);
            }
        }

        foreach ($queuedUuidXmp as $blob) {
            $this->appendUniqueXmp($xmpBlobs, $xmpHashes, $blob);
        }

        $qt               = $qtKeys === [] ? null : new QuickTimeMeta($qtKeys);
        $itemReferenceMap = $itemReferences === [] ? null : new IsoBmffItemReferenceMap($itemReferences);
        $dataReferenceMap = $dataReferences === [] ? null : new IsoBmffDataReferenceMap($dataReferences);

        return [$exifBlobs, $xmpBlobs, $qt, $itemReferenceMap, $dataReferenceMap, $unresolvedItems];
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
     * @param BoxDescriptor                          $moov            Box descriptor for the movie box.
     * @param list<string>                           $exifBlobs
     * @param list<string>                           $xmpBlobs
     * @param array<int, list<IsoBmffItemReference>> $itemReferences
     * @param array<int, IsoBmffDataReference>       $dataReferences
     * @param list<IsoBmffUnresolvedItem>            $unresolvedItems
     * @param array<string, bool>                    $xmpHashes
     * @param QuickTimeKeyMap                        $qtKeys
     */
    private function parseMoovBox(BoxDescriptor $moov, array &$exifBlobs, array &$xmpBlobs, array &$qtKeys, array &$itemReferences, array &$dataReferences, array &$unresolvedItems, array &$xmpHashes): void
    {
        foreach ($this->walkChildren($moov) as $child) {
            if ($child->type === self::BOX_META) {
                $this->parseMetaBox($child, $exifBlobs, $xmpBlobs, $qtKeys, $itemReferences, $dataReferences, $unresolvedItems, $xmpHashes);
            } elseif ($child->type === self::BOX_UDTA) {
                $this->parseUdtaBox($child, $exifBlobs, $xmpBlobs, $qtKeys, $itemReferences, $dataReferences, $unresolvedItems, $xmpHashes);
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
     * @param BoxDescriptor                          $udta            Box descriptor for the user data box.
     * @param list<string>                           $exifBlobs
     * @param list<string>                           $xmpBlobs
     * @param array<int, list<IsoBmffItemReference>> $itemReferences
     * @param array<int, IsoBmffDataReference>       $dataReferences
     * @param list<IsoBmffUnresolvedItem>            $unresolvedItems
     * @param array<string, bool>                    $xmpHashes
     * @param QuickTimeKeyMap                        $qtKeys
     */
    private function parseUdtaBox(BoxDescriptor $udta, array &$exifBlobs, array &$xmpBlobs, array &$qtKeys, array &$itemReferences, array &$dataReferences, array &$unresolvedItems, array &$xmpHashes): void
    {
        foreach ($this->walkChildren($udta) as $child) {
            if ($child->type === self::BOX_META) {
                $this->parseMetaBox($child, $exifBlobs, $xmpBlobs, $qtKeys, $itemReferences, $dataReferences, $unresolvedItems, $xmpHashes);
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

        // ISO/IEC 14496-12 §8.3.2: version 0 uses 32-bit timestamps, version 1 uses 64-bit
        if ($version === 1) {
            if ($tkhd->contentSize < 96) {
                throw new ParseError('tkhd version 1 box truncated');
            }

            $win->read(8 + 8 + 4 + 4 + 8); // creation(64), modification(64), track_id(32), reserved(32), duration(64)
        } else {
            $win->read(4 + 4 + 4 + 4 + 4); // creation(32), modification(32), track_id(32), reserved(32), duration(32)
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

        if ($entryCount > self::MAX_STSD_ENTRIES) {
            throw new ParseError('stsd entry count exceeds maximum allowed');
        }

        $result = [];
        $pos    = $win->tell();

        for ($i = 0; $i < $entryCount; ++$i) {
            if ($pos + 8 > $stsd->contentSize) {
                throw new ParseError('stsd entry truncated');
            }

            $win->seek($pos);
            $entrySize = $win->readU32BE();
            $format    = $win->read(4);

            if (($entrySize < 16) || (($pos + $entrySize) > $stsd->contentSize)) {
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
     * @param BoxDescriptor                          $meta            Box descriptor for the metadata box.
     * @param list<string>                           $exifBlobs
     * @param list<string>                           $xmpBlobs
     * @param array<int, list<IsoBmffItemReference>> $itemReferences
     * @param array<int, IsoBmffDataReference>       $dataReferences
     * @param list<IsoBmffUnresolvedItem>            $unresolvedItems
     * @param array<string, bool>                    $xmpHashes
     * @param QuickTimeKeyMap                        $qtKeys
     */
    private function parseMetaBox(BoxDescriptor $meta, array &$exifBlobs, array &$xmpBlobs, array &$qtKeys, array &$itemReferences, array &$dataReferences, array &$unresolvedItems, array &$xmpHashes): void
    {
        if ($meta->contentSize < 4) {
            throw new ParseError('meta box truncated');
        }

        // EXIF 3.0 Annex A.2 describes how the `meta` box aggregates
        // direct Exif boxes, UUID-wrapped payloads and item references, so we collect each
        // channel before normalising the referenced data.
        $payloads       = $this->collectDirectPayloads($meta);
        $itemReferences = $this->mergeItemReferences($itemReferences, $payloads['itemReferences']);
        $dataReferences = $this->mergeDataReferences($dataReferences, $payloads['dataReferences']);
        $idatPayload    = $payloads['idatPayload'];

        foreach ($payloads['directExif'] as $blob) {
            $exifBlobs[] = $blob;
        }

        [$exifItemIds, $xmpItemIds] = $this->gatherItemIds($payloads['itemInfos'], $payloads['primaryItemId']);

        // Resolve EXIF item payloads and normalize leading headers.
        // EXIF 3.0 §4.8 notes that item payloads omit the APP1 signature; some
        // encoders still include it, so we normalise accordingly.
        foreach ($this->resolveQueuedItems($exifItemIds, $payloads['locations'], $payloads['itemReferences'], $this->normalizeExifBlob(...), $payloads['dataReferences'], $idatPayload, $unresolvedItems) as $blob) {
            $exifBlobs[] = $blob;
        }

        // Resolve referenced XMP payloads in declared priority order.
        foreach ($this->resolveQueuedItems($xmpItemIds, $payloads['locations'], $payloads['itemReferences'], null, $payloads['dataReferences'], $idatPayload, $unresolvedItems) as $blob) {
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
     *     locations: array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int,index:?int}>}>,
     *     itemReferences: array<int, list<IsoBmffItemReference>>,
     *     dataReferences: array<int, IsoBmffDataReference>,
     *     primaryItemId: ?int,
     *     directXmp: list<string>,
     *     uuidXmp: list<string>,
     *     directExif: list<string>,
     *     idatPayload: ?string,
     *     keysMaps: list<array<int, string>>,
     *     ilstBoxes: list<BoxDescriptor>
     * }
     */
    private function collectDirectPayloads(BoxDescriptor $meta): array
    {
        /** @var array<int, array{id: int, itemType: ?string, name: ?string, contentType: ?string}> $itemInfos */
        $itemInfos = [];
        /** @var array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations */
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
        /** @var list<array<int, string>> $keysMaps */
        $keysMaps = [];
        /** @var list<BoxDescriptor> $ilstBoxes */
        $ilstBoxes = [];

        $childOffset = $this->detectMetaChildOffset($meta);
        foreach ($this->walkChildren($meta, $childOffset) as $child) {
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
                case self::BOX_IDAT:
                    if ($idatPayload === null) {
                        if ($child->contentSize > self::MAX_ITEM_PAYLOAD_SIZE) {
                            throw new ParseError('idat payload exceeds configured limit');
                        }

                        $idatPayload = $this->readAll($child->window);
                    }

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
        ];
    }

    /**
     * Determines whether the meta box includes a full box header (version/flags).
     */
    private function detectMetaChildOffset(BoxDescriptor $meta): int
    {
        // ISO/IEC 14496-12 §8.11.1 defines `meta` as a FullBox, but some QuickTime
        // files omit the version/flags header, so probe for the first child box.
        if ($meta->contentSize < 8) {
            return 0;
        }

        $window = $meta->window;
        $window->seek(0);

        $peekLength = $meta->contentSize < 12 ? $meta->contentSize : 12;
        $peek       = $window->read($peekLength);
        $peekSize   = strlen($peek);

        if ($peekSize >= 8) {
            $size = $this->readU32FromBytes($peek, 0, 'meta child size');
            $type = substr($peek, 4, 4);

            if ($this->isPrintableFourcc($type) && $this->isPlausibleBoxSize($size, $meta->contentSize)) {
                return 0;
            }
        }

        if ($peekSize >= 12) {
            $size = $this->readU32FromBytes($peek, 4, 'meta child size');
            $type = substr($peek, 8, 4);

            if ($this->isPrintableFourcc($type) && $this->isPlausibleBoxSize($size, $meta->contentSize - 4)) {
                return 4;
            }
        }

        return 4;
    }

    /**
     * Reads a big-endian 32-bit unsigned integer from a byte sequence.
     */
    private function readU32FromBytes(string $bytes, int $offset, string $context): int
    {
        if ($offset < 0 || ($offset + 4) > strlen($bytes)) {
            throw new ParseError('Insufficient bytes for ' . $context . '.');
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
     * @param list<int>                                                                                                                                $itemIds         Item IDs to resolve.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations       Item location metadata.
     * @param array<int, list<IsoBmffItemReference>>                                                                                                   $itemReferences  Parsed item references for construction_method=2 extents.
     * @param (callable(string):string)|null                                                                                                           $transform       Optional transform function.
     * @param array<int, IsoBmffDataReference>                                                                                                         $dataReferences  Parsed data references for the current meta box.
     * @param string|null                                                                                                                              $idatPayload     Cached idat payload for construction_method=1 extents.
     * @param list<IsoBmffUnresolvedItem>                                                                                                              $unresolvedItems Accumulator for unresolved item payloads.
     *
     * @return list<string> List of resolved item payloads.
     */
    private function resolveQueuedItems(array $itemIds, array $locations, array $itemReferences, ?callable $transform, array $dataReferences, ?string $idatPayload, array &$unresolvedItems): array
    {
        /** @var list<string> $resolved */
        $resolved = [];

        // Pull data for each referenced item and optionally transform the payload.
        foreach ($itemIds as $itemId) {
            $data = $this->resolveItemData($itemId, $locations, $itemReferences, $dataReferences, $idatPayload, $unresolvedItems);
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

        foreach ($this->walkChildren($dinf) as $child) {
            if ($child->type === self::BOX_DREF) {
                $references = $this->mergeDataReferences($references, $this->parseDref($child));
            }
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
            throw new ParseError('dref box truncated');
        }

        $win->read(4); // version/flags
        $entryCount = $win->readU32BE();

        if ($entryCount > self::MAX_DREF_ENTRIES) {
            throw new ParseError('dref entry count exceeds maximum allowed');
        }

        $references = [];
        $index      = 0;

        foreach ($this->walkChildren($dref, 8) as $child) {
            ++$index;
            $references[$index] = $this->parseDataReferenceEntry($child, $index);
            if ($index >= $entryCount) {
                break;
            }
        }

        if ($index !== $entryCount) {
            throw new ParseError('dref entry count mismatch');
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
        $win = $entry->window;
        $win->seek(0);

        if ($entry->contentSize < 4) {
            throw new ParseError('dref entry truncated');
        }

        $win->readU8(); // version
        $flags = $this->readUInt24($win);

        $payloadSize = $entry->contentSize - 4;
        $payload     = $payloadSize > 0 ? $win->read($payloadSize) : '';
        $uri         = null;

        if ($entry->type === self::BOX_URL || $entry->type === self::BOX_URN) {
            $trimmed = rtrim($payload, "\0");
            $uri     = $trimmed !== '' ? $trimmed : null;
        }

        $selfContained = ($flags & BitMask::BIT_0) !== 0;

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
        if (strlen($blob) < 4) {
            return $blob;
        }

        // ISO 14496-12: Exif items start with a 4-byte big-endian offset to the TIFF header
        $unpacked = @unpack('Noffset', substr($blob, 0, 4));
        if (is_array($unpacked) && isset($unpacked['offset']) && is_int($unpacked['offset'])) {
            $offset = $unpacked['offset'];

            // Validate offset and skip to TIFF header
            if (($offset >= 0) && ((4 + $offset) <= strlen($blob))) {
                return substr($blob, 4 + $offset);
            }
        }

        // Fallback: check for Exif\0\0 signature directly (legacy/non-conformant writers)
        if (str_starts_with($blob, "Exif\0\0")) {
            return substr($blob, 6);
        }

        return $blob;
    }

    /**
     * Resolves metadata item references described by an `iloc` box.
     *
     * @param int                                                                                                                                      $itemId          Identifier of the item to resolve.
     * @param array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int,index:?int}>}> $locations
     * @param array<int, list<IsoBmffItemReference>>                                                                                                   $itemReferences
     * @param array<int, IsoBmffDataReference>                                                                                                         $dataReferences
     * @param string|null                                                                                                                              $idatPayload
     * @param list<IsoBmffUnresolvedItem>                                                                                                              $unresolvedItems
     * @param list<int>                                                                                                                                $visitedItemIds
     *
     * @return string|null
     */
    private function resolveItemData(int $itemId, array $locations, array $itemReferences, array $dataReferences, ?string $idatPayload, array &$unresolvedItems, array $visitedItemIds = []): ?string
    {
        if (!isset($locations[$itemId])) {
            return null;
        }

        $location = $locations[$itemId];
        if (in_array($itemId, $visitedItemIds, true)) {
            $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems);

            return null;
        }

        // EXIF 3.0 Annex A.2.4 confines Exif item data to self-contained payloads.
        // We only resolve items with local data references; other sources are tracked
        // as unresolved for the caller to decide how to handle them.
        if ($location['dataReferenceIndex'] !== 0) {
            $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems);

            return null;
        }

        if ($location['constructionMethod'] === ConstructionMethod::FileOffset->value) {
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

                if (($total > $fileSize) || ($length > ($fileSize - $total))) {
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
                if (($length > $fileSize) || ($offset > ($fileSize - $length))) {
                    throw new ParseError('iloc extent outside file');
                }

                $blob .= $this->readAll($this->stream->window($offset, $length));
            }

            return $blob === '' ? null : $blob;
        }

        if ($location['constructionMethod'] === ConstructionMethod::IdatOffset->value) {
            if ($idatPayload === null) {
                $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems);

                return null;
            }

            // ISO/IEC 14496-12 §8.11.3.2 defines construction_method=1 offsets as idat-relative.
            $blob     = '';
            $total    = 0;
            $idatSize = strlen($idatPayload);
            foreach ($location['extents'] as $extent) {
                $length = $extent['length'];
                if ($length === 0) {
                    continue;
                }

                if ($length > self::MAX_ITEM_PAYLOAD_SIZE - $total) {
                    throw new ParseError('iloc item payload exceeds configured limit');
                }

                if (($total > $idatSize) || ($length > ($idatSize - $total))) {
                    throw new ParseError('iloc extent length exceeds idat payload');
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
                if (($length > $idatSize) || ($offset > ($idatSize - $length))) {
                    throw new ParseError('iloc extent outside idat payload');
                }

                $blob .= substr($idatPayload, $offset, $length);
            }

            return $blob === '' ? null : $blob;
        }

        if ($location['constructionMethod'] === ConstructionMethod::ItemOffset->value) {
            $references = $itemReferences[$itemId] ?? [];
            if ($references === []) {
                $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems);

                return null;
            }

            // ISO/IEC 14496-12 §8.11.3.2 ties construction_method=2 extents to item references.
            $blob  = '';
            $total = 0;
            foreach ($location['extents'] as $extent) {
                $length = $extent['length'];
                if ($length === 0) {
                    continue;
                }

                if ($length > self::MAX_ITEM_PAYLOAD_SIZE - $total) {
                    throw new ParseError('iloc item payload exceeds configured limit');
                }

                // extent_index is optional; treat it as zero-based with a one-based fallback.
                $referencePosition = $extent['index'] ?? 0;
                if (!isset($references[$referencePosition]) && ($referencePosition > 0) && isset($references[$referencePosition - 1])) {
                    --$referencePosition;
                }

                if (!isset($references[$referencePosition])) {
                    $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems);

                    return null;
                }

                $referenceItemId = $references[$referencePosition]->toItemId;
                $nextVisited     = $visitedItemIds;
                $nextVisited[]   = $itemId;
                if (in_array($referenceItemId, $nextVisited, true)) {
                    $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems);

                    return null;
                }

                $referenceData = $this->resolveItemData($referenceItemId, $locations, $itemReferences, $dataReferences, $idatPayload, $unresolvedItems, $nextVisited);
                if ($referenceData === null) {
                    $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems);

                    return null;
                }

                $referenceSize = strlen($referenceData);
                $baseOffset    = $location['baseOffset'];
                $extentOffset  = $extent['offset'];
                if ($baseOffset < 0 || $extentOffset < 0) {
                    throw new ParseError('iloc negative offset');
                }

                if ($baseOffset > PHP_INT_MAX - $extentOffset) {
                    throw new ParseError('iloc offset overflow');
                }

                $offset = $baseOffset + $extentOffset;
                if (($length > $referenceSize) || ($offset > ($referenceSize - $length))) {
                    throw new ParseError('iloc extent outside referenced item');
                }

                $blob .= substr($referenceData, $offset, $length);
                $total += $length;
            }

            return $blob === '' ? null : $blob;
        }

        $this->registerUnresolvedItem($itemId, $location, $dataReferences, $unresolvedItems);

        return null;
    }

    /**
     * Records an unresolved item payload for external references.
     *
     * @param int                                                                                                                          $itemId
     * @param array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int,index:?int}>} $location
     * @param array<int, IsoBmffDataReference>                                                                                             $dataReferences
     * @param list<IsoBmffUnresolvedItem>                                                                                                  $unresolvedItems
     */
    private function registerUnresolvedItem(int $itemId, array $location, array $dataReferences, array &$unresolvedItems): void
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
        );
    }

    /**
     * Parses the item information box and returns descriptors for each entry.
     *
     * EXIF 3.0 Annex A.2.2 defines the `iinf` container layout for Exif-in-ISO BMFF
     * metadata collections.
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

        if ($entryCount > self::MAX_IINF_ENTRIES) {
            throw new ParseError('iinf entry count exceeds maximum allowed');
        }

        $start = $win->tell();
        $items = [];
        $index = 0;
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
     * EXIF 3.0 Annex A.2.3 describes the item entry fields and the recommended
     * item type/content type combinations for Exif payloads.
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
        $this->readUInt24($win);

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

        // ISO 14496-12: Version 2 uses 16-bit item_ID, version 3+ uses 32-bit.
        // Note: flags bit 0 indicates hidden_item, not item_ID size.
        $id = $version >= 3 ? $win->readU32BE() : $win->readU16BE();
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
     * EXIF 3.0 Annex A.2.4 mandates how `iloc` describes Exif item offsets; EXIF 3.0
     * adds version 2 support and constrains base offsets and extent sizing.
     *
     * @param BoxDescriptor $iloc Box descriptor representing the `iloc` payload.
     *
     * @return array<int, array{dataReferenceIndex:int, constructionMethod:int, baseOffset:int, extents:list<array{offset:int,length:int,index:?int}>}>
     */
    private function parseIloc(BoxDescriptor $iloc): array
    {
        $win = $iloc->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = $this->readUInt24($win);

        // ISO/IEC 14496-12 §8.11.3: offset_size and length_size are packed in 4-bit nibbles
        $offsetLengthSizes = $win->readU8();
        $offsetSize        = $this->validateSizeNibble(($offsetLengthSizes >> 4) & BitMask::LOW_NIBBLE); // High nibble
        $lengthSize        = $this->validateSizeNibble($offsetLengthSizes & BitMask::LOW_NIBBLE);         // Low nibble

        // ISO/IEC 14496-12 §8.11.3: base_offset_size in high nibble, index_size_size in low nibble (v1/v2)
        $baseField      = $win->readU8();
        $baseOffsetSize = $this->validateSizeNibble(($baseField >> 4) & BitMask::LOW_NIBBLE);
        $indexSize      = 0;
        if ($version === 1 || $version === 2) {
            $indexSize = $this->validateSizeNibble($baseField & BitMask::LOW_NIBBLE);
        }

        $itemCount = $version < 2 ? $win->readU16BE() : $win->readU32BE();

        if ($itemCount > self::MAX_ILOC_ITEMS) {
            throw new ParseError('iloc item count exceeds maximum allowed');
        }

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
                // ISO/IEC 14496-12 §8.11.3: construction_method is in the high 4 bits of a 16-bit field
                $tmp                = $win->readU16BE();
                $constructionMethod = ($tmp >> 12) & BitMask::LOW_NIBBLE;
            }

            $dataReferenceIndex = $win->readU16BE();
            $baseOffset         = $baseOffsetSize > 0 ? $this->readUInt($win, $baseOffsetSize) : 0;
            $extentCount        = $win->readU16BE();
            /** @var list<array{offset:int,length:int,index:?int}> $extents */
            $extents = [];

            for ($j = 0; $j < $extentCount; ++$j) {
                $extentIndex = null;
                if ($indexSize > 0) {
                    $extentIndex = $this->readUInt($win, $indexSize);
                }

                $extentOffset = $offsetSize > 0 ? $this->readUInt($win, $offsetSize) : 0;
                $extentLength = $lengthSize > 0 ? $this->readUInt($win, $lengthSize) : 0;
                $extents[]    = ['offset' => $extentOffset, 'length' => $extentLength, 'index' => $extentIndex];
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

        $version = $win->readU8();
        $this->readUInt24($win);

        return $version === 0 ? $win->readU16BE() : $win->readU32BE();
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
            throw new ParseError('iref box truncated');
        }

        $version = $win->readU8();
        $this->readUInt24($win); // flags

        if ($version !== 0 && $version !== 1) {
            throw new ParseError('unsupported iref box version');
        }

        $references = [];

        foreach ($this->walkChildren($iref, 4) as $child) {
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
            throw new ParseError('iref entry truncated');
        }

        $fromItemId     = $idSize === 2 ? $win->readU16BE() : $win->readU32BE();
        $referenceCount = $win->readU16BE();

        if ($referenceCount > self::MAX_IREF_REFERENCES) {
            throw new ParseError('iref reference count exceeds maximum allowed');
        }

        $remaining = $entry->contentSize - $win->tell();
        $expected  = $referenceCount * $idSize;
        if ($remaining < $expected) {
            throw new ParseError('iref entry truncated');
        }

        $relation   = $this->normaliseFourcc($entry->type);
        $references = [];
        for ($i = 0; $i < $referenceCount; ++$i) {
            $toItemId     = $idSize === 2 ? $win->readU16BE() : $win->readU32BE();
            $references[] = new IsoBmffItemReference($relation, $toItemId);
        }

        if ($win->tell() !== $entry->contentSize) {
            throw new ParseError('iref entry size mismatch');
        }

        return [
            'fromItemId' => $fromItemId,
            'references' => $references,
        ];
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

        if ($entryCount > self::MAX_KEYS_ENTRIES) {
            throw new ParseError('keys entry count exceeds maximum allowed');
        }

        $map = [];
        $pos = $win->tell();

        for ($i = 1; $i <= $entryCount; ++$i) {
            if ($pos + 8 > $keys->contentSize) {
                throw new ParseError('keys entry truncated');
            }

            $win->seek($pos);
            $size      = $win->readU32BE();
            $namespace = $win->read(4);
            if (($size < 8) || (($pos + $size) > $keys->contentSize)) {
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
                    $value            = $this->parseDataBox($sub);
                    $result[$keyName] = $this->coerceQuickTimeValue($keyName, $value);
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
     * @return string|int|float
     */
    private function parseDataBox(BoxDescriptor $data): string|int|float
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

        if ($type === self::DATA_TYPE_INT32) {
            if ($payloadSize < 4) {
                return $payload;
            }

            $unsigned = Unpack::int('N', substr($payload, 0, 4), 'QuickTime int32 payload');

            return $unsigned >= 0x80000000 ? $unsigned - 0x100000000 : $unsigned;
        }

        if ($type === self::DATA_TYPE_FLOAT32) {
            if ($payloadSize < 4) {
                return $payload;
            }

            return Unpack::float('G', substr($payload, 0, 4), 'QuickTime float32 payload');
        }

        if ($type === self::DATA_TYPE_FLOAT64) {
            if ($payloadSize < 8) {
                return $payload;
            }

            return Unpack::float('E', substr($payload, 0, 8), 'QuickTime float64 payload');
        }

        return $payload;
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
