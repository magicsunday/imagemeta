<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jxl;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;

/**
 * Defines the contract for extracting metadata from JPEG XL container streams.
 */
interface JxlParserInterface
{
    /**
     * Extracts EXIF blobs, XMP packets, and the gain map blob from the JXL container.
     *
     * @return array{0: list<string>, 1: list<string>, 2: ?string} Tuple of [EXIF blobs, XMP packets, gain map blob].
     *
     * @throws ParseError
     * @throws BoundsError
     */
    public function extract(): array;
}
