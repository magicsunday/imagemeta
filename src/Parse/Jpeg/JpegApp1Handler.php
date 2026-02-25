<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function array_key_exists;
use function sha1;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Routes APP1 payloads to Exif blob storage, standard XMP deduplication,
 * or the ExtendedXMP assembler.
 *
 * EXIF 3.0 §4.7.2 mandates that Exif data inside APP1 begins with "Exif\0\0"
 * followed by the TIFF header defined in §4.5.
 */
final class JpegApp1Handler
{
    /**
     * Signatures identifying metadata-bearing APP1 segments.
     */
    private const string EXIF_SIGNATURE = "Exif\0\0";

    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    /** @var list<string> */
    private array $exifBlobs = [];

    private ?int $firstExifApp1Offset = null;

    /** @var list<string> */
    private array $xmpPackets = [];

    /** @var array<string, bool> */
    private array $xmpPacketHashes = [];

    private ExtendedXmpAssembler $extendedXmpAssembler;

    /**
     * @param int $extendedXmpGuidLength ExtendedXMP GUID length from config.
     * @param int $maxExtendedXmpSize    Maximum cumulative ExtendedXMP payload size in bytes.
     */
    public function __construct(
        private readonly int $extendedXmpGuidLength,
        private readonly int $maxExtendedXmpSize = 10_485_760,
    ) {
        $this->extendedXmpAssembler = new ExtendedXmpAssembler(
            $extendedXmpGuidLength,
            $this->appendXmpPacket(...),
            $this->maxExtendedXmpSize,
        );
    }

    /**
     * Processes one APP1 payload for Exif and XMP signatures.
     *
     * @param string $payload Raw APP1 payload including leading signature.
     * @param int    $offset  Offset in the stream where the marker begins.
     */
    public function handleApp1(string $payload, int $offset): void
    {
        if (str_starts_with($payload, self::EXIF_SIGNATURE)) {
            if ($this->firstExifApp1Offset !== null) {
                return;
            }

            $this->firstExifApp1Offset = $offset;

            // EXIF 3.0 §4.7.2 requires APP1 Exif data to start with "Exif\0\0"
            // followed by a valid TIFF header (byte order + magic number).
            $tiffData = substr($payload, strlen(self::EXIF_SIGNATURE));
            $this->validateApp1TiffHeader($tiffData);
            $this->exifBlobs[] = $tiffData;
        } elseif (str_starts_with($payload, self::XMP_SIGNATURE)) {
            $packet = substr($payload, strlen(self::XMP_SIGNATURE));
            $guid   = $this->extendedXmpAssembler->extractGuidFromPacket($packet, $offset);

            if ($guid !== null) {
                $this->extendedXmpAssembler->addBasePacket($packet, $guid, $offset);

                return;
            }

            $this->appendXmpPacket($packet);
        } elseif (str_starts_with($payload, ExtendedXmpAssembler::SIGNATURE)) {
            $this->extendedXmpAssembler->handleSegment($payload, $offset);
        }
    }

    /**
     * Stores an XMP packet while preserving first-seen order and deduplicating by hash.
     *
     * @param string $packet Raw XMP packet body.
     */
    public function appendXmpPacket(string $packet): void
    {
        $hash = sha1($packet);

        if (!array_key_exists($hash, $this->xmpPacketHashes)) {
            $this->xmpPacketHashes[$hash] = true;
            $this->xmpPackets[]           = $packet;
        }
    }

    /**
     * Finalises extended XMP assembly.
     */
    public function finalise(): void
    {
        $this->extendedXmpAssembler->finalise();
    }

    /**
     * Returns all discovered EXIF payloads in the order they appeared.
     *
     * @return list<string>
     */
    public function getExifBlobs(): array
    {
        return $this->exifBlobs;
    }

    /**
     * Returns the offset of the first Exif APP1 marker, or null if none was found.
     */
    public function getFirstExifApp1Offset(): ?int
    {
        return $this->firstExifApp1Offset;
    }

    /**
     * Returns all discovered XMP packets in the order they appeared.
     *
     * @return list<string>
     */
    public function getXmpPackets(): array
    {
        return $this->xmpPackets;
    }

    /**
     * Resets all APP1 state for a fresh parse pass.
     */
    public function reset(): void
    {
        $this->exifBlobs            = [];
        $this->firstExifApp1Offset  = null;
        $this->xmpPackets           = [];
        $this->xmpPacketHashes      = [];
        $this->extendedXmpAssembler = new ExtendedXmpAssembler(
            $this->extendedXmpGuidLength,
            $this->appendXmpPacket(...),
            $this->maxExtendedXmpSize,
        );
    }

    /**
     * Validates the TIFF header region after the Exif signature.
     *
     * EXIF 3.0 §4.7.2 requires APP1 Exif payload to carry a structurally valid TIFF header:
     * byte-order marker (II or MM) followed by TIFF magic (0x002A) or BigTIFF magic (0x002B).
     *
     * @param string $tiffData Raw bytes after the "Exif\0\0" signature.
     */
    private function validateApp1TiffHeader(string $tiffData): void
    {
        // Minimum TIFF header is 4 bytes: 2 byte-order + 2 magic
        PayloadGuard::ensureMinimumLength($tiffData, 4, 'APP1 Exif payload', 1400);

        $byteOrder = substr($tiffData, 0, 2);
        if ($byteOrder !== 'II' && $byteOrder !== 'MM') {
            throw new ParseError('APP1 Exif TIFF header has invalid byte order', 1401);
        }

        $format = $byteOrder === 'II' ? 'v' : 'n';
        $magic  = Unpack::int($format, substr($tiffData, 2, 2), 'APP1 Exif TIFF magic number');

        if ($magic !== 0x002A && $magic !== 0x002B) {
            throw new ParseError('APP1 Exif TIFF header has invalid magic number', 1402);
        }
    }
}
