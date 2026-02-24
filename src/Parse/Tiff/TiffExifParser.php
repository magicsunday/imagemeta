<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Contract\TiffExifParserInterface;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Parse\ParserLimits;

use function count;
use function in_array;
use function is_string;
use function sprintf;

/**
 * Parses classic TIFF and BigTIFF structures embedded in EXIF payloads.
 *
 * EXIF 3.0 §4.5 outlines the TIFF header layout, data type handling, and IFD
 * traversal rules honoured by this reader. TIFF 6.0 §2.1 defines the file
 * structure and byte order, §2.2 defines field types, and §8 provides the
 * baseline directory semantics shared by both formats.
 */
final class TiffExifParser implements TiffExifParserInterface
{
    private MemoryBuffer $buffer;

    private Endian $bo;

    private bool $bigTiff = false;

    private int $bigTiffOffsetSize = 8;

    private UInt64 $blobSize;

    private ?string $makerNoteRaw = null;

    /** @var array<int, Ifd> */
    private array $ifdCache = [];

    private ?TiffByteOrderHandler $byteOrderHandler = null;

    private ?ExifTagDecoder $tagDecoder = null;

    private ?IfdParser $ifdParser = null;

    private DngValidator $dngValidator;

    private TiffStructuralValidator $structuralValidator;

    private TiffBinaryReader $binaryReader;

    private TiffOffsetValidator $offsetValidator;

    private TiffValueDecoder $decoder;

    private DngValueNormalizer $dngNormalizer;

    private TiffExifTagValidator $tagValidator;

    private TiffJpegThumbnailValidator $thumbnailValidator;

    private TiffIfdTraverser $traverser;

    /**
     * Parses an EXIF TIFF blob into a structured document model.
     *
     * EXIF 3.0 §4.5 describes the TIFF header, byte-order markers, and IFD chaining strategy
     * applied while decoding embedded EXIF payloads.
     *
     * @param string        $tiffBlob        Raw TIFF data including headers.
     * @param Registry|null $registry        Optional registry used to decode manufacturer-specific maker notes.
     * @param bool          $jpegContext     Whether the TIFF is embedded in a JPEG context.
     * @param bool          $embeddedContext Whether the TIFF is embedded in an ISO BMFF context.
     */
    public function parseFromBlob(string $tiffBlob, ?Registry $registry = null, bool $jpegContext = false, bool $embeddedContext = false): ParsedExif
    {
        $this->buffer = new MemoryBuffer($tiffBlob);
        $this->buffer->seek(0);

        $this->blobSize = UInt64::fromInt($this->buffer->size());

        $this->makerNoteRaw      = null;
        $this->ifdCache          = [];
        $this->bigTiffOffsetSize = 8;
        $this->byteOrderHandler ??= new TiffByteOrderHandler();
        $this->tagDecoder ??= new ExifTagDecoder();
        $this->ifdParser ??= new IfdParser();

        $byteOrderHandler = $this->byteOrderHandler;
        $tagDecoder       = $this->tagDecoder;

        // byte order
        // EXIF 3.0 §4.5.1 follows TIFF 6.0 §2.1 (Image File Header) in defining the
        // "II"/"MM" byte-order signatures used for byte-order detection.
        $boSig    = $this->buffer->read(2);
        $this->bo = match ($boSig) {
            'II'    => Endian::Little,
            'MM'    => Endian::Big,
            default => throw new ParseError('Bad TIFF byte order', 1301),
        };

        $this->dngValidator        = new DngValidator($this->bo, $this->buffer);
        $this->structuralValidator = new TiffStructuralValidator($this->buffer);

        $this->binaryReader = new TiffBinaryReader(
            $this->buffer,
            $this->bo,
            $byteOrderHandler,
            $this->bigTiff,
            $this->bigTiffOffsetSize,
        );
        $this->offsetValidator = new TiffOffsetValidator($this->buffer, $this->blobSize);
        $this->decoder         = new TiffValueDecoder(
            $this->binaryReader,
            $this->offsetValidator,
            $tagDecoder,
        );
        $this->dngNormalizer = new DngValueNormalizer(
            $this->binaryReader,
            $this->offsetValidator,
            $this->decoder,
        );
        $this->tagValidator       = new TiffExifTagValidator();
        $this->thumbnailValidator = new TiffJpegThumbnailValidator($this->buffer);

        $ifd0 = $this->readFirstIfd($byteOrderHandler, $tagDecoder);

        $this->traverser = new TiffIfdTraverser(
            $this->offsetValidator,
            fn (int|UInt64|string $offset): Ifd => $this->readIfd($offset),
            $this->bigTiff,
        );

        // follow pointers
        $exifIfd = null;
        $gpsIfd  = null;
        $ifd1    = null;

        $exifPointer = $ifd0->get(ExifTag::EXIF_IFD_POINTER);
        if ($exifPointer instanceof IfdEntry) {
            $offset = $this->traverser->pointerOffset($exifPointer);
            if ($offset !== null) {
                $exifIfd = $this->readIfd($offset);
            }
        }

        $interopIfd = $this->traverser->locateInteropIfd($exifIfd, $ifd0);

        $gpsPointer = $ifd0->get(ExifTag::GPS_IFD_POINTER);
        if ($gpsPointer instanceof IfdEntry) {
            $gpsOffset = $this->traverser->pointerOffset($gpsPointer);
            if ($gpsOffset !== null) {
                $gpsIfd = $this->readIfd($gpsOffset);
            }
        }

        $this->tagValidator->validateGpsReferenceTagLayouts($gpsIfd);
        $this->tagValidator->validateGpsCoordinateTagLayouts($gpsIfd);

        $subIfds = $this->traverser->resolveSubIfds($ifd0);

        $additionalIfds = [];
        $visitedOffsets = [];

        $nextOffset = $ifd0->nextIfdOffset;
        while ($nextOffset !== null && $nextOffset > 0) {
            if (isset($visitedOffsets[$nextOffset])) {
                throw new ParseError('Cyclic IFD chain detected at offset ' . $nextOffset . '.', 1359);
            }

            if (count($visitedOffsets) >= ParserLimits::MAX_IFD_CHAIN_LENGTH) {
                throw new ParseError(
                    sprintf('IFD chain length exceeds maximum allowed %d.', ParserLimits::MAX_IFD_CHAIN_LENGTH),
                    1964,
                );
            }

            $visitedOffsets[$nextOffset] = true;

            $nextIfd          = $this->readIfd($nextOffset);
            $additionalIfds[] = $nextIfd;

            if (!$ifd1 instanceof Ifd) {
                $ifd1 = $nextIfd;
            }

            $nextOffset = $nextIfd->nextIfdOffset;
        }

        $this->validateParsedIfds($ifd0, $ifd1, $exifIfd, $jpegContext, $embeddedContext, $additionalIfds);

        if (!($interopIfd instanceof Ifd) && ($additionalIfds !== [])) {
            $interopIfd = $this->traverser->locateInteropIfd(...$additionalIfds);
        }

        $makerNoteDispatcher = new MakerNoteDispatcher();
        $makerNotes          = $makerNoteDispatcher->resolve($this->makerNoteRaw, $registry, $ifd0, $exifIfd);

        $parsedExif = new ParsedExif(
            $ifd0,
            $exifIfd,
            $gpsIfd,
            $interopIfd,
            $ifd1,
            $makerNotes,
            $additionalIfds,
            $subIfds,
        );

        $this->tagValidator->validateCompositeImageDependencies($exifIfd, $parsedExif);
        $this->tagValidator->validateSourceExposureTimesPayload($exifIfd, $parsedExif);

        return $parsedExif;
    }

    /**
     * Runs all DNG, structural, tag, and thumbnail validators on the parsed IFD set.
     *
     * @param Ifd       $ifd0            Primary image file directory.
     * @param Ifd|null  $ifd1            Secondary IFD (thumbnail), if present.
     * @param Ifd|null  $exifIfd         Exif IFD, if present.
     * @param bool      $jpegContext     Whether the TIFF is embedded in a JPEG.
     * @param bool      $embeddedContext Whether the TIFF is embedded in an ISO BMFF container.
     * @param list<Ifd> $additionalIfds  All chained IFDs beyond IFD0.
     */
    private function validateParsedIfds(
        Ifd $ifd0,
        ?Ifd $ifd1,
        ?Ifd $exifIfd,
        bool $jpegContext,
        bool $embeddedContext,
        array $additionalIfds,
    ): void {
        $isDngContainer = ($ifd0->get(DngTag::DNG_VERSION) instanceof IfdEntry)
            || ($ifd0->get(DngTag::DNG_BACKWARD_VERSION) instanceof IfdEntry)
            || ($ifd0->get(DngTag::UNIQUE_CAMERA_MODEL) instanceof IfdEntry);

        $this->dngValidator->validatePreLoop($ifd0);

        $this->structuralValidator->validateEnhancedIfd($ifd0);
        foreach ($additionalIfds as $additionalIfd) {
            $this->structuralValidator->validateEnhancedIfd($additionalIfd);
            $this->structuralValidator->validatePerIfd($additionalIfd, !$isDngContainer);
            $this->dngValidator->validatePerIfd($additionalIfd);
        }

        $this->dngValidator->validateIfd0($ifd0, $additionalIfds);
        $this->structuralValidator->validateIfd0($ifd0, $ifd1, $jpegContext, !$isDngContainer);

        $this->tagValidator->validatePrimaryThumbnailStructureCompatibility($ifd0, $ifd1, $jpegContext);
        $this->tagValidator->validateCameraControlEnumDomains($ifd0, $exifIfd, $ifd1, ...$additionalIfds);
        $this->tagValidator->validateFlashBitfield($exifIfd);

        $this->thumbnailValidator->validateJpegThumbnailStream($ifd1);

        // JPEG and ISO BMFF containers provide image dimensions/layout
        // externally (SOF header / ispe box), so TIFF-level structural tags
        // are not required.
        if (!$jpegContext && !$embeddedContext) {
            $this->structuralValidator->validateImageData($ifd0);
        }

        if ($jpegContext) {
            $this->tagValidator->validateJpegContextProhibitions($ifd0);
        }

        $this->tagValidator->validateExifIfdPlacement($ifd0);

        if ($exifIfd instanceof Ifd) {
            $this->tagValidator->validateCompanionArtist($ifd0, $exifIfd);
            $this->tagValidator->validateCompanionSoftware($ifd0, $exifIfd);
            $this->tagValidator->validateSensitivityCombinations($exifIfd);
        }
    }

    /**
     * Reads and validates the TIFF magic, handles BigTIFF upgrade, and returns IFD0.
     *
     * @param TiffByteOrderHandler $byteOrderHandler Endian-aware primitive I/O.
     * @param ExifTagDecoder       $tagDecoder       Tag classification for decoding.
     */
    private function readFirstIfd(TiffByteOrderHandler $byteOrderHandler, ExifTagDecoder $tagDecoder): Ifd
    {
        $magic = $this->binaryReader->readU16();

        if ($magic === TiffConst::MAGIC_BIG) {
            $this->bigTiff = true;
            $this->parseBigTiffHeader();

            // Re-create collaborators with updated BigTIFF settings.
            $this->binaryReader = new TiffBinaryReader(
                $this->buffer,
                $this->bo,
                $byteOrderHandler,
                $this->bigTiff,
                $this->bigTiffOffsetSize,
            );
            $this->decoder = new TiffValueDecoder(
                $this->binaryReader,
                $this->offsetValidator,
                $tagDecoder,
            );
            $this->dngNormalizer = new DngValueNormalizer(
                $this->binaryReader,
                $this->offsetValidator,
                $this->decoder,
            );

            $firstIfd = $this->binaryReader->readU64();

            if ($firstIfd->isZero()) {
                throw new ParseError('missing 0th IFD offset', 1302);
            }

            return $this->readIfd($firstIfd);
        }

        if ($magic === TiffConst::MAGIC_CLASSIC) {
            $this->bigTiff = false;

            $firstIfd = $this->binaryReader->readU32();

            if ($firstIfd < TiffConst::HEADER_SIZE_CLASSIC) {
                throw new ParseError('missing 0th IFD offset', 1303);
            }

            return $this->readIfd($firstIfd);
        }

        throw new ParseError(
            sprintf('Unknown TIFF magic 0x%04X', $magic),
            1304,
        );
    }

    /**
     * Validates the BigTIFF header following the magic identifier.
     *
     * EXIF 3.0 §4.5.1 adopts the BigTIFF header layout and retains the reserved
     * word semantics aligned with TIFF 6.0 §8, constraining the offset-size and
     * reserved fields before the first IFD pointer.
     */
    private function parseBigTiffHeader(): void
    {
        // BigTIFF header after magic: 2 bytes offset size, 2 bytes reserved, then the first IFD offset
        $offSize  = $this->binaryReader->readU16();
        $reserved = $this->binaryReader->readU16();

        // BigTIFF offset size is fixed to 8 bytes per spec.
        if ($offSize !== 8) {
            throw new ParseError('Unsupported BigTIFF offset size (expected 8)', 1305);
        }

        // The reserved field must remain zero (EXIF 3.0 §4.5.1; TIFF 6.0 §8 legacy rule).
        if ($reserved !== 0) {
            throw new ParseError('Bad BigTIFF header (reserved != 0)', 1306);
        }

        $this->bigTiffOffsetSize = $offSize;
    }

    /**
     * Parses an image file directory starting at the given byte offset.
     *
     * EXIF 3.0 §4.5.2 details the layout of classic and BigTIFF IFD structures,
     * including entry counts, entry sizes, and next-pointer chaining.
     *
     * @param int|UInt64|string $offset Zero-based byte offset to the IFD structure.
     */
    private function readIfd(int|UInt64|string $offset): Ifd
    {
        if ($offset instanceof UInt64) {
            // A zero pointer denotes an absent directory (EXIF 3.0 §4.5.2 Note 1),
            // so return an empty IFD structure.
            if ($offset->isZero()) {
                return new Ifd([]);
            }

            $offsetInt = $this->offsetValidator->ensureOffset($offset, 'IFD offset');
        } elseif (is_int($offset)) {
            // EXIF 3.0 §4.5.2 clarifies that null or non-positive offsets mean the
            // referenced directory is omitted.
            if ($offset <= 0) {
                return new Ifd([]);
            }

            $offsetInt = $this->offsetValidator->ensureOffset($offset, 'IFD offset');
        } else {
            // BigTIFF offsets may arrive as decimal strings (§4.5.2, BigTIFF note),
            // with zero indicating that the referenced directory is absent.
            if ($this->offsetValidator->decimalStringIsZero($offset)) {
                return new Ifd([]);
            }

            $offsetInt = $this->offsetValidator->ensureOffset($offset, 'IFD offset');
        }

        if (isset($this->ifdCache[$offsetInt])) {
            return $this->ifdCache[$offsetInt];
        }

        $this->buffer->seek($offsetInt);
        $entryCount = $this->bigTiff ? $this->binaryReader->readU64()->toInt('IFD entry count') : $this->binaryReader->readU16();

        if ($entryCount === 0) {
            throw new ParseError('IFD must contain at least one entry per TIFF 6.0.', 1307);
        }

        // Enforce maximum IFD entry count to prevent DoS
        if ($entryCount > ParserLimits::MAX_IFD_ENTRIES) {
            throw new ParseError(
                sprintf('IFD entry count %d exceeds maximum allowed %d', $entryCount, ParserLimits::MAX_IFD_ENTRIES),
                1360,
            );
        }

        // EXIF 3.0 §4.5.2 and TIFF 6.0 §8 prescribe 12-byte (classic) and 20-byte
        // (BigTIFF) directory entries and the unsigned entry count preceding them.
        $entries = [];
        for ($i = 0; $i < $entryCount; ++$i) {
            try {
                $entry = $this->readDirEntry();
            } catch (BoundsError) {
                // Postel's Law: when an individual IFD entry references data
                // beyond the TIFF boundary (common in truncated RAW files and
                // maker-note blobs), skip the entry and continue extracting
                // remaining valid metadata instead of aborting.  (GH-1549)
                continue;
            }

            $validated                = $this->ifdParser?->validateEntry($entries, $entry) ?? $entry;
            $entries[$validated->tag] = $validated;
        }

        if ($this->bigTiff) {
            $next = $this->offsetValidator->normalizeBigTiffOptionalOffset(
                $this->binaryReader->readU64(),
                'IFD next offset',
            );
        } else {
            // TIFF 6.0 §8 retains a 32-bit pointer to the next IFD; EXIF 3.0 §4.5.2
            // notes the value is zero when the chain terminates.
            $next = $this->binaryReader->readU32();
        }

        $ifd = new Ifd($entries, $next > 0 ? $next : null);

        $this->ifdCache[$offsetInt] = $ifd;

        return $ifd;
    }

    /**
     * Reads a single directory entry and returns it keyed by tag identifier.
     *
     * EXIF 3.0 §4.5.2 defines the tag, type, count, and value/offset fields mirrored
     * by this reader, aligning with the TIFF 6.0 §8 directory entry layout.
     */
    private function readDirEntry(): IfdEntry
    {
        $tag  = $this->binaryReader->readU16();
        $type = $this->binaryReader->readU16();

        if (
            !$this->bigTiff
            && in_array($type, [TiffConst::TYPE_LONG8, TiffConst::TYPE_SLONG8, TiffConst::TYPE_IFD8], true)
        ) {
            throw new ParseError('BigTIFF-only field type ' . $type . ' in classic TIFF', 1309);
        }

        $cnt = $this->bigTiff ? $this->binaryReader->readU64()->toInt('directory entry value count') : $this->binaryReader->readU32();

        $this->tagValidator->validateFixedLengthTagLayout($tag, $type, $cnt);
        $this->tagValidator->validateTypeOnlyTagLayout($tag, $type);

        // Read the Value/Offset field.  For inline values (data fits within the
        // field) the raw bytes are returned directly to avoid endianness-dependent
        // reinterpretation (TIFF 6.0 §2, EXIF 3.0 §4.5.2).
        $componentSize = $this->decoder->bytesPerComponent($type);
        $valueBytes    = $this->decoder->safeValueByteCount($componentSize, $cnt);

        [$valOrOff, $inlineBytes] = $this->binaryReader->readValueOrOffset($valueBytes);

        [$rawBytes] = $this->decoder->valueBytes($type, $cnt, $valOrOff, $inlineBytes);
        $value      = $this->decoder->decodeBytes($tag, $type, $cnt, $rawBytes);
        $value      = $this->decoder->convertUInt64Values($tag, $value);

        $value = $this->dngNormalizer->normalizeDngStringValue($tag, $type, $rawBytes, $value);

        if ($tag === ExifTag::CFA_PATTERN && is_string($value)) {
            $value = $this->dngNormalizer->decodeCfaPatternPayload($rawBytes);
        }

        if ($tag === ExifTag::MAKER_NOTE) {
            $this->makerNoteRaw = $rawBytes;
        }

        // DNGPrivateData validation skipped — non-DNG RAW formats (ARW, NEF, CR2)
        // reuse this tag with proprietary semantics that predate the DNG spec.

        $this->tagValidator->validateTagValueDomain($tag, $value);

        if ($this->dngNormalizer->isCountedImageDataTag($tag)) {
            $value = $this->dngNormalizer->normalizeCountedImageDataField($tag, $type, $cnt, $rawBytes);
        }

        return new IfdEntry($tag, $type, $cnt, $value);
    }
}
