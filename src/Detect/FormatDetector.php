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

use const SEEK_CUR;

/**
 * Detects the container type of a binary stream based on magic numbers.
 */
final class FormatDetector
{
    /**
     * Box types that can appear before the main ISO BMFF payload.
     *
     * @var array<string, bool>
     */
    private const array ISO_BMFF_PADDING_BOXES = [
        'wide' => true,
        'free' => true,
        'skip' => true,
    ];

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

    private const int ISO_BMFF_MAX_BOX_SCAN = 4;

    /**
     * Inspects the leading bytes of the stream and returns the detected container type.
     *
     * @param Stream $stream seekable stream positioned at an arbitrary offset
     *
     * @return ContainerType detected container format
     *
     * @throws ParseError when the signature cannot be read or does not match a known container
     */
    public static function detect(Stream $stream): ContainerType
    {
        try {
            $stream->seek(0);
            $magic2 = $stream->read(2);
        } catch (BoundsError $exception) {
            throw new ParseError('Unable to read container signature', 0, $exception);
        }

        if ($magic2 === "\xFF\xD8") {
            return ContainerType::JPEG;
        }

        try {
            if (self::looksLikeIsoBmff($stream)) {
                return ContainerType::ISOBMFF;
            }
        } catch (BoundsError $exception) {
            throw new ParseError('Unable to read container signature', 0, $exception);
        }

        // a few HEIC files may start with 0 size+ftyp; we already cover 'ftyp' at [4..8]
        throw new ParseError('Unsupported or unknown container');
    }

    /**
     * Detects ISO BMFF signatures by scanning a handful of top-level boxes.
     */
    private static function looksLikeIsoBmff(Stream $stream): bool
    {
        $stream->seek(0);

        for ($scan = 0; $scan < self::ISO_BMFF_MAX_BOX_SCAN; ++$scan) {
            $boxSize = $stream->readU32BE();
            $boxType = $stream->read(4);

            if (isset(self::ISO_BMFF_SIGNATURE_BOXES[$boxType])) {
                return true;
            }

            if (!isset(self::ISO_BMFF_PADDING_BOXES[$boxType])) {
                return false;
            }

            $headerSize = 8;
            $size       = $boxSize;

            if ($boxSize === 1) {
                $size       = $stream->readU64BE()->toInt('ISOBMFF 64-bit box size');
                $headerSize = 16;
            }

            if ($size === 0 || $size < $headerSize) {
                return false;
            }

            $skip = $size - $headerSize;
            if ($skip > 0) {
                $stream->seek($skip, SEEK_CUR);
            }
        }

        return false;
    }
}
