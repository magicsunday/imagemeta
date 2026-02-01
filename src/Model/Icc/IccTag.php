<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Icc;

/**
 * Defines offsets for ICC profile header fields.
 *
 * ICC.1:2022 §7.2 defines the profile header layout and field offsets.
 */
final class IccTag
{
    /**
     * Profile size field.
     *
     * ICC.1:2022 §7.2.2 (Profile size field).
     */
    public const int PROFILE_SIZE = 0x0000;

    /**
     * Profile CMM type field.
     *
     * ICC.1:2022 §7.2.3 (Preferred CMM type field).
     */
    public const int CMM_TYPE = 0x0004;

    /**
     * Profile version field.
     *
     * ICC.1:2022 §7.2.4 (Profile version field).
     */
    public const int PROFILE_VERSION = 0x0008;

    /**
     * Profile/device class field.
     *
     * ICC.1:2022 §7.2.5 (Profile/device class field).
     */
    public const int PROFILE_CLASS = 0x000C;

    /**
     * Input color space field.
     *
     * ICC.1:2022 §7.2.6 (Data colour space field).
     */
    public const int COLOR_SPACE = 0x0010;

    /**
     * Profile connection space field.
     *
     * ICC.1:2022 §7.2.7 (PCS field).
     */
    public const int PCS = 0x0014;

    /**
     * Profile creation date/time field.
     *
     * ICC.1:2022 §7.2.8 (Date and time field).
     */
    public const int PROFILE_DATE_TIME = 0x0018;

    /**
     * Profile file signature field.
     *
     * ICC.1:2022 §7.2.9 (Profile file signature field).
     */
    public const int PROFILE_SIGNATURE = 0x0024;

    /**
     * Primary platform signature field.
     *
     * ICC.1:2022 §7.2.10 (Primary platform field).
     */
    public const int PRIMARY_PLATFORM = 0x0028;

    /**
     * Profile flags field.
     *
     * ICC.1:2022 §7.2.11 (Profile flags field).
     */
    public const int PROFILE_FLAGS = 0x002C;

    /**
     * Device manufacturer field.
     *
     * ICC.1:2022 §7.2.12 (Device manufacturer field).
     */
    public const int DEVICE_MANUFACTURER = 0x0030;

    /**
     * Device model field.
     *
     * ICC.1:2022 §7.2.13 (Device model field).
     */
    public const int DEVICE_MODEL = 0x0034;

    /**
     * Device attributes field.
     *
     * ICC.1:2022 §7.2.14 (Device attributes field).
     */
    public const int DEVICE_ATTRIBUTES = 0x0038;

    /**
     * Rendering intent field.
     *
     * ICC.1:2022 §7.2.15 (Rendering intent field).
     */
    public const int RENDERING_INTENT = 0x0040;

    /**
     * Profile connection space illuminant field.
     *
     * ICC.1:2022 §7.2.16 (PCS illuminant field).
     */
    public const int CONNECTION_SPACE_ILLUMINANT = 0x0044;

    /**
     * Profile creator field.
     *
     * ICC.1:2022 §7.2.17 (Profile creator field).
     */
    public const int PROFILE_CREATOR = 0x0050;

    /**
     * Profile ID field.
     *
     * ICC.1:2022 §7.2.18 (Profile ID field).
     */
    public const int PROFILE_ID = 0x0054;

    /**
     * Tag table offset (start of tag table).
     *
     * ICC.1:2022 §7.3 (Tag table).
     */
    public const int TAG_TABLE = 0x0080;

    /**
     * Tag table entry count field.
     *
     * ICC.1:2022 §7.3 (Tag table).
     */
    public const int TAG_COUNT = 0x0080;

    /**
     * Tag table first record offset.
     *
     * ICC.1:2022 §7.3 (Tag table).
     */
    public const int TAG_RECORDS = 0x0084;

    /**
     * Reserved bytes (shall be zero).
     *
     * ICC.1:2022 §7.2.19 (Reserved field).
     */
    public const int RESERVED = 0x0064;

    private function __construct()
    {
    }
}
