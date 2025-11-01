<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;

use function array_any;
use function array_slice;
use function array_values;
use function chr;
use function count;
use function function_exists;
use function iconv;
use function in_array;
use function intdiv;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function ltrim;
use function mb_convert_encoding;
use function ord;
use function pack;
use function rtrim;
use function sha1;
use function sprintf;
use function strlen;
use function strspn;
use function substr;

/**
 * Parses classic TIFF and BigTIFF structures embedded in EXIF payloads.
 *
 * EXIF 3.0 §4.5 outlines the TIFF header layout, data type handling and IFD
 * traversal rules honoured by this reader; EXIF 2.32 §4.5 documents the legacy
 * behaviour retained for older images, EXIF 2.1 §2.5.1 and §2.6.2 describe the
 * original TIFF header and directory traversal rules, while TIFF 6.0 §8 provides
 * the baseline directory semantics shared by both formats.
 */
final class TiffExifReader
{
    /**
     * XP metadata tags stored as UTF-16LE byte sequences.
     *
     * @var list<int>
     */
    private const array UTF16LE_STRING_TAGS = [
        ExifTag::XP_TITLE,
        ExifTag::XP_COMMENT,
        ExifTag::XP_AUTHOR,
        ExifTag::XP_KEYWORDS,
        ExifTag::XP_SUBJECT,
    ];

    /**
     * Tag identifiers that store counted image data such as strips or tiles.
     *
     * EXIF 3.0 §4.6.4 (Table 3) and EXIF 2.32 §4.6.4 describe these TIFF attributes for
     * thumbnail and primary image payloads, including the JPEG interchange fields;
     * EXIF 2.1 §2.6.4 catalogues the same strip and tile bookkeeping tags.
     *
     * @var list<int>
     */
    private const array COUNTED_IMAGE_DATA_TAGS = [
        ExifTag::STRIP_OFFSETS,
        ExifTag::STRIP_BYTE_COUNTS,
        ExifTag::TILE_OFFSETS,
        ExifTag::TILE_BYTE_COUNTS,
    ];

    /**
     * Tags whose values encode offsets within the TIFF blob.
     *
     * EXIF 3.0 §4.6.3 and EXIF 2.32 §4.6.3 list the Exif, GPS and Interoperability IFD
     * pointer fields that chain the directory hierarchy, with EXIF 2.1 §2.6.3 establishing
     * the original Exif and GPS pointer layout. The SubIFDs and preview offsets are tracked
     * here as well because EXIF 3.0 §4.6.12 keeps them in the same structure.
     *
     * @var list<int>
     */
    private const array POINTER_TAGS = [
        ExifTag::EXIF_IFD_POINTER,
        ExifTag::GPS_IFD_POINTER,
        ExifTag::INTEROPERABILITY_IFD_POINTER,
        ExifTag::SUB_IFDS,
        ExifTag::JPEG_INTERCHANGE_FORMAT,
        ExifTag::PREVIEW_IMAGE_START,
    ];

    private const int UTF16_HIGH_SURROGATE_START = 0xD800;

    private const int UTF16_HIGH_SURROGATE_END = 0xDBFF;

    private const int UTF16_LOW_SURROGATE_START = 0xDC00;

    private const int UTF16_LOW_SURROGATE_END = 0xDFFF;

    private const int UTF16_SUPPLEMENTARY_OFFSET = 0x10000;

    private const int UTF8_SINGLE_BYTE_MAX = 0x7F;

    private const int UTF8_TWO_BYTE_MAX = 0x7FF;

    private const int UTF8_THREE_BYTE_MAX = 0xFFFF;

    private const int UTF8_MAX_CODE_POINT = 0x10FFFF;

    private const int UTF8_TWO_BYTE_PREFIX = 0xC0;

    private const int UTF8_THREE_BYTE_PREFIX = 0xE0;

    private const int UTF8_FOUR_BYTE_PREFIX = 0xF0;

    private const int ASCII_PRINTABLE_MIN = 0x20;

    private const int ASCII_PRINTABLE_MAX = 0x7E;

    private const int UTF8_CONTINUATION_PREFIX = 0x80;

    private const string UNICODE_REPLACEMENT = "\u{FFFD}";

    private MemoryBuffer $buf;

    private Endian $bo;

    private bool $bigTiff = false;

    private int $bigTiffOffsetSize = 8;

    private UInt64 $blobSize;

    private ?string $makerNoteRaw = null;

    /**
     * @var array<int, Ifd>
     */
    private array $subIfds = [];

    /**
     * @var array<int, Ifd>
     */
    private array $ifdCache = [];

    /**
     * Tracks pointer offsets that have already been inspected while resolving interoperability IFDs.
     *
     * @var array<int, bool>
     */
    private array $interopVisitedOffsets = [];

    /**
     * Parses an EXIF TIFF blob into a structured document model.
     *
     * EXIF 3.0 §4.5 describes the TIFF header, byte-order markers and IFD
     * chaining strategy applied while decoding embedded EXIF payloads; EXIF 2.32 §4.5
     * and EXIF 2.1 §2.5.1/§2.6.2 outline the same traversal rules for legacy files.
     *
     * @param string        $tiffBlob           Raw TIFF data including headers.
     * @param Registry|null $makerNotesRegistry Optional registry used to decode manufacturer-specific maker notes.
     *
     * @return ParsedExif
     */
    public function parseFromBlob(string $tiffBlob, ?Registry $makerNotesRegistry = null): ParsedExif
    {
        $this->buf = new MemoryBuffer($tiffBlob);
        $this->buf->seek(0);

        $this->blobSize = UInt64::fromInt($this->buf->size());

        $this->makerNoteRaw          = null;
        $this->subIfds               = [];
        $this->ifdCache              = [];
        $this->bigTiffOffsetSize     = 8;
        $this->interopVisitedOffsets = [];

        // byte order
        // EXIF 3.0 §4.5.1 follows TIFF 6.0 §8 (Image File Directory) in defining the
        // "II"/"MM" byte-order signatures used for byte-order detection.
        $boSig    = $this->buf->read(2);
        $this->bo = match ($boSig) {
            'II'    => Endian::Little,
            'MM'    => Endian::Big,
            default => throw new ParseError('Bad TIFF byte order'),
        };

        $magic = $this->readU16();
        // EXIF 3.0 §4.5.1 recognises 0x002A (classic TIFF) and 0x002B (BigTIFF)
        // magic identifiers, retaining the classic 32-bit header from EXIF 2.32 §4.5.1.
        if ($magic === TiffConst::MAGIC_BIG) {
            $this->bigTiff = true;
            $this->parseBigTiffHeader();
            $firstIfd = $this->readBigTiffOffsetValue();
            $ifd0     = $this->readIfd($firstIfd);
        } elseif ($magic === TiffConst::MAGIC_CLASSIC) {
            $this->bigTiff = false;
            // Classic TIFF header layout per EXIF 3.0 §4.5.1 and TIFF 6.0 §8
            // stores the first IFD offset as a 32-bit pointer immediately
            // after the byte-order and magic fields.
            $firstIfd = $this->readU32();
            $ifd0     = $this->readIfd($firstIfd);
        } else {
            throw new ParseError(
                sprintf(
                    'Unknown TIFF magic (expected 0x%04X or 0x%04X)',
                    TiffConst::MAGIC_CLASSIC,
                    TiffConst::MAGIC_BIG,
                ),
            );
        }

        // follow pointers
        $exifIfd    = null;
        $gpsIfd     = null;
        $interopIfd = null;
        $ifd1       = null;

        $exifPointer = $ifd0->get(ExifTag::EXIF_IFD_POINTER);
        if ($exifPointer instanceof IfdEntry) {
            $exifIfd = $this->readIfd($this->pointerOffset($exifPointer));
        }

        $interopIfd = $this->locateInteropIfd($exifIfd, $ifd0);

        $gpsPointer = $ifd0->get(ExifTag::GPS_IFD_POINTER);
        if ($gpsPointer instanceof IfdEntry) {
            $gpsIfd = $this->readIfd($this->pointerOffset($gpsPointer));
        }

        $additionalIfds = [];
        $visitedOffsets = [];

        $nextOffset = $ifd0->nextIfdOffset;
        while ($nextOffset !== null && $nextOffset > 0) {
            if (isset($visitedOffsets[$nextOffset])) {
                break;
            }

            $visitedOffsets[$nextOffset] = true;

            $nextIfd          = $this->readIfd($nextOffset);
            $additionalIfds[] = $nextIfd;

            if (!$ifd1 instanceof Ifd) {
                $ifd1 = $nextIfd;
            }

            $nextOffset = $nextIfd->nextIfdOffset;
        }

        if (!$interopIfd instanceof Ifd && $additionalIfds !== []) {
            $interopIfd = $this->locateInteropIfd(...$additionalIfds);
        }

        if (!$interopIfd instanceof Ifd && $this->subIfds !== []) {
            $interopIfd = $this->locateInteropIfd(...array_values($this->subIfds));
        }

        $makerNotes = $this->resolveMakerNotes($makerNotesRegistry, $ifd0, $exifIfd);

        return new ParsedExif(
            $ifd0,
            $exifIfd,
            $gpsIfd,
            $interopIfd,
            $ifd1,
            $makerNotes,
            $additionalIfds,
            $this->subIfds,
        );
    }

    /**
     * Validates the BigTIFF header following the magic identifier.
     *
     * EXIF 3.0 §4.5.1 adopts the BigTIFF header layout and retains the reserved
     * word semantics from EXIF 2.32 §4.5.1/TIFF 6.0 §8, constraining the
     * offset-size and reserved fields before the first IFD pointer.
     */
    private function parseBigTiffHeader(): void
    {
        // BigTIFF header after magic: 2 bytes offset size (8 or 16), 2 bytes reserved, then the first IFD offset
        $offSize  = $this->readU16();
        $reserved = $this->readU16();

        // EXIF 3.0 §4.5.1 restricts BigTIFF offset sizes to 8 or 16 bytes.
        if ($offSize !== 8 && $offSize !== 16) {
            throw new ParseError('Unsupported BigTIFF offset size (expected 8 or 16)');
        }

        // The reserved field must remain zero (EXIF 3.0 §4.5.1; TIFF 6.0 §8 legacy rule).
        if ($reserved !== 0) {
            throw new ParseError('Bad BigTIFF header (reserved != 0)');
        }

        $this->bigTiffOffsetSize = $offSize;
    }

    /**
     * Parses an image file directory starting at the given byte offset.
     *
     * EXIF 3.0 §4.5.2 details the layout of classic and BigTIFF IFD structures,
     * including entry counts, entry sizes and next-pointer chaining; EXIF 2.32 §4.5.2
     * and EXIF 2.1 §2.6.2 describe the same classic TIFF directory form.
     *
     * @param int|UInt64|string $offset Zero-based byte offset to the IFD structure.
     *
     * @return Ifd
     */
    private function readIfd(int|UInt64|string $offset): Ifd
    {
        if ($offset instanceof UInt64) {
            // A zero pointer denotes an absent directory (EXIF 3.0 §4.5.2 Note 1
            // and EXIF 2.32 §4.5.2), so return an empty IFD structure.
            if ($offset->isZero()) {
                return new Ifd([]);
            }

            $offsetInt = $this->ensureOffset($offset, 'IFD offset');
        } elseif (is_int($offset)) {
            // Section 4.5.2 of EXIF 3.0 clarifies that null or non-positive offsets
            // mean the referenced directory is omitted.
            if ($offset <= 0) {
                return new Ifd([]);
            }

            $offsetInt = $this->ensureOffset($offset, 'IFD offset');
        } else {
            // BigTIFF offsets may arrive as decimal strings (§4.5.2, BigTIFF note),
            // with zero indicating that the referenced directory is absent.
            if ($this->decimalStringIsZero($offset)) {
                return new Ifd([]);
            }

            $offsetInt = $this->ensureOffset($offset, 'IFD offset');
        }

        if (isset($this->ifdCache[$offsetInt])) {
            return $this->ifdCache[$offsetInt];
        }

        $this->buf->seek($offsetInt);
        $entryCount = $this->bigTiff ? $this->readU64()->toInt('IFD entry count') : $this->readU16();
        // EXIF 3.0 §4.5.2 and TIFF 6.0 §8 prescribe 12-byte (classic) and 20-byte
        // (BigTIFF) directory entries and the unsigned entry count preceding them.
        $entries = [];
        for ($i = 0; $i < $entryCount; ++$i) {
            $entries += $this->readDirEntry();
        }

        if ($this->bigTiff) {
            $next = $this->normaliseBigTiffOptionalOffset(
                $this->readBigTiffOffsetValue(),
                'IFD next offset',
            );
        } else {
            // TIFF 6.0 §8 retains a 32-bit pointer to the next IFD; EXIF 3.0 §4.5.2
            // notes the value is zero when the chain terminates.
            $next = $this->readU32();
        }

        $ifd = new Ifd($entries, $next > 0 ? $next : null);

        $this->ifdCache[$offsetInt] = $ifd;

        return $ifd;
    }

    /**
     * Reads a single directory entry and returns it keyed by tag identifier.
     *
     * EXIF 3.0 §4.5.2 (and EXIF 2.32 §4.5.2 for legacy files) define the tag,
     * type, count and value/offset fields mirrored by this reader, aligning with
     * the TIFF 6.0 §8 directory entry layout and the EXIF 2.1 §2.6.2 field schema.
     *
     * @return array<int, IfdEntry> tagId => entry
     */
    private function readDirEntry(): array
    {
        $tag  = $this->readU16();
        $type = $this->readU16();
        $cnt  = $this->bigTiff ? $this->readU64()->toInt('directory entry value count') : $this->readU32();
        if ($this->bigTiff) {
            // BigTIFF stores the inline value/offset field as an 8- or 16-byte
            // quantity (EXIF 3.0 §4.5.2 BigTIFF note) with inline payloads padded
            // to the negotiated offset width.
            [$valOrOff, $inlineBytes] = $this->readValueOrOffset($type, $cnt);
        } else {
            $valOrOff    = $this->readU32();
            $inlineBytes = null;
        }

        [$rawBytes] = $this->valueBytes($type, $cnt, $valOrOff, $inlineBytes);
        $value      = $this->decodeBytes($type, $cnt, $rawBytes);
        $value      = $this->convertUInt64Values($tag, $type, $cnt, $rawBytes, $value);

        if (in_array($tag, self::UTF16LE_STRING_TAGS, true)) {
            $value = $this->decodeUtf16LeString($rawBytes);
        }

        if ($tag === ExifTag::MAKER_NOTE) {
            $this->makerNoteRaw = $rawBytes;
        }

        if (in_array($tag, self::COUNTED_IMAGE_DATA_TAGS, true)) {
            $value = $this->normaliseCountedImageDataField($tag, $type, $cnt, $rawBytes, $value);
        }

        $entry = new IfdEntry($tag, $type, $cnt, $value);

        if ($tag === ExifTag::SUB_IFDS) {
            $this->collectSubIfds($entry);
        }

        return [$tag => $entry];
    }

    /**
     * Normalises numeric list fields that describe strip or tile data.
     *
     * EXIF 3.0 §4.6.2 and §4.6.4 enumerate the strip/tile offset and byte-count
     * tags whose component counts are normalised here (see EXIF 2.32 §4.6.2/§4.6.4
     * for the legacy wording, and EXIF 2.1 §2.6.4 for the original tag listings).
     *
     * @param int                                                                   $tag
     * @param int                                                                   $type     TIFF field type code.
     * @param int                                                                   $count    Number of values represented.
     * @param string                                                                $rawBytes Raw value bytes read for the entry.
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value
     *
     * @return int|ExifNumericList
     */
    private function normaliseCountedImageDataField(
        int $tag,
        int $type,
        int $count,
        string $rawBytes,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value,
    ): int|ExifNumericList {
        if ($count <= 0) {
            return new ExifNumericList([]);
        }

        if ($count === 1) {
            if ($value instanceof ExifNumericList) {
                $first = $value->values[0] ?? null;

                if (is_int($first)) {
                    return $first;
                }

                if (is_float($first)) {
                    return (int) $first;
                }
            }

            if (is_int($value)) {
                return $value;
            }

            if (is_float($value)) {
                return (int) $value;
            }

            $components = $this->decodeCountedComponents($tag, $type, $rawBytes, $count);

            return $components[0] ?? 0;
        }

        if ($value instanceof ExifNumericList) {
            $normalised = [];

            foreach ($value->values as $component) {
                if (is_int($component)) {
                    $normalised[] = $component;
                    continue;
                }

                if (is_float($component)) {
                    $normalised[] = (int) $component;
                }
            }

            return new ExifNumericList($normalised);
        }

        $components = $this->decodeCountedComponents($tag, $type, $rawBytes, $count);

        return new ExifNumericList($components);
    }

    /**
     * Decodes numeric components for counted strip/tile entries into integers.
     *
     * EXIF 3.0 §4.6.2 and EXIF 2.32 §4.6.2 document the strip/tile tags whose
     * value counts and component types are interpreted here, matching the EXIF 2.1
     * §2.6.4 guidance for the original dataset.
     *
     * @param int    $tag      TIFF tag identifier used to determine bounds checks.
     * @param int    $type     TIFF field type code.
     * @param string $rawBytes Raw bytes representing the values.
     * @param int    $count    Number of values represented.
     *
     * @return list<int>
     */
    private function decodeCountedComponents(int $tag, int $type, string $rawBytes, int $count): array
    {
        $componentSize   = $this->bytesPerComponent($type);
        $expectedLength  = $componentSize * $count;
        $availableLength = strlen($rawBytes);

        if ($availableLength < $expectedLength) {
            throw new ParseError('Truncated numeric components for TIFF entry.');
        }

        $components = [];

        for ($i = 0; $i < $count; ++$i) {
            $chunk = substr($rawBytes, $i * $componentSize, $componentSize);

            $value = match ($type) {
                TiffConst::TYPE_SHORT  => $this->unpackU16($chunk),
                TiffConst::TYPE_SSHORT => $this->unpackS16($chunk),
                TiffConst::TYPE_LONG,
                TiffConst::TYPE_IFD   => $this->unpackU32($chunk),
                TiffConst::TYPE_SLONG => $this->unpackS32($chunk),
                TiffConst::TYPE_LONG8,
                TiffConst::TYPE_IFD8   => $this->unpackU64($chunk),
                TiffConst::TYPE_SLONG8 => $this->unpackS64($chunk),
                default                => throw new ParseError('Unsupported numeric type for strip/tile field: ' . $type),
            };

            if ($value instanceof UInt64) {
                $value = ($tag === ExifTag::STRIP_OFFSETS || $tag === ExifTag::TILE_OFFSETS)
                    ? $this->ensureOffset($value, sprintf('IFD tag 0x%04X', $tag))
                    : $value->toInt(sprintf('IFD tag 0x%04X', $tag));
            }

            $components[] = $value;
        }

        return $components;
    }

    /**
     * Converts decoded UInt64 values into integers when possible, preserving oversize pointer offsets.
     *
     * @param string $rawBytes Raw bytes backing the decoded value.
     */
    private function convertUInt64Values(
        int $tag,
        int $type,
        int $count,
        string $rawBytes,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 {
        if ($value instanceof UInt64) {
            return $this->normaliseScalarUInt64($tag, $value);
        }

        if ($value instanceof ExifNumericList) {
            $converted       = [];
            $needsConversion = false;
            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $converted[]     = $this->normaliseScalarUInt64($tag, $component);
                    $needsConversion = true;

                    continue;
                }

                $converted[] = $component;
            }

            if ($needsConversion) {
                return new ExifNumericList($converted);
            }
        }

        return $value;
    }

    /**
     * Normalises a UInt64 scalar into an integer when possible, preserving oversized pointer values.
     *
     * @param int    $tag
     * @param UInt64 $value
     *
     * @return int|UInt64
     */
    private function normaliseScalarUInt64(int $tag, UInt64 $value): int|UInt64
    {
        if ($this->isPointerTag($tag)) {
            if ($value->fitsSignedInt()) {
                return $value->toInt(sprintf('IFD pointer tag 0x%04X', $tag));
            }

            return $value;
        }

        return $value->toInt(sprintf('IFD tag 0x%04X value', $tag));
    }

    private function isPointerTag(int $tag): bool
    {
        return in_array($tag, self::POINTER_TAGS, true);
    }

    /**
     * Collects nested image file directories referenced by a SubIFDs entry.
     *
     * EXIF 3.0 §4.5.5 reserves the SubIFDs pointer for reduced-resolution and
     * auxiliary images that must be parsed as additional IFD chains, matching the
     * TIFF 6.0 §8 definition of tag 0x014A.
     */
    private function collectSubIfds(IfdEntry $entry): void
    {
        if (
            !in_array($entry->type, [TiffConst::TYPE_IFD, TiffConst::TYPE_IFD8, TiffConst::TYPE_LONG], true)
        ) {
            return;
        }

        $offsets = $this->subIfdOffsets($entry);

        if ($offsets === []) {
            return;
        }

        $returnPos = $this->buf->tell();

        foreach ($offsets as $offset) {
            if ($offset <= 0) {
                continue;
            }

            if (isset($this->subIfds[$offset])) {
                continue;
            }

            // SubIFDs provide auxiliary/reduced-resolution images (EXIF 3.0 §4.5.5
            // and EXIF 2.32 §4.5.5) and correspond to the TIFF 6.0 §8 SubIFD tag.
            $this->subIfds[$offset] = $this->readIfd($offset);
        }

        $this->buf->seek($returnPos);
    }

    /**
     * Attempts to resolve an interoperability IFD from the provided directories.
     *
     * EXIF 3.0 §4.6.3 and EXIF 2.32 §4.6.3 specify that the Interoperability IFD is
     * located via the pointer tag 0xA005 stored within the Exif IFD, matching the
     * pointer semantics first published in EXIF 2.1 §2.6.3.
     */
    private function locateInteropIfd(?Ifd ...$ifds): ?Ifd
    {
        $deferred = [];

        foreach ($ifds as $ifd) {
            if (!$ifd instanceof Ifd) {
                continue;
            }

            if ($this->ifdLooksLikeInterop($ifd)) {
                return $ifd;
            }

            $entry = $ifd->get(ExifTag::INTEROPERABILITY_IFD_POINTER);
            if (!$entry instanceof IfdEntry) {
                continue;
            }

            $offset = $this->pointerOffset($entry);
            if (isset($this->interopVisitedOffsets[$offset])) {
                continue;
            }

            $this->interopVisitedOffsets[$offset] = true;

            $candidate = $this->readIfd($offset);

            if ($this->ifdLooksLikeInterop($candidate)) {
                return $candidate;
            }

            $deferred[] = $candidate;
        }

        if ($deferred !== []) {
            $resolved = $this->locateInteropIfd(...$deferred);

            if ($resolved instanceof Ifd) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Determines whether the provided directory contains interoperability tags.
     *
     * EXIF 3.0 §4.6.4 enumerates the interoperability tag set checked by this
     * helper to recognise interoperability directories, mirroring EXIF 2.32 §4.6.4
     * and the EXIF 2.1 §2.6.7 interoperability tag roster.
     */
    private function ifdLooksLikeInterop(Ifd $ifd): bool
    {
        $interopTags = [
            ExifTag::INTEROPERABILITY_INDEX,
            ExifTag::INTEROPERABILITY_VERSION,
            ExifTag::RELATED_IMAGE_FILE_FORMAT,
            ExifTag::RELATED_IMAGE_WIDTH,
            ExifTag::RELATED_IMAGE_LENGTH,
        ];

        return array_any($interopTags, fn (int $tag): bool => $ifd->get($tag) instanceof IfdEntry);
    }

    /**
     * Normalises the offsets described by a SubIFDs entry.
     *
     * @return list<int>
     */
    private function subIfdOffsets(IfdEntry $entry): array
    {
        $value = $entry->value;

        if ($value instanceof ExifNumericList) {
            $offsets = [];
            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $offsetEntry = new IfdEntry($entry->tag, $entry->type, 1, $component);
                    $offsets[]   = $this->pointerOffset($offsetEntry);

                    continue;
                }

                $offsetEntry = new IfdEntry($entry->tag, $entry->type, 1, $component);
                $offsets[]   = $this->pointerOffset($offsetEntry);
            }

            return $offsets;
        }

        if (is_int($value) || is_float($value)) {
            return [$this->pointerOffset($entry)];
        }

        return [];
    }

    /**
     * Converts raw bytes into PHP scalar values based on the TIFF type.
     *
     * EXIF 3.0 §4.5.2 Table 3 (mirroring TIFF 6.0 §2.2) defines the numeric
     * representations mapped to PHP scalars in this helper, consistent with EXIF 2.32 §4.5.2
     * and the EXIF 2.1 §2.6.2 type definitions.
     *
     * @param int    $type  TIFF field type code.
     * @param int    $count Number of values represented.
     * @param string $bytes Raw value bytes read from the blob.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64
     */
    private function decodeBytes(int $type, int $count, string $bytes): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64
    {
        $componentSize = $this->bytesPerComponent($type);
        $bytesLength   = strlen($bytes);
        $expectedBytes = $componentSize * $count;

        if ($bytesLength < $expectedBytes) {
            throw new ParseError(
                sprintf(
                    'Truncated value for TIFF type %d (expected %d bytes, got %d)',
                    $type,
                    $expectedBytes,
                    $bytesLength,
                ),
            );
        }

        // ASCII
        if ($type === TiffConst::TYPE_ASCII) {
            return rtrim($bytes, "\0");
        }

        if ($type === TiffConst::TYPE_UNDEFINED) {
            if ($this->isPrintableAsciiOrNull($bytes)) {
                return rtrim($bytes, "\0");
            }

            return $bytes;
        }

        // RATIONAL / SRATIONAL
        if ($type === TiffConst::TYPE_RATIONAL || $type === TiffConst::TYPE_SRATIONAL) {
            $rationalValues = [];
            for ($i = 0; $i < $count; ++$i) {
                $num              = $this->read32FromBytes($bytes, $i * 8, $type === TiffConst::TYPE_SRATIONAL);
                $den              = $this->read32FromBytes($bytes, $i * 8 + 4, $type === TiffConst::TYPE_SRATIONAL);
                $rationalValues[] = new ExifRational($num, $den);
            }

            return $count === 1
                ? $rationalValues[0]
                : new ExifRationalList($rationalValues);
        }

        $vals   = [];
        $cursor = 0;
        for ($i = 0; $i < $count; ++$i) {
            $vals[] = match ($type) {
                // BYTE
                TiffConst::TYPE_BYTE => ord($bytes[$cursor]),
                // SBYTE
                TiffConst::TYPE_SBYTE => $this->toSigned(ord($bytes[$cursor]), 8),
                // SHORT
                TiffConst::TYPE_SHORT => $this->unpackU16(substr($bytes, $cursor, 2)),
                // SSHORT
                TiffConst::TYPE_SSHORT => $this->unpackS16(substr($bytes, $cursor, 2)),
                // LONG
                TiffConst::TYPE_LONG,
                TiffConst::TYPE_IFD => $this->unpackU32(substr($bytes, $cursor, 4)),
                // SLONG
                TiffConst::TYPE_SLONG => $this->unpackS32(substr($bytes, $cursor, 4)),
                // LONG8 / IFD8
                TiffConst::TYPE_LONG8,
                TiffConst::TYPE_IFD8 => $this->unpackU64(substr($bytes, $cursor, 8)),
                // SLONG8
                TiffConst::TYPE_SLONG8 => $this->unpackS64(substr($bytes, $cursor, 8)),
                // FLOAT
                TiffConst::TYPE_FLOAT => $this->unpackFloat(substr($bytes, $cursor, 4)),
                // DOUBLE
                TiffConst::TYPE_DOUBLE => $this->unpackDouble(substr($bytes, $cursor, 8)),

                default => throw new ParseError('Unsupported type in decodeBytes: ' . $type),
            };
            $cursor += $componentSize;
        }

        return $count === 1 ? $vals[0] : new ExifNumericList($vals);
    }

    /**
     * Decodes a UTF-16LE encoded XP string into UTF-8.
     *
     * EXIF 3.0 §4.6.3 enumerates the XP metadata tags transported as UTF-16LE
     * character data inside the primary IFD.
     */
    private function decodeUtf16LeString(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $trimmed = $this->trimUtf16LeNullTerminators($bytes);

        if ($trimmed === '') {
            return '';
        }

        if ((strlen($trimmed) & 1) === 1) {
            $trimmed = substr($trimmed, 0, -1);
        }

        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, "\xFF\xFE")) {
            $trimmed = substr($trimmed, 2);
        }

        if ($trimmed === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = mb_convert_encoding($trimmed, 'UTF-8', 'UTF-16LE');
            if ($converted !== '') {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = iconv('UTF-16LE', 'UTF-8//IGNORE', $trimmed);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $this->decodeUtf16LeManually($trimmed);
    }

    /**
     * Removes trailing UTF-16LE NULL terminators from a byte sequence.
     */
    private function trimUtf16LeNullTerminators(string $bytes): string
    {
        $length      = strlen($bytes);
        $initialSize = $length;

        while ($length >= 2 && $bytes[$length - 1] === "\0" && $bytes[$length - 2] === "\0") {
            $length -= 2;
        }

        if ($length <= 0) {
            return '';
        }

        if ($length === $initialSize) {
            return $bytes;
        }

        return substr($bytes, 0, $length);
    }

    /**
     * Converts a UTF-16LE encoded byte sequence into UTF-8 without extensions.
     */
    private function decodeUtf16LeManually(string $bytes): string
    {
        $length = strlen($bytes);

        if ($length === 0) {
            return '';
        }

        $result = '';

        for ($i = 0; $i + 1 < $length; $i += 2) {
            $unit = ord($bytes[$i]) | (ord($bytes[$i + 1]) << 8);

            if ($unit >= self::UTF16_HIGH_SURROGATE_START && $unit <= self::UTF16_HIGH_SURROGATE_END) {
                if ($i + 3 >= $length) {
                    $result .= self::UNICODE_REPLACEMENT;
                    break;
                }

                $low = ord($bytes[$i + 2]) | (ord($bytes[$i + 3]) << 8);

                if ($low < self::UTF16_LOW_SURROGATE_START || $low > self::UTF16_LOW_SURROGATE_END) {
                    $result .= self::UNICODE_REPLACEMENT;
                    continue;
                }

                $codePoint = self::UTF16_SUPPLEMENTARY_OFFSET + (($unit - self::UTF16_HIGH_SURROGATE_START) << 10) + ($low - self::UTF16_LOW_SURROGATE_START);
                $i += 2;
            } elseif ($unit >= self::UTF16_LOW_SURROGATE_START && $unit <= self::UTF16_LOW_SURROGATE_END) {
                $result .= self::UNICODE_REPLACEMENT;
                continue;
            } else {
                $codePoint = $unit;
            }

            $result .= $this->codePointToUtf8($codePoint);
        }

        return $result;
    }

    /**
     * Encodes a Unicode code point into UTF-8.
     */
    private function codePointToUtf8(int $codePoint): string
    {
        if ($codePoint <= self::UTF8_SINGLE_BYTE_MAX) {
            return chr($codePoint);
        }

        if ($codePoint <= self::UTF8_TWO_BYTE_MAX) {
            return chr(self::UTF8_TWO_BYTE_PREFIX | ($codePoint >> 6))
                . chr(self::UTF8_CONTINUATION_PREFIX | ($codePoint & BitMask::SIX_BIT_MASK));
        }

        if ($codePoint <= self::UTF8_THREE_BYTE_MAX) {
            return chr(self::UTF8_THREE_BYTE_PREFIX | ($codePoint >> 12))
                . chr(self::UTF8_CONTINUATION_PREFIX | (($codePoint >> 6) & BitMask::SIX_BIT_MASK))
                . chr(self::UTF8_CONTINUATION_PREFIX | ($codePoint & BitMask::SIX_BIT_MASK));
        }

        $codePoint = min(
            $codePoint,
            self::UTF8_MAX_CODE_POINT
        );

        return chr(self::UTF8_FOUR_BYTE_PREFIX | ($codePoint >> 18))
            . chr(self::UTF8_CONTINUATION_PREFIX | (($codePoint >> 12) & BitMask::SIX_BIT_MASK))
            . chr(self::UTF8_CONTINUATION_PREFIX | (($codePoint >> 6) & BitMask::SIX_BIT_MASK))
            . chr(self::UTF8_CONTINUATION_PREFIX | ($codePoint & BitMask::SIX_BIT_MASK));
    }

    /**
     * Determines whether the given byte sequence consists only of printable ASCII or NUL bytes.
     */
    private function isPrintableAsciiOrNull(string $bytes): bool
    {
        $length = strlen($bytes);

        for ($i = 0; $i < $length; ++$i) {
            $value = ord($bytes[$i]);

            if ($value === 0) {
                continue;
            }

            if ($value < self::ASCII_PRINTABLE_MIN || $value > self::ASCII_PRINTABLE_MAX) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reads the 4- or 8-byte value/offset field for a directory entry.
     *
     * @param int $type  TIFF field type code.
     * @param int $count Number of values represented.
     *
     * @return array{0:int|UInt64|string,1:string|null}
     */
    private function readValueOrOffset(int $type, int $count): array
    {
        if (!$this->bigTiff) {
            return [$this->readU32(), null];
        }

        $componentSize    = $this->bytesPerComponent($type);
        $inlineThreshold  = $this->bigTiffOffsetSize;
        $inlineValueBytes = $componentSize * $count;

        if ($inlineValueBytes <= $inlineThreshold) {
            $rawField    = $this->buf->read($inlineThreshold);
            $inlineBytes = $inlineValueBytes === $inlineThreshold
                ? $rawField
                : substr($rawField, 0, $inlineValueBytes);

            return [$inlineBytes, $inlineBytes];
        }

        return [$this->readBigTiffOffsetValue(), null];
    }

    /**
     * Ensures that an offset lies within the TIFF blob and returns it as an integer.
     */
    private function ensureOffset(int|UInt64|string $offset, string $context, int $length = 0): int
    {
        if (is_string($offset)) {
            return $this->ensureDecimalOffset($offset, $context, $length);
        }

        $offset64 = $offset instanceof UInt64 ? $offset : UInt64::fromInt($offset);

        $this->assertOffsetRange($offset64, $length, $context);

        return $offset64->toInt($context);
    }

    /**
     * Normalises an optional offset that may be zero.
     */
    private function normaliseOptionalOffset(UInt64 $offset, string $context): int
    {
        if ($offset->isZero()) {
            return 0;
        }

        return $this->ensureOffset($offset, $context);
    }

    /**
     * Normalises a BigTIFF optional offset according to the configured field width.
     */
    private function normaliseBigTiffOptionalOffset(int|UInt64|string $offset, string $context): int
    {
        if ($offset instanceof UInt64) {
            return $this->normaliseOptionalOffset($offset, $context);
        }

        if (is_int($offset)) {
            if ($offset <= 0) {
                return 0;
            }

            return $this->ensureOffset($offset, $context);
        }

        if ($this->decimalStringIsZero($offset)) {
            return 0;
        }

        return $this->ensureOffset($offset, $context);
    }

    /**
     * Verifies that an offset and optional length are contained within the TIFF blob.
     */
    private function assertOffsetRange(UInt64 $offset, int $length, string $context): void
    {
        if ($offset->compare($this->blobSize) > 0) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context));
        }

        $size = $this->buf->size();

        if ($length > $size) {
            throw new BoundsError(sprintf('%s length %d exceeds TIFF data length.', $context, $length));
        }

        $offsetInt = $offset->toInt($context);

        if ($length > 0 && $offsetInt > $size - $length) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context));
        }
    }

    /**
     * Extracts the raw bytes addressed by a directory entry.
     *
     * @param int               $type          TIFF field type code.
     * @param int               $count         Number of values represented.
     * @param int|UInt64|string $valueOrOffset Inline value bytes or an offset into the blob.
     * @param string|null       $inlineBytes   Raw bytes captured from the value/offset field.
     *
     * @return array{0: string, 1: int|null}
     */
    private function valueBytes(int $type, int $count, int|UInt64|string $valueOrOffset, ?string $inlineBytes = null): array
    {
        $unitSize        = $this->bytesPerComponent($type);
        $dataSize        = $unitSize * $count;
        $inlineThreshold = $this->bigTiff ? 8 : 4;

        if ($inlineBytes !== null) {
            if (strlen($inlineBytes) < $dataSize) {
                throw new ParseError(
                    sprintf(
                        'Inline value for TIFF type %d truncated (expected %d bytes, got %d)',
                        $type,
                        $dataSize,
                        strlen($inlineBytes),
                    ),
                );
            }

            return [substr($inlineBytes, 0, $dataSize), null];
        }

        if ($dataSize <= $inlineThreshold) {
            if (is_string($valueOrOffset)) {
                if (strlen($valueOrOffset) < $dataSize) {
                    throw new ParseError(
                        sprintf(
                            'Inline value for TIFF type %d truncated (expected %d bytes, got %d)',
                            $type,
                            $dataSize,
                            strlen($valueOrOffset),
                        ),
                    );
                }

                return [substr($valueOrOffset, 0, $dataSize), null];
            }

            $raw = $this->uXToBytes($valueOrOffset, $inlineThreshold);

            return [substr($raw, 0, $dataSize), null];
        }

        $offset  = $this->ensureOffset($valueOrOffset, sprintf('Value offset for TIFF type %d', $type), $dataSize);
        $current = $this->buf->tell();
        $this->buf->seek($offset);
        $bytes = $this->buf->read($dataSize);
        $this->buf->seek($current);

        return [$bytes, $offset];
    }

    /**
     * Resolves maker note metadata using the provided registry when available.
     *
     * EXIF 3.0 §4.6.5 (Table 4) and EXIF 2.32 §4.6.5 define the MakerNote tag semantics
     * and the MakerNoteSafety flag used to indicate whether in-place modification is safe,
     * continuing the EXIF 2.1 §2.6.5 guidance on manufacturer-specific tags.
     */
    private function resolveMakerNotes(?Registry $registry, Ifd $ifd0, ?Ifd $exifIfd): ?MakerNotesRecord
    {
        if ($this->makerNoteRaw === null) {
            return null;
        }

        $safety = $this->makerNoteSafety($exifIfd);

        if (!$registry instanceof Registry || !$exifIfd instanceof Ifd) {
            return $this->makerNotesDigest($safety);
        }

        $make = $this->stringFromIfd($ifd0, ExifTag::MAKE);
        if ($make === null || $make === '') {
            return $this->makerNotesDigest($safety);
        }

        $decoder = $registry->find($make);
        if (!$decoder instanceof MakerNotesDecoderInterface) {
            return $this->makerNotesDigest($safety);
        }

        $model = $this->stringFromIfd($ifd0, ExifTag::MODEL);

        $metadata = $decoder->decode($this->makerNoteRaw, $make, $model);

        return $this->applyMakerNoteSafety($metadata, $safety);
    }

    /**
     * Creates a digest metadata instance for unknown maker notes.
     */
    private function makerNotesDigest(?bool $isSafe): MakerNotesRecord
    {
        $raw = $this->makerNoteRaw ?? '';

        return new MakerNotesRecord('Unknown', strlen($raw), sha1($raw), null, $isSafe);
    }

    /**
     * Applies the maker note safety flag to the provided metadata instance.
     */
    private function applyMakerNoteSafety(MakerNotesRecord $metadata, ?bool $isSafe): MakerNotesRecord
    {
        if ($metadata->isSafe === $isSafe) {
            return $metadata;
        }

        return new MakerNotesRecord(
            $metadata->vendor,
            $metadata->length,
            $metadata->sha1,
            $metadata->apple,
            $isSafe,
        );
    }

    /**
     * Converts the maker note safety numeric flag into a boolean representation.
     *
     * EXIF 3.0 §4.6.5 and EXIF 2.32 §4.6.5 describe MakerNoteSafety as the indicator for
     * whether the maker note contents can be altered without breaking compatibility.
     */
    private function makerNoteSafety(?Ifd $exifIfd): ?bool
    {
        if (!$exifIfd instanceof Ifd) {
            return null;
        }

        $entry = $exifIfd->get(ExifTag::MAKER_NOTE_SAFETY);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $value = $entry->value;

        if ($value instanceof UInt64) {
            $value = $this->normaliseScalarUInt64(ExifTag::MAKER_NOTE_SAFETY, $value);
        } elseif ($value instanceof ExifNumericList) {
            $normalised = [];
            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $normalised[] = $this->normaliseScalarUInt64(ExifTag::MAKER_NOTE_SAFETY, $component);

                    continue;
                }

                $normalised[] = $component;
            }

            $value = new ExifNumericList($normalised);
        }

        if (!is_int($value) && !is_float($value) && !$value instanceof ExifNumericList && !is_string($value)) {
            return null;
        }

        return ValueConverters::makerNoteSafety($value);
    }

    /**
     * Returns the trimmed string value for a specific tag within an IFD.
     */
    private function stringFromIfd(?Ifd $ifd, int $tag): ?string
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        $entry = $ifd->get($tag);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $value = $entry->value;
        if (!is_string($value)) {
            return null;
        }

        return rtrim($value, "\0");
    }

    /**
     * Returns the number of bytes used per component for a TIFF field type.
     *
     * @param int $type TIFF field type code.
     *
     * @return int
     */
    private function bytesPerComponent(int $type): int
    {
        return match ($type) {
            // BYTE, ASCII, SBYTE, UNDEFINED
            TiffConst::TYPE_BYTE,
            TiffConst::TYPE_ASCII,
            TiffConst::TYPE_SBYTE,
            TiffConst::TYPE_UNDEFINED => 1,

            // SHORT, SSHORT
            TiffConst::TYPE_SHORT,
            TiffConst::TYPE_SSHORT => 2,

            // LONG, SLONG, FLOAT
            TiffConst::TYPE_LONG,
            TiffConst::TYPE_IFD,
            TiffConst::TYPE_SLONG,
            TiffConst::TYPE_FLOAT => 4,

            // RATIONAL, SRATIONAL, DOUBLE
            TiffConst::TYPE_RATIONAL,
            TiffConst::TYPE_SRATIONAL,
            TiffConst::TYPE_DOUBLE,
            TiffConst::TYPE_LONG8,
            TiffConst::TYPE_SLONG8,
            TiffConst::TYPE_IFD8 => 8,

            default => throw new ParseError('Unsupported TIFF type: ' . $type),
        };
    }

    /**
     * Reads an unsigned 16-bit integer using the file byte order.
     *
     * @return int
     */
    private function readU16(): int
    {
        return $this->bo === Endian::Little ? $this->buf->readU16LE() : $this->buf->readU16BE();
    }

    /**
     * Reads an unsigned 32-bit integer using the file byte order.
     *
     * @return int
     */
    private function readU32(): int
    {
        return $this->bo === Endian::Little ? $this->buf->readU32LE() : $this->buf->readU32BE();
    }

    /**
     * Reads an unsigned 64-bit integer using the file byte order.
     *
     * @return UInt64
     */
    private function readU64(): UInt64
    {
        return $this->bo === Endian::Little ? $this->buf->readU64LE() : $this->buf->readU64BE();
    }

    /**
     * Converts an integer into a byte string respecting the configured endianness.
     *
     * @param int|UInt64 $v     Integer value to convert.
     * @param int        $bytes Number of bytes to output.
     *
     * @return string
     */
    private function uXToBytes(int|UInt64 $v, int $bytes): string
    {
        // Convert integer to a byte string of specific length using current endianness
        if ($bytes === 4) {
            $value = $v instanceof UInt64 ? $v->toInt('Inline 32-bit value') : $v;

            return $this->bo === Endian::Little ? pack('V', $value) : pack('N', $value);
        }

        if ($bytes === 8) {
            if ($v instanceof UInt64) {
                $hi = $v->high();
                $lo = $v->low();
            } else {
                $lo = $v & BitMask::UINT32_MAX;
                $hi = intdiv($v, BitMask::UINT32_BASE);
            }

            return $this->bo === Endian::Little ? pack('V2', $lo, $hi) : pack('N2', $hi, $lo);
        }

        // fallback (shouldn't happen here)
        $bin   = '';
        $value = $v instanceof UInt64 ? $v->toInt('Inline value') : $v;
        for ($i = 0; $i < $bytes; ++$i) {
            $bin = chr(($value >> ($this->bo === Endian::Little ? ($i * 8) : (($bytes - 1 - $i) * 8))) & BitMask::LOW_BYTE) . $bin;
        }

        return $bin;
    }

    /**
     * Reads a 32-bit integer from a byte buffer using the configured endianness.
     *
     * @param string $bytes  Source buffer containing the integer.
     * @param int    $offset Byte offset within the buffer.
     * @param bool   $signed Whether to interpret the value as signed.
     *
     * @return int
     */
    private function read32FromBytes(string $bytes, int $offset, bool $signed): int
    {
        $chunk = substr($bytes, $offset, 4);

        return $signed ? $this->unpackS32($chunk) : $this->unpackU32($chunk);
    }

    /**
     * Unpacks an unsigned 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackU16(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'v' : 'n';

        return Unpack::int($format, $b, '16-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackS16(string $b): int
    {
        $u = $this->unpackU16($b);

        return $u >= BitMask::SIGN_BIT_16 ? $u - BitMask::UINT16_BASE : $u;
    }

    /**
     * Unpacks an unsigned 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackU32(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'V' : 'N';

        return Unpack::int($format, $b, '32-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackS32(string $b): int
    {
        $u = $this->unpackU32($b);

        return (($u & BitMask::SIGN_BIT_32) !== 0) ? -((~$u & BitMask::UINT32_MAX) + 1) : $u;
    }

    /**
     * Unpacks an IEEE-754 single-precision float from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return float
     */
    private function unpackFloat(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'g' : 'G';

        return Unpack::float($format, $b, '32-bit float from TIFF bytes');
    }

    /**
     * Unpacks an IEEE-754 double-precision float from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return float
     */
    private function unpackDouble(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'e' : 'E';

        return Unpack::float($format, $b, '64-bit float from TIFF bytes');
    }

    /**
     * Unpacks an unsigned 64-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return UInt64
     */
    private function unpackU64(string $b): UInt64
    {
        return Unpack::uint64($b, $this->bo === Endian::Little, '64-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 64-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackS64(string $b): int
    {
        $unsigned = $this->unpackU64($b);
        $hi       = $unsigned->high();
        $lo       = $unsigned->low();

        if (($hi & BitMask::SIGN_BIT_32) === 0) {
            return $unsigned->toInt('Signed 64-bit integer');
        }

        $hiComplement = (~$hi) & BitMask::UINT32_MAX;
        $loComplement = (~$lo) & BitMask::UINT32_MAX;
        $magnitude    = Unpack::combineUint32($hiComplement, $loComplement)->addSmall(1)->toInt('Signed 64-bit integer magnitude');

        return -$magnitude;
    }

    /**
     * Converts an unsigned integer to its signed representation for the given width.
     *
     * @param int $u    Unsigned integer value.
     * @param int $bits Bit width of the target signed representation.
     *
     * @return int
     */
    private function toSigned(int $u, int $bits): int
    {
        $sign = 1 << ($bits - 1);

        return (($u & $sign) !== 0) ? $u - (1 << $bits) : $u;
    }

    /**
     * Ensures that an IFD entry encodes a valid offset and returns it as an integer.
     *
     * EXIF 3.0 §4.6.3 and EXIF 2.32 §4.6.3 require pointer tags to reference additional
     * directories by absolute offsets. The helper normalises the supported numeric
     * representations into validated offsets within the EXIF payload, respecting the
     * EXIF 2.1 §2.6.3 pointer semantics.
     *
     * @param IfdEntry $entry Entry that should contain a pointer/offset value.
     *
     * @return int
     */
    private function pointerOffset(IfdEntry $entry): int
    {
        $value = $entry->value;

        if (is_int($value)) {
            return $this->validatePointerOffset($value, $entry->tag);
        }

        if ($value instanceof UInt64) {
            return $this->ensureOffset($value, sprintf('IFD pointer tag 0x%04X', $entry->tag));
        }

        if (is_float($value)) {
            return $this->pointerOffsetFromFloat($value, $entry->tag);
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;
            if (is_int($first)) {
                return $this->validatePointerOffset($first, $entry->tag);
            }

            if ($first instanceof UInt64) {
                return $this->ensureOffset($first, sprintf('IFD pointer tag 0x%04X', $entry->tag));
            }

            if (is_float($first)) {
                return $this->pointerOffsetFromFloat($first, $entry->tag);
            }
        }

        throw new ParseError(sprintf('IFD pointer tag 0x%04X must contain a numeric offset.', $entry->tag));
    }

    /**
     * Validates that an offset fits within the supported integer range.
     *
     * @param int $offset Candidate offset.
     * @param int $tag    Tag identifier emitting the offset.
     *
     * @return int
     */
    private function validatePointerOffset(int $offset, int $tag): int
    {
        if ($offset < 0) {
            throw new ParseError(sprintf('IFD pointer tag 0x%04X must not be negative.', $tag));
        }

        return $this->ensureOffset($offset, sprintf('IFD pointer tag 0x%04X', $tag));
    }

    /**
     * Normalises a floating-point offset representation to a validated integer.
     *
     * @param float $value Floating-point representation to normalise.
     * @param int   $tag   Tag identifier emitting the offset.
     *
     * @return int
     */
    private function pointerOffsetFromFloat(float $value, int $tag): int
    {
        if (!is_finite($value) || (float) (int) $value !== $value) {
            throw new ParseError(sprintf('IFD pointer tag 0x%04X must contain an integer offset.', $tag));
        }

        if ($value < 0.0) {
            throw new ParseError(sprintf('IFD pointer tag 0x%04X must not be negative.', $tag));
        }

        return $this->ensureOffset((int) $value, sprintf('IFD pointer tag 0x%04X', $tag));
    }

    /**
     * Reads a BigTIFF offset using the configured field width.
     *
     * EXIF 3.0 §4.5.2; EXIF 2.32 §4.5.2; TIFF 6.0 §8 define the BigTIFF offset field
     * width (8 or 16 bytes), null-pointer semantics and the handling of offsets that
     * exceed native integer precision, so this helper normalises the raw value into
     * the closest PHP representation.
     */
    private function readBigTiffOffsetValue(): int|UInt64|string
    {
        if ($this->bigTiffOffsetSize === 8) {
            return $this->readU64();
        }

        if ($this->bigTiffOffsetSize !== 16) {
            throw new ParseError('Unsupported BigTIFF offset size.');
        }

        $raw    = $this->buf->read(16);
        $little = $this->bo === Endian::Little;

        $lowBytes  = $little ? substr($raw, 0, 8) : substr($raw, 8, 8);
        $highBytes = $little ? substr($raw, 8, 8) : substr($raw, 0, 8);

        $low  = Unpack::uint64($lowBytes, $little, 'BigTIFF offset (low)');
        $high = Unpack::uint64($highBytes, $little, 'BigTIFF offset (high)');

        if (!$high->isZero()) {
            return $this->uint128ToDecimal($high, $low);
        }

        if ($low->fitsSignedInt()) {
            return $low->toInt('BigTIFF offset');
        }

        return $this->uint64ToDecimal($low);
    }

    /**
     * Converts an unsigned 64-bit integer into its decimal string representation.
     */
    private function uint64ToDecimal(UInt64 $value): string
    {
        return $this->wordsToDecimal([
            $value->high(),
            $value->low(),
        ]);
    }

    /**
     * Converts a 128-bit unsigned integer into a decimal string.
     */
    private function uint128ToDecimal(UInt64 $high, UInt64 $low): string
    {
        return $this->wordsToDecimal([
            $high->high(),
            $high->low(),
            $low->high(),
            $low->low(),
        ]);
    }

    /**
     * Converts an array of base-2^32 words (most significant first) into a decimal string.
     *
     * @param array<int> $words
     */
    private function wordsToDecimal(array $words): string
    {
        $words = $this->trimLeadingZeroWords($words);

        if ($words === []) {
            return '0';
        }

        $digits = '';

        while ($words !== []) {
            [$words, $remainder] = $this->divModWordsBy10($words);
            $digits              = $remainder . $digits;
            $words               = $this->trimLeadingZeroWords($words);
        }

        return $digits;
    }

    /**
     * Divides a base-2^32 big integer by 10.
     *
     * @param array<int> $words
     *
     * @return array{0: array<int>, 1: int}
     */
    private function divModWordsBy10(array $words): array
    {
        $quotient = [];
        $carry    = 0;

        foreach ($words as $word) {
            $value = ($carry << 32) + $word;
            $q     = intdiv($value, 10);
            $r     = $value - ($q * 10);

            if ($quotient !== [] || $q !== 0) {
                $quotient[] = $q;
            }

            $carry = $r;
        }

        return [$quotient, $carry];
    }

    /**
     * Removes leading zero words from a base-2^32 representation.
     *
     * @param array<int> $words
     *
     * @return array<int>
     */
    private function trimLeadingZeroWords(array $words): array
    {
        $index = 0;
        $count = count($words);

        while ($index < $count && $words[$index] === 0) {
            ++$index;
        }

        if ($index === 0) {
            return $words;
        }

        if ($index >= $count) {
            return [];
        }

        return array_slice($words, $index);
    }

    /**
     * Ensures that a decimal offset lies within the TIFF blob and returns it as an integer.
     */
    private function ensureDecimalOffset(string $offset, string $context, int $length): int
    {
        $normalised = $this->normaliseDecimalString($offset);
        $size       = $this->buf->size();

        if ($this->compareDecimalStringToInt($normalised, $size) > 0) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context));
        }

        if ($length > $size) {
            throw new BoundsError(sprintf('%s length %d exceeds TIFF data length.', $context, $length));
        }

        if ($length > 0) {
            $limit = $size - $length;
            if ($this->compareDecimalStringToInt($normalised, $limit) > 0) {
                throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context));
            }
        }

        return (int) $normalised;
    }

    /**
     * Normalises a decimal string by validating its characters and removing leading zeros.
     */
    private function normaliseDecimalString(string $value): string
    {
        if ($value === '') {
            throw new ParseError('Decimal offset must not be empty.');
        }

        if (strspn($value, '0123456789') !== strlen($value)) {
            throw new ParseError('Decimal offset contains invalid characters.');
        }

        $trimmed = ltrim($value, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * Compares a decimal string against a non-negative integer.
     */
    private function compareDecimalStringToInt(string $decimal, int $int): int
    {
        if ($int < 0) {
            return 1;
        }

        $intString = $int === 0 ? '0' : ltrim((string) $int, '0');
        $decLen    = strlen($decimal);
        $intLen    = strlen($intString);

        if ($decLen !== $intLen) {
            return $decLen <=> $intLen;
        }

        return $decimal <=> $intString;
    }

    /**
     * Determines whether a decimal string represents zero.
     */
    private function decimalStringIsZero(string $value): bool
    {
        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            if ($value[$i] !== '0') {
                return false;
            }
        }

        return true;
    }
}
