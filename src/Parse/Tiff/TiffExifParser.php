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
use MagicSunday\ImageMeta\Core\BinaryReadAccessInterface;
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
use MagicSunday\ImageMeta\Model\Adobe\AdobeTag;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Iptc\IptcTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffItTag;
use MagicSunday\ImageMeta\Parse\ParserLimits;

use function count;
use function in_array;
use function is_int;
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
    private BinaryReadAccessInterface $buffer;

    private Endian $bo;

    private bool $bigTiff = false;

    private int $bigTiffOffsetSize = 8;

    private ?string $makerNoteRaw = null;

    private ?string $xmpPacketRaw = null;

    private ?string $iccProfileRaw = null;

    private ?string $iptcNaaRaw = null;

    /** @var array<int, Ifd> */
    private array $ifdCache = [];

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
        $buffer = new MemoryBuffer($tiffBlob);

        return $this->parseFromSource($buffer, $registry, $jpegContext, $embeddedContext);
    }

    /**
     * Parses EXIF from a seekable TIFF data source without pre-materializing a full blob.
     *
     * @param BinaryReadAccessInterface $tiffSource      Seekable TIFF data source.
     * @param Registry|null             $registry        Optional registry used to decode manufacturer-specific maker notes.
     * @param bool                      $jpegContext     Whether the TIFF is embedded in a JPEG context.
     * @param bool                      $embeddedContext Whether the TIFF is embedded in an ISO BMFF context.
     */
    public function parseFromStream(
        BinaryReadAccessInterface $tiffSource,
        ?Registry $registry = null,
        bool $jpegContext = false,
        bool $embeddedContext = false,
    ): ParsedExif {
        return $this->parseFromSource($tiffSource, $registry, $jpegContext, $embeddedContext);
    }

    /**
     * Parses EXIF from a seekable TIFF data source.
     */
    private function parseFromSource(
        BinaryReadAccessInterface $tiffSource,
        ?Registry $registry = null,
        bool $jpegContext = false,
        bool $embeddedContext = false,
    ): ParsedExif {
        [$byteOrderHandler, $tagDecoder]                  = $this->initializeState($tiffSource);
        [$ifd0, $exifIfd, $gpsIfd, $interopIfd, $subIfds] = $this->readPrimaryIfds($byteOrderHandler, $tagDecoder);
        [$ifd1, $additionalIfds]                          = $this->walkAdditionalIfds($ifd0);

        return $this->finalizeAndValidate(
            $ifd0,
            $ifd1,
            $exifIfd,
            $gpsIfd,
            $interopIfd,
            $registry,
            $jpegContext,
            $embeddedContext,
            $additionalIfds,
            $subIfds,
        );
    }

    /**
     * Initializes parser state and binary collaborators for a new TIFF source.
     *
     * EXIF 3.0 §4.5.1 and TIFF 6.0 §2.1 define the byte-order marker handling
     * and baseline TIFF header assumptions used during setup.
     *
     * @return array{0: TiffByteOrderHandler, 1: ExifTagDecoder}
     */
    private function initializeState(BinaryReadAccessInterface $tiffSource): array
    {
        $this->buffer = $tiffSource;
        $this->buffer->seek(0);

        $blobSize = UInt64::fromInt($this->buffer->size());

        $this->makerNoteRaw      = null;
        $this->xmpPacketRaw      = null;
        $this->iccProfileRaw     = null;
        $this->iptcNaaRaw        = null;
        $this->ifdCache          = [];
        $this->bigTiff           = false;
        $this->bigTiffOffsetSize = 8;
        $this->ifdParser ??= new IfdParser();

        $byteOrderHandler = new TiffByteOrderHandler();
        $tagDecoder       = new ExifTagDecoder();

        // byte order
        // EXIF 3.0 §4.5.1 follows TIFF 6.0 §2.1 (Image File Header) in defining the
        // "II"/"MM" byte-order signatures used for byte-order detection.
        $boSig    = $this->buffer->read(2);
        $this->bo = match ($boSig) {
            'II'    => Endian::Little,
            'MM'    => Endian::Big,
            default => throw new ParseError('Bad TIFF byte order', 1970),
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
        $this->offsetValidator = new TiffOffsetValidator($this->buffer, $blobSize);
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

        return [$byteOrderHandler, $tagDecoder];
    }

    /**
     * Reads IFD0 and pointer-derived primary directories (Exif/GPS/Interop/SubIFD).
     *
     * @return array{0: Ifd, 1: ?Ifd, 2: ?Ifd, 3: ?Ifd, 4: array<int, Ifd>}
     */
    private function readPrimaryIfds(TiffByteOrderHandler $byteOrderHandler, ExifTagDecoder $tagDecoder): array
    {
        $ifd0 = $this->readFirstIfd($byteOrderHandler, $tagDecoder);

        $this->traverser = new TiffIfdTraverser(
            $this->offsetValidator,
            fn (int|UInt64|string $offset): Ifd => $this->readIfd($offset),
        );

        $exifIfd    = $this->resolveExifIfd($ifd0);
        $interopIfd = $this->traverser->locateInteropIfd($exifIfd, $ifd0);
        $gpsIfd     = $this->resolveGpsIfd($ifd0);

        $this->tagValidator->validateGpsReferenceTagLayouts();
        $this->tagValidator->validateGpsCoordinateTagLayouts();

        $subIfds = $this->traverser->resolveSubIfds($ifd0);

        return [$ifd0, $exifIfd, $gpsIfd, $interopIfd, $subIfds];
    }

    /**
     * Resolves the Exif IFD pointer from IFD0.
     */
    private function resolveExifIfd(Ifd $ifd0): ?Ifd
    {
        $exifPointer = $ifd0->get(ExifTag::EXIF_IFD_POINTER);

        if (!$exifPointer instanceof IfdEntry) {
            return null;
        }

        $offset = $this->traverser->pointerOffset($exifPointer);

        if ($offset === null) {
            return null;
        }

        return $this->readIfd($offset);
    }

    /**
     * Resolves the GPS IFD pointer from IFD0.
     */
    private function resolveGpsIfd(Ifd $ifd0): ?Ifd
    {
        $gpsPointer = $ifd0->get(ExifTag::GPS_IFD_POINTER);

        if (!$gpsPointer instanceof IfdEntry) {
            return null;
        }

        $offset = $this->traverser->pointerOffset($gpsPointer);

        if ($offset === null) {
            return null;
        }

        return $this->readIfd($offset);
    }

    /**
     * Traverses the chained next-IFD pointers beyond IFD0.
     *
     * @return array{0: ?Ifd, 1: list<Ifd>}
     */
    private function walkAdditionalIfds(Ifd $ifd0): array
    {
        $ifd1           = null;
        $additionalIfds = [];
        $visitedOffsets = [];
        $nextOffset     = $ifd0->nextIfdOffset;

        try {
            while (($nextOffset !== null) && ($nextOffset > 0)) {
                if (isset($visitedOffsets[$nextOffset])) {
                    break;
                }

                if (count($visitedOffsets) >= ParserLimits::MAX_IFD_CHAIN_LENGTH) {
                    throw new ParseError(
                        sprintf('IFD chain length exceeds maximum allowed %d.', ParserLimits::MAX_IFD_CHAIN_LENGTH),
                        2082,
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
        } catch (BoundsError) {
            // Postel's Law: when the buffer is truncated during IFD chain
            // traversal, return whatever was successfully parsed rather
            // than discarding all metadata.
        }

        return [$ifd1, $additionalIfds];
    }

    /**
     * Runs final validation and assembles the parsed EXIF result.
     *
     * @param list<Ifd>       $additionalIfds
     * @param array<int, Ifd> $subIfds
     */
    private function finalizeAndValidate(
        Ifd $ifd0,
        ?Ifd $ifd1,
        ?Ifd $exifIfd,
        ?Ifd $gpsIfd,
        ?Ifd $interopIfd,
        ?Registry $registry,
        bool $jpegContext,
        bool $embeddedContext,
        array $additionalIfds,
        array $subIfds,
    ): ParsedExif {
        $this->validateParsedIfds($ifd0, $ifd1, $exifIfd, $jpegContext, $embeddedContext, $additionalIfds);

        if ((!$interopIfd instanceof Ifd) && ($additionalIfds !== [])) {
            $interopIfd = $this->traverser->locateInteropIfd(...$additionalIfds);
        }

        $makerNoteDispatcher = new MakerNoteDispatcher();
        $makerNotes          = $makerNoteDispatcher->resolve($this->makerNoteRaw, $registry, $ifd0, $exifIfd);

        return new ParsedExif(
            $ifd0,
            $exifIfd,
            $gpsIfd,
            $interopIfd,
            $ifd1,
            $makerNotes,
            $additionalIfds,
            $subIfds,
            xmpPacketRaw: $this->xmpPacketRaw,
            iccProfileRaw: $this->iccProfileRaw,
            iptcNaaRaw: $this->iptcNaaRaw,
        );
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
                throw new ParseError('missing 0th IFD offset', 1971);
            }

            return $this->readIfd($firstIfd);
        }

        if ($magic === TiffConst::MAGIC_CLASSIC) {
            $this->bigTiff = false;

            $firstIfd = $this->binaryReader->readU32();

            if ($firstIfd < TiffConst::HEADER_SIZE_CLASSIC) {
                throw new ParseError('missing 0th IFD offset', 1972);
            }

            return $this->readIfd($firstIfd);
        }

        throw new ParseError(
            sprintf('Unknown TIFF magic 0x%04X', $magic),
            1973,
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
            throw new ParseError('Bad BigTIFF header (reserved != 0)', 1974);
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
        // A zero or non-positive pointer denotes an absent directory
        // (EXIF 3.0 §4.5.2 Note 1), so return an empty IFD structure.
        if ($this->isAbsentIfdOffset($offset)) {
            return new Ifd([]);
        }

        $offsetInt = $this->offsetValidator->ensureOffset($offset, 'IFD offset');

        if (isset($this->ifdCache[$offsetInt])) {
            return $this->ifdCache[$offsetInt];
        }

        $this->buffer->seek($offsetInt);
        $entryCount = $this->bigTiff ? $this->binaryReader->readU64()->toInt('IFD entry count') : $this->binaryReader->readU16();

        // Postel's Law: treat zero-entry IFD as empty.
        if ($entryCount === 0) {
            return new Ifd([]);
        }

        // Keep the hard 10k guard to prevent pathological memory/CPU usage.
        if ($entryCount > ParserLimits::MAX_IFD_ENTRIES) {
            if ($this->bigTiff) {
                throw new ParseError(
                    sprintf('IFD entry count %d exceeds maximum allowed %d', $entryCount, ParserLimits::MAX_IFD_ENTRIES),
                    1360,
                );
            }

            // Postel's Law: classic TIFF files in the wild sometimes contain
            // corrupted IFD entry counts (e.g. 10825/25600/49152). Skip that
            // directory and continue parsing remaining metadata sources.
            $ifd                        = new Ifd([]);
            $this->ifdCache[$offsetInt] = $ifd;

            return $ifd;
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
                // remaining valid metadata instead of aborting.
                continue;
            }

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            $validated                = $this->ifdParser?->validateEntry($entries, $entry) ?? $entry;
            $entries[$validated->tag] = $validated;
        }

        try {
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
        } catch (BoundsError) {
            // Postel's Law: when the buffer is truncated before the
            // next-IFD pointer, return the entries parsed so far
            // instead of discarding the entire directory.
            $next = 0;
        }

        $ifd = new Ifd($entries, $next > 0 ? $next : null);

        $this->ifdCache[$offsetInt] = $ifd;

        return $ifd;
    }

    /**
     * Determines whether the given offset represents an absent IFD.
     *
     * A zero UInt64, a non-positive integer, or a decimal-string zero all signal that
     * the referenced directory does not exist (EXIF 3.0 §4.5.2 Note 1).
     *
     * @param int|UInt64|string $offset Candidate IFD offset.
     */
    private function isAbsentIfdOffset(int|UInt64|string $offset): bool
    {
        if ($offset instanceof UInt64) {
            return $offset->isZero();
        }

        if (is_int($offset)) {
            return $offset <= 0;
        }

        return $this->offsetValidator->decimalStringIsZero($offset);
    }

    /**
     * Reads a single directory entry and returns it keyed by tag identifier.
     *
     * EXIF 3.0 §4.5.2 defines the tag, type, count, and value/offset fields mirrored
     * by this reader, aligning with the TIFF 6.0 §8 directory entry layout.
     */
    private function readDirEntry(): ?IfdEntry
    {
        $tag  = $this->binaryReader->readU16();
        $type = $this->binaryReader->readU16();

        $cnt = $this->bigTiff ? $this->binaryReader->readU64()->toInt('directory entry value count') : $this->binaryReader->readU32();

        if (!$this->bigTiff && in_array($type, [TiffConst::TYPE_LONG8, TiffConst::TYPE_SLONG8, TiffConst::TYPE_IFD8], true)) {
            $this->binaryReader->readValueOrOffset(0);

            return null;
        }

        $this->tagValidator->validateFixedLengthTagLayout();
        $this->tagValidator->validateTypeOnlyTagLayout();

        // Read the Value/Offset field.  For inline values (data fits within the
        // field) the raw bytes are returned directly to avoid endianness-dependent
        // reinterpretation (TIFF 6.0 §2, EXIF 3.0 §4.5.2).
        $componentSize = $this->decoder->bytesPerComponent($type);

        if ($componentSize === null) {
            $this->binaryReader->readValueOrOffset(0);

            return null;
        }

        $valueBytes = $this->decoder->safeValueByteCount($componentSize, $cnt);

        [$valOrOff, $inlineBytes] = $this->binaryReader->readValueOrOffset($valueBytes);

        [$rawBytes] = $this->decoder->valueBytes($type, $cnt, $valOrOff, $inlineBytes, $componentSize, $valueBytes);
        $value      = $this->decoder->decodeBytes($tag, $type, $cnt, $rawBytes, $componentSize, $valueBytes);
        $value      = $this->decoder->convertUInt64Values($tag, $value);

        $value = $this->dngNormalizer->normalizeDngStringValue($tag, $type, $rawBytes, $value);

        if (($tag === ExifTag::CFA_PATTERN) && is_string($value)) {
            $value = $this->dngNormalizer->decodeCfaPatternPayload($rawBytes);
        }

        if ($tag === ExifTag::MAKER_NOTE) {
            $this->makerNoteRaw = $rawBytes;
        }

        // Adobe XMP Part 3 — tag 700 (0x02BC) carries a UTF-8 XMP/RDF packet
        if ($tag === AdobeTag::XMP_PACKET) {
            $this->xmpPacketRaw = $rawBytes;
        }

        // TIFF 6.0 §Appendix (TIFF/IT); ICC.1 — tag 34675 (0x8773) carries an ICC profile
        if ($tag === TiffItTag::ICC_PROFILE) {
            $this->iccProfileRaw = $rawBytes;
        }

        // IPTC-IIM — tag 33723 (0x83BB) carries IPTC/NAA metadata
        if ($tag === IptcTag::IPTC_NAA) {
            $this->iptcNaaRaw = $rawBytes;
        }

        // DNGPrivateData validation skipped — non-DNG RAW formats (ARW, NEF, CR2)
        // reuse this tag with proprietary semantics that predate the DNG spec.

        $this->tagValidator->validateTagValueDomain($tag, $value);

        if ($this->dngNormalizer->isCountedImageDataTag($tag)) {
            $value = $this->dngNormalizer->normalizeCountedImageDataField($tag, $type, $cnt, $rawBytes, $componentSize, $valueBytes);
        }

        return new IfdEntry($tag, $type, $cnt, $value);
    }
}
