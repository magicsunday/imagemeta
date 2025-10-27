<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \MagicSunday\ImageMeta\Model\Exif\ExifTag
 */
#[CoversClass(ExifTag::class)]
final class ExifTagTest extends TestCase
{
    /**
     * Ensures the constant map matches the EXIF 3.0 tag registry with legacy additions.
     */
    public function testConstantsMatchExif30(): void
    {
        $expected = [
            // GPS sub IFD
            'GPS_VERSION_ID'          => 0x0000,
            'GPS_LATITUDE_REF'        => 0x0001,
            'GPS_LATITUDE'            => 0x0002,
            'GPS_LONGITUDE_REF'       => 0x0003,
            'GPS_LONGITUDE'           => 0x0004,
            'GPS_ALTITUDE_REF'        => 0x0005,
            'GPS_ALTITUDE'            => 0x0006,
            'GPS_TIME_STAMP'          => 0x0007,
            'GPS_SATELLITES'          => 0x0008,
            'GPS_STATUS'              => 0x0009,
            'GPS_MEASURE_MODE'        => 0x000A,
            'GPS_DOP'                 => 0x000B,
            'GPS_SPEED_REF'           => 0x000C,
            'GPS_SPEED'               => 0x000D,
            'GPS_TRACK_REF'           => 0x000E,
            'GPS_TRACK'               => 0x000F,
            'GPS_IMG_DIRECTION_REF'   => 0x0010,
            'GPS_IMG_DIRECTION'       => 0x0011,
            'GPS_MAP_DATUM'           => 0x0012,
            'GPS_DEST_LATITUDE_REF'   => 0x0013,
            'GPS_DEST_LATITUDE'       => 0x0014,
            'GPS_DEST_LONGITUDE_REF'  => 0x0015,
            'GPS_DEST_LONGITUDE'      => 0x0016,
            'GPS_DEST_BEARING_REF'    => 0x0017,
            'GPS_DEST_BEARING'        => 0x0018,
            'GPS_DEST_DISTANCE_REF'   => 0x0019,
            'GPS_DEST_DISTANCE'       => 0x001A,
            'GPS_PROCESSING_METHOD'   => 0x001B,
            'GPS_AREA_INFORMATION'    => 0x001C,
            'GPS_DATE_STAMP'          => 0x001D,
            'GPS_DIFFERENTIAL'        => 0x001E,
            'GPS_H_POSITIONING_ERROR' => 0x001F,

            // Image file directory (IFD0)
            'NEW_SUBFILE_TYPE'             => 0x00FE,
            'SUBFILE_TYPE'                 => 0x00FF,
            'PROCESSING_SOFTWARE'           => 0x000B,
            'IMAGE_WIDTH'                    => 0x0100,
            'IMAGE_HEIGHT'                   => 0x0101,
            'BITS_PER_SAMPLE'                => 0x0102,
            'COMPRESSION'                    => 0x0103,
            'PHOTOMETRIC_INTERPRETATION'     => 0x0106,
            'DOCUMENT_NAME'                   => 0x010D,
            'IMAGE_DESCRIPTION'              => 0x010E,
            'IMAGE_TITLE'                    => 0xA436,
            'XP_TITLE'                       => 0x9C9B,
            'XP_COMMENT'                     => 0x9C9C,
            'XP_AUTHOR'                      => 0x9C9D,
            'XP_KEYWORDS'                    => 0x9C9E,
            'XP_SUBJECT'                     => 0x9C9F,
            'IMAGE_TITLE_LEGACY'             => 0x0320,
            'MAKE'                           => 0x010F,
            'MODEL'                          => 0x0110,
            'STRIP_OFFSETS'                  => 0x0111,
            'ORIENTATION'                    => 0x0112,
            'SAMPLES_PER_PIXEL'              => 0x0115,
            'ROWS_PER_STRIP'                 => 0x0116,
            'STRIP_BYTE_COUNTS'              => 0x0117,
            'TILE_WIDTH'                     => 0x0142,
            'TILE_LENGTH'                    => 0x0143,
            'TILE_OFFSETS'                   => 0x0144,
            'TILE_BYTE_COUNTS'               => 0x0145,
            'X_RESOLUTION'                   => 0x011A,
            'Y_RESOLUTION'                   => 0x011B,
            'PLANAR_CONFIGURATION'           => 0x011C,
            'RESOLUTION_UNIT'                => 0x0128,
            'TRANSFER_FUNCTION'              => 0x012D,
            'SOFTWARE'                       => 0x0131,
            'DATETIME'                       => 0x0132,
            'MODIFY_DATE'                    => 0x0132,
            'ARTIST'                         => 0x013B,
            'WHITE_POINT'                    => 0x013E,
            'PRIMARY_CHROMATICITIES'         => 0x013F,
            'TILE_WIDTH'                    => 0x0142,
            'TILE_LENGTH'                   => 0x0143,
            'TILE_OFFSETS'                  => 0x0144,
            'TILE_BYTE_COUNTS'              => 0x0145,
            'JPEG_INTERCHANGE_FORMAT'        => 0x0201,
            'JPEG_INTERCHANGE_FORMAT_LENGTH' => 0x0202,
            'PREVIEW_IMAGE_START'            => 0xC51B,
            'PREVIEW_IMAGE_LENGTH'           => 0xC51C,
            'PREVIEW_IMAGE_ENCODING'         => 0xC51D,
            'PREVIEW_IMAGE_MIME_TYPE'        => 0xC51E,
            'PREVIEW_IMAGE_WIDTH'            => 0xC51F,
            'PREVIEW_IMAGE_HEIGHT'           => 0xC520,
            'PREVIEW_IMAGE_COLOR_SPACE'      => 0xC521,
            'PREVIEW_IMAGE_BIT_DEPTH'        => 0xC522,
            'PREVIEW_DATE_TIME'              => 0xC523,
            'PREVIEW_DATE_TIME_DIGITIZED'    => 0xC524,
            'YCBCR_COEFFICIENTS'             => 0x0211,
            'YCBCR_SUB_SAMPLING'             => 0x0212,
            'YCBCR_POSITIONING'              => 0x0213,
            'REFERENCE_BLACK_WHITE'          => 0x0214,
            'COPYRIGHT'                      => 0x8298,
            'PHOTOGRAPHER'                   => 0xA437,
            'PHOTOGRAPHER_LEGACY'            => 0xE92D,
            'IMAGE_EDITOR'                   => 0xA438,
            'IMAGE_EDITOR_LEGACY'            => 0xE92E,

            // Pointer tags
            'SUB_IFDS'                    => 0x014A,
            'EXIF_IFD_POINTER'             => 0x8769,
            'GPS_IFD_POINTER'              => 0x8825,
            'INTEROPERABILITY_IFD_POINTER' => 0xA005,

            // EXIF sub IFD
            'CFA_REPEAT_PATTERN_DIM'                 => 0x828D,
            'BATTERY_LEVEL'                          => 0x828F,
            'EXPOSURE_TIME'                            => 0x829A,
            'F_NUMBER'                                 => 0x829D,
            'EXPOSURE_PROGRAM'                         => 0x8822,
            'SPECTRAL_SENSITIVITY'                     => 0x8824,
            'ISO_SPEED_RATINGS_LEGACY'                 => 0x8827,
            'PHOTOGRAPHIC_SENSITIVITY'                 => 0x8827,
            'OECF'                                     => 0x8828,
            'INTERLACE'                                => 0x8829,
            'TIME_ZONE_OFFSET'                         => 0x882A,
            'SELF_TIMER_MODE'                          => 0x882B,
            'SENSITIVITY_TYPE'                         => 0x8830,
            'STANDARD_OUTPUT_SENSITIVITY'              => 0x8831,
            'RECOMMENDED_EXPOSURE_INDEX'               => 0x8832,
            'ISO_SPEED'                                => 0x8833,
            'ISO_SPEED_LATITUDE_YYY'                   => 0x8834,
            'ISO_SPEED_LATITUDE_ZZZ'                   => 0x8835,
            'EXIF_VERSION'                             => 0x9000,
            'DATETIME_ORIGINAL'                        => 0x9003,
            'DATETIME_DIGITIZED'                       => 0x9004,
            'OFFSET_TIME'                              => 0x9010,
            'OFFSET_TIME_ORIGINAL'                     => 0x9011,
            'OFFSET_TIME_DIGITIZED'                    => 0x9012,
            'COMPONENTS_CONFIGURATION'                 => 0x9101,
            'COMPRESSED_BITS_PER_PIXEL'                => 0x9102,
            'SHUTTER_SPEED_VALUE'                      => 0x9201,
            'APERTURE_VALUE'                           => 0x9202,
            'BRIGHTNESS_VALUE'                         => 0x9203,
            'EXPOSURE_BIAS_VALUE'                      => 0x9204,
            'MAX_APERTURE_VALUE'                       => 0x9205,
            'SUBJECT_DISTANCE'                         => 0x9206,
            'METERING_MODE'                            => 0x9207,
            'LIGHT_SOURCE'                             => 0x9208,
            'FLASH'                                    => 0x9209,
            'FOCAL_LENGTH'                             => 0x920A,
            'SUBJECT_AREA'                             => 0x9214,
            'MAKER_NOTE'                               => 0x927C,
            'PRINT_IMAGE_MATCHING'                     => 0xC4A5,
            'MAKER_NOTE_SAFETY'                        => 0xC635,
            'PROFILE_HUE_SAT_MAP_DIMS'                 => 0xC6F6,
            'PROFILE_HUE_SAT_MAP_DATA_1'               => 0xC6F7,
            'PROFILE_HUE_SAT_MAP_DATA_2'               => 0xC6F8,
            'PROFILE_HUE_SAT_MAP_DATA_3'               => 0xC6F9,
            'PROFILE_LOOK_TABLE_DIMS'                  => 0xC6FA,
            'PROFILE_LOOK_TABLE_DATA'                  => 0xC6FB,
            'PROFILE_TONE_CURVE'                       => 0xC6FC,
            'PROFILE_CALIBRATION_SIGNATURE'            => 0xC6F4,
            'USER_COMMENT'                             => 0x9286,
            'SUB_SEC_TIME'                             => 0x9290,
            'SUB_SEC_TIME_ORIGINAL'                    => 0x9291,
            'SUB_SEC_TIME_DIGITIZED'                   => 0x9292,
            'FLASHPIX_VERSION'                         => 0xA000,
            'COLOR_SPACE'                              => 0xA001,
            'PIXEL_X_DIMENSION'                        => 0xA002,
            'PIXEL_Y_DIMENSION'                        => 0xA003,
            'RELATED_SOUND_FILE'                       => 0xA004,
            'FLASH_ENERGY'                             => 0xA20B,
            'SPATIAL_FREQUENCY_RESPONSE'               => 0xA20C,
            'NOISE'                                    => 0xA20D,
            'FOCAL_PLANE_X_RESOLUTION'                 => 0xA20E,
            'FOCAL_PLANE_Y_RESOLUTION'                 => 0xA20F,
            'FOCAL_PLANE_RESOLUTION_UNIT'              => 0xA210,
            'IMAGE_NUMBER'                             => 0xA211,
            'SECURITY_CLASSIFICATION'                  => 0xA212,
            'IMAGE_HISTORY'                            => 0xA213,
            'SUBJECT_LOCATION'                         => 0xA214,
            'EXPOSURE_INDEX'                           => 0xA215,
            'TIFF_EP_STANDARD_ID'                      => 0xA216,
            'SENSING_METHOD'                           => 0xA217,
            'FILE_SOURCE'                              => 0xA300,
            'SCENE_TYPE'                               => 0xA301,
            'CFA_PATTERN'                              => 0xA302,
            'CUSTOM_RENDERED'                          => 0xA401,
            'EXPOSURE_MODE'                            => 0xA402,
            'WHITE_BALANCE'                            => 0xA403,
            'DIGITAL_ZOOM_RATIO'                       => 0xA404,
            'FOCAL_LENGTH_IN_35MM_FILM'                => 0xA405,
            'SCENE_CAPTURE_TYPE'                       => 0xA406,
            'GAIN_CONTROL'                             => 0xA407,
            'CONTRAST'                                 => 0xA408,
            'SATURATION'                               => 0xA409,
            'SHARPNESS'                                => 0xA40A,
            'DEVICE_SETTING_DESCRIPTION'               => 0xA40B,
            'SUBJECT_DISTANCE_RANGE'                   => 0xA40C,
            'IMAGE_UNIQUE_ID'                          => 0xA420,
            'CAMERA_OWNER_NAME'                        => 0xA430,
            'BODY_SERIAL_NUMBER'                       => 0xA431,
            'CAMERA_SERIAL_NUMBER'                     => 0xC62F,
            'LENS_SPECIFICATION'                       => 0xA432,
            'LENS_MAKE'                                => 0xA433,
            'LENS_MODEL'                               => 0xA434,
            'LENS_SERIAL_NUMBER'                       => 0xA435,
            'CAMERA_FIRMWARE_VERSION_LEGACY'           => 0xA436,
            'CAMERA_FIRMWARE'                          => 0xA439,
            'RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY'   => 0xA439,
            'RAW_DEVELOPING_SOFTWARE'                  => 0xA43A,
            'IMAGE_EDITING_SOFTWARE_VERSION_LEGACY'    => 0xA43B,
            'IMAGE_EDITING_SOFTWARE'                   => 0xA43B,
            'METADATA_EDITING_SOFTWARE_VERSION_LEGACY' => 0xA43C,
            'METADATA_EDITING_SOFTWARE'                => 0xA43C,
            'COMPOSITE_IMAGE'                          => 0xA460,
            'SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE'   => 0xA461,
            'SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE' => 0xA462,
            'GAMMA'                                    => 0xA500,

            // Environmental sensing and processing notes
            'TEMPERATURE'                      => 0x9400,
            'HUMIDITY'                         => 0x9401,
            'PRESSURE'                         => 0x9402,
            'WATER_DEPTH'                      => 0x9403,
            'ACCELERATION'                     => 0x9404,
            'CAMERA_CALIBRATION_SIGNATURE'     => 0xC6F3,
            'CAMERA_ELEVATION_ANGLE'           => 0x9405,
            'CAMERA_YAW_DEGREE'                => 0x9406,
            'CAMERA_PITCH_DEGREE'              => 0x9407,
            'CAMERA_ROLL_DEGREE'               => 0x9408,
            'FLIGHT_YAW_DEGREE'                => 0x9406,
            'FLIGHT_PITCH_DEGREE'              => 0x9407,
            'FLIGHT_ROLL_DEGREE'               => 0x9408,
            'GIMBAL_YAW_DEGREE'                => 0x9409,
            'GIMBAL_PITCH_DEGREE'              => 0x940A,
            'GIMBAL_ROLL_DEGREE'               => 0x940B,
            'AIRCRAFT_MAKE'                    => 0x940C,
            'AIRCRAFT_MODEL'                   => 0x940D,
            'CAMERA_FIRMWARE_LEGACY'           => 0xE92F,
            'RAW_DEVELOPING_SOFTWARE_LEGACY'   => 0xE930,
            'IMAGE_EDITING_SOFTWARE_LEGACY'    => 0xE931,
            'METADATA_EDITING_SOFTWARE_LEGACY' => 0xE932,

            // Interoperability IFD
            'INTEROPERABILITY_INDEX'   => 0x0001,
            'INTEROPERABILITY_VERSION' => 0x0002,
            'RELATED_IMAGE_FILE_FORMAT' => 0x1000,
            'RELATED_IMAGE_WIDTH'       => 0x1001,
            'RELATED_IMAGE_LENGTH'      => 0x1002,
        ];

        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();
        ksort($constants);
        ksort($expected);

        $legacyOnly = [
            'HOST_COMPUTER' => 0x013C,
        ];

        $combined = $expected + $legacyOnly;
        ksort($combined);

        self::assertSame($combined, $constants);
    }

    /**
     * Ensures the legacy HostComputer tag remains available for EXIF 2.x images.
     */
    public function testHostComputerConstantIsRetained(): void
    {
        self::assertSame(0x013C, ExifTag::HOST_COMPUTER);
    }

    /**
     * Ensures the TIFF subfile type identifiers match the registry values.
     */
    public function testSubfileTypeConstantsMatchSpecification(): void
    {
        self::assertSame(0x00FE, ExifTag::NEW_SUBFILE_TYPE);
        self::assertSame(0x00FF, ExifTag::SUBFILE_TYPE);
    }

    /**
     * Ensures the ModifyDate alias shares the DateTime identifier.
     */
    public function testModifyDateAliasMatchesDateTime(): void
    {
        self::assertSame(ExifTag::DATETIME, ExifTag::MODIFY_DATE);
    }

    /**
     * Ensures the ISO Speed Ratings alias shares the PhotographicSensitivity identifier.
     */
    public function testIsoSpeedRatingsLegacyAliasMatchesPhotographicSensitivity(): void
    {
        self::assertSame(
            ExifTag::PHOTOGRAPHIC_SENSITIVITY,
            ExifTag::ISO_SPEED_RATINGS_LEGACY
        );
    }
}
