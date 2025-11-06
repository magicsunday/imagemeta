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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests tag categorization and verifies EXIF 3.0 specification compliance.
 * 
 * This test suite validates:
 * - All official EXIF 3.0 tags (Tables 64-67) are present
 * - Non-EXIF tags are identifiable
 * - Tag sources are properly documented
 */
#[CoversClass(ExifTag::class)]
final class ExifTagSourcesTest extends TestCase
{
    /**
     * Official EXIF 3.0 tags from Table 64 (0th IFD TIFF Tags).
     * 
     * EXIF 3.0 §H.6 Table 64
     */
    #[Test]
    public function exif30Table64TagsArePresent(): void
    {
        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();

        $expectedTags = [
            'IMAGE_WIDTH'                    => 0x0100,
            'IMAGE_HEIGHT'                   => 0x0101,
            'BITS_PER_SAMPLE'                => 0x0102,
            'COMPRESSION'                    => 0x0103,
            'PHOTOMETRIC_INTERPRETATION'     => 0x0106,
            'IMAGE_DESCRIPTION'              => 0x010E,
            'MAKE'                           => 0x010F,
            'MODEL'                          => 0x0110,
            'STRIP_OFFSETS'                  => 0x0111,
            'ORIENTATION'                    => 0x0112,
            'SAMPLES_PER_PIXEL'              => 0x0115,
            'ROWS_PER_STRIP'                 => 0x0116,
            'STRIP_BYTE_COUNTS'              => 0x0117,
            'X_RESOLUTION'                   => 0x011A,
            'Y_RESOLUTION'                   => 0x011B,
            'PLANAR_CONFIGURATION'           => 0x011C,
            'RESOLUTION_UNIT'                => 0x0128,
            'TRANSFER_FUNCTION'              => 0x012D,
            'SOFTWARE'                       => 0x0131,
            'DATETIME'                       => 0x0132,
            'ARTIST'                         => 0x013B,
            'WHITE_POINT'                    => 0x013E,
            'PRIMARY_CHROMATICITIES'         => 0x013F,
            'JPEG_INTERCHANGE_FORMAT'        => 0x0201,
            'JPEG_INTERCHANGE_FORMAT_LENGTH' => 0x0202,
            'YCBCR_COEFFICIENTS'             => 0x0211,
            'YCBCR_SUB_SAMPLING'             => 0x0212,
            'YCBCR_POSITIONING'              => 0x0213,
            'REFERENCE_BLACK_WHITE'          => 0x0214,
            'COPYRIGHT'                      => 0x8298,
            'EXIF_IFD_POINTER'               => 0x8769,
            'GPS_IFD_POINTER'                => 0x8825,
        ];

        foreach ($expectedTags as $name => $expectedValue) {
            self::assertArrayHasKey(
                $name,
                $constants,
                sprintf('EXIF 3.0 Table 64 tag %s should be present', $name),
            );
            self::assertSame(
                $expectedValue,
                $constants[$name],
                sprintf('EXIF 3.0 Table 64 tag %s should have value 0x%04X', $name, $expectedValue),
            );
        }
    }

    /**
     * Official EXIF 3.0 tags from Table 65 (Exif Private Tags).
     * 
     * EXIF 3.0 §H.6 Table 65
     */
    #[Test]
    public function exif30Table65TagsArePresent(): void
    {
        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();

        $expectedTags = [
            'EXPOSURE_TIME'                            => 0x829A,
            'F_NUMBER'                                 => 0x829D,
            'EXPOSURE_PROGRAM'                         => 0x8822,
            'SPECTRAL_SENSITIVITY'                     => 0x8824,
            'PHOTOGRAPHIC_SENSITIVITY'                 => 0x8827,
            'OECF'                                     => 0x8828,
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
            'USER_COMMENT'                             => 0x9286,
            'SUB_SEC_TIME'                             => 0x9290,
            'SUB_SEC_TIME_ORIGINAL'                    => 0x9291,
            'SUB_SEC_TIME_DIGITIZED'                   => 0x9292,
            'TEMPERATURE'                              => 0x9400,
            'HUMIDITY'                                 => 0x9401,
            'PRESSURE'                                 => 0x9402,
            'WATER_DEPTH'                              => 0x9403,
            'ACCELERATION'                             => 0x9404,
            'CAMERA_ELEVATION_ANGLE'                   => 0x9405,
            'FLASHPIX_VERSION'                         => 0xA000,
            'COLOR_SPACE'                              => 0xA001,
            'PIXEL_X_DIMENSION'                        => 0xA002,
            'PIXEL_Y_DIMENSION'                        => 0xA003,
            'RELATED_SOUND_FILE'                       => 0xA004,
            'INTEROPERABILITY_IFD_POINTER'             => 0xA005,
            'FLASH_ENERGY'                             => 0xA20B,
            'SPATIAL_FREQUENCY_RESPONSE'               => 0xA20C,
            'FOCAL_PLANE_X_RESOLUTION'                 => 0xA20E,
            'FOCAL_PLANE_Y_RESOLUTION'                 => 0xA20F,
            'FOCAL_PLANE_RESOLUTION_UNIT'              => 0xA210,
            'SUBJECT_LOCATION'                         => 0xA214,
            'EXPOSURE_INDEX'                           => 0xA215,
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
            'LENS_SPECIFICATION'                       => 0xA432,
            'LENS_MAKE'                                => 0xA433,
            'LENS_MODEL'                               => 0xA434,
            'LENS_SERIAL_NUMBER'                       => 0xA435,
            'COMPOSITE_IMAGE'                          => 0xA460,
            'SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE'   => 0xA461,
            'SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE' => 0xA462,
            'GAMMA'                                    => 0xA500,
            'IMAGE_TITLE'                              => 0xA436,
            'PHOTOGRAPHER'                             => 0xA437,
            'IMAGE_EDITOR'                             => 0xA438,
            'CAMERA_FIRMWARE'                          => 0xA439,
            'RAW_DEVELOPING_SOFTWARE'                  => 0xA43A,
            'IMAGE_EDITING_SOFTWARE'                   => 0xA43B,
            'METADATA_EDITING_SOFTWARE'                => 0xA43C,
        ];

        foreach ($expectedTags as $name => $expectedValue) {
            self::assertArrayHasKey(
                $name,
                $constants,
                sprintf('EXIF 3.0 Table 65 tag %s should be present', $name),
            );
            self::assertSame(
                $expectedValue,
                $constants[$name],
                sprintf('EXIF 3.0 Table 65 tag %s should have value 0x%04X', $name, $expectedValue),
            );
        }
    }

    /**
     * Official EXIF 3.0 tags from Table 66 (GPS Info Tags).
     * 
     * EXIF 3.0 §H.6 Table 66
     */
    #[Test]
    public function exif30Table66GpsTagsArePresent(): void
    {
        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();

        $expectedTags = [
            'GPS_VERSION_ID'         => 0x0000,
            'GPS_LATITUDE_REF'       => 0x0001,
            'GPS_LATITUDE'           => 0x0002,
            'GPS_LONGITUDE_REF'      => 0x0003,
            'GPS_LONGITUDE'          => 0x0004,
            'GPS_ALTITUDE_REF'       => 0x0005,
            'GPS_ALTITUDE'           => 0x0006,
            'GPS_TIME_STAMP'         => 0x0007,
            'GPS_SATELLITES'         => 0x0008,
            'GPS_STATUS'             => 0x0009,
            'GPS_MEASURE_MODE'       => 0x000A,
            'GPS_DOP'                => 0x000B,
            'GPS_SPEED_REF'          => 0x000C,
            'GPS_SPEED'              => 0x000D,
            'GPS_TRACK_REF'          => 0x000E,
            'GPS_TRACK'              => 0x000F,
            'GPS_IMG_DIRECTION_REF'  => 0x0010,
            'GPS_IMG_DIRECTION'      => 0x0011,
            'GPS_MAP_DATUM'          => 0x0012,
            'GPS_DEST_LATITUDE_REF'  => 0x0013,
            'GPS_DEST_LATITUDE'      => 0x0014,
            'GPS_DEST_LONGITUDE_REF' => 0x0015,
            'GPS_DEST_LONGITUDE'     => 0x0016,
            'GPS_DEST_BEARING_REF'   => 0x0017,
            'GPS_DEST_BEARING'       => 0x0018,
            'GPS_DEST_DISTANCE_REF'  => 0x0019,
            'GPS_DEST_DISTANCE'      => 0x001A,
            'GPS_PROCESSING_METHOD'  => 0x001B,
            'GPS_AREA_INFORMATION'   => 0x001C,
            'GPS_DATE_STAMP'         => 0x001D,
            'GPS_DIFFERENTIAL'       => 0x001E,
            'GPS_H_POSITIONING_ERROR' => 0x001F,
        ];

        foreach ($expectedTags as $name => $expectedValue) {
            self::assertArrayHasKey(
                $name,
                $constants,
                sprintf('EXIF 3.0 Table 66 GPS tag %s should be present', $name),
            );
            self::assertSame(
                $expectedValue,
                $constants[$name],
                sprintf('EXIF 3.0 Table 66 GPS tag %s should have value 0x%04X', $name, $expectedValue),
            );
        }
    }

    /**
     * Official EXIF 3.0 tags from Table 67 (Interoperability Tags).
     * 
     * EXIF 3.0 §H.6 Table 67
     */
    #[Test]
    public function exif30Table67InteropTagsArePresent(): void
    {
        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();

        $expectedTags = [
            'INTEROPERABILITY_INDEX' => 0x0001,
        ];

        foreach ($expectedTags as $name => $expectedValue) {
            self::assertArrayHasKey(
                $name,
                $constants,
                sprintf('EXIF 3.0 Table 67 tag %s should be present', $name),
            );
            self::assertSame(
                $expectedValue,
                $constants[$name],
                sprintf('EXIF 3.0 Table 67 tag %s should have value 0x%04X', $name, $expectedValue),
            );
        }
    }

    /**
     * EXIF 3.0 adds camera orientation tags (not in older versions).
     * 
     * EXIF 3.0 §4.6 (new in version 3.0)
     */
    #[Test]
    public function exif30OrientationTagsArePresent(): void
    {
        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();

        $expectedTags = [
            'CAMERA_YAW_DEGREE'   => 0x9406,
            'CAMERA_PITCH_DEGREE' => 0x9407,
            'CAMERA_ROLL_DEGREE'  => 0x9408,
            'GIMBAL_YAW_DEGREE'   => 0x9409,
            'GIMBAL_PITCH_DEGREE' => 0x940A,
            'GIMBAL_ROLL_DEGREE'  => 0x940B,
            'AIRCRAFT_MAKE'       => 0x940C,
            'AIRCRAFT_MODEL'      => 0x940D,
        ];

        foreach ($expectedTags as $name => $expectedValue) {
            self::assertArrayHasKey(
                $name,
                $constants,
                sprintf('EXIF 3.0 orientation tag %s should be present', $name),
            );
            self::assertSame(
                $expectedValue,
                $constants[$name],
                sprintf('EXIF 3.0 orientation tag %s should have value 0x%04X', $name, $expectedValue),
            );
        }
    }

    /**
     * Microsoft XP tags are proprietary Windows extensions.
     */
    #[Test]
    public function microsoftXpTagsAreIdentifiable(): void
    {
        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();

        $microsoftXpTags = [
            'XP_TITLE'    => 0x9C9B,
            'XP_COMMENT'  => 0x9C9C,
            'XP_AUTHOR'   => 0x9C9D,
            'XP_KEYWORDS' => 0x9C9E,
            'XP_SUBJECT'  => 0x9C9F,
        ];

        foreach ($microsoftXpTags as $name => $expectedValue) {
            self::assertArrayHasKey(
                $name,
                $constants,
                sprintf('Microsoft XP tag %s should be present for compatibility', $name),
            );
            self::assertSame(
                $expectedValue,
                $constants[$name],
                sprintf('Microsoft XP tag %s should have value 0x%04X', $name, $expectedValue),
            );
        }
    }

    /**
     * TIFF 6.0 baseline tags that are not in EXIF 3.0 tables.
     */
    #[Test]
    public function tiff60TagsAreIdentifiable(): void
    {
        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();

        $tiffTags = [
            'NEW_SUBFILE_TYPE' => 0x00FE,
            'SUBFILE_TYPE'     => 0x00FF,
            'PREDICTOR'        => 0x013D,
            'ICC_PROFILE'      => 0x8773,
        ];

        foreach ($tiffTags as $name => $expectedValue) {
            self::assertArrayHasKey(
                $name,
                $constants,
                sprintf('TIFF 6.0 tag %s should be present', $name),
            );
            self::assertSame(
                $expectedValue,
                $constants[$name],
                sprintf('TIFF 6.0 tag %s should have value 0x%04X', $name, $expectedValue),
            );
        }
    }

    /**
     * Adobe DNG tags should be identifiable (though they belong in DngTag.php).
     */
    #[Test]
    public function dngTagsAreMinimalInExifTag(): void
    {
        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();

        // These DNG tags appear in ExifTag.php but should ideally be in DngTag.php
        $dngTagsInExifTag = [
            'CAMERA_CALIBRATION_SIGNATURE'  => 0xC6F3,
            'PROFILE_CALIBRATION_SIGNATURE' => 0xC6F4,
            'CAMERA_SERIAL_NUMBER'          => 0xC62F,
        ];

        foreach ($dngTagsInExifTag as $name => $expectedValue) {
            self::assertArrayHasKey(
                $name,
                $constants,
                sprintf('DNG tag %s is present in ExifTag (consider moving to DngTag)', $name),
            );
            self::assertSame(
                $expectedValue,
                $constants[$name],
                sprintf('DNG tag %s should have value 0x%04X', $name, $expectedValue),
            );
        }
    }

    /**
     * Legacy tags maintain backwards compatibility.
     */
    #[Test]
    public function legacyTagsAreRetainedForCompatibility(): void
    {
        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();

        // Legacy tags that are aliases or deprecated
        $legacyTags = [
            'ISO_SPEED_RATINGS_LEGACY' => 0x8827, // Same as PHOTOGRAPHIC_SENSITIVITY
            'HOST_COMPUTER'            => 0x013C, // Removed from EXIF 3.0
        ];

        foreach ($legacyTags as $name => $expectedValue) {
            self::assertArrayHasKey(
                $name,
                $constants,
                sprintf('Legacy tag %s should be present for backwards compatibility', $name),
            );
            self::assertSame(
                $expectedValue,
                $constants[$name],
                sprintf('Legacy tag %s should have value 0x%04X', $name, $expectedValue),
            );
        }
    }

    /**
     * Verifies that the DATETIME constant is aliased as MODIFY_DATE.
     * 
     * EXIF 3.0 renamed DateTime to ModifyDate for clarity.
     */
    #[Test]
    public function modifyDateIsAliasForDateTime(): void
    {
        $reflection = new ReflectionClass(ExifTag::class);
        $constants  = $reflection->getConstants();

        self::assertSame(
            $constants['DATETIME'] ?? null,
            $constants['MODIFY_DATE'] ?? null,
            'MODIFY_DATE should be an alias for DATETIME',
        );
    }
}
