<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use MagicSunday\ImageMeta\Exif\Support\EnumFromIntStringNullable;

/**
 * Enumerates TIFF/EXIF compression schemes catalogued in
 * EXIF 2.32 §4.6.2 and EXIF 3.0 §4.6.2 (image data structure),
 * which reference TIFF 6.0 §8 for baseline definitions.
 */
enum Compression: int
{
    use EnumFromIntStringNullable;

    case UNCOMPRESSED               = 1;
    case CCITT_MODIFIED_HUFFMAN_RLE = 2;
    case CCITT_T4_GROUP3_FAX        = 3;
    case CCITT_T6_GROUP4_FAX        = 4;
    case LZW                        = 5;
    case JPEG_OLD_STYLE             = 6;
    case JPEG                       = 7;
    case ADOBE_DEFLATE              = 8;
    case PACKBITS                   = 32773;
    case THUNDERSCAN                = 32809;
    case JPEG_2000                  = 34712;
    case LOSSY_JPEG                 = 34892;

}
