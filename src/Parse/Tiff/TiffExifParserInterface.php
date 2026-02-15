<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\MakerNotes\Registry;

/**
 * Defines the contract for parsing TIFF-encoded EXIF payloads.
 */
interface TiffExifParserInterface
{
    /**
     * Parses a TIFF/EXIF blob and returns the decoded EXIF document.
     */
    public function parseFromBlob(string $tiffBlob, ?Registry $registry = null, bool $jpegContext = false): ParsedExif;
}
