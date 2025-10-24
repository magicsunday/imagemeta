<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

/**
 * Enumerates TIFF/EXIF compression schemes.
 */
enum Compression: int
{
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

    /**
     * Converts a raw compression id to the backed enum value.
     */
    public static function fromExifValue(int|string|null $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = is_string($value) ? (int) $value : $value;

        return self::tryFrom($intValue);
    }
}
