<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;

/**
 * Enumerates the TIFF/EXIF compression schemes recorded for the Compression tag
 * in EXIF 3.0 §4.6.5.1.4 (image configuration) and the baseline assignments
 * from TIFF 6.0 §8.
 *
 * The EXIF specification omits the Compression tag for primary JPEG images; for
 * JPEG thumbnails, the tag shall be recorded with value 6 (JPEG compression).
 */
enum Compression: int
{
    use EnumFromIntStringNullable;

    case UNCOMPRESSED               = 1;
    case CCITT_MODIFIED_HUFFMAN_RLE = 2;
    case CCITT_T4_GROUP3_FAX        = 3;
    case CCITT_T6_GROUP4_FAX        = 4;
    case LZW                        = 5;
    case JPEG                       = 6;
    case JPEG_NEW_STYLE             = 7;
    case ADOBE_DEFLATE              = 8;
    case PACKBITS                   = 32773;
    case THUNDERSCAN                = 32809;
    case JPEG_2000                  = 34712;
    case LOSSY_JPEG                 = 34892;
    case JPEG_XL                    = 52546;
}
