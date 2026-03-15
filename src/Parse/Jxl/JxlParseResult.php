<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jxl;

/**
 * Immutable result object returned by {@see JxlParserInterface::extract()}.
 */
final readonly class JxlParseResult
{
    /**
     * @param list<string> $exifBlobs   TIFF-EXIF blobs extracted from Exif boxes.
     * @param list<string> $xmpBlobs    XMP packets extracted from xml boxes.
     * @param string|null  $gainMapBlob Raw HDR gain map image from a hrgm box.
     */
    public function __construct(
        public array $exifBlobs,
        public array $xmpBlobs,
        public ?string $gainMapBlob,
    ) {
    }
}
