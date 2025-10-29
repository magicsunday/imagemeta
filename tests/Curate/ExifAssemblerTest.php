<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Curate\ExifAssembler;
use MagicSunday\ImageMeta\Curate\Structured\GpsCoordinate;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Mpf\MpfAttributes;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\Mpf\MpfEntry;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Tests\Fixtures\Icc\IccFixtures;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\DngProfileGainTableTag;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use MagicSunday\ImageMeta\Value\Regions\RegionType;
use MagicSunday\ImageMeta\Value\RunTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function pack;
use function str_repeat;

/**
 * @covers \MagicSunday\ImageMeta\Curate\ExifAssembler
 * @covers \MagicSunday\ImageMeta\Curate\StructuredMetadata
 * @covers \MagicSunday\ImageMeta\Curate\Exif\ValueFactory
 */
final class ExifAssemblerTest extends TestCase
{
    /**
     * Ensures DSLR style EXIF data is mapped to the extended value objects.
     */
    #[Test]
    public function buildsStructuredAggregateForDslrJpeg(): void
    {
        $oecfPayload = self::buildOecfPayload();
        $sfrPayload  = self::buildSpatialFrequencyResponsePayload();

        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH                    => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 6720),
            ExifTag::IMAGE_HEIGHT                   => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 4480),
            ExifTag::BITS_PER_SAMPLE                => new IfdEntry(ExifTag::BITS_PER_SAMPLE, 3, 3, new ExifNumericList([14, 14, 14])),
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::JPEG->value),
            ExifTag::PHOTOMETRIC_INTERPRETATION     => new IfdEntry(ExifTag::PHOTOMETRIC_INTERPRETATION, 3, 1, Photometric::YCBCR->value),
            ExifTag::PLANAR_CONFIGURATION           => new IfdEntry(ExifTag::PLANAR_CONFIGURATION, 3, 1, PlanarConfiguration::CHUNKY->value),
            ExifTag::RESOLUTION_UNIT                => new IfdEntry(ExifTag::RESOLUTION_UNIT, 3, 1, ResolutionUnit::INCHES->value),
            ExifTag::X_RESOLUTION                   => new IfdEntry(ExifTag::X_RESOLUTION, 5, 1, [[300, 1]]),
            ExifTag::Y_RESOLUTION                   => new IfdEntry(ExifTag::Y_RESOLUTION, 5, 1, [[300, 1]]),
            ExifTag::YCBCR_POSITIONING              => new IfdEntry(ExifTag::YCBCR_POSITIONING, 3, 1, YCbCrPositioning::CENTERED->value),
            ExifTag::YCBCR_SUB_SAMPLING             => new IfdEntry(ExifTag::YCBCR_SUB_SAMPLING, 3, 2, new ExifNumericList([2, 2])),
            ExifTag::YCBCR_COEFFICIENTS             => new IfdEntry(ExifTag::YCBCR_COEFFICIENTS, 5, 3, [[299, 1000], [587, 1000], [114, 1000]]),
            ExifTag::WHITE_POINT                    => new IfdEntry(ExifTag::WHITE_POINT, 5, 2, [[3127, 10000], [3290, 10000]]),
            ExifTag::PRIMARY_CHROMATICITIES         => new IfdEntry(ExifTag::PRIMARY_CHROMATICITIES, 5, 6, [[6400, 10000], [3300, 10000], [3000, 10000], [6000, 10000], [1500, 10000], [6000, 10000]]),
            ExifTag::TILE_WIDTH                     => new IfdEntry(ExifTag::TILE_WIDTH, 4, 1, 256),
            ExifTag::TILE_LENGTH                    => new IfdEntry(ExifTag::TILE_LENGTH, 4, 1, 256),
            ExifTag::STRIP_OFFSETS                  => new IfdEntry(ExifTag::STRIP_OFFSETS, 4, 3, new ExifNumericList([512, 1024, 1536])),
            ExifTag::STRIP_BYTE_COUNTS              => new IfdEntry(ExifTag::STRIP_BYTE_COUNTS, 4, 3, new ExifNumericList([2048, 2048, 1024])),
            ExifTag::TILE_OFFSETS                   => new IfdEntry(ExifTag::TILE_OFFSETS, 4, 3, new ExifNumericList([4096, 8192, 12288])),
            ExifTag::TILE_BYTE_COUNTS               => new IfdEntry(ExifTag::TILE_BYTE_COUNTS, 4, 3, new ExifNumericList([1024, 2048, 2048])),
            ExifTag::TRANSFER_FUNCTION              => new IfdEntry(ExifTag::TRANSFER_FUNCTION, 3, 3, new ExifNumericList([0, 32768, 65535])),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 24576),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 8192),
            ExifTag::REFERENCE_BLACK_WHITE          => new IfdEntry(ExifTag::REFERENCE_BLACK_WHITE, 5, 6, [[0, 1], [255, 1], [0, 1], [255, 1], [0, 1], [255, 1]]),
            ExifTag::COPYRIGHT                      => new IfdEntry(ExifTag::COPYRIGHT, 2, 13, 'Jane Doe 2024'),
            ExifTag::MAKE                           => new IfdEntry(ExifTag::MAKE, 2, 5, 'Canon'),
            ExifTag::MODEL                          => new IfdEntry(ExifTag::MODEL, 2, 8, 'EOS R6 II'),
            ExifTag::SOFTWARE                       => new IfdEntry(ExifTag::SOFTWARE, 2, 8, 'Firmware1'),
            ExifTag::PROCESSING_SOFTWARE            => new IfdEntry(ExifTag::PROCESSING_SOFTWARE, 2, 18, "ImageMeta Studio\0\0"),
            ExifTag::IMAGE_DESCRIPTION              => new IfdEntry(ExifTag::IMAGE_DESCRIPTION, 2, 16, 'Sunset over Alps'),
            ExifTag::ORIENTATION                    => new IfdEntry(ExifTag::ORIENTATION, 3, 1, Orientation::RIGHT_TOP->value),
            ExifTag::ARTIST                         => new IfdEntry(ExifTag::ARTIST, 2, 12, 'Jane Doe'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::IMAGE_TITLE               => new IfdEntry(ExifTag::IMAGE_TITLE, 2, 12, 'Sunset Title'),
            ExifTag::CAMERA_OWNER_NAME         => new IfdEntry(ExifTag::CAMERA_OWNER_NAME, 2, 10, 'Jane Owner'),
            ExifTag::PHOTOGRAPHER              => new IfdEntry(ExifTag::PHOTOGRAPHER, 2, 22, 'Jane D. Photographer'),
            ExifTag::IMAGE_EDITOR              => new IfdEntry(ExifTag::IMAGE_EDITOR, 2, 12, 'John Editor'),
            ExifTag::EXIF_VERSION              => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
            ExifTag::FLASHPIX_VERSION          => new IfdEntry(ExifTag::FLASHPIX_VERSION, 7, 4, '0100'),
            ExifTag::PREVIEW_IMAGE_START       => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 4, 1, 131_072),
            ExifTag::PREVIEW_IMAGE_LENGTH      => new IfdEntry(ExifTag::PREVIEW_IMAGE_LENGTH, 4, 1, 65_536),
            ExifTag::PREVIEW_IMAGE_WIDTH       => new IfdEntry(ExifTag::PREVIEW_IMAGE_WIDTH, 4, 1, 2_048),
            ExifTag::PREVIEW_IMAGE_HEIGHT      => new IfdEntry(ExifTag::PREVIEW_IMAGE_HEIGHT, 4, 1, 1_152),
            ExifTag::PREVIEW_IMAGE_ENCODING    => new IfdEntry(ExifTag::PREVIEW_IMAGE_ENCODING, 2, 4, 'JPEG'),
            ExifTag::PREVIEW_IMAGE_MIME_TYPE   => new IfdEntry(ExifTag::PREVIEW_IMAGE_MIME_TYPE, 2, 10, 'image/jpeg'),
            ExifTag::PREVIEW_IMAGE_COLOR_SPACE => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_COLOR_SPACE,
                3,
                1,
                ColorSpace::ADOBE_RGB->value,
            ),
            ExifTag::PREVIEW_IMAGE_BIT_DEPTH     => new IfdEntry(ExifTag::PREVIEW_IMAGE_BIT_DEPTH, 3, 1, 12),
            ExifTag::PREVIEW_IMAGE_COMPRESSION   => new IfdEntry(ExifTag::PREVIEW_IMAGE_COMPRESSION, 3, 1, Compression::JPEG->value),
            ExifTag::PREVIEW_IMAGE_SCALE         => new IfdEntry(ExifTag::PREVIEW_IMAGE_SCALE, 5, 1, new ExifRational(1, 1)),
            ExifTag::PREVIEW_DATE_TIME           => new IfdEntry(ExifTag::PREVIEW_DATE_TIME, 2, 19, '2024:05:01 12:40:00'),
            ExifTag::PREVIEW_DATE_TIME_DIGITIZED => new IfdEntry(ExifTag::PREVIEW_DATE_TIME_DIGITIZED, 2, 19, '2024:05:01 12:35:00'),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY    => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 400),
            ExifTag::ISO_SPEED_LATITUDE_YYY      => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_YYY, 3, 1, 320),
            ExifTag::ISO_SPEED_LATITUDE_ZZZ      => new IfdEntry(ExifTag::ISO_SPEED_LATITUDE_ZZZ, 3, 1, 540),
            ExifTag::EXPOSURE_TIME               => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, [[1, 125]]),
            ExifTag::F_NUMBER                    => new IfdEntry(ExifTag::F_NUMBER, 5, 1, [[56, 10]]),
            ExifTag::SHUTTER_SPEED_VALUE         => new IfdEntry(ExifTag::SHUTTER_SPEED_VALUE, 10, 1, new ExifRational(697, 100)),
            ExifTag::APERTURE_VALUE              => new IfdEntry(ExifTag::APERTURE_VALUE, 5, 1, new ExifRational(497, 100)),
            ExifTag::EXPOSURE_PROGRAM            => new IfdEntry(ExifTag::EXPOSURE_PROGRAM, 3, 1, ExposureProgram::APERTURE_PRIORITY->value),
            ExifTag::IMAGE_NUMBER                => new IfdEntry(ExifTag::IMAGE_NUMBER, 4, 1, 128),
            ExifTag::SECURITY_CLASSIFICATION     => new IfdEntry(ExifTag::SECURITY_CLASSIFICATION, 2, 1, 'Restricted'),
            ExifTag::IMAGE_HISTORY               => new IfdEntry(ExifTag::IMAGE_HISTORY, 2, 1, 'Developed in Raw Studio'),
            ExifTag::INTERLACE                   => new IfdEntry(ExifTag::INTERLACE, 3, 1, 1),
            ExifTag::EXPOSURE_BIAS_VALUE         => new IfdEntry(ExifTag::EXPOSURE_BIAS_VALUE, 10, 1, [[-2, 1]]),
            ExifTag::METERING_MODE               => new IfdEntry(ExifTag::METERING_MODE, 3, 1, MeteringMode::PATTERN->value),
            ExifTag::LIGHT_SOURCE                => new IfdEntry(ExifTag::LIGHT_SOURCE, 3, 1, LightSource::DAYLIGHT->value),
            ExifTag::FLASH                       => new IfdEntry(ExifTag::FLASH, 3, 1, 0x59),
            ExifTag::WHITE_BALANCE               => new IfdEntry(ExifTag::WHITE_BALANCE, 3, 1, WhiteBalance::MANUAL->value),
            ExifTag::BRIGHTNESS_VALUE            => new IfdEntry(ExifTag::BRIGHTNESS_VALUE, 10, 1, [[76, 10]]),
            ExifTag::COLOR_SPACE                 => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::SRGB->value),
            ExifTag::COMPONENTS_CONFIGURATION    => new IfdEntry(ExifTag::COMPONENTS_CONFIGURATION, 7, 4, new ExifNumericList([1, 2, 3, 0])),
            ExifTag::COMPRESSED_BITS_PER_PIXEL   => new IfdEntry(ExifTag::COMPRESSED_BITS_PER_PIXEL, 5, 1, [[45, 10]]),
            ExifTag::EXPOSURE_MODE               => new IfdEntry(ExifTag::EXPOSURE_MODE, 3, 1, ExposureMode::MANUAL->value),
            ExifTag::GAIN_CONTROL                => new IfdEntry(ExifTag::GAIN_CONTROL, 3, 1, GainControl::LOW_GAIN_UP->value),
            ExifTag::CONTRAST                    => new IfdEntry(ExifTag::CONTRAST, 3, 1, 1),
            ExifTag::SATURATION                  => new IfdEntry(ExifTag::SATURATION, 3, 1, 0),
            ExifTag::SHARPNESS                   => new IfdEntry(ExifTag::SHARPNESS, 3, 1, 2),
            ExifTag::DIGITAL_ZOOM_RATIO          => new IfdEntry(ExifTag::DIGITAL_ZOOM_RATIO, 5, 1, [[1, 1]]),
            ExifTag::FOCAL_LENGTH                => new IfdEntry(ExifTag::FOCAL_LENGTH, 5, 1, [[85, 1]]),
            ExifTag::FOCAL_LENGTH_IN_35MM_FILM   => new IfdEntry(ExifTag::FOCAL_LENGTH_IN_35MM_FILM, 3, 1, 85),
            ExifTag::MAX_APERTURE_VALUE          => new IfdEntry(ExifTag::MAX_APERTURE_VALUE, 5, 1, [[1995, 1000]]),
            ExifTag::LENS_SPECIFICATION          => new IfdEntry(ExifTag::LENS_SPECIFICATION, 5, 4, [[35, 1], [40, 10], [150, 1], [56, 10]]),
            ExifTag::LENS_MODEL                  => new IfdEntry(ExifTag::LENS_MODEL, 2, 15, 'EF 85mm f/1.4L'),
            ExifTag::LENS_MAKE                   => new IfdEntry(ExifTag::LENS_MAKE, 2, 5, 'Canon'),
            ExifTag::LENS_SERIAL_NUMBER          => new IfdEntry(ExifTag::LENS_SERIAL_NUMBER, 2, 10, '1234ABC'),
            ExifTag::SPECTRAL_SENSITIVITY        => new IfdEntry(ExifTag::SPECTRAL_SENSITIVITY, 2, 16, 'Standard Spectral'),
            ExifTag::OECF                        => new IfdEntry(ExifTag::OECF, 7, strlen($oecfPayload), $oecfPayload),
            ExifTag::USER_COMMENT                => new IfdEntry(ExifTag::USER_COMMENT, 7, 28, "ASCII\0\0\0Shot with ND filter\0"),
            ExifTag::SUB_SEC_TIME                => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 3, '321'),
            ExifTag::SUB_SEC_TIME_ORIGINAL       => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 3, '123'),
            ExifTag::SUB_SEC_TIME_DIGITIZED      => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 3, '456'),
            ExifTag::OFFSET_TIME_ORIGINAL        => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 6, '+01:30'),
            ExifTag::OFFSET_TIME_DIGITIZED       => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 6, '+01:30'),
            ExifTag::OFFSET_TIME                 => new IfdEntry(ExifTag::OFFSET_TIME, 2, 6, '+01:30'),
            ExifTag::TIME_ZONE_OFFSET            => new IfdEntry(ExifTag::TIME_ZONE_OFFSET, 8, 2, new ExifNumericList([-2, -1])),
            ExifTag::SELF_TIMER_MODE             => new IfdEntry(ExifTag::SELF_TIMER_MODE, 3, 1, 10),
            ExifTag::TEMPERATURE                 => new IfdEntry(ExifTag::TEMPERATURE, 10, 1, new ExifRational(215, 10)),
            ExifTag::HUMIDITY                    => new IfdEntry(ExifTag::HUMIDITY, 10, 1, new ExifRational(600, 10)),
            ExifTag::PRESSURE                    => new IfdEntry(ExifTag::PRESSURE, 10, 1, new ExifRational(101325, 100)),
            ExifTag::BATTERY_LEVEL               => new IfdEntry(ExifTag::BATTERY_LEVEL, 5, 1, new ExifRational(82, 100)),
            ExifTag::WATER_DEPTH                 => new IfdEntry(ExifTag::WATER_DEPTH, 10, 1, new ExifRational(150, 10)),
            ExifTag::ACCELERATION                => new IfdEntry(ExifTag::ACCELERATION, 10, 1, new ExifRational(98, 10)),
            ExifTag::CAMERA_ELEVATION_ANGLE      => new IfdEntry(ExifTag::CAMERA_ELEVATION_ANGLE, 10, 1, new ExifRational(150, 10)),
            ExifTag::AIRCRAFT_MAKE               => new IfdEntry(ExifTag::AIRCRAFT_MAKE, 2, 3, 'DJI'),
            ExifTag::AIRCRAFT_MODEL              => new IfdEntry(ExifTag::AIRCRAFT_MODEL, 2, 6, 'Mavic 3'),
            ExifTag::CAMERA_YAW_DEGREE           => new IfdEntry(ExifTag::CAMERA_YAW_DEGREE, 10, 1, new ExifRational(123, 10)),
            ExifTag::CAMERA_PITCH_DEGREE         => new IfdEntry(ExifTag::CAMERA_PITCH_DEGREE, 10, 1, new ExifRational(-35, 10)),
            ExifTag::CAMERA_ROLL_DEGREE          => new IfdEntry(ExifTag::CAMERA_ROLL_DEGREE, 10, 1, new ExifRational(20, 10)),
            ExifTag::GIMBAL_YAW_DEGREE           => new IfdEntry(ExifTag::GIMBAL_YAW_DEGREE, 10, 1, new ExifRational(210, 10)),
            ExifTag::GIMBAL_PITCH_DEGREE         => new IfdEntry(ExifTag::GIMBAL_PITCH_DEGREE, 10, 1, new ExifRational(-110, 10)),
            ExifTag::GIMBAL_ROLL_DEGREE          => new IfdEntry(ExifTag::GIMBAL_ROLL_DEGREE, 10, 1, new ExifRational(5, 10)),
            ExifTag::RELATED_SOUND_FILE          => new IfdEntry(ExifTag::RELATED_SOUND_FILE, 2, 10, 'sound.wav'),
            ExifTag::FLASH_ENERGY                => new IfdEntry(ExifTag::FLASH_ENERGY, 5, 1, new ExifRational(250, 10)),
            ExifTag::NOISE                       => new IfdEntry(ExifTag::NOISE, 5, 1, new ExifRational(456, 10)),
            ExifTag::SPATIAL_FREQUENCY_RESPONSE  => new IfdEntry(ExifTag::SPATIAL_FREQUENCY_RESPONSE, 7, strlen($sfrPayload), $sfrPayload),
            ExifTag::FOCAL_PLANE_X_RESOLUTION    => new IfdEntry(ExifTag::FOCAL_PLANE_X_RESOLUTION, 5, 1, new ExifRational(4321, 100)),
            ExifTag::FOCAL_PLANE_Y_RESOLUTION    => new IfdEntry(ExifTag::FOCAL_PLANE_Y_RESOLUTION, 5, 1, new ExifRational(4300, 100)),
            ExifTag::FOCAL_PLANE_RESOLUTION_UNIT => new IfdEntry(ExifTag::FOCAL_PLANE_RESOLUTION_UNIT, 3, 1, ResolutionUnit::CENTIMETER->value),
            ExifTag::TIFF_EP_STANDARD_ID         => new IfdEntry(ExifTag::TIFF_EP_STANDARD_ID, 1, 4, new ExifNumericList([1, 0, 0, 0])),
            ExifTag::SUBJECT_LOCATION            => new IfdEntry(ExifTag::SUBJECT_LOCATION, 3, 2, new ExifNumericList([1600, 1700])),
            ExifTag::EXPOSURE_INDEX              => new IfdEntry(ExifTag::EXPOSURE_INDEX, 5, 1, new ExifRational(400, 1)),
            ExifTag::SCENE_CAPTURE_TYPE          => new IfdEntry(ExifTag::SCENE_CAPTURE_TYPE, 3, 1, SceneCaptureType::STANDARD->value),
            ExifTag::SCENE_TYPE                  => new IfdEntry(ExifTag::SCENE_TYPE, 7, 1, chr(1)),
            ExifTag::SUBJECT_DISTANCE_RANGE      => new IfdEntry(ExifTag::SUBJECT_DISTANCE_RANGE, 3, 1, SubjectDistanceRange::DISTANT->value),
            ExifTag::FILE_SOURCE                 => new IfdEntry(ExifTag::FILE_SOURCE, 7, 1, chr(FileSource::DIGITAL_CAMERA->value)),
            ExifTag::SENSING_METHOD              => new IfdEntry(ExifTag::SENSING_METHOD, 3, 1, SensingMethod::ONE_CHIP_COLOR_AREA->value),
            ExifTag::GAMMA                       => new IfdEntry(ExifTag::GAMMA, 5, 1, [[22, 10]]),
            ExifTag::CFA_REPEAT_PATTERN_DIM      => new IfdEntry(ExifTag::CFA_REPEAT_PATTERN_DIM, 3, 2, new ExifNumericList([8, 6])),
            ExifTag::CFA_PATTERN                 => new IfdEntry(ExifTag::CFA_PATTERN, 7, 4, "\x02\x01\x01\x00"),
            ExifTag::CUSTOM_RENDERED             => new IfdEntry(ExifTag::CUSTOM_RENDERED, 3, 1, 1),
            ExifTag::DEVICE_SETTING_DESCRIPTION  => new IfdEntry(ExifTag::DEVICE_SETTING_DESCRIPTION, 7, 12, 'Profile:Portrait'),
            ExifTag::CAMERA_FIRMWARE             => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 6, '1.2.3'),
            ExifTag::RAW_DEVELOPING_SOFTWARE     => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE, 2, 11, 'Raw Studio'),
            ExifTag::IMAGE_EDITING_SOFTWARE      => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 13, 'Image Studio'),
            ExifTag::METADATA_EDITING_SOFTWARE   => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 15, 'Metadata Studio'),
            ExifTag::MAKER_NOTE_SAFETY           => new IfdEntry(ExifTag::MAKER_NOTE_SAFETY, 3, 1, 1),
        ]);

        $interopIfd = new Ifd([
            ExifTag::INTEROPERABILITY_INDEX    => new IfdEntry(ExifTag::INTEROPERABILITY_INDEX, 2, 4, 'R98'),
            ExifTag::INTEROPERABILITY_VERSION  => new IfdEntry(ExifTag::INTEROPERABILITY_VERSION, 7, 6, "0100\0 "),
            ExifTag::RELATED_IMAGE_FILE_FORMAT => new IfdEntry(ExifTag::RELATED_IMAGE_FILE_FORMAT, 2, 4, 'JPEG'),
            ExifTag::RELATED_IMAGE_WIDTH       => new IfdEntry(ExifTag::RELATED_IMAGE_WIDTH, 4, 1, 4000),
            ExifTag::RELATED_IMAGE_LENGTH      => new IfdEntry(ExifTag::RELATED_IMAGE_LENGTH, 4, 1, 3000),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, $interopIfd, null);

        $xmpDocument = new XmpDocument([
            '{http://purl.org/dc/elements/1.1/}creator'                                                => ['Jane Doe'],
            '{http://purl.org/dc/elements/1.1/}rights'                                                 => 'XMP Rights Statement',
            '{http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/}CreatorContactInfo/Iptc4xmpCore:CiEmailWork' => 'jane@example.com',
            '{http://ns.adobe.com/tiff/1.0/}Make'                                                      => 'Canon',
            '{http://ns.adobe.com/tiff/1.0/}Model'                                                     => 'EOS R6 II',
            '{http://ns.adobe.com/tiff/1.0/}DocumentName'                                              => 'IMG_5123.CR3',
            '{http://ns.adobe.com/xap/1.0/aux/}OwnerName'                                              => 'XMP Owner Name',
        ]);

        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY => 'asset-01',
            'com.apple.quicktime.make'            => 'Canon',
            'com.apple.quicktime.model'           => 'EOS R6 II',
            'com.apple.quicktime.software'        => '1.2.3',
        ]);

        $metadata = new Metadata(['primary'], $quickTime, $exifDocument, ['<xmp/>'], $xmpDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(
            [
                'index'                  => 'R98',
                'version'                => '0100',
                'relatedImageFileFormat' => 'JPEG',
                'relatedImageWidth'      => 4000,
                'relatedImageLength'     => 3000,
            ],
            get_object_vars($structured->technical->interop),
        );

        self::assertSame(Compression::JPEG, $structured->technical->tiff->compression);
        self::assertSame(Photometric::YCBCR, $structured->technical->tiff->photometric);
        self::assertSame([2, 2], $structured->technical->tiff->ycbcrSubSampling);
        self::assertSame([0.299, 0.587, 0.114], $structured->technical->tiff->ycbcrCoefficients);
        self::assertSame([0.3127, 0.329], $structured->technical->tiff->whitePoint);
        self::assertSame([0.64, 0.33, 0.3, 0.6, 0.15, 0.6], $structured->technical->tiff->primaryChromaticities);
        self::assertSame([512, 1024, 1536], $structured->technical->tiff->stripOffsets);
        self::assertSame([2048, 2048, 1024], $structured->technical->tiff->stripByteCounts);
        self::assertSame(256, $structured->technical->tiff->tileWidth);
        self::assertSame(256, $structured->technical->tiff->tileLength);
        self::assertSame([4096, 8192, 12288], $structured->technical->tiff->tileOffsets);
        self::assertSame([1024, 2048, 2048], $structured->technical->tiff->tileByteCounts);
        self::assertSame([0, 32768, 65535], $structured->technical->tiff->transferFunction);
        self::assertSame(24576, $structured->technical->tiff->jpegInterchangeFormat);
        self::assertSame(8192, $structured->technical->tiff->jpegInterchangeFormatLength);
        self::assertSame([0.0, 255.0, 0.0, 255.0, 0.0, 255.0], $structured->technical->tiff->referenceBlackWhite);
        self::assertSame('Jane Doe 2024', $structured->technical->tiff->copyright);

        self::assertSame('Canon', $structured->camera->make);
        self::assertSame('EOS R6 II', $structured->camera->model);
        self::assertSame('Jane Doe', $structured->rights->author->artist);
        self::assertSame('Jane Owner', $structured->rights->author->ownerName);
        self::assertSame('Jane Doe 2024', $structured->rights->copyright);
        self::assertSame('Restricted', $structured->rights->securityClassification);
        self::assertSame('1.2.3', $structured->camera->firmware);
        self::assertSame(FileSource::DIGITAL_CAMERA, $structured->camera->fileSource);
        self::assertSame(SensingMethod::ONE_CHIP_COLOR_AREA, $structured->camera->sensingMethod);
        self::assertSame([], $structured->technical->flashPix->streams);

        self::assertSame('EF 85mm f/1.4L', $structured->lens->model);
        self::assertSame(85.0, $structured->lens->focalLength);
        self::assertSame(85, $structured->lens->equivalent35mm());
        self::assertSame([35.0, 4.0, 150.0, 5.6], $structured->lens->specification);
        self::assertEqualsWithDelta(1.9965, $structured->lens->maximumAperture, 0.001);

        self::assertEqualsWithDelta(43.09095238095239, $structured->lens->hyperfocalDistance, 1e-6);
        self::assertEqualsWithDelta(28.558322, $structured->lens->fieldOfViewDiagonal, 1e-6);
        self::assertEqualsWithDelta(23.913168, $structured->lens->fieldOfViewHorizontal, 1e-6);
        self::assertEqualsWithDelta(16.071421, $structured->lens->fieldOfViewVertical, 1e-6);
        self::assertEqualsWithDelta(1.0, $structured->lens->cropFactor, 1e-6);

        self::assertSame(6720, $structured->media->image->width);
        self::assertSame(4480, $structured->media->image->height);
        self::assertSame(Orientation::RIGHT_TOP, $structured->media->image->orientation);
        self::assertSame(ColorSpace::SRGB, $structured->media->image->colorSpace);
        self::assertSame(128, $structured->media->image->imageNumber);
        self::assertSame('Sunset Title', $structured->media->image->documentName);
        self::assertSame('Sunset over Alps', $structured->media->image->description);
        self::assertSame('Sunset Title', $structured->media->image->title);
        self::assertSame([1, 2, 3, 0], $structured->media->image->componentsConfiguration);
        self::assertSame(4.5, $structured->media->image->compressedBitsPerPixel);
        self::assertSame(1, $structured->media->image->interlace);
        self::assertSame('Shot with ND filter', $structured->media->image->userComment);
        self::assertSame('ASCII', $structured->media->image->userCommentEncoding);
        self::assertSame('Developed in Raw Studio', $structured->file->integrity->imageHistory);
        self::assertTrue($structured->file->integrity->makerNotesSafe);
        self::assertTrue($structured->media->preview->hasThumbnail);
        self::assertTrue($structured->media->preview->hasPreview);
        self::assertSame(2_048, $structured->media->preview->previewWidth);
        self::assertSame(1_152, $structured->media->preview->previewHeight);
        self::assertSame(ColorSpace::ADOBE_RGB, $structured->media->preview->previewColorSpace);
        self::assertSame(12, $structured->media->preview->previewBitDepth);
        self::assertSame(Compression::JPEG, $structured->media->preview->previewCompression);
        self::assertSame(1.0, $structured->media->preview->previewScale);
        self::assertSame('JPEG', $structured->media->preview->previewEncoding);
        self::assertSame('image/jpeg', $structured->media->preview->previewMimeType);
        self::assertSame(131_072, $structured->media->preview->previewOffset);
        self::assertSame(65_536, $structured->media->preview->previewLength);

        self::assertSame(400, $structured->exposure->iso);
        self::assertSame(0.008, $structured->exposure->exposureTimeSec);
        self::assertSame(5.6, $structured->exposure->fNumber);
        self::assertSame(-2.0, $structured->exposure->exposureBiasEv);
        self::assertEqualsWithDelta(6.97, $structured->exposure->shutterSpeedEv, 0.01);
        self::assertEqualsWithDelta(4.97, $structured->exposure->apertureEv, 0.01);
        self::assertSame(ExposureProgram::APERTURE_PRIORITY, $structured->exposure->program);
        self::assertSame(MeteringMode::PATTERN, $structured->exposure->meteringMode);
        self::assertSame(WhiteBalance::MANUAL, $structured->exposure->whiteBalance);
        self::assertSame(GainControl::LOW_GAIN_UP, $structured->exposure->gainControl);
        self::assertSame(Contrast::SOFT, $structured->exposure->contrast);
        self::assertSame(Saturation::NORMAL, $structured->exposure->saturation);
        self::assertSame(Sharpness::HARD, $structured->exposure->sharpness);
        self::assertSame(320, $structured->exposure->isoLatitudeYyy);
        self::assertSame(540, $structured->exposure->isoLatitudeZzz);
        self::assertSame(400.0, $structured->exposure->exposureIndex);
        self::assertSame(25.0, $structured->exposure->flashEnergy);

        $flash = $structured->exposure->flash;
        self::assertNotNull($flash);
        self::assertTrue($flash->fired);

        self::assertSame(SceneCaptureType::STANDARD, $structured->capture->scene->type);
        self::assertSame(SceneType::DIRECTLY_PHOTOGRAPHED_IMAGE, $structured->capture->scene->sceneType);
        self::assertSame(SubjectDistanceRange::DISTANT, $structured->capture->scene->subjectDistanceRange);

        self::assertSame('3.00', $structured->technical->standards->exifVersion);
        self::assertSame('3.0', $structured->technical->standards->profile);
        self::assertSame('1.00', $structured->technical->standards->flashpixVersion);
        self::assertSame([1, 0, 0, 0], $structured->technical->standards->tiffEpStandardId);
        self::assertSame('1.0.0.0', $structured->technical->standards->tiffEpStandardString);

        self::assertEqualsWithDelta(1.9965, $structured->lens->maximumAperture, 0.001);

        self::assertEqualsWithDelta(21.5, $structured->capture->details->temperatureC, 0.001);
        self::assertEqualsWithDelta(82.0, $structured->capture->details->batteryLevelPercent, 0.001);
        self::assertEqualsWithDelta(60.0, $structured->capture->details->humidityPercent, 0.001);
        self::assertEqualsWithDelta(1013.25, $structured->capture->details->pressureHPa, 0.001);
        self::assertEqualsWithDelta(15.0, $structured->capture->details->waterDepthM, 0.001);
        self::assertEqualsWithDelta(9.8, $structured->capture->details->accelerationMs2, 0.001);
        self::assertEqualsWithDelta(15.0, $structured->capture->details->cameraElevationAngleDeg, 0.001);
        self::assertSame(10, $structured->capture->details->selfTimerModeSeconds);
        self::assertSame('DJI', $structured->sensor->uav->manufacturer);
        self::assertSame('Mavic 3', $structured->sensor->uav->model);
        self::assertEqualsWithDelta(12.3, $structured->sensor->uav->flightYaw ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(-3.5, $structured->sensor->uav->flightPitch ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(2.0, $structured->sensor->uav->flightRoll ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(21.0, $structured->sensor->uav->gimbalYaw ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(-11.0, $structured->sensor->uav->gimbalPitch ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.5, $structured->sensor->uav->gimbalRoll ?? 0.0, 0.0001);
        $motionRoll  = $structured->sensor->motion->rollDeg;
        $motionPitch = $structured->sensor->motion->pitchDeg;
        $motionYaw   = $structured->sensor->motion->yawDeg;
        self::assertNotNull($motionRoll);
        self::assertNotNull($motionPitch);
        self::assertNotNull($motionYaw);
        self::assertEqualsWithDelta(2.0, $motionRoll, 0.0001);
        self::assertEqualsWithDelta(-3.5, $motionPitch, 0.0001);
        self::assertEqualsWithDelta(12.3, $motionYaw, 0.0001);

        self::assertSame('sound.wav', $structured->rights->related->relatedSoundFile);

        self::assertSame('Standard Spectral', $structured->sensor->hardware->spectralSensitivity);
        $sensorOecf = $structured->sensor->hardware->oecf;
        self::assertNotNull($sensorOecf);
        self::assertSame($oecfPayload, $sensorOecf['payload']);
        $sensorOecfMatrix = $sensorOecf['matrix'];
        self::assertNotNull($sensorOecfMatrix);
        self::assertSame(['Input 0', 'Input 1'], $sensorOecfMatrix['labels']['columns']);
        self::assertSame(['Channel R', 'Channel G'], $sensorOecfMatrix['labels']['rows']);
        self::assertEqualsWithDelta(0.1, $sensorOecfMatrix['values'][0][0] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.2, $sensorOecfMatrix['values'][0][1] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.3, $sensorOecfMatrix['values'][1][0] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.4, $sensorOecfMatrix['values'][1][1] ?? 0.0, 0.0001);
        $sensorSfr = $structured->sensor->hardware->spatialFrequencyResponse;
        self::assertNotNull($sensorSfr);
        self::assertSame(['10lp/mm', '20lp/mm', '40lp/mm'], $sensorSfr['labels']['columns']);
        self::assertSame(['Luminance', 'Chrominance'], $sensorSfr['labels']['rows']);
        self::assertEqualsWithDelta(0.9, $sensorSfr['values'][0][0] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.75, $sensorSfr['values'][0][1] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.6, $sensorSfr['values'][0][2] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.85, $sensorSfr['values'][1][0] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.7, $sensorSfr['values'][1][1] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(0.55, $sensorSfr['values'][1][2] ?? 0.0, 0.0001);
        self::assertSame(8, $structured->sensor->hardware->cfaWidth);
        self::assertSame(6, $structured->sensor->hardware->cfaHeight);
        self::assertSame([2, 1, 1, 0], $structured->sensor->hardware->cfaPattern);
        self::assertEqualsWithDelta(43.21, $structured->sensor->hardware->focalPlaneXResolution, 0.001);
        self::assertEqualsWithDelta(43.0, $structured->sensor->hardware->focalPlaneYResolution, 0.001);
        self::assertSame(ResolutionUnit::CENTIMETER, $structured->sensor->hardware->focalPlaneResolutionUnit);

        self::assertSame(Sharpness::HARD, $structured->processing->settings->sharpness);
        self::assertSame(Contrast::SOFT, $structured->processing->settings->contrast);
        self::assertSame(Saturation::NORMAL, $structured->processing->settings->saturation);
        self::assertSame('Profile:Portrait', $structured->processing->settings->deviceSettingDescription);
        self::assertSame(1, $structured->processing->settings->customRendered);
        self::assertSame('ImageMeta Studio', $structured->processing->settings->processingSoftware);
        self::assertEqualsWithDelta(45.6, $structured->processing->settings->noiseReduction, 0.0001);

        self::assertSame('Jane D. Photographer', $structured->rights->author->photographer);
        self::assertSame('John Editor', $structured->rights->author->imageEditor);

        self::assertSame('Raw Studio', $structured->camera->device->rawDevelopingSoftware);
        self::assertSame('Image Studio', $structured->camera->device->imageEditingSoftware);
        self::assertSame('Metadata Studio', $structured->camera->device->metadataEditingSoftware);

        self::assertSame('321', $structured->capture->temporal->subSecTime);
        self::assertSame('123', $structured->capture->temporal->subSecTimeOriginal);
        self::assertSame('456', $structured->capture->temporal->subSecTimeDigitized);
        self::assertSame('+01:30', $structured->capture->temporal->offsetTime);
        self::assertSame('+01:30', $structured->capture->temporal->offsetTimeOriginal);
        self::assertSame('+01:30', $structured->capture->temporal->offsetTimeDigitized);
        self::assertSame([-120, -60], $structured->capture->temporal->timeZoneOffsetMinutes);
        self::assertSame('OffsetTimeOriginal', $structured->capture->temporal->tzSource);
    }

    #[Test]
    public function previewMetadataOmitsInvalidCompressionAndScale(): void
    {
        $ifd0 = new Ifd([
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 8_192),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 2_048),
        ]);

        $exifIfd = new Ifd([
            ExifTag::PREVIEW_IMAGE_START       => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 4, 1, 16_384),
            ExifTag::PREVIEW_IMAGE_LENGTH      => new IfdEntry(ExifTag::PREVIEW_IMAGE_LENGTH, 4, 1, 4_096),
            ExifTag::PREVIEW_IMAGE_COMPRESSION => new IfdEntry(ExifTag::PREVIEW_IMAGE_COMPRESSION, 3, 1, 0),
            ExifTag::PREVIEW_IMAGE_SCALE       => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_SCALE,
                5,
                1,
                new ExifRational(0, 1),
            ),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        $preview = $structured->media->preview;
        self::assertTrue($preview->hasThumbnail);
        self::assertTrue($preview->hasPreview);
        self::assertNull($preview->previewCompression);
        self::assertNull($preview->previewScale);
    }

    #[Test]
    public function structuredMetadataUsesFallbackExposureTemporalAndUserCommentEncoding(): void
    {
        $ifd0 = new Ifd([
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 8_192),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 2_048),
        ]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL         => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, 'corrupted timestamp'),
            ExifTag::DATETIME_DIGITIZED        => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 1, '2024:05:06 07:08:09'),
            ExifTag::OFFSET_TIME_DIGITIZED     => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 1, '+02:00'),
            ExifTag::EXPOSURE_INDEX            => new IfdEntry(ExifTag::EXPOSURE_INDEX, 5, 1, new ExifRational(400, 1)),
            ExifTag::USER_COMMENT              => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, 'Travel day'),
            ExifTag::PREVIEW_IMAGE_START       => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 4, 1, 16_384),
            ExifTag::PREVIEW_IMAGE_LENGTH      => new IfdEntry(ExifTag::PREVIEW_IMAGE_LENGTH, 4, 1, 4_096),
            ExifTag::PREVIEW_IMAGE_COMPRESSION => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_COMPRESSION,
                3,
                1,
                Compression::JPEG->value,
            ),
            ExifTag::PREVIEW_IMAGE_SCALE => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_SCALE,
                5,
                1,
                new ExifRational(1, 2),
            ),
        ]);

        $metadata   = new Metadata(['primary'], null, new ExifDocument($ifd0, $exifIfd, null, null, null));
        $structured = (new ExifAssembler())->assemble($metadata);
        $temporal   = $structured->capture->temporal;
        $preview    = $structured->media->preview;

        self::assertSame(400, $structured->exposure->iso);

        $original = $temporal->original;
        self::assertInstanceOf(DateTimeImmutable::class, $original);
        self::assertSame('2024-05-06T07:08:09+02:00', $original->format(DATE_ATOM));

        self::assertSame('Travel day', $structured->media->image->userComment);
        self::assertSame('ASCII', $structured->media->image->userCommentEncoding);

        self::assertTrue($preview->hasThumbnail);
        self::assertTrue($preview->hasPreview);
        self::assertSame(16_384, $preview->previewOffset);
        self::assertSame(4_096, $preview->previewLength);
        self::assertSame(Compression::JPEG, $preview->previewCompression);
        self::assertEqualsWithDelta(0.5, $preview->previewScale ?? 0.0, 1e-6);
    }

    #[Test]
    public function doesNotFallbackToXmpForRightsOrOwnerName(): void
    {
        $xmpDocument = new XmpDocument([
            '{http://purl.org/dc/elements/1.1/}rights'    => 'XMP Rights Statement',
            '{http://ns.adobe.com/xap/1.0/aux/}OwnerName' => 'XMP Owner Name',
            '{http://purl.org/dc/elements/1.1/}creator'   => ['XMP Creator'],
        ]);

        $metadata = new Metadata(
            ['primary'],
            null,
            new ExifDocument(new Ifd([]), new Ifd([]), null, null, null),
            ['<xmp/>'],
            $xmpDocument,
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertNull($structured->rights->copyright);
        self::assertNull($structured->rights->author->ownerName);
        self::assertSame('XMP Creator', $structured->rights->author->creator);
    }

    #[Test]
    public function mapsZeroOrientationToUnknownEnum(): void
    {
        $ifd0 = new Ifd([
            ExifTag::ORIENTATION => new IfdEntry(ExifTag::ORIENTATION, 3, 1, 0),
        ]);

        $exifDocument = new ExifDocument($ifd0, null, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(Orientation::UNKNOWN, $structured->media->image->orientation);
    }

    #[Test]
    public function buildsUavFromQuickTimeFallbackWhenExifFieldsMissing(): void
    {
        $exifDocument = new ExifDocument(new Ifd([]), new Ifd([]), null, null, null);

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.make'              => 'Parrot',
            'com.apple.quicktime.model'             => 'Anafi',
            'com.apple.quicktime.flightYawDegree'   => 72.5,
            'com.apple.quicktime.flightPitchDegree' => -12.0,
            'com.apple.quicktime.flightRollDegree'  => 3.2,
            'com.apple.quicktime.gimbalYawDegree'   => 15.4,
            'com.apple.quicktime.gimbalPitchDegree' => -8.6,
            'com.apple.quicktime.gimbalRollDegree'  => 1.1,
        ]);

        $metadata   = new Metadata(['primary'], $quickTime, $exifDocument);
        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('Parrot', $structured->sensor->uav->manufacturer);
        self::assertSame('Anafi', $structured->sensor->uav->model);
        self::assertEqualsWithDelta(72.5, $structured->sensor->uav->flightYaw ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(-12.0, $structured->sensor->uav->flightPitch ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(3.2, $structured->sensor->uav->flightRoll ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(15.4, $structured->sensor->uav->gimbalYaw ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(-8.6, $structured->sensor->uav->gimbalPitch ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(1.1, $structured->sensor->uav->gimbalRoll ?? 0.0, 0.0001);
    }

    #[Test]
    public function leavesSensorCfaDimensionsNullWhenInvalid(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::CFA_REPEAT_PATTERN_DIM => new IfdEntry(
                ExifTag::CFA_REPEAT_PATTERN_DIM,
                3,
                1,
                new ExifNumericList([5]),
            ),
        ]);

        $metadata = new Metadata(['primary'], null, new ExifDocument($ifd0, $exifIfd, null, null, null));

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertNull($structured->sensor->hardware->cfaWidth);
        self::assertNull($structured->sensor->hardware->cfaHeight);
    }

    #[Test]
    public function usesDocumentNameTagWhenAvailable(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DOCUMENT_NAME => new IfdEntry(ExifTag::DOCUMENT_NAME, 2, 1, 'Legacy Document'),
        ]);

        $structured = (new ExifAssembler())
            ->assemble(new Metadata(['primary'], null, new ExifDocument($ifd0, null, null, null, null)));

        self::assertSame('Legacy Document', $structured->media->image->documentName);
    }

    #[Test]
    public function populatesXpMetadataWhenExif30FieldsMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::XP_TITLE    => new IfdEntry(ExifTag::XP_TITLE, 1, 1, 'XP Title'),
            ExifTag::XP_COMMENT  => new IfdEntry(ExifTag::XP_COMMENT, 1, 1, 'XP Comment'),
            ExifTag::XP_AUTHOR   => new IfdEntry(ExifTag::XP_AUTHOR, 1, 1, 'XP Author'),
            ExifTag::XP_KEYWORDS => new IfdEntry(ExifTag::XP_KEYWORDS, 1, 1, 'Alpha;Beta'),
            ExifTag::XP_SUBJECT  => new IfdEntry(ExifTag::XP_SUBJECT, 1, 1, 'XP Subject'),
        ]);

        $structured = (new ExifAssembler())
            ->assemble(new Metadata(['primary'], null, new ExifDocument($ifd0, null, null, null, null)));

        self::assertSame('XP Subject', $structured->media->image->documentName);
        self::assertSame('XP Title', $structured->media->image->title);
        self::assertSame('XP Comment', $structured->media->image->description);
        self::assertSame('XP Author', $structured->rights->author->photographer);
        self::assertSame('XP Author', $structured->rights->author->imageEditor);
        self::assertSame(['Alpha', 'Beta'], $structured->capture->keywords->flat);
    }

    /**
     * Ensures file level metadata is propagated to the structured representation.
     */
    #[Test]
    public function propagatesFileInformationFromMetadataAggregate(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: null,
            makerNotes: null,
            iccProfile: null,
            iccSegments: [],
            flashPixStreams: [],
            mpfDocument: null,
            jpegBitsPerSample: null,
            jpegFrameSamplingFactors: null,
            jpegYCbCrSubSampling: null,
            mimeType: 'image/jpeg',
            fileSize: 54321,
            extension: 'jpg',
            digestSha1: 'sha1-digest',
            digestMd5: 'md5-digest',
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('image/jpeg', $structured->file->mimeType);
        self::assertSame(54321, $structured->file->fileSize);
        self::assertSame('jpg', $structured->file->extension);
        self::assertSame('sha1-digest', $structured->file->digestSha1);
        self::assertSame('md5-digest', $structured->file->digestMd5);
    }

    /**
     * Ensures printable UNDEFINED EXIF values parsed from a TIFF blob map to structured standards metadata.
     */
    #[Test]
    public function buildsStandardsFromPrintableUndefinedBlob(): void
    {
        $blob     = $this->buildClassicVersionBlob();
        $document = (new TiffExifReader())->parseFromBlob($blob);

        $metadata   = new Metadata([$blob], null, $document);
        $structured = (new ExifAssembler())->assemble($metadata);
        $standards  = $structured->technical->standards;

        self::assertSame('2.32', $standards->exifVersion);
        self::assertSame('1.00', $standards->flashpixVersion);
        self::assertSame('2.32', $standards->profile);
    }

    /**
     * Ensures HEIF metadata including composite images is mapped correctly.
     */
    #[Test]
    public function buildsStructuredAggregateForHeif(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH         => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 4032),
            ExifTag::IMAGE_HEIGHT        => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 3024),
            ExifTag::MAKE                => new IfdEntry(ExifTag::MAKE, 2, 5, 'Apple'),
            ExifTag::MODEL               => new IfdEntry(ExifTag::MODEL, 2, 9, 'iPhone 15'),
            ExifTag::ORIENTATION         => new IfdEntry(ExifTag::ORIENTATION, 3, 1, Orientation::TOP_LEFT->value),
            ExifTag::PROCESSING_SOFTWARE => new IfdEntry(ExifTag::PROCESSING_SOFTWARE, 2, 8, 'iOS 17.3'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION                             => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY                 => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 125),
            ExifTag::EXPOSURE_TIME                            => new IfdEntry(ExifTag::EXPOSURE_TIME, 5, 1, [[1, 120]]),
            ExifTag::F_NUMBER                                 => new IfdEntry(ExifTag::F_NUMBER, 5, 1, [[19, 10]]),
            ExifTag::COMPOSITE_IMAGE                          => new IfdEntry(ExifTag::COMPOSITE_IMAGE, 3, 1, CompositeImage::GENERAL_COMPOSITE->value),
            ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE   => new IfdEntry(ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE, 3, 2, new ExifNumericList([9, 4])),
            ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE => new IfdEntry(ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE, 5, 4, [[1, 120], [1, 60], [1, 30], [1, 15]]),
            ExifTag::DATETIME_ORIGINAL                        => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 19, '2024:02:01 20:45:00'),
            ExifTag::OFFSET_TIME_ORIGINAL                     => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 6, '+01:00'),
            ExifTag::OFFSET_TIME                              => new IfdEntry(ExifTag::OFFSET_TIME, 2, 6, '+01:30'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $xmpDocument = new XmpDocument([
            '{http://ns.adobe.com/xap/1.0/}CreateDate' => '2024-02-01T20:45:00+01:00',
        ]);

        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY => 'burst-01',
            'HDRImageType'                        => 'HDR',
            'NightMode'                           => 1,
            'DepthData'                           => 'depth-asset',
            'com.apple.quicktime.software'        => '17.3',
            'CreationDate'                        => '2024-02-01T19:45:00Z',
        ]);

        $metadata = new Metadata(['primary'], $quickTime, $exifDocument, ['<xmp/>'], $xmpDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(CompositeImage::GENERAL_COMPOSITE, $structured->media->composite->type);
        self::assertSame([9, 4], $structured->media->composite->counts);
        $expectedCompositeExposureTimes = [
            0.008333333333333333,
            0.016666666666666666,
            0.03333333333333333,
            0.06666666666666667,
        ];

        $compositeExposureTimes = $structured->media->composite->exposureTimesTotal;
        self::assertNotNull($compositeExposureTimes);
        self::assertCount(count($expectedCompositeExposureTimes), $compositeExposureTimes);

        foreach ($compositeExposureTimes as $index => $actualExposureTime) {
            self::assertEqualsWithDelta(
                $expectedCompositeExposureTimes[$index],
                $actualExposureTime,
                1e-12,
            );
        }

        self::assertSame('iOS 17.3', $structured->camera->device->software);

        self::assertTrue($structured->capture->scene->hdrScene);
        self::assertTrue($structured->capture->scene->nightMode);

        self::assertSame('3.0', $structured->technical->standards->profile);

        self::assertSame('OffsetTimeOriginal', $structured->capture->temporal->tzSource);
        $originalCaptureTime = $structured->capture->temporal->original;
        self::assertInstanceOf(DateTimeImmutable::class, $originalCaptureTime);
        self::assertSame('+01:00', $originalCaptureTime->format('P'));
    }

    #[Test]
    public function prefersProcessingSoftwareOverLegacyTag(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PROCESSING_SOFTWARE => new IfdEntry(ExifTag::PROCESSING_SOFTWARE, 2, 1, 'ImageMeta Studio'),
            ExifTag::SOFTWARE            => new IfdEntry(ExifTag::SOFTWARE, 2, 1, 'Legacy Writer'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $metadata = new Metadata(
            ['primary'],
            new QuickTimeMeta([]),
            $exifDocument,
            [],
            new XmpDocument([]),
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('ImageMeta Studio', $structured->camera->device->software);
    }

    #[Test]
    public function usesHostComputerWhenSoftwareResolversEmpty(): void
    {
        $ifd0 = new Ifd([
            ExifTag::HOST_COMPUTER => new IfdEntry(ExifTag::HOST_COMPUTER, 2, 1, 'PowerMac G4'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0221'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $metadata = new Metadata(
            ['primary'],
            new QuickTimeMeta([]),
            $exifDocument,
            [],
            new XmpDocument([]),
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('PowerMac G4', $structured->camera->device->software);
    }

    #[Test]
    public function fallsBackToQuickTimeSoftwareWhenExifMissing(): void
    {
        $ifd0 = new Ifd([]);

        $exifDocument = new ExifDocument($ifd0, null, null, null, null);

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.software' => 'QuickTime Studio',
        ]);

        $metadata   = new Metadata(['primary'], $quickTime, $exifDocument);
        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('QuickTime Studio', $structured->camera->device->software);
    }

    /**
     * Ensures maker notes Apple data is preferred over QuickTime metadata and propagates to white balance and motion.
     */
    #[Test]
    public function prefersMakerNotesAppleData(): void
    {
        $appleMakerNotes = new AppleMakerNotes(
            contentIdentifier: 'maker-content',
            cameraType: 'Maker Wide',
            hdrHeadroom: 3.0,
            hdrGain: [2.1, 2.2, 2.3],
            snr: 18.5,
            aeStable: true,
            aeTarget: 0.92,
            aeAverage: 0.78,
            afStable: false,
            afPerformance: 0.44,
            signalToNoiseRatioType: 'focus',
            luminanceNoiseAmplitude: 1.25,
            focusPosition: 0.5,
            livePhotoIndex: 4,
            colorTemperature: 4300,
            semanticStylePreset: 'MakerPreset',
            semanticStyleWarmth: 0.3,
            semanticStyleTone: -0.2,
            flags: ['livePhotoAuto' => false, 'nightMode' => true],
            accelerationVector: [0.05, 0.1, -0.1],
            imageCaptureRequestId: 'req-42',
            qualityHint: 'High',
            colorCorrectionMatrix: [1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0],
            livePhotoTime: 0.2,
            runTime: new RunTime(epoch: 7, timescale: 20, value: 100, flags: 9),
            makerNoteVersion: '2.0',
            hdrImageType: 'HDR',
            burstUuid: 'maker-burst',
            focusDistanceRange: [0.4, 1.8],
            oisMode: 'Video',
            imageCaptureType: 'Portrait',
            imageUniqueId: 'maker-unique',
            photoIdentifier: 'maker-photo',
            afMeasuredDepth: 0.85,
            afConfidence: 0.76,
        );

        $makerNotes = new MakerNotesRecord('Apple', 64, str_repeat('a', 40), $appleMakerNotes, false);

        $ifd0         = new Ifd([]);
        $exifDocument = new ExifDocument($ifd0, null, null, null, null, $makerNotes);

        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY => 'qt-content',
            'CameraType'                          => 'QuickTime Camera',
            'HdrHeadroom'                         => 1.0,
            'HdrGain'                             => '0.5 0.6 0.7',
            'SNRSetting'                          => 9.0,
            'FocusPosition'                       => 0.2,
            'LivePhotoVideoIndex'                 => 1,
            'ColorTemperature'                    => 6500,
            'SemanticStylePreset'                 => 'QuickPreset',
            'SemanticStyleWarmth'                 => 0.1,
            'SemanticStyleTone'                   => 0.15,
            'LivePhotoAuto'                       => 1,
            'NightMode'                           => 0,
        ]);

        $metadata   = new Metadata(['primary'], $quickTime, $exifDocument, [], null, $makerNotes);
        $structured = (new ExifAssembler())->assemble($metadata);

        $apple = self::assertAppleMakerNotes($structured->makerNotes->apple);
        self::assertSame($appleMakerNotes, $apple);

        self::assertSame('maker-content', $apple->contentIdentifier);
        self::assertSame('Maker Wide', $apple->cameraType);
        self::assertSame([2.1, 2.2, 2.3], $apple->hdrGain);
        self::assertEqualsWithDelta(3.0, $apple->hdrHeadroom, 1e-12);
        self::assertEqualsWithDelta(18.5, $apple->snr, 1e-12);
        self::assertTrue($apple->aeStable);
        self::assertEqualsWithDelta(0.92, $apple->aeTarget, 1e-12);
        self::assertEqualsWithDelta(0.78, $apple->aeAverage, 1e-12);
        self::assertFalse($apple->afStable);
        self::assertEqualsWithDelta(0.44, $apple->afPerformance, 1e-12);
        self::assertSame('focus', $apple->signalToNoiseRatioType);
        self::assertEqualsWithDelta(1.25, $apple->luminanceNoiseAmplitude, 1e-12);
        self::assertEqualsWithDelta(0.5, $apple->focusPosition, 1e-12);
        self::assertSame(4, $apple->livePhotoIndex);
        self::assertEqualsWithDelta(0.2, $apple->livePhotoTime, 1e-12);
        self::assertSame(4300, $apple->colorTemperature);
        self::assertSame('MakerPreset', $apple->semanticStylePreset);
        self::assertEqualsWithDelta(0.3, $apple->semanticStyleWarmth, 1e-12);
        self::assertEqualsWithDelta(-0.2, $apple->semanticStyleTone, 1e-12);
        self::assertFalse($apple->flags['livePhotoAuto']);
        self::assertTrue($apple->flags['nightMode']);
        self::assertSame([0.05, 0.1, -0.1], $apple->accelerationVector);
        self::assertSame('req-42', $apple->imageCaptureRequestId);
        self::assertSame('High', $apple->qualityHint);
        self::assertSame([1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0], $apple->colorCorrectionMatrix);
        self::assertSame('2.0', $apple->makerNoteVersion);
        self::assertSame('HDR', $apple->hdrImageType);
        self::assertSame('maker-burst', $apple->burstUuid);
        self::assertSame([0.4, 1.8], $apple->focusDistanceRange);
        self::assertSame('Video', $apple->oisMode);
        self::assertSame('Portrait', $apple->imageCaptureType);
        self::assertSame('maker-unique', $apple->imageUniqueId);
        self::assertSame('maker-photo', $apple->photoIdentifier);
        self::assertEqualsWithDelta(0.85, $apple->afMeasuredDepth, 1e-12);
        self::assertEqualsWithDelta(0.76, $apple->afConfidence, 1e-12);

        $runTime = $apple->runTime;
        self::assertInstanceOf(RunTime::class, $runTime);
        self::assertSame(7, $runTime->epoch);
        self::assertSame(20, $runTime->timescale);
        self::assertSame(100, $runTime->value);
        self::assertSame(9, $runTime->flags);

        self::assertSame(4300, $structured->processing->whiteBalance->kelvin);
        self::assertNull($structured->sensor->motion->rollDeg);
        self::assertNull($structured->sensor->motion->pitchDeg);
        self::assertNull($structured->sensor->motion->yawDeg);
        self::assertEqualsWithDelta(0.05, $structured->sensor->motion->accelX, 1e-12);
        self::assertEqualsWithDelta(0.1, $structured->sensor->motion->accelY, 1e-12);
        self::assertEqualsWithDelta(-0.1, $structured->sensor->motion->accelZ, 1e-12);
        self::assertFalse($structured->capture->scene->nightMode);
        self::assertFalse($structured->file->integrity->makerNotesSafe);
    }

    #[Test]
    public function propagatesBitmaskFlagsFromAppleMakerNotes(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');
        $method->setAccessible(true);

        $appleMakerNotes = $method->invoke($decoder, [
            'ContentIdentifier'     => 'bitfield',
            'SceneFlags'            => [0, 1],
            'ImageProcessingFlags'  => ['values' => [0, 1]],
            'PhotosAppFeatureFlags' => [0],
            'AEStable'              => 1,
            'AFStable'              => 0,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $appleMakerNotes);

        $makerNotes = new MakerNotesRecord(
            'Apple',
            64,
            str_repeat('b', 40),
            $appleMakerNotes,
        );

        $metadata   = new Metadata(['primary'], null, null, [], null, $makerNotes);
        $structured = (new ExifAssembler())->assemble($metadata);

        $apple = self::assertAppleMakerNotes($structured->makerNotes->apple);
        self::assertTrue($apple->flags['nightMode']);
        self::assertTrue($apple->flags['longExposure']);
        self::assertTrue($apple->flags['hdrEnabled']);
        self::assertTrue($apple->flags['hdrAuto']);
        self::assertTrue($apple->flags['personInPhoto']);
        self::assertFalse($apple->flags['petInPhoto']);
        self::assertTrue($apple->flags['aeStable']);
        self::assertFalse($apple->flags['afStable']);
    }

    /**
     * Ensures EXIF acceleration vectors populate motion when Apple data is absent.
     */
    #[Test]
    public function usesExifAccelerationVectorWhenAppleDataMissing(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::ACCELERATION => new IfdEntry(
                ExifTag::ACCELERATION,
                10,
                3,
                new ExifRationalList([
                    new ExifRational(-3, 1),
                    new ExifRational(4, 1),
                    new ExifRational(1, 2),
                ]),
            ),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $metadata   = new Metadata(['primary'], null, $exifDocument, []);
        $structured = (new ExifAssembler())->assemble($metadata);

        $apple = self::assertAppleMakerNotes($structured->makerNotes->apple);
        self::assertNull($apple->accelerationVector);
        self::assertEqualsWithDelta(-3.0, $structured->sensor->motion->accelX, 1e-12);
        self::assertEqualsWithDelta(4.0, $structured->sensor->motion->accelY, 1e-12);
        self::assertEqualsWithDelta(0.5, $structured->sensor->motion->accelZ, 1e-12);
    }

    /**
     * Ensures EXIF acceleration vector data is used when maker notes and QuickTime values are absent.
     */
    #[Test]
    public function usesExifAccelerationVectorWhenOtherSourcesMissing(): void
    {
        $exifIfd = new Ifd([
            ExifTag::ACCELERATION => new IfdEntry(
                ExifTag::ACCELERATION,
                10,
                3,
                new ExifRationalList([
                    new ExifRational(1, 5),
                    new ExifRational(-3, 10),
                    new ExifRational(7, 20),
                ]),
            ),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);

        $metadata   = new Metadata(['primary'], null, $exifDocument, []);
        $structured = (new ExifAssembler())->assemble($metadata);

        $apple = self::assertAppleMakerNotes($structured->makerNotes->apple);
        self::assertNull($apple->accelerationVector);

        self::assertEqualsWithDelta(0.2, $structured->sensor->motion->accelX, 1e-12);
        self::assertEqualsWithDelta(-0.3, $structured->sensor->motion->accelY, 1e-12);
        self::assertEqualsWithDelta(0.35, $structured->sensor->motion->accelZ, 1e-12);
    }

    /**
     * Ensures JPEG-only metadata uses the maker note night mode flag when QuickTime metadata is absent.
     *
     * @param bool $nightModeFlag Maker note flag value to assert.
     */
    #[Test]
    #[DataProvider('makerNoteNightModeFlagsProvider')]
    public function usesMakerNoteNightModeWhenQuickTimeMissing(bool $nightModeFlag): void
    {
        $appleMakerNotes = new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: null,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: ['nightMode' => $nightModeFlag],
            accelerationVector: null,
        );

        $makerNotes = new MakerNotesRecord('Apple', 0, str_repeat('a', 40), $appleMakerNotes);

        $metadata = new Metadata(
            ['primary'],
            null,
            new ExifDocument(new Ifd([]), null, null, null, null, $makerNotes),
            [],
            null,
            $makerNotes,
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame($nightModeFlag, $structured->capture->scene->nightMode);
    }

    /**
     * Provides maker note night mode flag variations for JPEG-only captures.
     *
     * @return iterable<string, array{0: bool}>
     */
    public static function makerNoteNightModeFlagsProvider(): iterable
    {
        yield 'night-mode-enabled' => [true];
        yield 'night-mode-disabled' => [false];
    }

    /**
     * Ensures "Standard" HDR image types do not force HDR scenes without supporting hints.
     */
    #[Test]
    public function treatsStandardHdrImageTypeAsNonHdrHint(): void
    {
        $standardMakerNotes = new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: null,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: [],
            accelerationVector: null,
            livePhotoTime: null,
            runTime: null,
            makerNoteVersion: null,
            hdrImageType: 'Standard',
        );

        $makerNotes   = new MakerNotesRecord('Apple', 8, str_repeat('c', 40), $standardMakerNotes);
        $exifDocument = new ExifDocument(new Ifd([]), null, null, null, null, $makerNotes);
        $metadata     = new Metadata(['primary'], new QuickTimeMeta([]), $exifDocument, [], null, $makerNotes);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertNull($structured->capture->scene->hdrScene);

        $headroomMakerNotes = new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: 1.2,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: [],
            accelerationVector: null,
            livePhotoTime: null,
            runTime: null,
            makerNoteVersion: null,
            hdrImageType: 'Standard',
        );

        $headroomMakerNotesMeta = new MakerNotesRecord('Apple', 9, str_repeat('d', 40), $headroomMakerNotes);
        $headroomExif           = new ExifDocument(new Ifd([]), null, null, null, null, $headroomMakerNotesMeta);
        $headroomMetadata       = new Metadata(['primary'], new QuickTimeMeta([]), $headroomExif, [], null, $headroomMakerNotesMeta);

        $structuredHeadroom = (new ExifAssembler())->assemble($headroomMetadata);

        self::assertTrue($structuredHeadroom->capture->scene->hdrScene);

        $flagMakerNotes = new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: null,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: ['hdrEnabled' => true],
            accelerationVector: null,
            livePhotoTime: null,
            runTime: null,
            makerNoteVersion: null,
            hdrImageType: 'Standard',
        );

        $flagMakerNotesMeta = new MakerNotesRecord('Apple', 10, str_repeat('e', 40), $flagMakerNotes);
        $flagExif           = new ExifDocument(new Ifd([]), null, null, null, null, $flagMakerNotesMeta);
        $flagMetadata       = new Metadata(['primary'], new QuickTimeMeta([]), $flagExif, [], null, $flagMakerNotesMeta);

        $structuredFlags = (new ExifAssembler())->assemble($flagMetadata);

        self::assertTrue($structuredFlags->capture->scene->hdrScene);
    }

    /**
     * Ensures maker note flags alone populate scene metadata when QuickTime metadata is absent.
     */
    #[Test]
    public function derivesSceneFlagsFromMakerNotesWithoutQuickTime(): void
    {
        $appleMakerNotes = new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: null,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: [
                'nightMode'  => true,
                'hdrEnabled' => true,
            ],
            accelerationVector: null,
        );

        $makerNotes = new MakerNotesRecord('Apple', 0, str_repeat('a', 40), $appleMakerNotes);

        $metadata = new Metadata(
            ['primary'],
            null,
            new ExifDocument(new Ifd([]), null, null, null, null, $makerNotes),
            [],
            null,
            $makerNotes,
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertTrue($structured->capture->scene->nightMode);
        self::assertTrue($structured->capture->scene->hdrScene);
    }

    /**
     * Ensures HDR scene detection falls back to maker note hints when QuickTime metadata is silent.
     */
    #[Test]
    public function infersHdrSceneFromAppleMakerNotes(): void
    {
        $makerNotesWithHeadroom = new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: 1.5,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: [],
            accelerationVector: null,
        );

        $makerNotes = new MakerNotesRecord('Apple', 32, str_repeat('a', 40), $makerNotesWithHeadroom);

        $exifDocument = new ExifDocument(new Ifd([]), null, null, null, null, $makerNotes);
        $metadata     = new Metadata(['primary'], new QuickTimeMeta([]), $exifDocument, [], null, $makerNotes);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertTrue($structured->capture->scene->hdrScene);

        $makerNotesWithFlags = new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: null,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: ['hdrEnabled' => true],
            accelerationVector: null,
        );

        $makerNotesFlags = new MakerNotesRecord('Apple', 16, str_repeat('b', 40), $makerNotesWithFlags);

        $exifDocumentFlags = new ExifDocument(new Ifd([]), null, null, null, null, $makerNotesFlags);
        $metadataFlags     = new Metadata(['primary'], new QuickTimeMeta([]), $exifDocumentFlags, [], null, $makerNotesFlags);

        $structuredFlags = (new ExifAssembler())->assemble($metadataFlags);

        self::assertTrue($structuredFlags->capture->scene->hdrScene);
    }

    /**
     * Ensures EXIF 2.2 markers derive the legacy profile identifier.
     */
    #[Test]
    public function derivesProfileForExif22(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0220'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('2.20', $structured->technical->standards->exifVersion);
        self::assertSame('2.2', $structured->technical->standards->profile);
    }

    /**
     * Ensures EXIF 2.3 markers derive the enhanced profile identifier.
     */
    #[Test]
    public function derivesProfileForExif23(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0231'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('2.31', $structured->technical->standards->exifVersion);
        self::assertSame('2.31', $structured->technical->standards->profile);
    }

    /**
     * Ensures missing metadata still results in instantiated value objects.
     */
    #[Test]
    public function handlesMissingMetadata(): void
    {
        $metadata = new Metadata([], null, null, []);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(
            [
                'index'                  => null,
                'version'                => null,
                'relatedImageFileFormat' => null,
                'relatedImageWidth'      => null,
                'relatedImageLength'     => null,
            ],
            get_object_vars($structured->technical->interop),
        );
        self::assertNull($structured->technical->tiff->compression);
        self::assertNull($structured->camera->make);
        self::assertSame('2.2', $structured->technical->standards->profile);
    }

    /**
     * Ensures non-EXIF metadata no longer populates camera, lens or image fields.
     */
    #[Test]
    public function ignoresNonExifFallbacks(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $xmpDocument = new XmpDocument([
            '{http://ns.adobe.com/tiff/1.0/}Make'          => 'XMP Make',
            '{http://ns.adobe.com/tiff/1.0/}DocumentName'  => 'Fallback Document',
            '{http://ns.adobe.com/exif/1.0/aux/}LensModel' => 'Fallback Lens',
        ]);

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.make'  => 'QuickTime Make',
            'com.apple.quicktime.model' => 'QuickTime Model',
        ]);

        $metadata = new Metadata(['primary'], $quickTime, $exifDocument, ['<xmp/>'], $xmpDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertNull($structured->camera->make);
        self::assertNull($structured->camera->model);
        self::assertNull($structured->lens->model);
        self::assertNull($structured->media->image->documentName);
    }

    #[Test]
    public function ignoresXmpExposureAndCaptureFallbacks(): void
    {
        $ifd0         = new Ifd([]);
        $exifIfd      = new Ifd([]);
        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $xmpDocument = new XmpDocument([
            '{http://ns.adobe.com/exif/1.0/}ISOSpeedRatings'  => '640',
            '{http://ns.adobe.com/exif/1.0/}ExposureTime'     => '0.01',
            '{http://ns.adobe.com/exif/1.0/}FNumber'          => '2.8',
            '{http://ns.adobe.com/exif/1.0/}DateTimeOriginal' => '2024-02-01T10:30:00',
        ]);

        $metadata = new Metadata(
            ['primary'],
            new QuickTimeMeta([]),
            $exifDocument,
            ['<xmp/>'],
            $xmpDocument,
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertNull($structured->exposure->iso);
        self::assertNull($structured->exposure->exposureTimeSec);
        self::assertNull($structured->exposure->fNumber);
        self::assertNull($structured->capture->details->dateTime);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function structuredIsoSensitivityTypeProvider(): array
    {
        return [
            'standard-output'           => [1, 640],
            'recommended-exposure'      => [2, 320],
            'iso-speed'                 => [3, 160],
            'standard-over-recommended' => [4, 640],
            'standard-over-iso-speed'   => [5, 640],
            'recommended-over-iso'      => [6, 320],
            'all-types'                 => [7, 640],
        ];
    }

    #[Test]
    #[DataProvider('structuredIsoSensitivityTypeProvider')]
    public function usesSensitivityTypePriorities(int $sensitivityType, int $expectedIso): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 80),
        ]);

        $exifIfd = new Ifd([
            ExifTag::SENSITIVITY_TYPE            => new IfdEntry(ExifTag::SENSITIVITY_TYPE, 3, 1, $sensitivityType),
            ExifTag::ISO_SPEED                   => new IfdEntry(ExifTag::ISO_SPEED, 3, 1, 160),
            ExifTag::STANDARD_OUTPUT_SENSITIVITY => new IfdEntry(ExifTag::STANDARD_OUTPUT_SENSITIVITY, 3, 1, 640),
            ExifTag::RECOMMENDED_EXPOSURE_INDEX  => new IfdEntry(ExifTag::RECOMMENDED_EXPOSURE_INDEX, 3, 1, 320),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY    => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 1280),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame($expectedIso, $structured->exposure->iso);
    }

    /**
     * Ensures the TimeZoneOffset tag provides a timezone when offset strings are absent.
     */
    #[Test]
    public function usesTimeZoneOffsetWhenOffsetTagsMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:02 12:00:00'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:02 12:00:00'),
            ExifTag::TIME_ZONE_OFFSET  => new IfdEntry(ExifTag::TIME_ZONE_OFFSET, 8, 1, new ExifNumericList([-2])),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('TimeZoneOffset', $structured->capture->temporal->tzSource);
        $originalCaptureTime = $structured->capture->temporal->original;
        self::assertInstanceOf(DateTimeImmutable::class, $originalCaptureTime);
        self::assertSame('-02:00', $originalCaptureTime->format('P'));
    }

    #[Test]
    public function buildsTemporalFromSrationalTimeZoneOffset(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:06:15 09:30:00'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:06:15 09:30:00'),
            ExifTag::TIME_ZONE_OFFSET  => new IfdEntry(
                ExifTag::TIME_ZONE_OFFSET,
                10,
                1,
                new ExifRationalList([
                    new ExifRational(11, 2),
                ]),
            ),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('TimeZoneOffset', $structured->capture->temporal->tzSource);
        $originalCaptureTime = $structured->capture->temporal->original;
        self::assertInstanceOf(DateTimeImmutable::class, $originalCaptureTime);
        self::assertSame('+05:30', $originalCaptureTime->format('P'));
        self::assertSame([330], $structured->capture->temporal->timeZoneOffsetMinutes);
    }

    /**
     * Ensures the original timestamp falls back to DateTimeDigitized metadata when necessary.
     */
    #[Test]
    public function temporalOriginalFallsBackToDigitizedTimestamp(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_DIGITIZED     => new IfdEntry(ExifTag::DATETIME_DIGITIZED, 2, 1, '2024:07:03 10:11:12'),
            ExifTag::OFFSET_TIME_DIGITIZED  => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 1, '+02:30'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 1, '987'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        $originalCaptureTime = $structured->capture->temporal->original;
        self::assertInstanceOf(DateTimeImmutable::class, $originalCaptureTime);
        self::assertSame('2024-07-03T10:11:12+02:30', $originalCaptureTime->format('c'));
        $timeZone = $structured->capture->temporal->tz;
        self::assertNotNull($timeZone);
        self::assertSame('+02:30', $timeZone->getName());
        self::assertNull($structured->capture->temporal->subSecTimeOriginal);
        self::assertSame('987', $structured->capture->temporal->subSecTimeDigitized);
        self::assertSame('987', $structured->capture->temporal->subSecTime);
    }

    /**
     * Ensures DateTimeOriginal does not leak the PHP default timezone when offset metadata is missing.
     */
    #[Test]
    public function leavesTimezoneUnsetWhenOffsetTagsMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:02 12:34:56'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:05:02 12:34:56'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertInstanceOf(DateTimeImmutable::class, $structured->capture->temporal->original);
        self::assertNull($structured->capture->temporal->tz);
        self::assertNull($structured->capture->temporal->tzSource);
    }

    /**
     * Validates that EXIF 2.2 style payloads populate ISO and dimensions while leaving the timezone unset.
     */
    #[Test]
    public function buildsExif22SampleWithoutTimezone(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 4000),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 3000),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION             => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0220'),
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 200),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(200, $structured->exposure->iso);
        self::assertSame(4000, $structured->media->image->width);
        self::assertSame(3000, $structured->media->image->height);
        self::assertNull($structured->capture->temporal->tz);
        self::assertNull($structured->capture->temporal->tzSource);
    }

    #[Test]
    public function populatesIsoFromAsciiEncodedValues(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::ISO_SPEED => new IfdEntry(ExifTag::ISO_SPEED, 2, 1, '012800'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(12_800, $structured->exposure->iso);
    }

    /**
     * Ensures JPEG containers (ContainerType::JPEG) do not populate video dimensions without QuickTime metadata.
     */
    #[Test]
    public function doesNotExposeVideoDimensionsForJpegContainers(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 5472),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 3648),
        ]);

        $exifDocument = new ExifDocument($ifd0, new Ifd([]), null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(5472, $structured->media->image->width);
        self::assertSame(3648, $structured->media->image->height);
        self::assertNull($structured->media->video->width);
        self::assertNull($structured->media->video->height);
        self::assertNull($structured->media->video->durationSec);
        self::assertNull($structured->media->video->frameRate);
        self::assertNull($structured->media->video->codec);
        self::assertNull($structured->media->video->hdr);
        self::assertNull($structured->media->video->transferFunction);
        self::assertNull($structured->media->video->colorPrimaries);
    }

    /**
     * Ensures legacy PhotographicSensitivity data stored in IFD0 is used when EXIF tags are absent.
     */
    #[Test]
    public function fallsBackToIfd0PhotographicSensitivityForIso(): void
    {
        $ifd0 = new Ifd([
            ExifTag::PHOTOGRAPHIC_SENSITIVITY => new IfdEntry(ExifTag::PHOTOGRAPHIC_SENSITIVITY, 3, 1, 160),
        ]);

        $exifDocument = new ExifDocument($ifd0, new Ifd([]), null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(160, $structured->exposure->iso);
    }

    /**
     * Verifies that image dimensions are resolved from IFD0 when EXIF pixel dimension tags are missing.
     */
    #[Test]
    public function fallsBackToIfd0DimensionsWhenExifPixelDimensionsMissing(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 2048),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 1536),
        ]);

        $exifDocument = new ExifDocument($ifd0, new Ifd([]), null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(2048, $structured->media->image->width);
        self::assertSame(1536, $structured->media->image->height);
    }

    /**
     * Ensures EXIF 2.3 inputs derive timezone information from the TimeZoneOffset list.
     */
    #[Test]
    public function buildsExif23SampleWithTimeZoneOffset(): void
    {
        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION      => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0230'),
            ExifTag::ISO_SPEED         => new IfdEntry(ExifTag::ISO_SPEED, 4, 1, 320),
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:03:10 10:15:30'),
            ExifTag::TIME_ZONE_OFFSET  => new IfdEntry(ExifTag::TIME_ZONE_OFFSET, 8, 1, new ExifNumericList([-130])),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(320, $structured->exposure->iso);
        self::assertSame('TimeZoneOffset', $structured->capture->temporal->tzSource);
        $originalCaptureTime = $structured->capture->temporal->original;
        self::assertInstanceOf(DateTimeImmutable::class, $originalCaptureTime);
        self::assertSame('-01:30', $originalCaptureTime->format('P'));
        self::assertSame([-90], $structured->capture->temporal->timeZoneOffsetMinutes);
    }

    /**
     * Covers EXIF 3.0 features including fractional seconds, offset tags and composite image metrics.
     */
    #[Test]
    public function buildsExif30SampleWithOffsetAndComposite(): void
    {
        $ifd0 = new Ifd([
            ExifTag::DATETIME_ORIGINAL => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, '2024:07:21 14:05:33'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION                             => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
            ExifTag::OFFSET_TIME_ORIGINAL                     => new IfdEntry(ExifTag::OFFSET_TIME_ORIGINAL, 2, 6, '+02:30'),
            ExifTag::OFFSET_TIME_DIGITIZED                    => new IfdEntry(ExifTag::OFFSET_TIME_DIGITIZED, 2, 6, '+02:30'),
            ExifTag::SUB_SEC_TIME                             => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 3, '321'),
            ExifTag::SUB_SEC_TIME_ORIGINAL                    => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 3, '654'),
            ExifTag::SUB_SEC_TIME_DIGITIZED                   => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 3, '987'),
            ExifTag::COMPOSITE_IMAGE                          => new IfdEntry(ExifTag::COMPOSITE_IMAGE, 3, 1, CompositeImage::GENERAL_COMPOSITE->value),
            ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE   => new IfdEntry(ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE, 3, 2, new ExifNumericList([5, 2])),
            ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE => new IfdEntry(ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE, 5, 3, [[1, 30], [1, 15], [1, 8]]),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('OffsetTimeOriginal', $structured->capture->temporal->tzSource);
        self::assertSame('+02:30', $structured->capture->temporal->offsetTimeOriginal);
        self::assertSame('321', $structured->capture->temporal->subSecTime);
        self::assertSame('654', $structured->capture->temporal->subSecTimeOriginal);
        self::assertSame('987', $structured->capture->temporal->subSecTimeDigitized);

        self::assertSame(CompositeImage::GENERAL_COMPOSITE, $structured->media->composite->type);
        self::assertSame([5, 2], $structured->media->composite->counts);
        $compositeExposureTimes = $structured->media->composite->exposureTimesTotal;
        self::assertNotNull($compositeExposureTimes);
        $expectedExposureTimes = [0.0333333333, 0.0666666666, 0.125];
        self::assertCount(count($expectedExposureTimes), $compositeExposureTimes);

        foreach ($compositeExposureTimes as $index => $actualExposureTime) {
            self::assertEqualsWithDelta($expectedExposureTimes[$index], $actualExposureTime, 1e-10);
        }
    }

    /**
     * Ensures EXIF fractional seconds are normalised to millisecond precision.
     */
    #[Test]
    public function normalizesFractionalSecondsToMillisecondsPrecision(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SUB_SEC_TIME           => new IfdEntry(ExifTag::SUB_SEC_TIME, 2, 6, '654321'),
            ExifTag::SUB_SEC_TIME_ORIGINAL  => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 9, '123456789'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 7, '98a7654'),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('654', $structured->capture->temporal->subSecTime);
        self::assertSame('123', $structured->capture->temporal->subSecTimeOriginal);
        self::assertSame('987', $structured->capture->temporal->subSecTimeDigitized);
    }

    /**
     * Ensures fractional seconds mirror into the generic field when the original precision is available.
     */
    #[Test]
    public function mirrorsFractionalSecondsFromOriginalIntoGenericField(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SUB_SEC_TIME_ORIGINAL  => new IfdEntry(ExifTag::SUB_SEC_TIME_ORIGINAL, 2, 3, '957'),
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 3, '957'),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('957', $structured->capture->temporal->subSecTimeOriginal);
        self::assertSame('957', $structured->capture->temporal->subSecTimeDigitized);
        self::assertSame('957', $structured->capture->temporal->subSecTime);
    }

    /**
     * Ensures fractional seconds mirror into the generic field when only digitized precision is present.
     */
    #[Test]
    public function mirrorsFractionalSecondsFromDigitizedIntoGenericField(): void
    {
        $exifIfd = new Ifd([
            ExifTag::SUB_SEC_TIME_DIGITIZED => new IfdEntry(ExifTag::SUB_SEC_TIME_DIGITIZED, 2, 3, '957'),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertNull($structured->capture->temporal->subSecTimeOriginal);
        self::assertSame('957', $structured->capture->temporal->subSecTimeDigitized);
        self::assertSame('957', $structured->capture->temporal->subSecTime);
    }

    /**
     * Verifies that the color space upgrades to Adobe RGB when tagged as uncalibrated with an R03 interop index.
     */
    #[Test]
    public function normalizesUncalibratedColorSpaceViaInteropR03(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 1024),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 768),
        ]);

        $exifIfd = new Ifd([
            ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::UNCALIBRATED->value),
        ]);

        $interopIfd = new Ifd([
            ExifTag::INTEROPERABILITY_INDEX => new IfdEntry(ExifTag::INTEROPERABILITY_INDEX, 2, 3, 'R03'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, $interopIfd, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(ColorSpace::ADOBE_RGB, $structured->media->image->colorSpace);
    }

    /**
     * Ensures an uncalibrated color space is normalized to sRGB when interoperability index R98 is present.
     */
    #[Test]
    public function normalizesUncalibratedColorSpaceViaInteropR98(): void
    {
        $ifd0 = new Ifd([
            ExifTag::IMAGE_WIDTH  => new IfdEntry(ExifTag::IMAGE_WIDTH, 4, 1, 1024),
            ExifTag::IMAGE_HEIGHT => new IfdEntry(ExifTag::IMAGE_HEIGHT, 4, 1, 768),
        ]);

        $exifIfd = new Ifd([
            ExifTag::COLOR_SPACE => new IfdEntry(ExifTag::COLOR_SPACE, 3, 1, ColorSpace::UNCALIBRATED->value),
        ]);

        $interopIfd = new Ifd([
            ExifTag::INTEROPERABILITY_INDEX => new IfdEntry(ExifTag::INTEROPERABILITY_INDEX, 2, 3, 'R98'),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, $interopIfd, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(ColorSpace::SRGB, $structured->media->image->colorSpace);
    }

    /**
     * Confirms that lenses providing only MAX_APERTURE_VALUE still expose the derived f-number.
     */
    #[Test]
    public function derivesLensMaxApertureFromApex(): void
    {
        $exifIfd = new Ifd([
            ExifTag::MAX_APERTURE_VALUE => new IfdEntry(ExifTag::MAX_APERTURE_VALUE, 5, 1, [[4, 1]]),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), $exifIfd, null, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertNotNull($structured->lens->maximumAperture);
        self::assertEqualsWithDelta(4.0, $structured->lens->maximumAperture, 0.0001);
    }

    /**
     * Ensures ICC colour profile data from JPEG metadata is mapped onto the structured aggregate.
     */
    #[Test]
    public function populatesColorProfileFromIccMetadata(): void
    {
        $icc = IccFixtures::minimalProfile();

        $metadata   = new Metadata([], null, null, [], null, null, $icc, []);
        $structured = (new ExifAssembler())->assemble($metadata);

        $profile = $structured->media->colorProfile;
        self::assertSame('Test Profile', $profile->profileName);
        self::assertSame('4.2.1', $profile->profileVersion);
        self::assertSame('XYZ ', $profile->pcs);
        self::assertSame('Media-Relative Colorimetric', $profile->renderingIntent);
        self::assertSame('00112233445566778899AABBCCDDEEFF', $profile->profileId);
        self::assertNull($profile->gamma);
        self::assertNull($profile->cameraCalibrationSignature);
        self::assertNull($profile->profileCalibrationSignature);
        self::assertNull($profile->hueSatMap);
        self::assertNull($profile->lookTable);
        self::assertNull($profile->toneCurve);
        self::assertNull($profile->gainMap);
    }

    /**
     * Ensures DNG calibration fields from EXIF metadata populate the colour profile value object.
     */
    #[Test]
    public function populatesColorProfileCalibrationFromExif(): void
    {
        $gainTableTag = DngProfileGainTableTag::GAIN_TABLE_MAP;

        $profileIfd = new Ifd([
            ExifTag::PROFILE_HUE_SAT_MAP_DIMS   => new IfdEntry(ExifTag::PROFILE_HUE_SAT_MAP_DIMS, 4, 3, new ExifNumericList([6, 3, 2])),
            ExifTag::PROFILE_HUE_SAT_MAP_DATA_1 => new IfdEntry(ExifTag::PROFILE_HUE_SAT_MAP_DATA_1, 11, 12, new ExifNumericList([
                0.1,
                0.2,
                0.3,
                0.4,
                0.5,
                0.6,
                0.7,
                0.8,
                0.9,
                1.0,
                1.1,
                1.2,
            ])),
            ExifTag::PROFILE_HUE_SAT_MAP_DATA_2 => new IfdEntry(ExifTag::PROFILE_HUE_SAT_MAP_DATA_2, 11, 3, new ExifNumericList([1.3, 1.4, 1.5])),
            ExifTag::PROFILE_HUE_SAT_MAP_DATA_3 => new IfdEntry(ExifTag::PROFILE_HUE_SAT_MAP_DATA_3, 11, 3, new ExifNumericList([1.6, 1.7, 1.8])),
            ExifTag::PROFILE_LOOK_TABLE_DIMS    => new IfdEntry(ExifTag::PROFILE_LOOK_TABLE_DIMS, 4, 3, new ExifNumericList([2, 2, 1])),
            ExifTag::PROFILE_LOOK_TABLE_DATA    => new IfdEntry(ExifTag::PROFILE_LOOK_TABLE_DATA, 11, 6, new ExifNumericList([
                0.05,
                0.06,
                0.07,
                0.08,
                0.09,
                0.1,
            ])),
            ExifTag::PROFILE_TONE_CURVE => new IfdEntry(ExifTag::PROFILE_TONE_CURVE, 11, 4, new ExifNumericList([0.0, 0.0, 0.5, 0.6])),
            $gainTableTag->value        => new IfdEntry($gainTableTag->value, 11, 4, new ExifNumericList([1.0, 1.05, 0.95, 1.1])),
        ]);

        $exifIfd = new Ifd([
            ExifTag::CAMERA_CALIBRATION_SIGNATURE  => new IfdEntry(ExifTag::CAMERA_CALIBRATION_SIGNATURE, 2, 16, 'CameraSig v1.0'),
            ExifTag::PROFILE_CALIBRATION_SIGNATURE => new IfdEntry(ExifTag::PROFILE_CALIBRATION_SIGNATURE, 2, 15, 'ProfileSig v2'),
        ]);

        $document = new ExifDocument(new Ifd([]), $exifIfd, null, null, null, null, [], [
            0 => $profileIfd,
        ]);

        $metadata   = new Metadata(['primary'], null, $document);
        $structured = (new ExifAssembler())->assemble($metadata);

        $profile = $structured->media->colorProfile;
        self::assertSame('CameraSig v1.0', $profile->cameraCalibrationSignature);
        self::assertSame('ProfileSig v2', $profile->profileCalibrationSignature);
        self::assertNotNull($profile->hueSatMap);
        self::assertSame(6, $profile->hueSatMap->hueDivisions);
        self::assertSame(3, $profile->hueSatMap->saturationDivisions);
        self::assertSame(2, $profile->hueSatMap->valueDivisions);
        self::assertNotNull($profile->hueSatMap->mapData1);
        self::assertCount(12, $profile->hueSatMap->mapData1);
        self::assertNotNull($profile->hueSatMap->mapData2);
        self::assertSame([1.3, 1.4, 1.5], $profile->hueSatMap->mapData2);
        self::assertNotNull($profile->hueSatMap->mapData3);
        self::assertSame([1.6, 1.7, 1.8], $profile->hueSatMap->mapData3);
        self::assertNotNull($profile->lookTable);
        self::assertNotNull($profile->lookTable->entries);
        self::assertSame([0.05, 0.06, 0.07], $profile->lookTable->entries[0]);
        self::assertSame([0.08, 0.09, 0.1], $profile->lookTable->entries[1]);
        self::assertNotNull($profile->toneCurve);
        self::assertSame([[0.0, 0.0], [0.5, 0.6]], $profile->toneCurve->points);
        self::assertNotNull($profile->gainMap);
        self::assertSame('ProfileGainTableMap', $profile->gainMap->label());
        self::assertSame([1.0, 1.05, 0.95, 1.1], $profile->gainMap->values);
    }

    /**
     * Ensures GPS speed values are converted from kilometres per hour to metres per second.
     */
    #[Test]
    public function convertsGpsSpeedToMetresPerSecond(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_SPEED_REF => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 2, 'K'),
            ExifTag::GPS_SPEED     => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, [[120, 1]]),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), null, $gpsIfd, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('K', $structured->gps->speedReference);
        self::assertEqualsWithDelta(33.3333333, $structured->gps->speedMs, 1e-6);
        self::assertSame('K', $structured->gps->speedOriginalReference);
        self::assertEqualsWithDelta(120.0, $structured->gps->speedOriginal, 1e-6);
    }

    /**
     * Ensures complex EXIF GPS metadata is fully represented in the structured aggregate.
     */
    #[Test]
    public function buildsCompleteGpsDatasetFromExifMetadata(): void
    {
        $gpsIfd = new Ifd([
            ExifTag::GPS_VERSION_ID   => new IfdEntry(ExifTag::GPS_VERSION_ID, 1, 4, [3, 0, 0, 0]),
            ExifTag::GPS_LATITUDE_REF => new IfdEntry(ExifTag::GPS_LATITUDE_REF, 2, 2, 'N'),
            ExifTag::GPS_LATITUDE     => new IfdEntry(
                ExifTag::GPS_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(51, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_LONGITUDE_REF, 2, 2, 'E'),
            ExifTag::GPS_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(8, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_ALTITUDE_REF => new IfdEntry(ExifTag::GPS_ALTITUDE_REF, 1, 1, 0),
            ExifTag::GPS_ALTITUDE     => new IfdEntry(ExifTag::GPS_ALTITUDE, 5, 1, new ExifRational(150, 1)),
            ExifTag::GPS_TIME_STAMP   => new IfdEntry(
                ExifTag::GPS_TIME_STAMP,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(12, 1),
                    new ExifRational(34, 1),
                    new ExifRational(56789, 1000),
                ]),
            ),
            ExifTag::GPS_DATE_STAMP        => new IfdEntry(ExifTag::GPS_DATE_STAMP, 2, 10, '2024:05:06'),
            ExifTag::GPS_SATELLITES        => new IfdEntry(ExifTag::GPS_SATELLITES, 2, 2, '05'),
            ExifTag::GPS_STATUS            => new IfdEntry(ExifTag::GPS_STATUS, 2, 1, 'A'),
            ExifTag::GPS_MEASURE_MODE      => new IfdEntry(ExifTag::GPS_MEASURE_MODE, 2, 1, '3'),
            ExifTag::GPS_DOP               => new IfdEntry(ExifTag::GPS_DOP, 5, 1, new ExifRational(25, 10)),
            ExifTag::GPS_SPEED_REF         => new IfdEntry(ExifTag::GPS_SPEED_REF, 2, 1, 'K'),
            ExifTag::GPS_SPEED             => new IfdEntry(ExifTag::GPS_SPEED, 5, 1, new ExifRational(72000, 1000)),
            ExifTag::GPS_TRACK_REF         => new IfdEntry(ExifTag::GPS_TRACK_REF, 2, 1, 'T'),
            ExifTag::GPS_TRACK             => new IfdEntry(ExifTag::GPS_TRACK, 5, 1, new ExifRational(12345, 100)),
            ExifTag::GPS_IMG_DIRECTION_REF => new IfdEntry(ExifTag::GPS_IMG_DIRECTION_REF, 2, 1, 'M'),
            ExifTag::GPS_IMG_DIRECTION     => new IfdEntry(ExifTag::GPS_IMG_DIRECTION, 5, 1, new ExifRational(2500, 10)),
            ExifTag::GPS_MAP_DATUM         => new IfdEntry(ExifTag::GPS_MAP_DATUM, 2, 6, 'WGS-84'),
            ExifTag::GPS_DEST_LATITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LATITUDE_REF, 2, 1, 'N'),
            ExifTag::GPS_DEST_LATITUDE     => new IfdEntry(
                ExifTag::GPS_DEST_LATITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(41, 1),
                    new ExifRational(0, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_DEST_LONGITUDE_REF => new IfdEntry(ExifTag::GPS_DEST_LONGITUDE_REF, 2, 1, 'E'),
            ExifTag::GPS_DEST_LONGITUDE     => new IfdEntry(
                ExifTag::GPS_DEST_LONGITUDE,
                5,
                3,
                new ExifRationalList([
                    new ExifRational(8, 1),
                    new ExifRational(30, 1),
                    new ExifRational(0, 1),
                ]),
            ),
            ExifTag::GPS_DEST_BEARING_REF    => new IfdEntry(ExifTag::GPS_DEST_BEARING_REF, 2, 1, 'T'),
            ExifTag::GPS_DEST_BEARING        => new IfdEntry(ExifTag::GPS_DEST_BEARING, 5, 1, new ExifRational(123, 1)),
            ExifTag::GPS_DEST_DISTANCE_REF   => new IfdEntry(ExifTag::GPS_DEST_DISTANCE_REF, 2, 1, 'K'),
            ExifTag::GPS_DEST_DISTANCE       => new IfdEntry(ExifTag::GPS_DEST_DISTANCE, 5, 1, new ExifRational(42, 1)),
            ExifTag::GPS_PROCESSING_METHOD   => new IfdEntry(ExifTag::GPS_PROCESSING_METHOD, 7, 11, "ASCII\0\0\0NETWORK"),
            ExifTag::GPS_AREA_INFORMATION    => new IfdEntry(ExifTag::GPS_AREA_INFORMATION, 7, 13, "ASCII\0\0\0AreaName"),
            ExifTag::GPS_DIFFERENTIAL        => new IfdEntry(ExifTag::GPS_DIFFERENTIAL, 3, 1, 2),
            ExifTag::GPS_H_POSITIONING_ERROR => new IfdEntry(ExifTag::GPS_H_POSITIONING_ERROR, 5, 1, new ExifRational(15, 10)),
        ]);

        $exifDocument = new ExifDocument(new Ifd([]), null, $gpsIfd, null, null);
        $metadata     = new Metadata(['primary'], null, $exifDocument);

        $structured = (new ExifAssembler())->assemble($metadata);

        $latitude = self::assertGpsCoordinate($structured->gps->latitude);
        self::assertSame('N', $latitude->reference());
        self::assertEqualsWithDelta(51.5, $latitude->toFloat(), 1e-6);

        $longitude = self::assertGpsCoordinate($structured->gps->longitude);
        self::assertSame('E', $longitude->reference());
        self::assertEqualsWithDelta(8.5, $longitude->toFloat(), 1e-6);
        self::assertSame(0, $structured->gps->altitudeReference);
        self::assertEqualsWithDelta(150.0, $structured->gps->altitude, 1e-6);
        self::assertSame('3.0.0.0', $structured->gps->version);
        self::assertSame('05', $structured->gps->satellites);
        self::assertSame('A', $structured->gps->status);
        self::assertSame('3', $structured->gps->measureMode);
        self::assertEqualsWithDelta(2.5, $structured->gps->dilutionOfPrecision, 1e-6);
        self::assertSame('K', $structured->gps->speedReference);
        self::assertEqualsWithDelta(20.0, $structured->gps->speedMs, 1e-6);
        self::assertSame('K', $structured->gps->speedOriginalReference);
        self::assertEqualsWithDelta(72.0, $structured->gps->speedOriginal, 1e-6);
        self::assertSame('T', $structured->gps->trackReference);
        self::assertEqualsWithDelta(123.45, $structured->gps->track, 1e-6);
        self::assertSame('M', $structured->gps->imageDirectionReference);
        self::assertEqualsWithDelta(250.0, $structured->gps->imageDirection, 1e-6);
        self::assertSame('WGS-84', $structured->gps->mapDatum);
        $destinationLatitude = self::assertGpsCoordinate($structured->gps->destinationLatitude);
        self::assertSame('N', $destinationLatitude->reference());
        self::assertEqualsWithDelta(41.0, $destinationLatitude->toFloat(), 1e-6);

        $destinationLongitude = self::assertGpsCoordinate($structured->gps->destinationLongitude);
        self::assertSame('E', $destinationLongitude->reference());
        self::assertEqualsWithDelta(8.5, $destinationLongitude->toFloat(), 1e-6);
        self::assertSame('T', $structured->gps->destinationBearingReference);
        self::assertEqualsWithDelta(123.0, $structured->gps->destinationBearing, 1e-6);
        self::assertSame('K', $structured->gps->destinationDistanceReference);
        self::assertEqualsWithDelta(42000.0, $structured->gps->destinationDistanceMetres, 1e-6);
        self::assertSame('K', $structured->gps->destinationDistanceOriginalReference);
        self::assertEqualsWithDelta(42.0, $structured->gps->destinationDistanceOriginal, 1e-6);
        self::assertSame('NETWORK', $structured->gps->processingMethod);
        self::assertSame('AreaName', $structured->gps->areaInformation);
        self::assertSame('2024-05-06', $structured->gps->date);
        self::assertSame('12:34:56.789', $structured->gps->time);
        $timestamp = $structured->gps->timestamp;
        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame('2024-05-06T12:34:56+00:00', $timestamp->format(DATE_ATOM));
        self::assertSame(2, $structured->gps->differential);
        self::assertEqualsWithDelta(1.5, $structured->gps->horizontalPositioningError, 1e-6);
    }

    /**
     * Verifies that empty metadata still instantiates every value object with null/default state.
     */
    #[Test]
    public function instantiatesValueObjectsWithNullStateWhenMetadataMissing(): void
    {
        $structured = (new ExifAssembler())->assemble(new Metadata([], null, null, []));

        foreach (get_object_vars($structured) as $name => $value) {
            self::assertIsObject($value, sprintf('Expected %s to be an object value object', $name));

            foreach (get_object_vars($value) as $field => $fieldValue) {
                if ($fieldValue === null) {
                    continue;
                }

                if ($name === 'standards' && $field === 'profile') {
                    self::assertSame('2.2', $fieldValue, 'standards::profile should fall back to the default EXIF capability profile');
                    continue;
                }

                if (is_array($fieldValue)) {
                    self::assertSame([], $fieldValue, sprintf('%s::%s should be an empty array when metadata is missing', $name, $field));
                    continue;
                }

                if (is_object($fieldValue)) {
                    foreach (get_object_vars($fieldValue) as $nestedField => $nestedValue) {
                        if ($name === 'media' && $field === 'multiPicture' && $nestedField === 'imageCount' && $nestedValue === 0) {
                            continue;
                        }

                        if ($name === 'technical' && $field === 'standards' && $nestedField === 'profile' && $nestedValue === '2.2') {
                            continue;
                        }

                        if ($nestedValue === null) {
                            continue;
                        }

                        if (is_array($nestedValue)) {
                            self::assertSame([], $nestedValue, sprintf('%s::%s::%s should be empty when metadata is missing', $name, $field, $nestedField));
                            continue;
                        }

                        self::fail(sprintf('%s::%s::%s expected null/empty, got %s', $name, $field, $nestedField, var_export($nestedValue, true)));
                    }

                    continue;
                }

                if ($name === 'multiPicture' && $field === 'imageCount' && $fieldValue === 0) {
                    continue;
                }

                self::fail(sprintf('%s::%s expected null/empty, got %s', $name, $field, var_export($fieldValue, true)));
            }
        }
    }

    /**
     * Ensures FlashPix streams collected on the metadata aggregate are forwarded into the structured view.
     */
    #[Test]
    public function forwardsFlashPixStreams(): void
    {
        $flashPix = [7 => 'flashpix-stream'];

        $metadata = new Metadata(
            [],
            null,
            null,
            [],
            null,
            null,
            null,
            [],
            $flashPix,
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame($flashPix, $structured->technical->flashPix->streams);
    }

    /**
     * Ensures XMP region metadata is propagated to the structured output including face counts.
     */
    #[Test]
    public function mergesFaceRegionsFromXmp(): void
    {
        $xmpDocument = new XmpDocument([
            '{http://ns.adobe.com/xmp/sType/Area#}x'                           => ['0.4', '0.75'],
            '{http://ns.adobe.com/xmp/sType/Area#}y'                           => ['0.45', '0.60'],
            '{http://ns.adobe.com/xmp/sType/Area#}w'                           => ['0.2', '0.10'],
            '{http://ns.adobe.com/xmp/sType/Area#}h'                           => ['0.25', '0.08'],
            '{http://www.metadataworkinggroup.com/schemas/regions/}Type'       => ['Face', 'Focus'],
            '{http://www.metadataworkinggroup.com/schemas/regions/}Name'       => ['Alice', ''],
            '{http://www.metadataworkinggroup.com/schemas/regions/}Confidence' => ['0.91', '0.5'],
            '{http://www.metadataworkinggroup.com/schemas/regions/}Rotation'   => ['12.5', '0'],
            '{http://ns.apple.com/faceinfo/1.0/}CenterX'                       => ['0.4', '0.72'],
            '{http://ns.apple.com/faceinfo/1.0/}CenterY'                       => ['0.45', '0.61'],
            '{http://ns.apple.com/faceinfo/1.0/}Width'                         => ['0.2', '0.12'],
            '{http://ns.apple.com/faceinfo/1.0/}Height'                        => ['0.25', '0.09'],
            '{http://ns.apple.com/faceinfo/1.0/}Confidence'                    => ['0.88', '0.42'],
            '{http://ns.apple.com/faceinfo/1.0/}Roll'                          => ['1.0', '-5.0'],
            '{http://ns.apple.com/faceinfo/1.0/}Name'                          => ['Alice', 'Bob'],
            '{http://ns.apple.com/faceinfo/1.0/}FaceID'                        => ['101', '202'],
        ]);

        $metadata   = new Metadata([], null, null, [], $xmpDocument);
        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(2, $structured->capture->scene->faceCount);
        self::assertCount(3, $structured->capture->regions->items);

        $first = $structured->capture->regions->items[0];
        self::assertSame(RegionType::FACE, $first->type);
        self::assertSame('Alice', $first->personName);
        self::assertSame('101', $first->faceId);
        self::assertNotNull($first->confidence);
        self::assertEqualsWithDelta(0.91, $first->confidence, 0.0001);
        self::assertNotNull($first->rotationDeg);
        self::assertEqualsWithDelta(12.5, $first->rotationDeg, 0.0001);

        $third = $structured->capture->regions->items[2];
        self::assertSame(RegionType::FACE, $third->type);
        self::assertSame('Bob', $third->personName);
        self::assertSame('202', $third->faceId);
        self::assertNotNull($third->rotationDeg);
        self::assertEqualsWithDelta(-5.0, $third->rotationDeg, 0.0001);
    }

    #[Test]
    public function usesAppleMakerNotesToPopulateWhiteBalanceDetails(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::WHITE_BALANCE => new IfdEntry(ExifTag::WHITE_BALANCE, 3, 1, WhiteBalance::AUTO->value),
        ]);

        $exifDocument = new ExifDocument($ifd0, $exifIfd, null, null, null);

        $apple = new AppleMakerNotes(
            contentIdentifier: 'uuid-123',
            cameraType: 'Tele',
            hdrHeadroom: 2.5,
            hdrGain: [1.0, 1.1, 1.2],
            snr: 24.0,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: 0.62,
            livePhotoIndex: 3,
            colorTemperature: 5200,
            semanticStylePreset: 'Warm',
            semanticStyleWarmth: 0.15,
            semanticStyleTone: -0.05,
            flags: ['hdrEnabled' => true],
            accelerationVector: [0.1, 0.2, 0.3],
            livePhotoTime: null,
            runTime: null,
        );

        $makerNotes = new MakerNotesRecord(
            'Apple',
            128,
            '0123456789abcdef0123456789abcdef01234567',
            $apple,
        );

        $metadata = new Metadata(['primary'], null, $exifDocument, [], null, $makerNotes);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(WhiteBalance::AUTO, $structured->processing->whiteBalance->mode);
        self::assertSame(5200, $structured->processing->whiteBalance->kelvin);

        $apple = self::assertAppleMakerNotes($structured->makerNotes->apple);
        self::assertSame('uuid-123', $apple->contentIdentifier);
        self::assertSame(5200, $apple->colorTemperature);
        self::assertArrayHasKey('hdrEnabled', $apple->flags);
        self::assertTrue($apple->flags['hdrEnabled']);
        self::assertSame([0.1, 0.2, 0.3], $apple->accelerationVector);
    }

    #[Test]
    public function exifThreeKeepsLegacyVersionFieldsEmpty(): void
    {
        $ifd0 = new Ifd([
            ExifTag::MAKE        => new IfdEntry(ExifTag::MAKE, 2, 1, 'Contoso'),
            ExifTag::MODEL       => new IfdEntry(ExifTag::MODEL, 2, 1, 'Model X'),
            ExifTag::IMAGE_TITLE => new IfdEntry(ExifTag::IMAGE_TITLE, 2, 1, 'Autumn Sunset'),
        ]);

        $exifIfd = new Ifd([
            ExifTag::EXIF_VERSION              => new IfdEntry(ExifTag::EXIF_VERSION, 7, 4, '0300'),
            ExifTag::RAW_DEVELOPING_SOFTWARE   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE, 2, 1, 'Raw Developer X'),
            ExifTag::IMAGE_EDITING_SOFTWARE    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 1, 'Image Editor Y'),
            ExifTag::METADATA_EDITING_SOFTWARE => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 1, 'Metadata Tool Z'),
        ]);

        $metadata = new Metadata(['primary'], null, new ExifDocument($ifd0, $exifIfd, null, null, null));

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('3.00', $structured->technical->standards->exifVersion);
        self::assertSame('3.0', $structured->technical->standards->profile);
        self::assertSame('Autumn Sunset', $structured->media->image->title);
        self::assertNull($structured->camera->firmware);
        self::assertSame('Raw Developer X', $structured->camera->device->rawDevelopingSoftware);
        self::assertSame('Image Editor Y', $structured->camera->device->imageEditingSoftware);
        self::assertSame('Metadata Tool Z', $structured->camera->device->metadataEditingSoftware);
    }

    #[Test]
    public function mapsQuickTimeMetadataIntoContainerVideoAndAudio(): void
    {
        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::MAJOR_BRAND_KEY           => 'qt  ',
            QuickTimeMeta::COMPATIBLE_BRANDS_KEY     => 'qt  iso2',
            QuickTimeMeta::MINOR_VERSION_KEY         => 512,
            QuickTimeMeta::COMPRESSOR_NAME_KEY       => 'Apple ProRes 422',
            QuickTimeMeta::VIDEO_CODEC_KEY           => 'apcn',
            QuickTimeMeta::VIDEO_WIDTH_KEY           => 1920,
            QuickTimeMeta::VIDEO_HEIGHT_KEY          => 1080,
            QuickTimeMeta::AUDIO_FORMAT_KEY          => 'lpcm',
            QuickTimeMeta::AUDIO_CHANNELS_KEY        => 2,
            QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY     => 48000,
            QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY => 24,
            QuickTimeMeta::HANDLER_DESCRIPTION_KEY   => 'Apple Video Media Handler',
            'com.apple.quicktime.encoder'            => 'Apple Encoder',
            'com.apple.quicktime.avgBitrate'         => 22000000,
            'com.apple.quicktime.duration'           => 12.5,
            'com.apple.quicktime.videoFrameRate'     => 24.0,
            'com.apple.quicktime.hdrFormat'          => true,
            'com.apple.quicktime.transferFunction'   => 'PQ',
            'com.apple.quicktime.colorPrimaries'     => 'BT2020',
        ]);

        $metadata   = new Metadata([], $quickTime);
        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame('qt', $structured->file->container->format);
        self::assertSame('Apple Encoder', $structured->file->container->encoder);
        self::assertSame(22000000, $structured->file->container->bitrate);
        self::assertSame('Apple ProRes 422', $structured->file->container->videoCodec);
        self::assertSame('lpcm', $structured->file->container->audioCodec);

        self::assertEqualsWithDelta(12.5, $structured->media->video->durationSec, 1e-6);
        self::assertSame(24.0, $structured->media->video->frameRate);
        self::assertSame(1920, $structured->media->video->width);
        self::assertSame(1080, $structured->media->video->height);
        self::assertSame('Apple ProRes 422', $structured->media->video->codec);
        self::assertTrue($structured->media->video->hdr);
        self::assertSame('PQ', $structured->media->video->transferFunction);
        self::assertSame('BT2020', $structured->media->video->colorPrimaries);

        self::assertSame(2, $structured->media->audio->channels);
        self::assertSame(48000, $structured->media->audio->sampleRate);
        self::assertSame('lpcm', $structured->media->audio->codec);
        self::assertSame(24, $structured->media->audio->bitDepth);
    }

    #[Test]
    public function forwardsJpegAudioStreamsIntoStructuredMetadata(): void
    {
        $audioStream = new JpegAudioStream('PCM', 2, 44_100, 16, 'DATA', '1.00');
        $metadata    = new Metadata([], null, jpegAudioStreams: [$audioStream]);

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertCount(1, $structured->media->embeddedAudio->clips);

        $clip = $structured->media->embeddedAudio->clips[0];
        self::assertSame('PCM', $clip->format);
        self::assertSame(2, $clip->channels);
        self::assertSame(44_100, $clip->sampleRate);
        self::assertSame(16, $clip->bitDepth);
        self::assertSame('DATA', $clip->data);
        self::assertSame('1.00', $clip->version);
    }

    /**
     * Ensures JPEG frame metadata backfills TIFF characteristics when EXIF is absent.
     */
    #[Test]
    public function backfillsTiffDataFromJpegFrameWhenExifMissing(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: null,
            makerNotes: null,
            iccProfile: null,
            iccSegments: [],
            flashPixStreams: [],
            mpfDocument: null,
            jpegBitsPerSample: 8,
            jpegFrameSamplingFactors: [
                1 => ['horizontal' => 2, 'vertical' => 2],
                2 => ['horizontal' => 1, 'vertical' => 1],
                3 => ['horizontal' => 1, 'vertical' => 1],
            ],
            jpegYCbCrSubSampling: [2, 2],
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertSame(8, $structured->technical->tiff->bitsPerSample);
        self::assertSame([2, 2], $structured->technical->tiff->ycbcrSubSampling);
    }

    /**
     * Ensures MPF documents are exposed via the structured multi-picture value.
     */
    #[Test]
    public function exposesMultiPictureValueFromMpfDocument(): void
    {
        $mpfDocument = new MpfDocument(
            version: '0100',
            imageCount: 2,
            entries: [
                new MpfEntry(0x10000001, 2048, 8192, 0, 0),
                new MpfEntry(0x00000002, 1024, 16384, 1, 0),
            ],
            attributes: new MpfAttributes(
                imageUidList: null,
                totalFrames: 3,
                individualImageNumber: 1,
                panoramaAngle: null,
                panoramaAxis: null,
                additionalTags: [],
            ),
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: null,
            makerNotes: null,
            iccProfile: null,
            iccSegments: [],
            flashPixStreams: [],
            mpfDocument: $mpfDocument,
        );

        $structured   = (new ExifAssembler())->assemble($metadata);
        $multiPicture = $structured->media->multiPicture;

        self::assertSame('0100', $multiPicture->version);
        self::assertSame(2, $multiPicture->imageCount);
        self::assertCount(2, $multiPicture->entries);
        self::assertSame(3, $multiPicture->totalFrames);
        self::assertSame(1, $multiPicture->individualImageNumber);
        self::assertSame(0x10000001, $multiPicture->entries[0]->attributes);
        self::assertSame(8192, $multiPicture->entries[0]->dataOffset);
        self::assertSame(1, $multiPicture->entries[1]->dependentImage1);
    }

    /**
     * Builds a Classic TIFF blob with EXIF/Flashpix version tags encoded as printable UNDEFINED values.
     */
    private function buildClassicVersionBlob(): string
    {
        $header = 'II' . pack('v', 0x002A) . pack('V', 8);

        $exifIfdOffset = 8 + 2 + 12 + 4;

        $ifd0Entry = pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', 4)
            . pack('V', 1)
            . pack('V', $exifIfdOffset);

        $ifd0 = pack('v', 1) . $ifd0Entry . pack('V', 0);

        $exifEntries = [
            pack('v', ExifTag::EXIF_VERSION)
                . pack('v', 7)
                . pack('V', 4)
                . pack('V', $this->inlineAsciiToInt('0232')),
            pack('v', ExifTag::FLASHPIX_VERSION)
                . pack('v', 7)
                . pack('V', 4)
                . pack('V', $this->inlineAsciiToInt('0100')),
        ];

        $exifIfd = pack('v', count($exifEntries)) . implode('', $exifEntries) . pack('V', 0);

        return $header . $ifd0 . $exifIfd;
    }

    /**
     * Ensures Apple maker notes are available before dereferencing.
     */
    private static function assertAppleMakerNotes(?AppleMakerNotes $apple): AppleMakerNotes
    {
        self::assertNotNull($apple);

        return $apple;
    }

    /**
     * Ensures GPS coordinates are available before dereferencing.
     */
    private static function assertGpsCoordinate(?GpsCoordinate $coordinate): GpsCoordinate
    {
        self::assertNotNull($coordinate);

        return $coordinate;
    }

    /**
     * Converts printable ASCII into an inline Classic TIFF integer representation.
     */
    private function inlineAsciiToInt(string $ascii): int
    {
        $bytes = str_pad($ascii, 4, "\0");

        $value = unpack('V', $bytes);

        if ($value === false) {
            return 0;
        }

        /** @var array{1:int} $value */
        return $value[1];
    }

    private static function buildOecfPayload(): string
    {
        $columns = 2;
        $rows    = 2;

        $payload = pack('n', $columns) . pack('n', $rows);
        $payload .= "Input 0\0";
        $payload .= "Input 1\0";
        $payload .= "Channel R\0";
        $payload .= "Channel G\0";

        $payload .= self::packSrational(1, 10);
        $payload .= self::packSrational(2, 10);
        $payload .= self::packSrational(3, 10);
        $payload .= self::packSrational(4, 10);

        return $payload;
    }

    private static function buildSpatialFrequencyResponsePayload(): string
    {
        $columns = 3;
        $rows    = 2;

        $payload = pack('n', $columns) . pack('n', $rows);
        $payload .= "10lp/mm\0";
        $payload .= "20lp/mm\0";
        $payload .= "40lp/mm\0";
        $payload .= "Luminance\0";
        $payload .= "Chrominance\0";

        $payload .= self::packSrational(90, 100);
        $payload .= self::packSrational(75, 100);
        $payload .= self::packSrational(60, 100);
        $payload .= self::packSrational(85, 100);
        $payload .= self::packSrational(70, 100);
        $payload .= self::packSrational(55, 100);

        return $payload;
    }

    private static function packSrational(int $numerator, int $denominator): string
    {
        return pack('N', $numerator) . pack('N', $denominator);
    }
}
