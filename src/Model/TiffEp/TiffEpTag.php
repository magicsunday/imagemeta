<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\TiffEp;

/**
 * TIFF/EP tag identifiers.
 *
 * Tags defined by the TIFF/EP standard (ISO 12234-2) for electronic still-picture
 * cameras. These tags pre-date or extend beyond the EXIF 3.0 specification.
 */
final readonly class TiffEpTag
{
    /**
     * Software or firmware that generated the image; TIFF/EP §5.6.1.
     */
    public const int PROCESSING_SOFTWARE = 0x000B;

    /**
     * Time-zone offset(s) of the ModifyDate relative to UTC; deprecated pre-EXIF 2.3 tag.
     */
    public const int TIME_ZONE_OFFSET    = 0x882A;

    /**
     * Image number assigned by the camera; deprecated pre-EXIF 2.3 tag.
     */
    public const int IMAGE_NUMBER        = 0x9211;

    /**
     * TIFF/EP standard identification version; TIFF/EP §5.3.
     */
    public const int TIFF_EP_STANDARD_ID = 0x9216;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
