<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Contract;

use MagicSunday\ImageMeta\Core\BinaryReadAccessInterface;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\MakerNotes\Registry;

/**
 * Defines the contract for parsing TIFF-encoded EXIF payloads.
 */
interface TiffExifParserInterface
{
    /**
     * Parses a TIFF/EXIF blob and returns the decoded EXIF document.
     *
     * @throws ParseError
     * @throws BoundsError
     */
    public function parseFromBlob(string $tiffBlob, ?Registry $registry = null, bool $jpegContext = false, bool $embeddedContext = false): ParsedExif;

    /**
     * Parses EXIF from a seekable TIFF data source without pre-materializing a full blob.
     *
     * @throws ParseError
     * @throws BoundsError
     */
    public function parseFromStream(BinaryReadAccessInterface $tiffSource, ?Registry $registry = null, bool $jpegContext = false, bool $embeddedContext = false): ParsedExif;
}
