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

use const SEEK_CUR;

/**
 * Detects the container type of a binary stream based on magic numbers.
 */
final readonly class FormatDetector
{
    /**
     * Box types that indicate an ISO BMFF / QuickTime container.
     *
     * @var array<string, bool>
     */
    private const array ISO_BMFF_SIGNATURE_BOXES = [
        'ftyp' => true,
        'moov' => true,
        'mdat' => true,
        'styp' => true,
        'meta' => true,
        'moof' => true,
        'mfra' => true,
        'uuid' => true,
    ];

    /**
     * GH-969: Maximum byte budget for ISO BMFF detection scanning.
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

        // GH-983: require at least one plausible marker after SOI
        if ($magic2 === "\xFF\xD8") {
            return $this->detectJpeg($stream);
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
     * GH-983: Validates JPEG marker structure beyond just SOI.
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
     * GH-969, GH-970: Detects ISO BMFF signatures by scanning top-level boxes with a byte budget.
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

            // GH-970: validate box size covers at least the header
            if ($size !== 0 && $size < $headerSize) {
                return false;
            }

            // GH-970: uuid boxes need at least 24 bytes (header + 16-byte usertype)
            if ($boxType === 'uuid' && $size !== 0 && $size < 24) {
                return false;
            }

            if (isset(self::ISO_BMFF_SIGNATURE_BOXES[$boxType])) {
                return true;
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

            $scanned += $size;
        }

        return false;
    }
}
