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
    /**
     * EXIF 3.0 tag recording the software responsible for final image processing.
     */
    public const int PROCESSING_SOFTWARE = 0x000B;

    public const int IMAGE_WIDTH = 0x0100;

    public const int IMAGE_HEIGHT = 0x0101;

    public const int BITS_PER_SAMPLE = 0x0102;

    public const int COMPRESSION = 0x0103;

    public const int PHOTOMETRIC_INTERPRETATION = 0x0106;

    public const int IMAGE_DESCRIPTION = 0x010E;

    public const int SUB_IFDS = 0x014A;

    public const int IMAGE_TITLE = 0xA436;

    /**
     * Microsoft XPTitle property encoded as UTF-16LE.
     */
    public const int XP_TITLE = 0x9C9B;

    /**
     * Microsoft XPComment property encoded as UTF-16LE.
     */
    public const int XP_COMMENT = 0x9C9C;

    /**
     * Microsoft XPAuthor property encoded as UTF-16LE.
     */
    public const int XP_AUTHOR = 0x9C9D;

    /**
     * Microsoft XPKeywords property encoded as UTF-16LE.
     */
    public const int XP_KEYWORDS = 0x9C9E;

    /**
     * Microsoft XPSubject property encoded as UTF-16LE.
     */
    public const int XP_SUBJECT = 0x9C9F;

    /**
     * Legacy EXIF 2.x tag that stored the document name within IFD0.
     *
     * Retained for backwards compatibility with images that have not been
     * updated to the EXIF 3.0 IMAGE_TITLE identifier.
     */
    public const int IMAGE_TITLE_LEGACY = 0x0320;

    public const int MAKE = 0x010F;

    public const int MODEL = 0x0110;

    public const int ORIENTATION = 0x0112;

    public const int STRIP_OFFSETS = 0x0111;

    public const int SAMPLES_PER_PIXEL = 0x0115;

    public const int ROWS_PER_STRIP = 0x0116;

    public const int STRIP_BYTE_COUNTS = 0x0117;

    public const int X_RESOLUTION = 0x011A;

    public const int Y_RESOLUTION = 0x011B;

    public const int PLANAR_CONFIGURATION = 0x011C;

    public const int RESOLUTION_UNIT = 0x0128;

    public const int TRANSFER_FUNCTION = 0x012D;

    public const int SOFTWARE = 0x0131;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * EXIF 3.0 renames the tag to ModifyDate, exposed via the MODIFY_DATE alias.
     */
    public const int DATETIME = 0x0132;

    /**
     * Preferred alias that matches the EXIF 3.0 ModifyDate tag name.
     */
    public const int MODIFY_DATE = 0x0132;

    public const int ARTIST = 0x013B;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * Removed from the EXIF 3.0 registry but still exposed for older files.
     */
    public const int HOST_COMPUTER = 0x013C;

    public const int PHOTOGRAPHER = 0xA437;

    /**
     * Legacy Microsoft EXIF tag that exposed the photographer credit prior to
     * EXIF 3.0.
     */
    public const int PHOTOGRAPHER_LEGACY = 0xE92D;

    public const int IMAGE_EDITOR = 0xA438;

    /**
     * Legacy Microsoft EXIF tag that exposed the image editor credit prior to
     * EXIF 3.0.
     */
    public const int IMAGE_EDITOR_LEGACY = 0xE92E;

    public const int WHITE_POINT = 0x013E;

    public const int PRIMARY_CHROMATICITIES = 0x013F;

    public const int JPEG_INTERCHANGE_FORMAT = 0x0201;

    public const int JPEG_INTERCHANGE_FORMAT_LENGTH = 0x0202;

    public const int YCBCR_COEFFICIENTS = 0x0211;

    public const int YCBCR_SUB_SAMPLING = 0x0212;

    public const int YCBCR_POSITIONING = 0x0213;

    public const int REFERENCE_BLACK_WHITE = 0x0214;

    public const int COPYRIGHT = 0x8298;

    // Pointer tags
    public const int EXIF_IFD_POINTER = 0x8769;

    public const int GPS_IFD_POINTER = 0x8825;

    public const int INTEROPERABILITY_IFD_POINTER = 0xA005;

    // EXIF sub IFD
    public const int CFA_REPEAT_PATTERN_DIM = 0x828D;

    public const int BATTERY_LEVEL = 0x828F;

    /**
     * Epson Print Image Matching (PrintIM) parameter block.
     */
    public const int PRINT_IMAGE_MATCHING = 0xC4A5;

    public const int MAKER_NOTE_SAFETY = 0xC635;

    public const int EXPOSURE_TIME = 0x829A;

    public const int F_NUMBER = 0x829D;

    public const int EXPOSURE_PROGRAM = 0x8822;

    public const int SPECTRAL_SENSITIVITY = 0x8824;

    /**
     * Legacy EXIF 2.x identifier retained for backwards compatibility.
     *
     * EXIF 3.0 renames the tag to PhotographicSensitivity, exposed via the
     * PHOTOGRAPHIC_SENSITIVITY alias.
     */
    public const int ISO_SPEED_RATINGS_LEGACY = 0x8827;

    public const int PHOTOGRAPHIC_SENSITIVITY = 0x8827;

    // Opto-Electric Conversion Function
    public const int OECF = 0x8828;

    public const int INTERLACE = 0x8829;

    public const int TIME_ZONE_OFFSET = 0x882A;

    public const int SELF_TIMER_MODE = 0x882B;

    public const int SENSITIVITY_TYPE = 0x8830;

    public const int STANDARD_OUTPUT_SENSITIVITY = 0x8831;

    public const int RECOMMENDED_EXPOSURE_INDEX = 0x8832;

    public const int ISO_SPEED = 0x8833;

    public const int ISO_SPEED_LATITUDE_YYY = 0x8834;

    public const int ISO_SPEED_LATITUDE_ZZZ = 0x8835;

    public const int EXIF_VERSION = 0x9000;

    public const int DATETIME_ORIGINAL = 0x9003;

    public const int DATETIME_DIGITIZED = 0x9004;

    public const int OFFSET_TIME = 0x9010;

    public const int OFFSET_TIME_ORIGINAL = 0x9011;

    public const int OFFSET_TIME_DIGITIZED = 0x9012;

    public const int COMPONENTS_CONFIGURATION = 0x9101;

    public const int COMPRESSED_BITS_PER_PIXEL = 0x9102;

    public const int SHUTTER_SPEED_VALUE = 0x9201;

    public const int APERTURE_VALUE = 0x9202;

    public const int BRIGHTNESS_VALUE = 0x9203;

    public const int EXPOSURE_BIAS_VALUE = 0x9204;

    public const int MAX_APERTURE_VALUE = 0x9205;

    public const int SUBJECT_DISTANCE = 0x9206;

    public const int METERING_MODE = 0x9207;

    public const int LIGHT_SOURCE = 0x9208;

    public const int FLASH = 0x9209;

    public const int FOCAL_LENGTH = 0x920A;

    public const int SUBJECT_AREA = 0x9214;

    public const int MAKER_NOTE = 0x927C;

    public const int USER_COMMENT = 0x9286;

    public const int SUB_SEC_TIME = 0x9290;

    public const int SUB_SEC_TIME_ORIGINAL = 0x9291;

    public const int SUB_SEC_TIME_DIGITIZED = 0x9292;

    public const int FLASHPIX_VERSION = 0xA000;

    public const int COLOR_SPACE = 0xA001;

    public const int PIXEL_X_DIMENSION = 0xA002;

    public const int PIXEL_Y_DIMENSION = 0xA003;

    public const int RELATED_SOUND_FILE = 0xA004;

    public const int FILE_SOURCE = 0xA300;

    public const int SCENE_TYPE = 0xA301;

    public const int CUSTOM_RENDERED = 0xA401;

    public const int EXPOSURE_MODE = 0xA402;

    public const int WHITE_BALANCE = 0xA403;

    public const int DIGITAL_ZOOM_RATIO = 0xA404;

    public const int FOCAL_LENGTH_IN_35MM_FILM = 0xA405;

    public const int SCENE_CAPTURE_TYPE = 0xA406;

    public const int GAIN_CONTROL = 0xA407;

    public const int CONTRAST = 0xA408;

    public const int SATURATION = 0xA409;

    public const int SHARPNESS = 0xA40A;

    public const int SUBJECT_DISTANCE_RANGE = 0xA40C;

    public const int IMAGE_UNIQUE_ID = 0xA420;

    public const int CAMERA_OWNER_NAME = 0xA430;

    public const int BODY_SERIAL_NUMBER = 0xA431;

    public const int LENS_SPECIFICATION = 0xA432;

    public const int LENS_MAKE = 0xA433;

    public const int LENS_MODEL = 0xA434;

    public const int LENS_SERIAL_NUMBER = 0xA435;

    /**
     * Legacy EXIF 2.x tag that stored the dedicated camera firmware version.
     *
     * The identifier was reassigned to IMAGE_TITLE in EXIF 3.0 and therefore
     * remains available only for backwards compatibility lookups.
     */
    public const int CAMERA_FIRMWARE_VERSION_LEGACY = 0xA436;

    public const int CAMERA_FIRMWARE = 0xA439;

    /**
     * Legacy EXIF 2.x tag that stored the raw developing software version.
     *
     * EXIF 3.0 reassigned this identifier to CAMERA_FIRMWARE.
     */
    public const int RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY = 0xA439;

    public const int RAW_DEVELOPING_SOFTWARE = 0xA43A;

    /**
     * Legacy EXIF 2.x tag that stored the image editing software version.
     *
     * EXIF 3.0 reassigned this identifier to IMAGE_EDITING_SOFTWARE.
     */
    public const int IMAGE_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43B;

    public const int IMAGE_EDITING_SOFTWARE = 0xA43B;

    /**
     * Legacy EXIF 2.x tag that stored the metadata editing software version.
     *
     * EXIF 3.0 reassigned this identifier to METADATA_EDITING_SOFTWARE.
     */
    public const int METADATA_EDITING_SOFTWARE_VERSION_LEGACY = 0xA43C;

    public const int METADATA_EDITING_SOFTWARE = 0xA43C;

    public const int COMPOSITE_IMAGE = 0xA460;

    public const int SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE = 0xA461;

    public const int SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE = 0xA462;

    public const int GAMMA = 0xA500;

    public const int FLASH_ENERGY = 0xA20B;

    public const int SPATIAL_FREQUENCY_RESPONSE = 0xA20C;

    public const int NOISE = 0xA20D;

    public const int FOCAL_PLANE_X_RESOLUTION = 0xA20E;

    public const int FOCAL_PLANE_Y_RESOLUTION = 0xA20F;

    public const int FOCAL_PLANE_RESOLUTION_UNIT = 0xA210;

    public const int IMAGE_NUMBER = 0xA211;

    public const int SECURITY_CLASSIFICATION = 0xA212;

    public const int IMAGE_HISTORY = 0xA213;

    public const int SUBJECT_LOCATION = 0xA214;

    public const int EXPOSURE_INDEX = 0xA215;

    public const int TIFF_EP_STANDARD_ID = 0xA216;

    public const int SENSING_METHOD = 0xA217;

    public const int CFA_PATTERN = 0xA302;

    public const int DEVICE_SETTING_DESCRIPTION = 0xA40B;

    // GPS sub IFD (Table 66 – EXIF 3.0)
    public const int GPS_VERSION_ID = 0x0000;

    public const int GPS_LATITUDE_REF = 0x0001;

    public const int GPS_LATITUDE = 0x0002;

    public const int GPS_LONGITUDE_REF = 0x0003;

    public const int GPS_LONGITUDE = 0x0004;

    public const int GPS_ALTITUDE_REF = 0x0005;

    public const int GPS_ALTITUDE = 0x0006;

    public const int GPS_TIME_STAMP = 0x0007;

    public const int GPS_SATELLITES = 0x0008;

    public const int GPS_STATUS = 0x0009;

    public const int GPS_MEASURE_MODE = 0x000A;

    public const int GPS_DOP = 0x000B;

    public const int GPS_SPEED_REF = 0x000C;

    public const int GPS_SPEED = 0x000D;

    public const int GPS_TRACK_REF = 0x000E;

    public const int GPS_TRACK = 0x000F;

    public const int GPS_IMG_DIRECTION_REF = 0x0010;

    public const int GPS_IMG_DIRECTION = 0x0011;

    public const int GPS_MAP_DATUM = 0x0012;

    public const int GPS_DEST_LATITUDE_REF = 0x0013;

    public const int GPS_DEST_LATITUDE = 0x0014;

    public const int GPS_DEST_LONGITUDE_REF = 0x0015;

    public const int GPS_DEST_LONGITUDE = 0x0016;

    public const int GPS_DEST_BEARING_REF = 0x0017;

    public const int GPS_DEST_BEARING = 0x0018;

    public const int GPS_DEST_DISTANCE_REF = 0x0019;

    public const int GPS_DEST_DISTANCE = 0x001A;

    public const int GPS_PROCESSING_METHOD = 0x001B;

    public const int GPS_AREA_INFORMATION = 0x001C;

    public const int GPS_DATE_STAMP = 0x001D;

    public const int GPS_DIFFERENTIAL = 0x001E;

    public const int GPS_H_POSITIONING_ERROR = 0x001F;

    public const int TEMPERATURE = 0x9400;

    public const int HUMIDITY = 0x9401;

    public const int PRESSURE = 0x9402;

    public const int WATER_DEPTH = 0x9403;

    public const int ACCELERATION = 0x9404;

    public const int CAMERA_ELEVATION_ANGLE = 0x9405;

    public const int CAMERA_YAW_DEGREE = 0x9406;

    public const int CAMERA_PITCH_DEGREE = 0x9407;

    public const int CAMERA_ROLL_DEGREE = 0x9408;

    /**
     * Legacy identifiers retained for backwards compatibility with pre-EXIF 3.0 metadata.
     *
     * The EXIF 3.0 specification renamed the tags to the CAMERA_* variants, but older drone
     * metadata may still expose the historic FLIGHT_* names.
     */
    public const int FLIGHT_YAW_DEGREE = self::CAMERA_YAW_DEGREE;

    public const int FLIGHT_PITCH_DEGREE = self::CAMERA_PITCH_DEGREE;

    public const int FLIGHT_ROLL_DEGREE = self::CAMERA_ROLL_DEGREE;

    public const int GIMBAL_YAW_DEGREE = 0x9409;

    public const int GIMBAL_PITCH_DEGREE = 0x940A;

    public const int GIMBAL_ROLL_DEGREE = 0x940B;

    public const int AIRCRAFT_MAKE = 0x940C;

    public const int AIRCRAFT_MODEL = 0x940D;

    /**
     * Legacy Microsoft EXIF tag that stored the camera firmware string.
     */
    public const int CAMERA_FIRMWARE_LEGACY = 0xE92F;

    /**
     * Legacy Microsoft EXIF tag that stored the raw developing software name.
     */
    public const int RAW_DEVELOPING_SOFTWARE_LEGACY = 0xE930;

    /**
     * Legacy Microsoft EXIF tag that stored the image editing software name.
     */
    public const int IMAGE_EDITING_SOFTWARE_LEGACY = 0xE931;

    /**
     * Legacy Microsoft EXIF tag that stored the metadata editing software name.
     */
    public const int METADATA_EDITING_SOFTWARE_LEGACY = 0xE932;

    // Interoperability IFD
    public const int INTEROPERABILITY_INDEX = 0x0001;

    public const int INTEROPERABILITY_VERSION = 0x0002;

    public const int RELATED_IMAGE_FILE_FORMAT = 0x1000;

    public const int RELATED_IMAGE_WIDTH = 0x1001;

    public const int RELATED_IMAGE_LENGTH = 0x1002;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
