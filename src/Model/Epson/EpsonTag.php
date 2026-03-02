<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Epson;

/**
 * Epson-defined TIFF/EXIF tag identifiers.
 *
 * Epson Print Image Matching (PIM) embeds proprietary binary data for
 * colour-accurate printing on compatible Epson printers.
 */
final readonly class EpsonTag
{
    /**
     * Epson Print Image Matching binary data; proprietary vendor extension.
     */
    public const int PRINT_IMAGE_MATCHING = 0xC4A5;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
