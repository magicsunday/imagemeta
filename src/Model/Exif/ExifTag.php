<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

/**
 * Centralised list of EXIF tag identifiers used throughout the library.
 */
final readonly class ExifTag
{
    // Image file directory (IFD0)
    public const int MAKE        = 0x010F;
    public const int MODEL       = 0x0110;
    public const int ORIENTATION = 0x0112;

    // Pointer tags
    public const int EXIF_IFD_POINTER             = 0x8769;
    public const int GPS_IFD_POINTER              = 0x8825;
    public const int INTEROPERABILITY_IFD_POINTER = 0xA005;

    // EXIF sub IFD
    public const int EXPOSURE_TIME            = 0x829A;
    public const int F_NUMBER                 = 0x829D;
    public const int PHOTOGRAPHIC_SENSITIVITY = 0x8827;
    public const int ISO_SPEED                = 0x8833;
    public const int DATETIME_ORIGINAL        = 0x9003;
    public const int OFFSET_TIME_ORIGINAL     = 0x9011;
    public const int FOCAL_LENGTH             = 0x920A;
    public const int LENS_MODEL               = 0xA434;

    // GPS sub IFD
    public const int GPS_LATITUDE_REF  = 0x0001;
    public const int GPS_LATITUDE      = 0x0002;
    public const int GPS_LONGITUDE_REF = 0x0003;
    public const int GPS_LONGITUDE     = 0x0004;
    public const int GPS_ALTITUDE_REF  = 0x0005;
    public const int GPS_ALTITUDE      = 0x0006;

    private function __construct()
    {
    }
}
