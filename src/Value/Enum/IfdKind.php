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
 * Image File Directory (IFD) type identifiers.
 *
 * EXIF 3.0 §4.6 defines the primary IFD structures used to organize EXIF metadata
 * within TIFF-based containers. This enum provides type-safe identification of the
 * major directory categories, matching the pointer tags documented in EXIF 3.0 §4.6.3
 * and EXIF 2.32 §4.6.3, alongside the legacy EXIF 2.1 §2.6.3 pointer layout.
 */
enum IfdKind: string
{
    /**
     * Primary image file directory (IFD0).
     *
     * EXIF 3.0 §4.5.2 and EXIF 2.32 §4.5.2 describe IFD0 as the root directory
     * containing primary image attributes, camera make/model, orientation, and
     * basic TIFF tags. The first IFD pointer in the TIFF header references IFD0.
     */
    case IFD0 = 'IFD0';

    /**
     * Thumbnail/secondary image directory (IFD1).
     *
     * EXIF 3.0 §4.5.2 and EXIF 2.32 §4.5.2 note that IFD1 stores thumbnail or
     * reduced-resolution image metadata. The nextIfdOffset chain from IFD0
     * typically points to IFD1 when a thumbnail is present.
     */
    case IFD1 = 'IFD1';

    /**
     * EXIF-specific attributes directory.
     *
     * EXIF 3.0 §4.6.3 Table 3 and EXIF 2.32 §4.6.3 describe the Exif IFD pointer
     * (tag 0x8769) stored in IFD0, linking to exposure, sensor, and capture-related
     * tags catalogued in EXIF 3.0 §4.6.4 Table 4.
     */
    case ExifIFD = 'ExifIFD';

    /**
     * GPS coordinate and location directory.
     *
     * EXIF 3.0 §4.6.3 Table 3 and EXIF 2.32 §4.6.3 define the GPS IFD pointer
     * (tag 0x8825) located within IFD0, referencing geographic metadata fields
     * enumerated in EXIF 3.0 §4.6.4 Table 9 and EXIF 2.1 §2.6.6.
     */
    case GPSIFD = 'GPSIFD';

    /**
     * Interoperability attributes directory.
     *
     * EXIF 3.0 §4.6.3 and EXIF 2.32 §4.6.3 specify the Interoperability IFD pointer
     * (tag 0xA005) within the Exif IFD, containing interoperability index/version
     * tags listed in EXIF 3.0 §4.6.4 and EXIF 2.1 §2.6.7.
     */
    case InteropIFD = 'InteropIFD';

    /**
     * Manufacturer-specific metadata directory.
     *
     * EXIF 3.0 §4.6.5 and EXIF 2.32 §4.6.5 describe the MakerNote tag (0x927C)
     * as a vendor-defined binary payload embedded in the Exif IFD, enabling
     * camera manufacturers to record proprietary settings and processing hints.
     */
    case MakerNotes = 'MakerNotes';

    /**
     * Auxiliary/reduced-resolution image directories.
     *
     * EXIF 3.0 §4.5.5 and EXIF 2.32 §4.5.5 reserve the SubIFDs pointer (tag 0x014A)
     * for linking additional image layers and preview images, aligning with the
     * TIFF 6.0 §8 SubIFD tag definition.
     */
    case SubIFD = 'SubIFD';
}
