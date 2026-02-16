<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Detect;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;

use function ord;
use function unpack;

use const SEEK_CUR;

/**
 * Detects the container type of a binary stream based on magic numbers.
 */
final readonly class FormatDetector
{
    /**
     * Box types that indicate an ISO BMFF / QuickTime container.
     *
     * Note: 'uuid' is a generic user-extension box and is not treated as a
     * definitive signature without additional structural evidence.
     *
     * @var array<string, bool>
     */
    private const array ISO_BMFF_SIGNATURE_BOXES = [
        'ftyp' => true,
        'moov' => true,
        'styp' => true,
        'meta' => true,
        'moof' => true,
        'mfra' => true,
    ];

    /**
     * Padding box types that may be safely skipped during detection.
     *
     * @var array<string, bool>
     */
    private const array PADDING_BOXES = [
        'free' => true,
        'skip' => true,
        'wide' => true,
        'mdat' => true,
        'uuid' => true,
    ];

    /**
     * Maximum byte budget for ISO BMFF detection scanning.
     */
    private const int ISO_BMFF_MAX_SCAN_BYTES = 65536;

    /**
     * Inspects the leading bytes of the stream and returns the detected container type.
     *
     * @param Stream $stream seekable stream positioned at an arbitrary offset
     *
     * @return ContainerType detected container format
     *
     * @throws ParseError when the signature cannot be read or does not match a known container
     */
    public function detect(Stream $stream): ContainerType
    {
        try {
            $stream->seek(0);
            $magic2 = $stream->read(2);
        } catch (BoundsError $exception) {
            throw new ParseError('Unable to read container signature', 1031, $exception);
        }

        // Require at least one plausible marker after SOI
        if ($magic2 === "\xFF\xD8") {
            return $this->detectJpeg($stream);
        }

        // Bare JPEG XL codestream: 0xFF 0x0A
        if ($magic2 === "\xFF\x0A") {
            return ContainerType::JXL;
        }

        if ($this->looksLikeTiff($stream, $magic2)) {
            return ContainerType::TIFF;
        }

        // JPEG XL ISO BMFF container: starts with a 12-byte JXL signature box
        if ($this->looksLikeJxl($stream)) {
            return ContainerType::JXL;
        }

        try {
            if ($this->looksLikeIsoBmff($stream)) {
                return ContainerType::ISOBMFF;
            }
        } catch (BoundsError $exception) {
            throw new ParseError('Unable to read container signature', 1032, $exception);
        }

        // a few HEIC files may start with 0 size+ftyp; we already cover 'ftyp' at [4..8]
        throw new ParseError('Unsupported or unknown container', 1033);
    }

    /**
     * Validates JPEG marker structure beyond just SOI.
     *
     * @throws ParseError when the stream has SOI but no valid marker structure
     */
    private function detectJpeg(Stream $stream): ContainerType
    {
        try {
            $markerPrefix = $stream->read(1);
            $markerCode   = $stream->read(1);
        } catch (BoundsError) {
            throw new ParseError('JPEG stream too short: no marker after SOI', 1439);
        }

        if ($markerPrefix !== "\xFF") {
            throw new ParseError('JPEG stream has no valid marker after SOI', 1440);
        }

        $code = ord($markerCode);
        if ($code === 0x00 || $code === 0xFF) {
            throw new ParseError('JPEG stream has no valid marker after SOI', 1440);
        }

        return ContainerType::JPEG;
    }

    /**
     * Checks whether the stream starts with the 12-byte JPEG XL signature box.
     *
     * ISO/IEC 18181-2: the JXL container begins with a box of type 'JXL '
     * (size=12) containing the two-byte content 0x0D0A 0x870A.
     */
    private function looksLikeJxl(Stream $stream): bool
    {
        try {
            $stream->seek(0);
            $header = $stream->read(12);
        } catch (BoundsError) {
            return false;
        }

        return $header === "\x00\x00\x00\x0C\x4A\x58\x4C\x20\x0D\x0A\x87\x0A";
    }

    /**
     * Checks whether the first two bytes are a TIFF byte-order mark and the following
     * two bytes contain a classic TIFF (0x002A) or BigTIFF (0x002B) magic number.
     */
    private function looksLikeTiff(Stream $stream, string $magic2): bool
    {
        if ($magic2 !== 'II' && $magic2 !== 'MM') {
            return false;
        }

        try {
            $rawMagic = $stream->read(2);
        } catch (BoundsError) {
            return false;
        }

        /** @var array{1: int}|false $unpacked */
        $unpacked = unpack($magic2 === 'II' ? 'v1' : 'n1', $rawMagic);

        if ($unpacked === false) {
            return false;
        }

        return $unpacked[1] === 0x002A || $unpacked[1] === 0x002B;
    }

    /**
     * Detects ISO BMFF signatures by scanning top-level boxes with a byte budget.
     *
     * Tolerates unknown box types by skipping them via their declared size.
     * Validates box header semantics before accepting signature boxes.
     */
    private function looksLikeIsoBmff(Stream $stream): bool
    {
        $stream->seek(0);
        $scanned = 0;

        while ($scanned < self::ISO_BMFF_MAX_SCAN_BYTES) {
            try {
                $boxSize = $stream->readU32BE();
                $boxType = $stream->read(4);
            } catch (BoundsError) {
                return false;
            }

            $headerSize = 8;
            $size       = $boxSize;

            if ($boxSize === 1) {
                try {
                    $size = $stream->readU64BE()->toInt('ISOBMFF 64-bit box size');
                } catch (BoundsError) {
                    return false;
                }

                $headerSize = 16;
            }

            // Validate box size covers at least the header
            if ($size !== 0 && $size < $headerSize) {
                return false;
            }

            // uuid boxes need at least 24 bytes (header + 16-byte usertype)
            if ($boxType === 'uuid' && $size !== 0 && $size < 24) {
                return false;
            }

            // Declared 32-bit/64-bit box sizes must stay within stream bounds
            if ($size !== 0) {
                $boxStart           = $stream->tell() - $headerSize;
                $remainingBoxStream = $stream->size() - $boxStart;
                if ($size > $remainingBoxStream) {
                    return false;
                }
            }

            // Signature boxes must have a well-defined size
            if ($size !== 0 && isset(self::ISO_BMFF_SIGNATURE_BOXES[$boxType])) {
                // ftyp/styp require at least 8 payload bytes and 4-byte brand alignment
                if ($boxType === 'ftyp' || $boxType === 'styp') {
                    $payload = $size - $headerSize;
                    if ($payload < 8 || ($payload - 8) % 4 !== 0) {
                        return false;
                    }
                }

                return true;
            }

            // Only skip known padding boxes; reject unknown non-signature types
            if (!isset(self::PADDING_BOXES[$boxType])) {
                return false;
            }

            // size == 0 means box extends to EOF — cannot skip
            if ($size === 0) {
                return false;
            }

            $skip = $size - $headerSize;
            if ($skip > 0) {
                try {
                    $stream->seek($skip, SEEK_CUR);
                } catch (BoundsError) {
                    return false;
                }
            }

            // Count only header bytes examined, not payload bytes skipped
            // via seek().  A seek() over a large mdat is essentially free;
            // the budget exists to bound actual I/O and parsing cost.
            $scanned += $headerSize;
        }

        return false;
    }
}
