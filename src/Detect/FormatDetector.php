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

/**
 * Detects the container type of a binary stream based on magic numbers.
 */
final class FormatDetector
{
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
            $stream->seek(4);
            $brand = $stream->read(4); // 'ftyp'
        } catch (BoundsError $exception) {
            throw new ParseError('Unable to read container signature', 0, $exception);
        }
        if ($brand === 'ftyp') {
            return ContainerType::ISOBMFF;
        }
        // a few HEIC files may start with 0 size+ftyp; we already cover 'ftyp' at [4..8]
        throw new ParseError('Unsupported or unknown container');
    }
}
