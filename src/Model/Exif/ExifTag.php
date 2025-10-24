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
    public const int IMAGE_WIDTH = 0x0100;

    public const int IMAGE_HEIGHT = 0x0101;

    public const int BITS_PER_SAMPLE = 0x0102;

    public const int COMPRESSION = 0x0103;

    public const int PHOTOMETRIC_INTERPRETATION = 0x0106;

    public const int DOCUMENT_NAME = 0x010D;

    public const int IMAGE_DESCRIPTION = 0x010E;

    public const int MAKE = 0x010F;

    public const int MODEL = 0x0110;

    public const int ORIENTATION = 0x0112;

    public const int SAMPLES_PER_PIXEL = 0x0115;

    public const int ROWS_PER_STRIP = 0x0116;

    public const int X_RESOLUTION = 0x011A;

    public const int Y_RESOLUTION = 0x011B;

    public const int PLANAR_CONFIGURATION = 0x011C;

    public const int RESOLUTION_UNIT = 0x0128;

    public const int DATETIME = 0x0132;

    public const int SOFTWARE = 0x0131;

    public const int ARTIST = 0x013B;

    public const int WHITE_POINT = 0x013E;

    public const int PRIMARY_CHROMATICITIES = 0x013F;

    public const int YCBCR_COEFFICIENTS = 0x0211;

    public const int YCBCR_SUB_SAMPLING = 0x0212;

    public const int YCBCR_POSITIONING = 0x0213;

    // Pointer tags
    public const int EXIF_IFD_POINTER = 0x8769;

    public const int GPS_IFD_POINTER = 0x8825;

    public const int INTEROPERABILITY_IFD_POINTER = 0xA005;

    // EXIF sub IFD
    public const int EXPOSURE_TIME = 0x829A;

    public const int F_NUMBER = 0x829D;

    public const int EXPOSURE_PROGRAM = 0x8822;

    public const int PHOTOGRAPHIC_SENSITIVITY = 0x8827;

    public const int STANDARD_OUTPUT_SENSITIVITY = 0x8830;

    public const int RECOMMENDED_EXPOSURE_INDEX = 0x8832;

    public const int ISO_SPEED = 0x8833;

    public const int DATETIME_ORIGINAL = 0x9003;

    public const int DATETIME_DIGITIZED = 0x9004;

    public const int EXIF_VERSION = 0x9000;

    public const int OFFSET_TIME = 0x9010;

    public const int OFFSET_TIME_ORIGINAL = 0x9011;

    public const int OFFSET_TIME_DIGITIZED = 0x9012;

    public const int BRIGHTNESS_VALUE = 0x9203;

    public const int EXPOSURE_BIAS_VALUE = 0x9204;

    public const int MAX_APERTURE_VALUE = 0x9205;

    public const int SUBJECT_DISTANCE = 0x9206;

    public const int METERING_MODE = 0x9207;

    public const int LIGHT_SOURCE = 0x9208;

    public const int FLASH = 0x9209;

    public const int SUBJECT_AREA = 0x9214;

    public const int FOCAL_LENGTH = 0x920A;

    public const int MAKER_NOTE = 0x927C;

    public const int FILE_SOURCE = 0xA300;

    public const int SENSING_METHOD = 0xA217;

    public const int COLOR_SPACE = 0xA001;

    public const int EXIF_IMAGE_WIDTH = 0xA002;

    public const int EXIF_IMAGE_HEIGHT = 0xA003;

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

    public const int LENS_INFO = 0xA432;

    public const int LENS_MAKE = 0xA433;

    public const int LENS_MODEL = 0xA434;

    public const int LENS_SERIAL_NUMBER = 0xA435;

    public const int FLASHPIX_VERSION = 0xA000;

    public const int COMPOSITE_IMAGE = 0xA460;

    public const int COMPOSITE_IMAGE_COUNT = 0xA461;

    public const int COMPOSITE_IMAGE_EXPOSURE_TIMES = 0xA462;

    public const int GAMMA = 0xA500;

    // GPS sub IFD
    public const int GPS_LATITUDE_REF = 0x0001;

    public const int GPS_LATITUDE = 0x0002;

    public const int GPS_LONGITUDE_REF = 0x0003;

    public const int GPS_LONGITUDE = 0x0004;

    public const int GPS_ALTITUDE_REF = 0x0005;

    public const int GPS_ALTITUDE = 0x0006;

    // Interoperability IFD
    public const int INTEROPERABILITY_INDEX = 0x0001;

    public const int INTEROPERABILITY_VERSION = 0x0002;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
