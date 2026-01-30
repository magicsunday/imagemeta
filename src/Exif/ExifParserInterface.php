<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif;

use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;

/**
 * Contract for EXIF readers that parse TIFF-structured metadata blobs.
 */
interface ExifParserInterface
{
    /**
     * Parses EXIF payloads encoded as classic TIFF or BigTIFF byte streams.
     *
     * @param string        $tiffBlob Raw EXIF payload starting with the TIFF header.
     * @param Registry|null $registry Optional maker-notes registry consulted for vendor data.
     *
     * @return ParsedExif Parsed EXIF document representing the decoded directory tree.
     */
    public function parseFromBlob(string $tiffBlob, ?Registry $registry = null): ParsedExif;
}
