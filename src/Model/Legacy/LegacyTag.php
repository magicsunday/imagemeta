<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Legacy;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;

/**
 * Legacy EXIF tag identifiers retained for backwards compatibility.
 *
 * These tags are either:
 * - Deprecated names from older EXIF versions (aliased to current EXIF 3.0 tags)
 * - Microsoft pre-EXIF 3.0 extensions
 * - Removed from newer specifications but needed for old file compatibility
 *
 * New code should use the current EXIF 3.0 tags from ExifTag instead.
 */
final readonly class LegacyTag
{
    /**
     * Legacy EXIF 2.x tag that stored the document name within IFD0.
     *
     * Pre-EXIF 3.0 usage. Modern images use ImageDescription or ImageTitle.
     */
    public const int IMAGE_TITLE_LEGACY = 0x0320;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * This is an alias for PHOTOGRAPHIC_SENSITIVITY (0x8827).
     * EXIF 2.x called this tag "ISOSpeedRatings", renamed in EXIF 3.0.
     *
     * @see ExifTag::PHOTOGRAPHIC_SENSITIVITY
     */
    public const int ISO_SPEED_RATINGS_LEGACY = 0x8827;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * Aliased to DATETIME (0x0132). EXIF 3.0 calls this ModifyDate.
     *
     * @see ExifTag::DATETIME
     * @see ExifTag::MODIFY_DATE
     */
    public const int DATETIME = 0x0132;

    /**
     * Legacy Microsoft EXIF tag that exposed the photographer credit prior to EXIF 3.0.
     *
     * Superseded by ExifTag::PHOTOGRAPHER (0xA437) in EXIF 3.0.
     */
    public const int PHOTOGRAPHER_LEGACY = 0xE92D;

    /**
     * Legacy Microsoft EXIF tag that exposed the image editor credit prior to EXIF 3.0.
     *
     * Superseded by ExifTag::IMAGE_EDITOR (0xA438) in EXIF 3.0.
     */
    public const int IMAGE_EDITOR_LEGACY = 0xE92E;

    /**
     * Legacy EXIF 2.x tag that stored the dedicated camera firmware version.
     *
     * WARNING: This constant has the same hex value (0xA436) as IMAGE_TITLE in EXIF 3.0!
     * EXIF 3.0 reassigned this tag ID. Use ExifTag::CAMERA_FIRMWARE (0xA439) instead.
     *
     * @deprecated Use ExifTag::CAMERA_FIRMWARE
     */
    public const int CAMERA_FIRMWARE_VERSION_LEGACY = 0xA436;

    /**
     * Legacy EXIF 2.x tag that stored the raw developing software version.
     *
     * WARNING: This constant has the same hex value (0xA439) as CAMERA_FIRMWARE in EXIF 3.0!
     * Use ExifTag::RAW_DEVELOPING_SOFTWARE (0xA43A) for the current tag.
     *
     * @deprecated Use ExifTag::RAW_DEVELOPING_SOFTWARE
     */
    public const int RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY = 0xA439;

    /**
     * Legacy EXIF 2.x tag that stored the image editing software version.
     *
     * WARNING: This constant has the same hex value (0xA43B) as IMAGE_EDITING_SOFTWARE in EXIF 3.0!
     * The tag was renamed/repurposed between EXIF versions.
     *
     * @deprecated Use ExifTag::IMAGE_EDITING_SOFTWARE
     */
    public const int IMAGE_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43B;

    /**
     * Legacy EXIF 2.x tag that stored the metadata editing software version.
     *
     * WARNING: This constant has the same hex value (0xA43C) as METADATA_EDITING_SOFTWARE in EXIF 3.0!
     * The tag was renamed/repurposed between EXIF versions.
     *
     * @deprecated Use ExifTag::METADATA_EDITING_SOFTWARE
     */
    public const int METADATA_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43C;

    /**
     * Legacy Microsoft EXIF tag that stored the camera firmware string.
     *
     * Pre-EXIF 3.0 Microsoft extension. Use ExifTag::CAMERA_FIRMWARE (0xA439) instead.
     */
    public const int CAMERA_FIRMWARE_LEGACY = 0xE92F;

    /**
     * Legacy Microsoft EXIF tag that stored the raw developing software name.
     *
     * Pre-EXIF 3.0 Microsoft extension. Use ExifTag::RAW_DEVELOPING_SOFTWARE (0xA43A) instead.
     */
    public const int RAW_DEVELOPING_SOFTWARE_LEGACY = 0xE930;

    /**
     * Legacy Microsoft EXIF tag that stored the image editing software name.
     *
     * Pre-EXIF 3.0 Microsoft extension. Use ExifTag::IMAGE_EDITING_SOFTWARE (0xA43B) instead.
     */
    public const int IMAGE_EDITING_SOFTWARE_LEGACY = 0xE931;

    /**
     * Legacy Microsoft EXIF tag that stored the metadata editing software name.
     *
     * Pre-EXIF 3.0 Microsoft extension. Use ExifTag::METADATA_EDITING_SOFTWARE (0xA43C) instead.
     */
    public const int METADATA_EDITING_SOFTWARE_LEGACY = 0xE932;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
