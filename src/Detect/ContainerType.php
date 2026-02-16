<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Detect;

/**
 * Enumerates supported top-level container formats for image metadata extraction.
 */
enum ContainerType
{
    /** JPEG file interchange format. */
    case JPEG;

    /** ISO Base Media File Format such as HEIC, AVIF, MP4, or MOV. */
    case ISOBMFF;

    /** TIFF-based format such as TIFF, DNG, NEF, or ARW. */
    case TIFF;

    /** JPEG XL container or bare codestream. */
    case JXL;
}
