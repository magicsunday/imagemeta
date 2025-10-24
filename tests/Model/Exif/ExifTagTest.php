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
     * Ensures the constant map exactly reflects the EXIF 3.0 tag registry.
     */
    public function testConstantsMatchExif30(): void
    {
        $expected = [
            'APERTURE_VALUE'                 => 0x9202,
            'ARTIST'                         => 0x013B,
            'BITS_PER_SAMPLE'                => 0x0102,
            'BODY_SERIAL_NUMBER'             => 0xA431,
            'BRIGHTNESS_VALUE'               => 0x9203,
            'CAMERA_OWNER_NAME'              => 0xA430,
            'COLOR_SPACE'                    => 0xA001,
            'COMPONENTS_CONFIGURATION'       => 0x9101,
            'COMPRESSED_BITS_PER_PIXEL'      => 0x9102,
            'COMPOSITE_IMAGE'                => 0xA460,
            'COMPOSITE_IMAGE_COUNT'          => 0xA461,
            'COMPOSITE_IMAGE_EXPOSURE_TIMES' => 0xA462,
            'COMPRESSION'                    => 0x0103,
            'CONTRAST'                       => 0xA408,
            'COPYRIGHT'                      => 0x8298,
            'CUSTOM_RENDERED'                => 0xA401,
            'DATETIME'                       => 0x0132,
            'DATETIME_DIGITIZED'             => 0x9004,
            'DATETIME_ORIGINAL'              => 0x9003,
            'DIGITAL_ZOOM_RATIO'             => 0xA404,
            'EXIF_IFD_POINTER'               => 0x8769,
            'EXIF_IMAGE_HEIGHT'              => 0xA003,
            'EXIF_IMAGE_WIDTH'               => 0xA002,
            'EXIF_VERSION'                   => 0x9000,
            'EXPOSURE_BIAS_VALUE'            => 0x9204,
            'EXPOSURE_MODE'                  => 0xA402,
            'EXPOSURE_PROGRAM'               => 0x8822,
            'EXPOSURE_TIME'                  => 0x829A,
            'F_NUMBER'                       => 0x829D,
            'FILE_SOURCE'                    => 0xA300,
            'FLASH'                          => 0x9209,
            'FLASHPIX_VERSION'               => 0xA000,
            'FOCAL_LENGTH'                   => 0x920A,
            'FOCAL_LENGTH_IN_35MM_FILM'      => 0xA405,
            'GAIN_CONTROL'                   => 0xA407,
            'GAMMA'                          => 0xA500,
            'GPS_ALTITUDE'                   => 0x0006,
            'GPS_ALTITUDE_REF'               => 0x0005,
            'GPS_AREA_INFORMATION'           => 0x001C,
            'GPS_DATE_STAMP'                 => 0x001D,
            'GPS_DEST_BEARING'               => 0x0018,
            'GPS_DEST_BEARING_REF'           => 0x0017,
            'GPS_DEST_DISTANCE'              => 0x001A,
            'GPS_DEST_DISTANCE_REF'          => 0x0019,
            'GPS_DEST_LATITUDE'              => 0x0014,
            'GPS_DEST_LATITUDE_REF'          => 0x0013,
            'GPS_DEST_LONGITUDE'             => 0x0016,
            'GPS_DEST_LONGITUDE_REF'         => 0x0015,
            'GPS_DIFFERENTIAL'               => 0x001E,
            'GPS_DOP'                        => 0x000B,
            'GPS_H_POSITIONING_ERROR'        => 0x001F,
            'GPS_IFD_POINTER'                => 0x8825,
            'GPS_IMG_DIRECTION'              => 0x0011,
            'GPS_IMG_DIRECTION_REF'          => 0x0010,
            'GPS_LATITUDE'                   => 0x0002,
            'GPS_LATITUDE_REF'               => 0x0001,
            'GPS_LONGITUDE'                  => 0x0004,
            'GPS_LONGITUDE_REF'              => 0x0003,
            'GPS_MAP_DATUM'                  => 0x0012,
            'GPS_MEASURE_MODE'               => 0x000A,
            'GPS_PROCESSING_METHOD'          => 0x001B,
            'GPS_SATELLITES'                 => 0x0008,
            'GPS_SPEED'                      => 0x000D,
            'GPS_SPEED_REF'                  => 0x000C,
            'GPS_STATUS'                     => 0x0009,
            'GPS_TIME_STAMP'                 => 0x0007,
            'GPS_TRACK'                      => 0x000F,
            'GPS_TRACK_REF'                  => 0x000E,
            'GPS_VERSION_ID'                 => 0x0000,
            'IMAGE_DESCRIPTION'              => 0x010E,
            'IMAGE_HEIGHT'                   => 0x0101,
            'IMAGE_UNIQUE_ID'                => 0xA420,
            'IMAGE_WIDTH'                    => 0x0100,
            'INTEROPERABILITY_IFD_POINTER'   => 0xA005,
            'INTEROPERABILITY_INDEX'         => 0x0001,
            'INTEROPERABILITY_VERSION'       => 0x0002,
            'ISO_SPEED'                      => 0x8833,
            'JPEG_INTERCHANGE_FORMAT'        => 0x0201,
            'JPEG_INTERCHANGE_FORMAT_LENGTH' => 0x0202,
            'LENS_INFO'                      => 0xA432,
            'LENS_MAKE'                      => 0xA433,
            'LENS_MODEL'                     => 0xA434,
            'LENS_SERIAL_NUMBER'             => 0xA435,
            'LIGHT_SOURCE'                   => 0x9208,
            'MAKE'                           => 0x010F,
            'MAKER_NOTE'                     => 0x927C,
            'MAX_APERTURE_VALUE'             => 0x9205,
            'METERING_MODE'                  => 0x9207,
            'MODEL'                          => 0x0110,
            'OFFSET_TIME'                    => 0x9010,
            'OFFSET_TIME_DIGITIZED'          => 0x9012,
            'OFFSET_TIME_ORIGINAL'           => 0x9011,
            'ORIENTATION'                    => 0x0112,
            'PHOTOGRAPHIC_SENSITIVITY'       => 0x8827,
            'PHOTOMETRIC_INTERPRETATION'     => 0x0106,
            'PLANAR_CONFIGURATION'           => 0x011C,
            'PRIMARY_CHROMATICITIES'         => 0x013F,
            'RECOMMENDED_EXPOSURE_INDEX'     => 0x8832,
            'REFERENCE_BLACK_WHITE'          => 0x0214,
            'RESOLUTION_UNIT'                => 0x0128,
            'ROWS_PER_STRIP'                 => 0x0116,
            'SAMPLES_PER_PIXEL'              => 0x0115,
            'SATURATION'                     => 0xA409,
            'SCENE_CAPTURE_TYPE'             => 0xA406,
            'SCENE_TYPE'                     => 0xA301,
            'SENSING_METHOD'                 => 0xA217,
            'SENSITIVITY_TYPE'               => 0x8830,
            'SHARPNESS'                      => 0xA40A,
            'SHUTTER_SPEED_VALUE'            => 0x9201,
            'SOFTWARE'                       => 0x0131,
            'STANDARD_OUTPUT_SENSITIVITY'    => 0x8831,
            'STRIP_BYTE_COUNTS'              => 0x0117,
            'STRIP_OFFSETS'                  => 0x0111,
            'SUB_SEC_TIME'                   => 0x9290,
            'SUB_SEC_TIME_DIGITIZED'         => 0x9292,
            'SUB_SEC_TIME_ORIGINAL'          => 0x9291,
            'SUBJECT_AREA'                   => 0x9214,
            'SUBJECT_DISTANCE'               => 0x9206,
            'SUBJECT_DISTANCE_RANGE'         => 0xA40C,
            'TRANSFER_FUNCTION'              => 0x012D,
            'USER_COMMENT'                   => 0x9286,
            'WHITE_BALANCE'                  => 0xA403,
            'WHITE_POINT'                    => 0x013E,
            'X_RESOLUTION'                   => 0x011A,
            'Y_RESOLUTION'                   => 0x011B,
            'YCBCR_COEFFICIENTS'             => 0x0211,
            'YCBCR_POSITIONING'              => 0x0213,
            'YCBCR_SUB_SAMPLING'             => 0x0212,
        ];

        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();
        ksort($constants);
        ksort($expected);

        self::assertSame($expected, $constants);
    }

    /**
     * Ensures removed EXIF tags such as HostComputer stay absent from the registry.
     */
    public function testRemovedTagsAreNotDeclared(): void
    {
        $constants = array_flip((new ReflectionClass(ExifTag::class))->getConstants());

        self::assertArrayNotHasKey(0x013C, $constants);
        self::assertArrayNotHasKey(0xA20E, $constants);
    }
}
