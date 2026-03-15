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
     * @throws ParseError
     * @throws BoundsError
     */
    public function extract(): JxlParseResult;
}
