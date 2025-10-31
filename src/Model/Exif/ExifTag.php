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
 *
 * EXIF 3.0 §4.6 catalogues the tag registry for the primary, Exif, GPS and
 * interoperability IFDs referenced by this enumeration.
 */
final readonly class ExifTag
{
    // Image file directory (IFD0 — EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1)
    /**
     * TIFF 6.0 subfile type bitfield describing the purpose of the image data.
     * EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1.
     */
    public const int NEW_SUBFILE_TYPE = 0x00FE;

    /**
     * Legacy TIFF 5.0 subfile type value describing the image purpose.
     * EXIF 2.32 §4.6.2 Table 1 retains the identifier for backward compatibility.
     */
    public const int SUBFILE_TYPE = 0x00FF;

    /**
     * EXIF 3.0 tag recording the software responsible for final image processing.
     * EXIF 3.0 §4.6.2 Table 1; mapped from EXIF 2.32 §4.6.2 Table 1 guidance.
     */
    public const int PROCESSING_SOFTWARE = 0x000B;

    public const int IMAGE_WIDTH = 0x0100; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int IMAGE_HEIGHT = 0x0101; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int BITS_PER_SAMPLE = 0x0102; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int COMPRESSION = 0x0103; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int PHOTOMETRIC_INTERPRETATION = 0x0106; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    /**
     * Legacy EXIF ≤ 2.x tag storing the document name within IFD0.
     * EXIF 2.32 §4.6.2 Table 1; superseded by the EXIF 3.0 ImageTitle tag in Table 1.
     *
     * Retained for backwards compatibility alongside the EXIF 3.0 IMAGE_TITLE tag.
     * EXIF 2.32 §4.6.2 Table 1 (legacy) / EXIF 3.0 §4.6.2 Table 1 (ImageTitle).
     */
    public const int DOCUMENT_NAME = 0x010D; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int IMAGE_DESCRIPTION = 0x010E; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int SUB_IFDS = 0x014A; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int IMAGE_TITLE = 0xA436; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    /**
     * Microsoft XPTitle property encoded as UTF-16LE.
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2.
     */
    public const int XP_TITLE = 0x9C9B; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    /**
     * Microsoft XPComment property encoded as UTF-16LE.
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2.
     */
    public const int XP_COMMENT = 0x9C9C; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    /**
     * Microsoft XPAuthor property encoded as UTF-16LE.
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2.
     */
    public const int XP_AUTHOR = 0x9C9D; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    /**
     * Microsoft XPKeywords property encoded as UTF-16LE.
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2.
     */
    public const int XP_KEYWORDS = 0x9C9E; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    /**
     * Microsoft XPSubject property encoded as UTF-16LE.
     * EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2.
     */
    public const int XP_SUBJECT = 0x9C9F; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    /**
     * Legacy EXIF 2.x tag that stored the document name within IFD0.
     *
     * Retained for backwards compatibility with images that have not been
     * updated to the EXIF 3.0 IMAGE_TITLE identifier.
     * EXIF 2.32 §4.6.2 Table 1 (legacy) / EXIF 3.0 §4.6.2 Table 1 (ImageTitle).
     */
    public const int IMAGE_TITLE_LEGACY = 0x0320; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int MAKE = 0x010F; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int MODEL = 0x0110; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int ORIENTATION = 0x0112; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int STRIP_OFFSETS = 0x0111; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int SAMPLES_PER_PIXEL = 0x0115; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int ROWS_PER_STRIP = 0x0116; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int STRIP_BYTE_COUNTS = 0x0117; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int TILE_WIDTH = 0x0142; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int TILE_LENGTH = 0x0143; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int TILE_OFFSETS = 0x0144; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int TILE_BYTE_COUNTS = 0x0145; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int X_RESOLUTION = 0x011A; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int Y_RESOLUTION = 0x011B; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int PLANAR_CONFIGURATION = 0x011C; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int RESOLUTION_UNIT = 0x0128; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int TRANSFER_FUNCTION = 0x012D; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int SOFTWARE = 0x0131; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * EXIF 3.0 renames the tag to ModifyDate, exposed via the MODIFY_DATE alias.
     * EXIF 2.32 §4.6.2 Table 2 (DateTime) / EXIF 3.0 §4.6.2 Table 2 (ModifyDate).
     */
    public const int DATETIME = 0x0132;

    /**
     * Preferred alias that matches the EXIF 3.0 ModifyDate tag name.
     * EXIF 3.0 §4.6.2 Table 2; aligns with EXIF 2.32 §4.6.2 Table 2 DateTime guidance.
     */
    public const int MODIFY_DATE = 0x0132;

    public const int ARTIST = 0x013B; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * Removed from the EXIF 3.0 registry but still exposed for older files.
     * EXIF 2.32 §4.6.2 Table 2; absent from EXIF 3.0 Table 2 but preserved as a legacy alias.
     */
    public const int HOST_COMPUTER = 0x013C; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    public const int PHOTOGRAPHER = 0xA437; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    /**
     * Legacy Microsoft EXIF tag that exposed the photographer credit prior to
     * EXIF 2.32 §4.6.2 Table 2; replaced by EXIF 3.0 §4.6.2 Table 2 Photographer.
     */
    public const int PHOTOGRAPHER_LEGACY = 0xE92D; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    public const int IMAGE_EDITOR = 0xA438; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    /**
     * Legacy Microsoft EXIF tag that exposed the image editor credit prior to
     * EXIF 2.32 §4.6.2 Table 2; replaced by EXIF 3.0 §4.6.2 Table 2 ImageEditor.
     */
    public const int IMAGE_EDITOR_LEGACY = 0xE92E; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    public const int WHITE_POINT = 0x013E; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int PRIMARY_CHROMATICITIES = 0x013F; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int JPEG_INTERCHANGE_FORMAT = 0x0201; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int JPEG_INTERCHANGE_FORMAT_LENGTH = 0x0202; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    // EXIF 3.0 preview tags (EXIF 3.0 §4.6.12 Tables 29–32 preview image data)
    public const int PREVIEW_IMAGE_START = 0xC51B; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int PREVIEW_IMAGE_LENGTH = 0xC51C; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int PREVIEW_IMAGE_ENCODING = 0xC51D; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int PREVIEW_IMAGE_MIME_TYPE = 0xC51E; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int PREVIEW_IMAGE_WIDTH = 0xC51F; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int PREVIEW_IMAGE_HEIGHT = 0xC520; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int PREVIEW_IMAGE_COLOR_SPACE = 0xC521; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int PREVIEW_IMAGE_BIT_DEPTH = 0xC522; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int PREVIEW_DATE_TIME = 0xC523; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int PREVIEW_DATE_TIME_DIGITIZED = 0xC524; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int PREVIEW_IMAGE_COMPRESSION = 0xC525; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int PREVIEW_IMAGE_SCALE = 0xC526; // EXIF 3.0 §4.6.12 Tables 29–32

    public const int YCBCR_COEFFICIENTS = 0x0211; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int YCBCR_SUB_SAMPLING = 0x0212; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int YCBCR_POSITIONING = 0x0213; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int REFERENCE_BLACK_WHITE = 0x0214; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    public const int COPYRIGHT = 0x8298; // EXIF 3.0 §4.6.2 Table 1; EXIF 2.32 §4.6.2 Table 1

    // Pointer tags (EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2 directory structure)
    public const int EXIF_IFD_POINTER = 0x8769; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    public const int GPS_IFD_POINTER = 0x8825; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    public const int INTEROPERABILITY_IFD_POINTER = 0xA005; // EXIF 3.0 §4.6.2 Table 2; EXIF 2.32 §4.6.2 Table 2

    // EXIF sub IFD (EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26 shooting conditions)
    public const int CFA_REPEAT_PATTERN_DIM = 0x828D; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int BATTERY_LEVEL = 0x828F; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    /**
     * Epson Print Image Matching (PrintIM) parameter block.
     */
    public const int PRINT_IMAGE_MATCHING = 0xC4A5; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int MAKER_NOTE_SAFETY = 0xC635; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int EXPOSURE_TIME = 0x829A; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int F_NUMBER = 0x829D; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int EXPOSURE_PROGRAM = 0x8822; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SPECTRAL_SENSITIVITY = 0x8824; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * EXIF 3.0 renames the tag to PhotographicSensitivity, exposed via the
     * PHOTOGRAPHIC_SENSITIVITY alias.
     * EXIF 2.32 §4.6.3 Table 13 (ISOSpeedRatings) / EXIF 3.0 §4.6.3 Table 13 (PhotographicSensitivity).
     */
    public const int ISO_SPEED_RATINGS_LEGACY = 0x8827; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int PHOTOGRAPHIC_SENSITIVITY = 0x8827; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    // Opto-Electric Conversion Function (EXIF 3.0 §4.6.3 Table 15; EXIF 2.32 §4.6.3 Table 15)
    public const int OECF = 0x8828; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int INTERLACE = 0x8829; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int TIME_ZONE_OFFSET = 0x882A; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SELF_TIMER_MODE = 0x882B; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SENSITIVITY_TYPE = 0x8830; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int STANDARD_OUTPUT_SENSITIVITY = 0x8831; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int RECOMMENDED_EXPOSURE_INDEX = 0x8832; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int ISO_SPEED = 0x8833; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int ISO_SPEED_LATITUDE_YYY = 0x8834; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int ISO_SPEED_LATITUDE_ZZZ = 0x8835; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int EXIF_VERSION = 0x9000; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int DATETIME_ORIGINAL = 0x9003; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int DATETIME_DIGITIZED = 0x9004; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int OFFSET_TIME = 0x9010; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int OFFSET_TIME_ORIGINAL = 0x9011; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int OFFSET_TIME_DIGITIZED = 0x9012; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int COMPONENTS_CONFIGURATION = 0x9101; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int COMPRESSED_BITS_PER_PIXEL = 0x9102; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SHUTTER_SPEED_VALUE = 0x9201; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int APERTURE_VALUE = 0x9202; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int BRIGHTNESS_VALUE = 0x9203; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int EXPOSURE_BIAS_VALUE = 0x9204; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int MAX_APERTURE_VALUE = 0x9205; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SUBJECT_DISTANCE = 0x9206; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int METERING_MODE = 0x9207; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int LIGHT_SOURCE = 0x9208; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int FLASH = 0x9209; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int FOCAL_LENGTH = 0x920A; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SUBJECT_AREA = 0x9214; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int MAKER_NOTE = 0x927C; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int USER_COMMENT = 0x9286; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SUB_SEC_TIME = 0x9290; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SUB_SEC_TIME_ORIGINAL = 0x9291; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SUB_SEC_TIME_DIGITIZED = 0x9292; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int FLASHPIX_VERSION = 0xA000; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int COLOR_SPACE = 0xA001; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int PIXEL_X_DIMENSION = 0xA002; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int PIXEL_Y_DIMENSION = 0xA003; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int RELATED_SOUND_FILE = 0xA004; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int FILE_SOURCE = 0xA300; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SCENE_TYPE = 0xA301; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int CUSTOM_RENDERED = 0xA401; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int EXPOSURE_MODE = 0xA402; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int WHITE_BALANCE = 0xA403; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int DIGITAL_ZOOM_RATIO = 0xA404; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int FOCAL_LENGTH_IN_35MM_FILM = 0xA405; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SCENE_CAPTURE_TYPE = 0xA406; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int GAIN_CONTROL = 0xA407; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int CONTRAST = 0xA408; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SATURATION = 0xA409; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SHARPNESS = 0xA40A; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SUBJECT_DISTANCE_RANGE = 0xA40C; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int IMAGE_UNIQUE_ID = 0xA420; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int CAMERA_OWNER_NAME = 0xA430; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int BODY_SERIAL_NUMBER = 0xA431; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int CAMERA_SERIAL_NUMBER = 0xC62F; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int LENS_SPECIFICATION = 0xA432; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int LENS_MAKE = 0xA433; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int LENS_MODEL = 0xA434; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int LENS_SERIAL_NUMBER = 0xA435; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    /**
     * Legacy EXIF 2.x tag that stored the dedicated camera firmware version.
     *
     * The identifier was reassigned to IMAGE_TITLE in EXIF 3.0 and therefore
     * EXIF 2.32 §4.6.3 Table 18 (FirmwareVersion) / EXIF 3.0 §4.6.2 Table 1 (ImageTitle).
     * remains available only for backwards compatibility lookups.
     */
    public const int CAMERA_FIRMWARE_VERSION_LEGACY = 0xA436; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int CAMERA_FIRMWARE = 0xA439; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    /**
     * Legacy EXIF 2.x tag that stored the raw developing software version.
     *
     * EXIF 3.0 reassigned this identifier to CAMERA_FIRMWARE.
     * EXIF 2.32 §4.6.3 Table 18 (RawDataUniqueID) / EXIF 3.0 §4.6.3 Table 18 (CameraFirmware).
     */
    public const int RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY = 0xA439; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int RAW_DEVELOPING_SOFTWARE = 0xA43A; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    /**
     * Legacy EXIF 2.x tag that stored the image editing software version.
     *
     * EXIF 3.0 reassigned this identifier to IMAGE_EDITING_SOFTWARE.
     * EXIF 2.32 §4.6.3 Table 18 (Software).
     */
    public const int IMAGE_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43B; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int IMAGE_EDITING_SOFTWARE = 0xA43B; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    /**
     * Legacy EXIF 2.x tag that stored the metadata editing software version.
     *
     * EXIF 3.0 reassigned this identifier to METADATA_EDITING_SOFTWARE.
     * EXIF 2.32 §4.6.3 Table 18 (MetadataEditing).
     */
    public const int METADATA_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43C; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int METADATA_EDITING_SOFTWARE = 0xA43C; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int COMPOSITE_IMAGE = 0xA460; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE = 0xA461; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE = 0xA462; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int GAMMA = 0xA500; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int FLASH_ENERGY = 0xA20B; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SPATIAL_FREQUENCY_RESPONSE = 0xA20C; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int NOISE = 0xA20D; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int FOCAL_PLANE_X_RESOLUTION = 0xA20E; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int FOCAL_PLANE_Y_RESOLUTION = 0xA20F; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int FOCAL_PLANE_RESOLUTION_UNIT = 0xA210; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int IMAGE_NUMBER = 0xA211; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SECURITY_CLASSIFICATION = 0xA212; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int IMAGE_HISTORY = 0xA213; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SUBJECT_LOCATION = 0xA214; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int EXPOSURE_INDEX = 0xA215; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int TIFF_EP_STANDARD_ID = 0xA216; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int SENSING_METHOD = 0xA217; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int CFA_PATTERN = 0xA302; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    public const int DEVICE_SETTING_DESCRIPTION = 0xA40B; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    // DNG colour profile tags (EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41, DNG extensions)
    /**
     * DNG camera calibration signature string recorded alongside the profile data.
     */
    public const int CAMERA_CALIBRATION_SIGNATURE = 0xC6F3; // EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41

    /**
     * DNG profile calibration signature string supplied by the camera vendor.
     */
    public const int PROFILE_CALIBRATION_SIGNATURE = 0xC6F4; // EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41

    /**
     * Lists the encoding functions applied to each hue/saturation/value channel in the profile maps.
     * EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41.
     */
    public const int PROFILE_HUE_SAT_MAP_ENCODINGS = 0xC6F5; // EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41

    /**
     * Records the hue/saturation/value grid dimensions used by the profile maps.
     */
    public const int PROFILE_HUE_SAT_MAP_DIMS = 0xC6F6; // EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41

    /**
     * Primary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_1 = 0xC6F7; // EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41

    /**
     * Secondary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_2 = 0xC6F8; // EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41

    /**
     * Tertiary hue/saturation/value adjustment map encoded as IEEE-754 floats.
     */
    public const int PROFILE_HUE_SAT_MAP_DATA_3 = 0xC6F9; // EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41

    /**
     * Defines the hue/saturation/value grid dimensions used by the look table.
     */
    public const int PROFILE_LOOK_TABLE_DIMS = 0xC6FA; // EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41

    /**
     * Profile look table entries encoded as triplets of IEEE-754 floats.
     */
    public const int PROFILE_LOOK_TABLE_DATA = 0xC6FB; // EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41

    /**
     * Optional tone curve defined as normalised IEEE-754 float pairs.
     */
    public const int PROFILE_TONE_CURVE = 0xC6FC; // EXIF 3.0 §4.6.3 Tables 35–41; EXIF 2.32 §4.6.3 Tables 35–41

    // GPS sub IFD (EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66)
    public const int GPS_VERSION_ID = 0x0000; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_LATITUDE_REF = 0x0001; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_LATITUDE = 0x0002; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_LONGITUDE_REF = 0x0003; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_LONGITUDE = 0x0004; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_ALTITUDE_REF = 0x0005; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_ALTITUDE = 0x0006; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_TIME_STAMP = 0x0007; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_SATELLITES = 0x0008; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_STATUS = 0x0009; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_MEASURE_MODE = 0x000A; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_DOP = 0x000B; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_SPEED_REF = 0x000C; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_SPEED = 0x000D; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_TRACK_REF = 0x000E; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_TRACK = 0x000F; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_IMG_DIRECTION_REF = 0x0010; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_IMG_DIRECTION = 0x0011; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_MAP_DATUM = 0x0012; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_DEST_LATITUDE_REF = 0x0013; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_DEST_LATITUDE = 0x0014; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_DEST_LONGITUDE_REF = 0x0015; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_DEST_LONGITUDE = 0x0016; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_DEST_BEARING_REF = 0x0017; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_DEST_BEARING = 0x0018; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_DEST_DISTANCE_REF = 0x0019; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_DEST_DISTANCE = 0x001A; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_PROCESSING_METHOD = 0x001B; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_AREA_INFORMATION = 0x001C; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_DATE_STAMP = 0x001D; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_DIFFERENTIAL = 0x001E; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GPS_H_POSITIONING_ERROR = 0x001F; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int TEMPERATURE = 0x9400; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int HUMIDITY = 0x9401; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int PRESSURE = 0x9402; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int WATER_DEPTH = 0x9403; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int ACCELERATION = 0x9404; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int CAMERA_ELEVATION_ANGLE = 0x9405; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int CAMERA_YAW_DEGREE = 0x9406; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int CAMERA_PITCH_DEGREE = 0x9407; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int CAMERA_ROLL_DEGREE = 0x9408; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    /**
     * Legacy identifiers retained for backwards compatibility with pre-EXIF 3.0 metadata.
     * EXIF 2.32 §4.6.6 Table 66 labels the FLIGHT_* names; EXIF 3.0 §4.6.6 Table 66 renames them to CAMERA_* variants.
     *
     * The EXIF 3.0 specification renamed the tags to the CAMERA_* variants, but older drone
     * metadata may still expose the historic FLIGHT_* names.
     */
    public const int FLIGHT_YAW_DEGREE = self::CAMERA_YAW_DEGREE;

    public const int FLIGHT_PITCH_DEGREE = self::CAMERA_PITCH_DEGREE;

    public const int FLIGHT_ROLL_DEGREE = self::CAMERA_ROLL_DEGREE;

    public const int GIMBAL_YAW_DEGREE = 0x9409; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GIMBAL_PITCH_DEGREE = 0x940A; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int GIMBAL_ROLL_DEGREE = 0x940B; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int AIRCRAFT_MAKE = 0x940C; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    public const int AIRCRAFT_MODEL = 0x940D; // EXIF 3.0 §4.6.6 Table 66; EXIF 2.32 §4.6.6 Table 66

    /**
     * Legacy Microsoft EXIF tag that stored the camera firmware string.
     * Documented in EXIF 2.32 §4.6.3 Table 18; superseded by EXIF 3.0 §4.6.3 Table 18 CameraFirmware.
     */
    public const int CAMERA_FIRMWARE_LEGACY = 0xE92F; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    /**
     * Legacy Microsoft EXIF tag that stored the raw developing software name.
     * Documented in EXIF 2.32 §4.6.3 Table 18; superseded by EXIF 3.0 §4.6.3 Table 18 RawDevelopingSoftware.
     */
    public const int RAW_DEVELOPING_SOFTWARE_LEGACY = 0xE930; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    /**
     * Legacy Microsoft EXIF tag that stored the image editing software name.
     * Documented in EXIF 2.32 §4.6.3 Table 18; superseded by EXIF 3.0 §4.6.3 Table 18 ImageEditingSoftware.
     */
    public const int IMAGE_EDITING_SOFTWARE_LEGACY = 0xE931; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    /**
     * Legacy Microsoft EXIF tag that stored the metadata editing software name.
     * Documented in EXIF 2.32 §4.6.3 Table 18; superseded by EXIF 3.0 §4.6.3 Table 18 MetadataEditingSoftware.
     */
    public const int METADATA_EDITING_SOFTWARE_LEGACY = 0xE932; // EXIF 3.0 §4.6.3 Tables 4–26; EXIF 2.32 §4.6.3 Tables 4–26

    // Interoperability IFD (EXIF 3.0 §4.6.7 Table 67; EXIF 2.32 §4.6.7 Table 67)
    public const int INTEROPERABILITY_INDEX = 0x0001; // EXIF 3.0 §4.6.7 Table 67; EXIF 2.32 §4.6.7 Table 67

    public const int INTEROPERABILITY_VERSION = 0x0002; // EXIF 3.0 §4.6.7 Table 67; EXIF 2.32 §4.6.7 Table 67

    public const int RELATED_IMAGE_FILE_FORMAT = 0x1000; // EXIF 3.0 §4.6.7 Table 67; EXIF 2.32 §4.6.7 Table 67

    public const int RELATED_IMAGE_WIDTH = 0x1001; // EXIF 3.0 §4.6.7 Table 67; EXIF 2.32 §4.6.7 Table 67

    public const int RELATED_IMAGE_LENGTH = 0x1002; // EXIF 3.0 §4.6.7 Table 67; EXIF 2.32 §4.6.7 Table 67

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
