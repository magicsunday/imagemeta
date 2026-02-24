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

    case Uncompressed            = 1;
    case CcittModifiedHuffmanRle = 2;
    case CcittT4Group3Fax        = 3;
    case CcittT6Group4Fax        = 4;
    case Lzw                     = 5;
    case Jpeg                    = 6;
    case JpegNewStyle            = 7;
    case AdobeDeflate            = 8;
    case Packbits                = 32773;
    case Thunderscan             = 32809;
    case Jpeg2000                = 34712;
    case LossyJpeg               = 34892;
    case JpegXl                  = 52546;
}
