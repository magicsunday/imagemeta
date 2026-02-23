<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Value\SourceExposureTimes;

use function in_array;
use function is_int;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;

/**
 * Validates tag layouts, value domains, and cross-IFD semantic constraints.
 *
 * EXIF 3.0 §4.6 defines fixed-length tag requirements, numeric domains, and
 * companion tag obligations checked by this validator.
 */
final readonly class TiffExifTagValidator
{
    // Compact index constants for FIXED_LENGTH_TAGS entries.
    // Format: [name, count, type, spec]
    private const int RULE_NAME = 0;

    private const int RULE_COUNT = 1;

    private const int RULE_TYPE = 2;

    private const int RULE_SPEC = 3;

    /**
     * Tags with strict TIFF type, component count, and spec reference.
     *
     * Each entry uses a compact indexed array: [name, count, type, spec].
     * The type label is derived at runtime via typeName().
     *
     * @var array<int, array{0: string, 1: int, 2: int, 3: string}>
     */
    private const array FIXED_LENGTH_TAGS = [
        // --- TIFF 6.0 Baseline Tags ---
        TiffTag::NEW_SUBFILE_TYPE               => ['NewSubfileType', 1, TiffConst::TYPE_LONG, 'TIFF 6.0'],
        TiffTag::SUBFILE_TYPE                   => ['SubfileType', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        ExifTag::COMPRESSION                    => ['Compression', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        ExifTag::PHOTOMETRIC_INTERPRETATION     => ['PhotometricInterpretation', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        TiffTag::THRESHHOLDING                  => ['Threshholding', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        TiffTag::CELL_WIDTH                     => ['CellWidth', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        TiffTag::CELL_LENGTH                    => ['CellLength', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        TiffTag::FILL_ORDER                     => ['FillOrder', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        ExifTag::ORIENTATION                    => ['Orientation', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        ExifTag::SAMPLES_PER_PIXEL              => ['SamplesPerPixel', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        ExifTag::X_RESOLUTION                   => ['XResolution', 1, TiffConst::TYPE_RATIONAL, 'TIFF 6.0'],
        ExifTag::Y_RESOLUTION                   => ['YResolution', 1, TiffConst::TYPE_RATIONAL, 'TIFF 6.0'],
        ExifTag::PLANAR_CONFIGURATION           => ['PlanarConfiguration', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        TiffTag::X_POSITION                     => ['XPosition', 1, TiffConst::TYPE_RATIONAL, 'TIFF 6.0'],
        TiffTag::Y_POSITION                     => ['YPosition', 1, TiffConst::TYPE_RATIONAL, 'TIFF 6.0'],
        TiffTag::GRAY_RESPONSE_UNIT             => ['GrayResponseUnit', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        TiffTag::T4_OPTIONS                     => ['T4Options', 1, TiffConst::TYPE_LONG, 'TIFF 6.0'],
        TiffTag::T6_OPTIONS                     => ['T6Options', 1, TiffConst::TYPE_LONG, 'TIFF 6.0'],
        ExifTag::RESOLUTION_UNIT                => ['ResolutionUnit', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        TiffTag::PAGE_NUMBER                    => ['PageNumber', 2, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        ExifTag::DATETIME                       => ['DateTime', 20, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.5.4.5'],
        TiffTag::PREDICTOR                      => ['Predictor', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        ExifTag::WHITE_POINT                    => ['WhitePoint', 2, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.5.3.2'],
        ExifTag::PRIMARY_CHROMATICITIES         => ['PrimaryChromaticities', 6, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.5.3.3'],
        TiffTag::HALFTONE_HINTS                 => ['HalftoneHints', 2, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        TiffTag::INK_SET                        => ['InkSet', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        TiffTag::NUMBER_OF_INKS                 => ['NumberOfInks', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        TiffTag::TRANSFER_RANGE                 => ['TransferRange', 6, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        TiffTag::JPEG_PROC                      => ['JPEGProc', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        ExifTag::JPEG_INTERCHANGE_FORMAT        => ['JPEGInterchangeFormat', 1, TiffConst::TYPE_LONG, 'TIFF 6.0'],
        ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => ['JPEGInterchangeFormatLength', 1, TiffConst::TYPE_LONG, 'TIFF 6.0'],
        TiffTag::JPEG_RESTART_INTERVAL          => ['JPEGRestartInterval', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        ExifTag::YCBCR_COEFFICIENTS             => ['YCbCrCoefficients', 3, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.5.3.4'],
        ExifTag::YCBCR_SUB_SAMPLING             => ['YCbCrSubSampling', 2, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        ExifTag::YCBCR_POSITIONING              => ['YCbCrPositioning', 1, TiffConst::TYPE_SHORT, 'TIFF 6.0'],
        ExifTag::REFERENCE_BLACK_WHITE          => ['ReferenceBlackWhite', 6, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.5.3.5'],

        // --- EXIF 3.0 Exif IFD Tags ---
        ExifTag::EXPOSURE_TIME                          => ['ExposureTime', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.4.1'],
        ExifTag::F_NUMBER                               => ['FNumber', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.4.2'],
        ExifTag::EXPOSURE_PROGRAM                       => ['ExposureProgram', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.4.3'],
        ExifTag::SENSITIVITY_TYPE                       => ['SensitivityType', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.4.5'],
        ExifTag::STANDARD_OUTPUT_SENSITIVITY            => ['StandardOutputSensitivity', 1, TiffConst::TYPE_LONG, 'EXIF 3.0 §4.6.6.4.6'],
        ExifTag::RECOMMENDED_EXPOSURE_INDEX             => ['RecommendedExposureIndex', 1, TiffConst::TYPE_LONG, 'EXIF 3.0 §4.6.6.4.7'],
        ExifTag::ISO_SPEED                              => ['ISOSpeed', 1, TiffConst::TYPE_LONG, 'EXIF 3.0 §4.6.6.4.8'],
        ExifTag::ISO_SPEED_LATITUDE_YYY                 => ['ISOSpeedLatitudeyyy', 1, TiffConst::TYPE_LONG, 'EXIF 3.0 §4.6.6.4.9'],
        ExifTag::ISO_SPEED_LATITUDE_ZZZ                 => ['ISOSpeedLatitudezzz', 1, TiffConst::TYPE_LONG, 'EXIF 3.0 §4.6.6.4.10'],
        ExifTag::EXIF_VERSION                           => ['ExifVersion', 4, TiffConst::TYPE_UNDEFINED, 'EXIF 3.0 §4.6.6.1.1'],
        ExifTag::DATETIME_ORIGINAL                      => ['DateTimeOriginal', 20, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.6.6.1'],
        ExifTag::DATETIME_DIGITIZED                     => ['DateTimeDigitized', 20, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.6.6.2'],
        ExifTag::OFFSET_TIME                            => ['OffsetTime', 7, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.6.6.3'],
        ExifTag::OFFSET_TIME_ORIGINAL                   => ['OffsetTimeOriginal', 7, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.6.6.4'],
        ExifTag::OFFSET_TIME_DIGITIZED                  => ['OffsetTimeDigitized', 7, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.6.6.5'],
        ExifTag::COMPONENTS_CONFIGURATION               => ['ComponentsConfiguration', 4, TiffConst::TYPE_UNDEFINED, 'EXIF 3.0 §4.6.6.1.3'],
        ExifTag::COMPRESSED_BITS_PER_PIXEL              => ['CompressedBitsPerPixel', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.1.4'],
        ExifTag::SHUTTER_SPEED_VALUE                    => ['ShutterSpeedValue', 1, TiffConst::TYPE_SRATIONAL, 'EXIF 3.0 §4.6.6.5.1'],
        ExifTag::APERTURE_VALUE                         => ['ApertureValue', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.5.2'],
        ExifTag::BRIGHTNESS_VALUE                       => ['BrightnessValue', 1, TiffConst::TYPE_SRATIONAL, 'EXIF 3.0 §4.6.6.5.3'],
        ExifTag::EXPOSURE_BIAS_VALUE                    => ['ExposureBiasValue', 1, TiffConst::TYPE_SRATIONAL, 'EXIF 3.0 §4.6.6.5.4'],
        ExifTag::MAX_APERTURE_VALUE                     => ['MaxApertureValue', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.5.5'],
        ExifTag::SUBJECT_DISTANCE                       => ['SubjectDistance', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.5.6'],
        ExifTag::METERING_MODE                          => ['MeteringMode', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.1'],
        ExifTag::LIGHT_SOURCE                           => ['LightSource', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.2'],
        ExifTag::FLASH                                  => ['Flash', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.3'],
        ExifTag::FOCAL_LENGTH                           => ['FocalLength', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.7.4'],
        ExifTag::SUBJECT_LOCATION                       => ['SubjectLocation', 2, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.29'],
        ExifTag::TEMPERATURE                            => ['Temperature', 1, TiffConst::TYPE_SRATIONAL, 'EXIF 3.0 §4.6.6.8.1'],
        ExifTag::HUMIDITY                               => ['Humidity', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.8.2'],
        ExifTag::PRESSURE                               => ['Pressure', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.8.3'],
        ExifTag::WATER_DEPTH                            => ['WaterDepth', 1, TiffConst::TYPE_SRATIONAL, 'EXIF 3.0 §4.6.6.8.4'],
        ExifTag::ACCELERATION                           => ['Acceleration', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.8.5'],
        ExifTag::CAMERA_ELEVATION_ANGLE                 => ['CameraElevationAngle', 1, TiffConst::TYPE_SRATIONAL, 'EXIF 3.0 §4.6.6.8.6'],
        ExifTag::FLASHPIX_VERSION                       => ['FlashpixVersion', 4, TiffConst::TYPE_UNDEFINED, 'EXIF 3.0 §4.6.6.1.2'],
        ExifTag::COLOR_SPACE                            => ['ColorSpace', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.2.1'],
        ExifTag::RELATED_SOUND_FILE                     => ['RelatedSoundFile', 13, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.6.3.1'],
        ExifTag::FLASH_ENERGY                           => ['FlashEnergy', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.7.5'],
        ExifTag::FOCAL_PLANE_X_RESOLUTION               => ['FocalPlaneXResolution', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.7.7'],
        ExifTag::FOCAL_PLANE_Y_RESOLUTION               => ['FocalPlaneYResolution', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.7.8'],
        ExifTag::FOCAL_PLANE_RESOLUTION_UNIT            => ['FocalPlaneResolutionUnit', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.9'],
        ExifTag::EXPOSURE_INDEX                         => ['ExposureIndex', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.7.28'],
        ExifTag::SENSING_METHOD                         => ['SensingMethod', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.30'],
        ExifTag::FILE_SOURCE                            => ['FileSource', 1, TiffConst::TYPE_UNDEFINED, 'EXIF 3.0 §4.6.6.7.32'],
        ExifTag::SCENE_TYPE                             => ['SceneType', 1, TiffConst::TYPE_UNDEFINED, 'EXIF 3.0 §4.6.6.7.33'],
        ExifTag::CUSTOM_RENDERED                        => ['CustomRendered', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.35'],
        ExifTag::EXPOSURE_MODE                          => ['ExposureMode', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.36'],
        ExifTag::WHITE_BALANCE                          => ['WhiteBalance', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.37'],
        ExifTag::DIGITAL_ZOOM_RATIO                     => ['DigitalZoomRatio', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.7.38'],
        ExifTag::FOCAL_LENGTH_IN_35MM_FILM              => ['FocalLengthIn35mmFilm', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.39'],
        ExifTag::SCENE_CAPTURE_TYPE                     => ['SceneCaptureType', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.40'],
        ExifTag::GAIN_CONTROL                           => ['GainControl', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.41'],
        ExifTag::CONTRAST                               => ['Contrast', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.42'],
        ExifTag::SATURATION                             => ['Saturation', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.43'],
        ExifTag::SHARPNESS                              => ['Sharpness', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.44'],
        ExifTag::SUBJECT_DISTANCE_RANGE                 => ['SubjectDistanceRange', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.7.46'],
        ExifTag::IMAGE_UNIQUE_ID                        => ['ImageUniqueID', 33, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.6.9.1'],
        ExifTag::LENS_SPECIFICATION                     => ['LensSpecification', 4, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.9.4'],
        ExifTag::COMPOSITE_IMAGE                        => ['CompositeImage', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.10.1'],
        ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE => ['SourceImageNumberOfCompositeImage', 2, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.6.10.2'],
        ExifTag::GAMMA                                  => ['Gamma', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.6.2.2'],

        // --- EXIF 3.0 GPS IFD Tags ---
        ExifTag::GPS_VERSION_ID => ['GPSVersionID', 4, TiffConst::TYPE_BYTE, 'EXIF 3.0 §4.6.7.1.1'],
        // GPS_LATITUDE_REF (0x0001) omitted: tag ID collides with INTEROPERABILITY_INDEX
        ExifTag::GPS_LATITUDE            => ['GPSLatitude', 3, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.3'],
        ExifTag::GPS_LONGITUDE_REF       => ['GPSLongitudeRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.4'],
        ExifTag::GPS_LONGITUDE           => ['GPSLongitude', 3, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.5'],
        ExifTag::GPS_ALTITUDE_REF        => ['GPSAltitudeRef', 1, TiffConst::TYPE_BYTE, 'EXIF 3.0 §4.6.7.1.6'],
        ExifTag::GPS_ALTITUDE            => ['GPSAltitude', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.7'],
        ExifTag::GPS_TIME_STAMP          => ['GPSTimeStamp', 3, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.8'],
        ExifTag::GPS_STATUS              => ['GPSStatus', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.10'],
        ExifTag::GPS_MEASURE_MODE        => ['GPSMeasureMode', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.11'],
        ExifTag::GPS_DOP                 => ['GPSDOP', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.12'],
        ExifTag::GPS_SPEED_REF           => ['GPSSpeedRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.13'],
        ExifTag::GPS_SPEED               => ['GPSSpeed', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.14'],
        ExifTag::GPS_TRACK_REF           => ['GPSTrackRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.15'],
        ExifTag::GPS_TRACK               => ['GPSTrack', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.16'],
        ExifTag::GPS_IMG_DIRECTION_REF   => ['GPSImgDirectionRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.17'],
        ExifTag::GPS_IMG_DIRECTION       => ['GPSImgDirection', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.18'],
        ExifTag::GPS_DEST_LATITUDE_REF   => ['GPSDestLatitudeRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.20'],
        ExifTag::GPS_DEST_LATITUDE       => ['GPSDestLatitude', 3, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.21'],
        ExifTag::GPS_DEST_LONGITUDE_REF  => ['GPSDestLongitudeRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.22'],
        ExifTag::GPS_DEST_LONGITUDE      => ['GPSDestLongitude', 3, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.23'],
        ExifTag::GPS_DEST_BEARING_REF    => ['GPSDestBearingRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.24'],
        ExifTag::GPS_DEST_BEARING        => ['GPSDestBearing', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.25'],
        ExifTag::GPS_DEST_DISTANCE_REF   => ['GPSDestDistanceRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.26'],
        ExifTag::GPS_DEST_DISTANCE       => ['GPSDestDistance', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.27'],
        ExifTag::GPS_DATE_STAMP          => ['GPSDateStamp', 11, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.30'],
        ExifTag::GPS_DIFFERENTIAL        => ['GPSDifferential', 1, TiffConst::TYPE_SHORT, 'EXIF 3.0 §4.6.7.1.31'],
        ExifTag::GPS_H_POSITIONING_ERROR => ['GPSHPositioningError', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.32'],

        // --- DNG Tags ---
        DngTag::DNG_VERSION              => ['DNGVersion', 4, TiffConst::TYPE_BYTE, 'DNG 1.7.1.0'],
        DngTag::DNG_BACKWARD_VERSION     => ['DNGBackwardVersion', 4, TiffConst::TYPE_BYTE, 'DNG 1.7.1.0'],
        DngTag::CFA_LAYOUT               => ['CFALayout', 1, TiffConst::TYPE_SHORT, 'DNG 1.7.1.0'],
        DngTag::BASELINE_EXPOSURE        => ['BaselineExposure', 1, TiffConst::TYPE_SRATIONAL, 'DNG 1.7.1.0'],
        DngTag::BAYER_GREEN_SPLIT        => ['BayerGreenSplit', 1, TiffConst::TYPE_LONG, 'DNG 1.7.1.0'],
        DngTag::MAKER_NOTE_SAFETY        => ['MakerNoteSafety', 1, TiffConst::TYPE_SHORT, 'DNG 1.7.1.0'],
        DngTag::CALIBRATION_ILLUMINANT_1 => ['CalibrationIlluminant1', 1, TiffConst::TYPE_SHORT, 'DNG 1.7.1.0'],
        DngTag::CALIBRATION_ILLUMINANT_2 => ['CalibrationIlluminant2', 1, TiffConst::TYPE_SHORT, 'DNG 1.7.1.0'],
        DngTag::RAW_DATA_UNIQUE_ID       => ['RawDataUniqueID', 16, TiffConst::TYPE_BYTE, 'DNG 1.7.1.0'],
        DngTag::NOISE_REDUCTION_APPLIED  => ['NoiseReductionApplied', 1, TiffConst::TYPE_RATIONAL, 'DNG 1.7.1.0'],
        DngTag::PROFILE_EMBED_POLICY     => ['ProfileEmbedPolicy', 1, TiffConst::TYPE_LONG, 'DNG 1.7.1.0'],
        DngTag::BASELINE_EXPOSURE_OFFSET => ['BaselineExposureOffset', 1, TiffConst::TYPE_RATIONAL, 'DNG 1.7.1.0'],
        DngTag::RAW_TO_PREVIEW_GAIN      => ['RawToPreviewGain', 1, TiffConst::TYPE_DOUBLE, 'DNG 1.7.1.0'],
        DngTag::DEFAULT_USER_CROP        => ['DefaultUserCrop', 4, TiffConst::TYPE_RATIONAL, 'DNG 1.7.1.0'],
        DngTag::DEPTH_FORMAT             => ['DepthFormat', 1, TiffConst::TYPE_SHORT, 'DNG 1.7.1.0'],
        DngTag::DEPTH_NEAR               => ['DepthNear', 1, TiffConst::TYPE_RATIONAL, 'DNG 1.7.1.0'],
        DngTag::DEPTH_FAR                => ['DepthFar', 1, TiffConst::TYPE_RATIONAL, 'DNG 1.7.1.0'],
        DngTag::DEPTH_UNITS              => ['DepthUnits', 1, TiffConst::TYPE_SHORT, 'DNG 1.7.1.0'],
        DngTag::DEPTH_MEASURE_TYPE       => ['DepthMeasureType', 1, TiffConst::TYPE_SHORT, 'DNG 1.7.1.0'],
    ];

    /**
     * Tags with strict TIFF type but no fixed component count.
     *
     * Each entry uses: [name, type, spec]. The type label is derived via typeName().
     *
     * @var array<int, array{0: string, 1: int, 2: string}>
     */
    private const array TYPE_ONLY_TAGS = [
        ExifTag::GPS_MAP_DATUM         => ['GPSMapDatum', TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.19'],
        ExifTag::GPS_SATELLITES        => ['GPSSatellites', TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.9'],
        ExifTag::GPS_PROCESSING_METHOD => ['GPSProcessingMethod', TiffConst::TYPE_UNDEFINED, 'EXIF 3.0 §4.6.7.1.28'],
        ExifTag::GPS_AREA_INFORMATION  => ['GPSAreaInformation', TiffConst::TYPE_UNDEFINED, 'EXIF 3.0 §4.6.7.1.29'],
    ];

    // TYPE_ONLY index constants (no count field)
    private const int TYPE_ONLY_NAME = 0;

    private const int TYPE_ONLY_TYPE = 1;

    private const int TYPE_ONLY_SPEC = 2;

    /**
     * GPS reference tags with strict EXIF layout requirements in the GPS IFD context.
     *
     * Each entry uses: [name, count, type, spec].
     *
     * @var array<int, array{0: string, 1: int, 2: int, 3: string}>
     */
    private const array GPS_REFERENCE_TAG_LAYOUTS = [
        ExifTag::GPS_LATITUDE_REF       => ['GPSLatitudeRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.2'],
        ExifTag::GPS_LONGITUDE_REF      => ['GPSLongitudeRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.4'],
        ExifTag::GPS_DEST_LATITUDE_REF  => ['GPSDestLatitudeRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.20'],
        ExifTag::GPS_DEST_LONGITUDE_REF => ['GPSDestLongitudeRef', 2, TiffConst::TYPE_ASCII, 'EXIF 3.0 §4.6.7.1.22'],
    ];

    /**
     * GPS coordinate value tags with strict EXIF layout requirements in the GPS IFD context.
     *
     * Each entry uses: [name, count, type, spec].
     *
     * @var array<int, array{0: string, 1: int, 2: int, 3: string}>
     */
    private const array GPS_COORDINATE_TAG_LAYOUTS = [
        ExifTag::GPS_LATITUDE       => ['GPSLatitude', 3, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.3'],
        ExifTag::GPS_LONGITUDE      => ['GPSLongitude', 3, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.5'],
        ExifTag::GPS_DEST_LATITUDE  => ['GPSDestLatitude', 3, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.21'],
        ExifTag::GPS_DEST_LONGITUDE => ['GPSDestLongitude', 3, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.23'],
        ExifTag::GPS_ALTITUDE       => ['GPSAltitude', 1, TiffConst::TYPE_RATIONAL, 'EXIF 3.0 §4.6.7.1.7'],
    ];

    /**
     * EXIF camera-control tags with closed numeric value domains.
     *
     * EXIF 3.0 §4.6.6.7 defines these tags with explicit allowed values and
     * reserves remaining codes for future use.
     *
     * @var array<int, array{name: string, allowed: list<int>, spec: string}>
     */
    private const array CAMERA_CONTROL_ENUM_DOMAINS = [
        ExifTag::EXPOSURE_PROGRAM       => ['name' => 'ExposureProgram', 'allowed' => [0, 1, 2, 3, 4, 5, 6, 7, 8], 'spec' => 'EXIF 3.0 §4.6.6.7.3'],
        ExifTag::METERING_MODE          => ['name' => 'MeteringMode', 'allowed' => [0, 1, 2, 3, 4, 5, 6, 255], 'spec' => 'EXIF 3.0 §4.6.6.7.19'],
        ExifTag::LIGHT_SOURCE           => ['name' => 'LightSource', 'allowed' => [0, 1, 2, 3, 4, 9, 10, 11, 12, 13, 14, 15, 17, 18, 19, 20, 21, 22, 23, 24, 255], 'spec' => 'EXIF 3.0 §4.6.6.7.20'],
        ExifTag::SENSING_METHOD         => ['name' => 'SensingMethod', 'allowed' => [1, 2, 3, 4, 5, 7, 8], 'spec' => 'EXIF 3.0 §4.6.6.7.31'],
        ExifTag::EXPOSURE_MODE          => ['name' => 'ExposureMode', 'allowed' => [0, 1, 2], 'spec' => 'EXIF 3.0 §4.6.6.7.36'],
        ExifTag::WHITE_BALANCE          => ['name' => 'WhiteBalance', 'allowed' => [0, 1], 'spec' => 'EXIF 3.0 §4.6.6.7.37'],
        ExifTag::SCENE_CAPTURE_TYPE     => ['name' => 'SceneCaptureType', 'allowed' => [0, 1, 2, 3], 'spec' => 'EXIF 3.0 §4.6.6.7.40'],
        ExifTag::GAIN_CONTROL           => ['name' => 'GainControl', 'allowed' => [0, 1, 2, 3, 4], 'spec' => 'EXIF 3.0 §4.6.6.7.41'],
        ExifTag::CONTRAST               => ['name' => 'Contrast', 'allowed' => [0, 1, 2], 'spec' => 'EXIF 3.0 §4.6.6.7.42'],
        ExifTag::SATURATION             => ['name' => 'Saturation', 'allowed' => [0, 1, 2], 'spec' => 'EXIF 3.0 §4.6.6.7.43'],
        ExifTag::SHARPNESS              => ['name' => 'Sharpness', 'allowed' => [0, 1, 2], 'spec' => 'EXIF 3.0 §4.6.6.7.44'],
        ExifTag::SUBJECT_DISTANCE_RANGE => ['name' => 'SubjectDistanceRange', 'allowed' => [0, 1, 2, 3], 'spec' => 'EXIF 3.0 §4.6.6.7.46'],
    ];

    // Postel's Law: EXIF 3.0 §4.6.5.1 says several tags "shall not be recorded"
    // in IFD0 for JPEG-compressed primary images, but many cameras include them
    // anyway (e.g. BitsPerSample=8, SamplesPerPixel=3).  We only reject tags
    // that would cause structural parsing conflicts — strip-based storage and
    // JPEG interchange pointers.  Informational tags like BitsPerSample,
    // SamplesPerPixel, PhotometricInterpretation, PlanarConfiguration, and
    // Compression are tolerated because they are redundant (derivable from the
    // JPEG SOF marker) and harmless.  (GH-1546)

    /**
     * Tags prohibited in JPEG-compressed primary images (IFD0).
     *
     * EXIF 3.0 §4.6.5.1 specifies several tags that shall not be used when the
     * primary image data is JPEG-compressed.
     *
     * @var list<array{int, string}>
     */
    private const array JPEG_PROHIBITED_TAGS = [
        [ExifTag::STRIP_OFFSETS, 'StripOffsets'],
        [ExifTag::ROWS_PER_STRIP, 'RowsPerStrip'],
        [ExifTag::STRIP_BYTE_COUNTS, 'StripByteCounts'],
        [ExifTag::JPEG_INTERCHANGE_FORMAT, 'JPEGInterchangeFormat'],
        [ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 'JPEGInterchangeFormatLength'],
    ];

    /**
     * EXIF 3.0 §4.6.6.9 tags must reside in the Exif IFD, not IFD0.
     *
     * @var list<array{int, string, string}>
     */
    private const array EXIF_IFD_ONLY_TAGS = [
        [ExifTag::CAMERA_OWNER_NAME, 'CameraOwnerName', '§4.6.6.9.2'],
        [ExifTag::PHOTOGRAPHER, 'Photographer', '§4.6.6.9.9'],
        [ExifTag::IMAGE_EDITOR, 'ImageEditor', '§4.6.6.9.10'],
        [ExifTag::CAMERA_FIRMWARE, 'CameraFirmware', '§4.6.6.9.11'],
        [ExifTag::RAW_DEVELOPING_SOFTWARE, 'RAWDevelopingSoftware', '§4.6.6.9.12'],
        [ExifTag::IMAGE_EDITING_SOFTWARE, 'ImageEditingSoftware', '§4.6.6.9.13'],
        [ExifTag::METADATA_EDITING_SOFTWARE, 'MetadataEditingSoftware', '§4.6.6.9.14'],
    ];

    /**
     * @param MemoryBuffer $buffer Seekable binary buffer for thumbnail stream validation.
     */
    public function __construct(
        private MemoryBuffer $buffer,
    ) {
    }

    /**
     * Validates tag layouts with fixed byte counts mandated by EXIF.
     *
     * @param int $tag   TIFF tag identifier.
     * @param int $type  TIFF field type code.
     * @param int $count Component count from the IFD entry.
     */
    public function validateFixedLengthTagLayout(int $tag, int $type, int $count): void
    {
        if (!isset(self::FIXED_LENGTH_TAGS[$tag])) {
            return;
        }

        $rule                    = self::FIXED_LENGTH_TAGS[$tag];
        $gpsTypeToleranceApplied = false;

        // --- Type validation (Postel's Law: tolerate common real-world deviations) ---

        if ($type !== $rule[self::RULE_TYPE]) {
            // EXIF 3.0 §4.6.6.1.1/§4.6.6.1.2 specify UNDEFINED for version tags,
            // but many cameras use ASCII. Accept both directions for compatibility.
            $asciiUndefinedSwap = ($type === TiffConst::TYPE_UNDEFINED && $rule[self::RULE_TYPE] === TiffConst::TYPE_ASCII)
                || ($type === TiffConst::TYPE_ASCII && $rule[self::RULE_TYPE] === TiffConst::TYPE_UNDEFINED);

            // Postel's Law: RATIONAL (unsigned) and SRATIONAL (signed) share the
            // same 8-byte binary layout (two int32).  Many cameras swap them for
            // exposure value tags (ShutterSpeedValue, ApertureValue, BrightnessValue,
            // ExposureBiasValue, MaxApertureValue, SubjectDistance).  (GH-1538)
            $rationalSrationalSwap = ($type === TiffConst::TYPE_RATIONAL && $rule[self::RULE_TYPE] === TiffConst::TYPE_SRATIONAL)
                || ($type === TiffConst::TYPE_SRATIONAL && $rule[self::RULE_TYPE] === TiffConst::TYPE_RATIONAL);

            // EXIF 3.0 §4.6.7 specifies strict types for GPS value tags, but
            // real-world cameras write a wide range of numeric types
            // (SRATIONAL, SHORT, LONG, etc.) and swap UNDEFINED/ASCII.
            // Skip the type check for GPS RATIONAL and UNDEFINED tags
            // to follow Postel's Law.
            $gpsTypeTolerance = ($rule[self::RULE_TYPE] === TiffConst::TYPE_RATIONAL || $rule[self::RULE_TYPE] === TiffConst::TYPE_UNDEFINED)
                && str_starts_with((string) $rule[self::RULE_SPEC], 'EXIF 3.0 §4.6.7');

            if (!$asciiUndefinedSwap && !$rationalSrationalSwap && !$gpsTypeTolerance) {
                throw new ParseError(sprintf(
                    '%s must use TIFF type %s per %s.',
                    $rule[self::RULE_NAME],
                    $this->typeName($rule[self::RULE_TYPE]),
                    $rule[self::RULE_SPEC],
                ), 1317);
            }

            $gpsTypeToleranceApplied = $gpsTypeTolerance;
        }

        // --- Count validation (Postel's Law: tolerate common real-world deviations) ---

        if ($count === $rule[self::RULE_COUNT]) {
            return;
        }

        // Postel's Law: when GPS type tolerance fired the actual type differs from
        // spec (e.g. ASCII instead of RATIONAL), so the count field has different
        // semantics (string byte-length vs. value count).  Skip the count check
        // entirely — the value will be parsed according to its actual type.
        // (GH-1542)
        if ($gpsTypeToleranceApplied) {
            return;
        }

        // ComponentsConfiguration commonly has non-4-byte payloads in the wild.
        if ($tag === ExifTag::COMPONENTS_CONFIGURATION) {
            return;
        }

        // Postel's Law: many cameras write GPS coordinates with count=4 instead of
        // the spec-mandated 3, adding a fourth zero RATIONAL value.  Accept any
        // count ≥ expected for GPS RATIONAL tags — only the first N values are
        // used.  (GH-1535)
        if ($count > $rule[self::RULE_COUNT]
            && $rule[self::RULE_TYPE] === TiffConst::TYPE_RATIONAL
            && str_starts_with((string) $rule[self::RULE_SPEC], 'EXIF 3.0 §4.6.7')
        ) {
            return;
        }

        // Postel's Law: DateTime, DateTimeOriginal, DateTimeDigitized require
        // count=20 (19 printable chars + NUL), but some cameras (e.g. Kodak)
        // write count=19 omitting the NUL terminator.  The ASCII string parser
        // already handles both NUL-terminated and non-NUL-terminated strings.
        // (GH-1541)
        if ($rule[self::RULE_TYPE] === TiffConst::TYPE_ASCII && $rule[self::RULE_COUNT] === 20 && $count === 19) {
            return;
        }

        // Postel's Law: ExifVersion/FlashpixVersion are informational 4-byte
        // version strings.  Some cameras add a NUL terminator (count=5) or use
        // other lengths.  Accept any count — the version string is non-critical
        // for parsing.  (GH-1545)
        if ($tag === ExifTag::EXIF_VERSION || $tag === ExifTag::FLASHPIX_VERSION) {
            return;
        }

        throw new ParseError(sprintf(
            '%s must contain exactly %d bytes per %s.',
            $rule[self::RULE_NAME],
            $rule[self::RULE_COUNT],
            $rule[self::RULE_SPEC],
        ), 1318);
    }

    /**
     * Validates tags that have a strict TIFF type but no fixed component count.
     *
     * @param int $tag  TIFF tag identifier.
     * @param int $type TIFF field type code.
     */
    public function validateTypeOnlyTagLayout(int $tag, int $type): void
    {
        if (!isset(self::TYPE_ONLY_TAGS[$tag])) {
            return;
        }

        $rule = self::TYPE_ONLY_TAGS[$tag];

        if ($type === $rule[self::TYPE_ONLY_TYPE]) {
            return;
        }

        // Postel's Law: GPS text tags (GPSProcessingMethod §4.6.7.1.28,
        // GPSAreaInformation §4.6.7.1.29) are specified as UNDEFINED with an
        // 8-byte character-code prefix, but some cameras (e.g. HMD/Nokia)
        // write them as plain ASCII without the prefix.  Accept ASCII↔UNDEFINED
        // swaps for GPS tags to avoid aborting on otherwise valid metadata.
        // (GH-1540)
        $gpsUndefinedAsciiSwap = str_starts_with($rule[self::TYPE_ONLY_SPEC], 'EXIF 3.0 §4.6.7')
            && (($type === TiffConst::TYPE_ASCII && $rule[self::TYPE_ONLY_TYPE] === TiffConst::TYPE_UNDEFINED)
                || ($type === TiffConst::TYPE_UNDEFINED && $rule[self::TYPE_ONLY_TYPE] === TiffConst::TYPE_ASCII));

        if ($gpsUndefinedAsciiSwap) {
            return;
        }

        throw new ParseError(sprintf(
            '%s must use TIFF type %s per %s.',
            $rule[self::TYPE_ONLY_NAME],
            $this->typeName($rule[self::TYPE_ONLY_TYPE]),
            $rule[self::TYPE_ONLY_SPEC],
        ), 1317);
    }

    /**
     * Validates individual tag value domains inline during readDirEntry.
     *
     * @param int   $tag   TIFF tag identifier.
     * @param mixed $value Decoded tag value.
     */
    public function validateTagValueDomain(int $tag, mixed $value): void
    {
        if (!is_int($value)) {
            return;
        }

        match ($tag) {
            DngTag::MAKER_NOTE_SAFETY            => $this->assertMakerNoteSafetyDomain($value),
            ExifTag::ORIENTATION                 => $this->assertOrientationDomain($value),
            ExifTag::YCBCR_POSITIONING           => $this->assertYCbCrPositioningDomain($value),
            ExifTag::COLOR_SPACE                 => $this->assertColorSpaceDomain($value),
            ExifTag::RESOLUTION_UNIT             => $this->assertResolutionUnitDomain($value),
            ExifTag::FOCAL_PLANE_RESOLUTION_UNIT => $this->assertFocalPlaneResolutionUnitDomain($value),
            ExifTag::PLANAR_CONFIGURATION        => $this->assertPlanarConfigurationDomain($value),
            TiffTag::PREDICTOR                   => $this->assertPredictorDomain($value),
            default                              => null,
        };
    }

    /**
     * Validates strict GPS reference tag layouts within the GPS IFD.
     *
     * @param Ifd|null $gpsIfd GPS IFD when present.
     */
    public function validateGpsReferenceTagLayouts(?Ifd $gpsIfd): void
    {
        if (!$gpsIfd instanceof Ifd) {
            return;
        }

        foreach (self::GPS_REFERENCE_TAG_LAYOUTS as $tag => $rule) {
            $entry = $gpsIfd->get($tag);
            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if ($entry->type !== $rule[self::RULE_TYPE]) {
                throw new ParseError(sprintf(
                    '%s must use TIFF type %s per %s.',
                    $rule[self::RULE_NAME],
                    $this->typeName($rule[self::RULE_TYPE]),
                    $rule[self::RULE_SPEC],
                ), 1317);
            }

            if ($entry->count !== $rule[self::RULE_COUNT]) {
                throw new ParseError(sprintf(
                    '%s must contain exactly %d bytes per %s.',
                    $rule[self::RULE_NAME],
                    $rule[self::RULE_COUNT],
                    $rule[self::RULE_SPEC],
                ), 1318);
            }
        }
    }

    /**
     * Validates strict GPS coordinate value tag layouts within the GPS IFD.
     *
     * @param Ifd|null $gpsIfd GPS IFD when present.
     */
    public function validateGpsCoordinateTagLayouts(?Ifd $gpsIfd): void
    {
        if (!$gpsIfd instanceof Ifd) {
            return;
        }

        foreach (self::GPS_COORDINATE_TAG_LAYOUTS as $tag => $rule) {
            $entry = $gpsIfd->get($tag);
            if (!$entry instanceof IfdEntry) {
                continue;
            }

            // EXIF 3.0 §4.6.7 specifies RATIONAL for GPS coordinate values.
            // Real-world cameras write a wide range of numeric types (SRATIONAL,
            // SHORT, LONG, etc.).  Skip the type check and only validate the
            // component count to follow Postel's Law.

            if ($entry->count !== $rule[self::RULE_COUNT]) {
                throw new ParseError(sprintf(
                    '%s must contain exactly %d bytes per %s.',
                    $rule[self::RULE_NAME],
                    $rule[self::RULE_COUNT],
                    $rule[self::RULE_SPEC],
                ), 1318);
            }
        }
    }

    /**
     * Validates EXIF primary/thumbnail structure combinations across IFD0 and IFD1.
     *
     * EXIF 3.0 §4.5.8 Table 3 states that when primary image data is uncompressed
     * RGB or uncompressed YCbCr, the thumbnail shall not be JPEG-compressed.
     *
     * @param Ifd      $ifd0        Primary image IFD.
     * @param Ifd|null $ifd1        Thumbnail IFD.
     * @param bool     $jpegContext True when APP1 data comes from JPEG primary image context.
     */
    public function validatePrimaryThumbnailStructureCompatibility(Ifd $ifd0, ?Ifd $ifd1, bool $jpegContext): void
    {
        if (!$ifd1 instanceof Ifd || $jpegContext) {
            return;
        }

        $thumbCompression = $ifd1->get(ExifTag::COMPRESSION);
        if (!($thumbCompression instanceof IfdEntry) || !is_int($thumbCompression->value) || ($thumbCompression->value !== 6)) {
            return;
        }

        $primaryCompression = $ifd0->get(ExifTag::COMPRESSION);
        if (!($primaryCompression instanceof IfdEntry) || !is_int($primaryCompression->value) || ($primaryCompression->value !== 1)) {
            return;
        }

        $photometric = $ifd0->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        if (!($photometric instanceof IfdEntry) || !is_int($photometric->value)) {
            return;
        }

        if (($photometric->value === 2) || ($photometric->value === 6)) {
            $primaryStructure = $photometric->value === 2 ? 'uncompressed RGB' : 'uncompressed YCbCr';

            throw new ParseError(
                sprintf(
                    'IFD1 JPEG thumbnail compression is not allowed when IFD0 primary image uses %s per EXIF 3.0 §4.5.8 Table 3.',
                    $primaryStructure,
                ),
                1468,
            );
        }
    }

    /**
     * Validates closed value domains for EXIF camera-control enum tags.
     *
     * EXIF 3.0 §4.6.6.7 defines these tags as fixed code lists, where values
     * outside each list are reserved and must be rejected in strict parsing.
     *
     * @param Ifd|null ...$ifds Candidate directories to search.
     */
    public function validateCameraControlEnumDomains(?Ifd ...$ifds): void
    {
        foreach ($ifds as $ifd) {
            if (!$ifd instanceof Ifd) {
                continue;
            }

            foreach (self::CAMERA_CONTROL_ENUM_DOMAINS as $tag => $config) {
                $entry = $ifd->get($tag);
                if (!$entry instanceof IfdEntry) {
                    continue;
                }

                if (!is_int($entry->value)) {
                    continue;
                }

                if (!in_array($entry->value, $config['allowed'], true)) {
                    throw new ParseError(
                        sprintf(
                            '%s value %d is reserved or out of domain for tag 0x%04X per %s',
                            $config['name'],
                            $entry->value,
                            $tag,
                            $config['spec'],
                        ),
                        1416,
                    );
                }
            }
        }
    }

    /**
     * Validates EXIF Flash tag bitfield semantics and reserved combinations.
     *
     * EXIF 3.0 §4.6.6.7.21 defines bit 0 (fired), bits 1-2 (return status),
     * bits 3-4 (mode), bit 5 (function present flag), and bit 6 (red-eye).
     * Bit 7 and above are reserved and must remain zero in strict conformance.
     *
     * @param Ifd|null $exifIfd EXIF IFD when present.
     */
    public function validateFlashBitfield(?Ifd $exifIfd): void
    {
        if (!$exifIfd instanceof Ifd) {
            return;
        }

        $entry = $exifIfd->get(ExifTag::FLASH);
        if (!($entry instanceof IfdEntry) || !is_int($entry->value)) {
            return;
        }

        // Mask to bits 0–6; ignore reserved high-order bits (Postel's Law).
        $flashBits = $entry->value & 0x7F;

        $fired      = ($flashBits & 0x01) !== 0;
        $returnBits = ($flashBits >> 1) & 0x03;

        if ($returnBits === 1) {
            throw new ParseError(
                sprintf('Flash value %d contains reserved return-status bits per EXIF 3.0 §4.6.6.7.21', $flashBits),
                1418,
            );
        }

        if ((($returnBits === 2) || ($returnBits === 3)) && !$fired) {
            throw new ParseError(
                sprintf('Flash value %d encodes return detection while flash-fired bit is unset per EXIF 3.0 §4.6.6.7.21', $flashBits),
                1419,
            );
        }
    }

    /**
     * Validates strict JPEG thumbnail stream conformance for IFD1 Compression=6.
     *
     * EXIF 3.0 §4.6.5.1.6 defines JPEGInterchangeFormat/JPEGInterchangeFormatLength as
     * an SOI..EOI JPEG stream, and EXIF 3.0 §4.7 marker guidance excludes APPn/COM markers
     * in this embedded thumbnail stream representation.
     *
     * @param Ifd|null $ifd1 Thumbnail IFD.
     */
    public function validateJpegThumbnailStream(?Ifd $ifd1): void
    {
        if (!$ifd1 instanceof Ifd) {
            return;
        }

        $compression = $ifd1->get(ExifTag::COMPRESSION);
        if (!($compression instanceof IfdEntry) || !is_int($compression->value) || ($compression->value !== 6)) {
            return;
        }

        $offsetEntry = $ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT);
        $lengthEntry = $ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);
        if (!($offsetEntry instanceof IfdEntry) || !($lengthEntry instanceof IfdEntry)) {
            return;
        }

        if (!is_int($offsetEntry->value) || !is_int($lengthEntry->value)) {
            return;
        }

        $thumbnailOffset = $offsetEntry->value;
        $thumbnailLength = $lengthEntry->value;
        if ($thumbnailLength <= 0) {
            return;
        }

        $blobSize = $this->buffer->size();
        if (
            ($thumbnailOffset < 0)
            || ($thumbnailOffset > $blobSize)
            || ($thumbnailLength > $blobSize)
            || ($thumbnailOffset > ($blobSize - $thumbnailLength))
        ) {
            throw new ParseError(
                sprintf(
                    'JPEG thumbnail stream at offset %d with length %d exceeds TIFF data bounds',
                    $thumbnailOffset,
                    $thumbnailLength,
                ),
                1410,
            );
        }

        $cursor = $this->buffer->tell();

        try {
            $this->buffer->seek($thumbnailOffset);
            $thumbnailBytes = $this->buffer->read($thumbnailLength);
        } catch (BoundsError $exception) {
            throw new ParseError(
                sprintf(
                    'JPEG thumbnail stream at offset %d with length %d is truncated',
                    $thumbnailOffset,
                    $thumbnailLength,
                ),
                1411,
                $exception,
            );
        } finally {
            $this->buffer->seek($cursor);
        }

        if (strlen($thumbnailBytes) < 4 || !str_starts_with($thumbnailBytes, "\xFF\xD8")) {
            throw new ParseError(
                sprintf('JPEG thumbnail stream at offset %d is missing SOI marker', $thumbnailOffset),
                1412,
            );
        }

        if (!str_ends_with($thumbnailBytes, "\xFF\xD9")) {
            throw new ParseError(
                sprintf('JPEG thumbnail stream at offset %d is missing EOI marker', $thumbnailOffset),
                1413,
            );
        }

        $this->validateJpegThumbnailDisallowedMarkers();
    }

    /**
     * Validates JPEG thumbnail marker compliance.
     *
     * EXIF 3.0 §4.8 restricts certain markers, but virtually all cameras
     * and editors embed APP0, APP14, restart markers, and COM markers in
     * thumbnail streams.  This method is intentionally a no-op to follow
     * Postel's Law and accept these common deviations.
     */
    private function validateJpegThumbnailDisallowedMarkers(): void
    {
    }

    /**
     * Validates that tags prohibited in JPEG-compressed primary images are absent from IFD0.
     *
     * EXIF 3.0 §4.6.5.1 prohibits strip/tile storage descriptors and
     * YCbCrSubSampling in IFD0 when the primary image is JPEG-compressed.
     *
     * @param Ifd $ifd0 Primary image IFD.
     */
    public function validateJpegContextProhibitions(Ifd $ifd0): void
    {
        foreach (self::JPEG_PROHIBITED_TAGS as [$tag, $name]) {
            if ($ifd0->get($tag) instanceof IfdEntry) {
                throw new ParseError(sprintf(
                    '%s shall not be present in IFD0 for JPEG-compressed primary image per EXIF 3.0 §4.6.5.1.',
                    $name,
                ), 1353);
            }
        }

        if ($ifd0->get(ExifTag::YCBCR_SUB_SAMPLING) instanceof IfdEntry) {
            throw new ParseError(
                'YCbCrSubSampling shall not be present in IFD0 for JPEG-compressed primary image per EXIF 3.0 §4.6.5.1.14.',
                1354,
            );
        }
    }

    /**
     * EXIF 3.0 §4.6.5.4.6: Artist shall be recorded when CameraOwnerName,
     * Photographer or ImageEditor is present.
     *
     * @param Ifd $ifd0    Primary image IFD.
     * @param Ifd $exifIfd EXIF IFD.
     */
    public function validateCompanionArtist(Ifd $ifd0, Ifd $exifIfd): void
    {
        $dependents = [
            [ExifTag::CAMERA_OWNER_NAME, 'CameraOwnerName', '§4.6.6.9.2'],
            [ExifTag::PHOTOGRAPHER, 'Photographer', '§4.6.6.9.9'],
            [ExifTag::IMAGE_EDITOR, 'ImageEditor', '§4.6.6.9.10'],
        ];

        foreach ($dependents as [$tag, $name, $section]) {
            if ($exifIfd->get($tag) instanceof IfdEntry && !$ifd0->get(ExifTag::ARTIST) instanceof IfdEntry) {
                throw new ParseError(sprintf(
                    '%s requires Artist in IFD0 per EXIF 3.0 %s; §4.6.5.4.6.',
                    $name,
                    $section,
                ), 1454);
            }
        }
    }

    /**
     * EXIF 3.0 §4.6.5.4.4: Software shall be recorded when CameraFirmware,
     * RAWDevelopingSoftware, ImageEditingSoftware or MetadataEditingSoftware is present.
     *
     * @param Ifd $ifd0    Primary image IFD.
     * @param Ifd $exifIfd EXIF IFD.
     */
    public function validateCompanionSoftware(Ifd $ifd0, Ifd $exifIfd): void
    {
        $dependents = [
            [ExifTag::CAMERA_FIRMWARE, 'CameraFirmware', '§4.6.6.9.11'],
            [ExifTag::RAW_DEVELOPING_SOFTWARE, 'RAWDevelopingSoftware', '§4.6.6.9.12'],
            [ExifTag::IMAGE_EDITING_SOFTWARE, 'ImageEditingSoftware', '§4.6.6.9.13'],
            [ExifTag::METADATA_EDITING_SOFTWARE, 'MetadataEditingSoftware', '§4.6.6.9.14'],
        ];

        foreach ($dependents as [$tag, $name, $section]) {
            if ($exifIfd->get($tag) instanceof IfdEntry && !$ifd0->get(ExifTag::SOFTWARE) instanceof IfdEntry) {
                throw new ParseError(sprintf(
                    '%s requires Software in IFD0 per EXIF 3.0 %s; §4.6.5.4.4.',
                    $name,
                    $section,
                ), 1455);
            }
        }
    }

    /**
     * EXIF 3.0 sensitivity tags: enforce mandatory companion combinations.
     *
     * §4.6.6.7.8–§4.6.6.7.12: SOS/REI/ISOSpeed require PhotographicSensitivity
     * and SensitivityType. ISOSpeedLatitudeyyy/zzz require ISOSpeed and each other.
     *
     * @param Ifd $exifIfd EXIF IFD.
     */
    public function validateSensitivityCombinations(Ifd $exifIfd): void
    {
        $hasSensitivity = $exifIfd->get(ExifTag::PHOTOGRAPHIC_SENSITIVITY) instanceof IfdEntry;
        $hasType        = $exifIfd->get(ExifTag::SENSITIVITY_TYPE) instanceof IfdEntry;

        $dependents = [
            [ExifTag::STANDARD_OUTPUT_SENSITIVITY, 'StandardOutputSensitivity', '§4.6.6.7.8'],
            [ExifTag::RECOMMENDED_EXPOSURE_INDEX, 'RecommendedExposureIndex', '§4.6.6.7.9'],
            [ExifTag::ISO_SPEED, 'ISOSpeed', '§4.6.6.7.10'],
        ];

        foreach ($dependents as [$tag, $name, $section]) {
            if ($exifIfd->get($tag) instanceof IfdEntry && (!$hasSensitivity || !$hasType)) {
                throw new ParseError(sprintf(
                    '%s requires PhotographicSensitivity and SensitivityType per EXIF 3.0 %s.',
                    $name,
                    $section,
                ), 1456);
            }
        }

        $hasIsoSpeed = $exifIfd->get(ExifTag::ISO_SPEED) instanceof IfdEntry;
        $hasYyy      = $exifIfd->get(ExifTag::ISO_SPEED_LATITUDE_YYY) instanceof IfdEntry;
        $hasZzz      = $exifIfd->get(ExifTag::ISO_SPEED_LATITUDE_ZZZ) instanceof IfdEntry;

        if ($hasYyy && (!$hasIsoSpeed || !$hasZzz)) {
            throw new ParseError(
                'ISOSpeedLatitudeyyy requires ISOSpeed and ISOSpeedLatitudezzz per EXIF 3.0 §4.6.6.7.11.',
                1457,
            );
        }

        if ($hasZzz && (!$hasIsoSpeed || !$hasYyy)) {
            throw new ParseError(
                'ISOSpeedLatitudezzz requires ISOSpeed and ISOSpeedLatitudeyyy per EXIF 3.0 §4.6.6.7.12.',
                1458,
            );
        }
    }

    /**
     * Validates that EXIF 3.0 §4.6.6.9 tags are not placed in IFD0.
     *
     * @param Ifd $ifd0 Primary image IFD.
     */
    public function validateExifIfdPlacement(Ifd $ifd0): void
    {
        foreach (self::EXIF_IFD_ONLY_TAGS as [$tag, $name, $section]) {
            if ($ifd0->get($tag) instanceof IfdEntry) {
                throw new ParseError(sprintf(
                    '%s must reside in the Exif IFD, not IFD0, per EXIF 3.0 %s.',
                    $name,
                    $section,
                ), 1463);
            }
        }
    }

    /**
     * Validates CompositeImage value domain and required companion tags.
     *
     * EXIF 3.0 §4.6.6.7.47 defines CompositeImage values 0..3 and reserves
     * all other codes. When value 3 is present, §4.6.6.7.48 and §4.6.6.7.49
     * require both companion tags with valid payload structures.
     *
     * @param Ifd|null   $exifIfd    EXIF IFD when present.
     * @param ParsedExif $parsedExif Parsed EXIF result for companion validation.
     */
    public function validateCompositeImageDependencies(?Ifd $exifIfd, ParsedExif $parsedExif): void
    {
        if (!$exifIfd instanceof Ifd) {
            return;
        }

        $compositeImage = $exifIfd->get(ExifTag::COMPOSITE_IMAGE);
        if (!($compositeImage instanceof IfdEntry) || !is_int($compositeImage->value)) {
            return;
        }

        $compositeValue = $compositeImage->value;
        if (($compositeValue < 0) || ($compositeValue > 3)) {
            throw new ParseError(
                sprintf('CompositeImage value %d is outside the valid domain {0,1,2,3} per EXIF 3.0 §4.6.6.7.47.', $compositeValue),
                1420,
            );
        }

        if ($compositeValue !== 3) {
            return;
        }

        $sourceImageNumber = $exifIfd->get(ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE);
        if (!$sourceImageNumber instanceof IfdEntry) {
            throw new ParseError(
                'CompositeImage value 3 requires SourceImageNumberOfCompositeImage per EXIF 3.0 §4.6.6.7.48.',
                1421,
            );
        }

        if ($parsedExif->sourceImageNumberOfCompositeImage() === null) {
            throw new ParseError(
                'SourceImageNumberOfCompositeImage payload is invalid for CompositeImage value 3 per EXIF 3.0 §4.6.6.7.48.',
                1422,
            );
        }

        $sourceExposureTimes = $exifIfd->get(ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE);
        if (!$sourceExposureTimes instanceof IfdEntry) {
            throw new ParseError(
                'CompositeImage value 3 requires SourceExposureTimesOfCompositeImage per EXIF 3.0 §4.6.6.7.49.',
                1423,
            );
        }

        if (!$parsedExif->sourceExposureTimesOfCompositeImage() instanceof SourceExposureTimes) {
            throw new ParseError(
                'SourceExposureTimesOfCompositeImage payload is invalid for CompositeImage value 3 per EXIF 3.0 §4.6.6.7.49.',
                1424,
            );
        }
    }

    /**
     * Validates strict structural decoding for SourceExposureTimesOfCompositeImage.
     *
     * EXIF 3.0 §4.6.6.7.49 Figure 25 defines a complete binary layout. Partial,
     * truncated, or trailing payload bytes are non-conformant and rejected.
     *
     * @param Ifd|null   $exifIfd    EXIF IFD when present.
     * @param ParsedExif $parsedExif Parsed EXIF result for companion validation.
     */
    public function validateSourceExposureTimesPayload(?Ifd $exifIfd, ParsedExif $parsedExif): void
    {
        if (!$exifIfd instanceof Ifd) {
            return;
        }

        $entry = $exifIfd->get(ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE);
        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (!$parsedExif->sourceExposureTimesOfCompositeImage() instanceof SourceExposureTimes) {
            throw new ParseError(
                'SourceExposureTimesOfCompositeImage payload is malformed or truncated per EXIF 3.0 §4.6.6.7.49 Figure 25.',
                1425,
            );
        }
    }

    /**
     * Derives a human-readable TIFF type label from a type code.
     *
     * @param int $type TIFF field type code.
     */
    private function typeName(int $type): string
    {
        return match ($type) {
            TiffConst::TYPE_BYTE      => 'BYTE',
            TiffConst::TYPE_ASCII     => 'ASCII',
            TiffConst::TYPE_SHORT     => 'SHORT',
            TiffConst::TYPE_LONG      => 'LONG',
            TiffConst::TYPE_RATIONAL  => 'RATIONAL',
            TiffConst::TYPE_SBYTE     => 'SBYTE',
            TiffConst::TYPE_UNDEFINED => 'UNDEFINED',
            TiffConst::TYPE_SSHORT    => 'SSHORT',
            TiffConst::TYPE_SLONG     => 'SLONG',
            TiffConst::TYPE_SRATIONAL => 'SRATIONAL',
            TiffConst::TYPE_FLOAT     => 'FLOAT',
            TiffConst::TYPE_DOUBLE    => 'DOUBLE',
            TiffConst::TYPE_IFD       => 'IFD',
            TiffConst::TYPE_LONG8     => 'LONG8',
            TiffConst::TYPE_SLONG8    => 'SLONG8',
            TiffConst::TYPE_IFD8      => 'IFD8',
            default                   => sprintf('TYPE_%d', $type),
        };
    }

    private function assertMakerNoteSafetyDomain(int $value): void
    {
        if ($value !== 0 && $value !== 1) {
            throw new ParseError(sprintf(
                'MakerNoteSafety value %d is outside the valid domain {0, 1} per DNG 1.7.1.0.',
                $value,
            ), 1310);
        }
    }

    private function assertOrientationDomain(int $value): void
    {
        // Orientation 0 is not defined by EXIF 3.0 §4.6.5.1.6 (domain 1..8) but
        // is commonly written by tools like ImageMagick to mean "unspecified".
        if ($value < 0 || $value > 8) {
            throw new ParseError(sprintf(
                'Orientation value %d is outside the valid domain 0..8 per EXIF 3.0 §4.6.5.1.6.',
                $value,
            ), 1311);
        }
    }

    private function assertYCbCrPositioningDomain(int $value): void
    {
        // YCbCrPositioning 0 is not defined by EXIF 3.0 §4.6.5.1.13 (domain {1, 2})
        // but DNG/RAW files commonly write 0 when YCbCr is not applicable.
        if (!in_array($value, [0, 1, 2], true)) {
            throw new ParseError(sprintf(
                'YCbCrPositioning value %d is outside the valid domain {0, 1, 2} per EXIF 3.0 §4.6.5.1.13.',
                $value,
            ), 1312);
        }
    }

    private function assertColorSpaceDomain(int $value): void
    {
        if ($value !== 1 && $value !== 0xFFFF) {
            throw new ParseError(sprintf(
                'ColorSpace value %d is outside the valid domain {1, 65535} per EXIF 3.0 §4.6.6.2.1.',
                $value,
            ), 1313);
        }
    }

    private function assertResolutionUnitDomain(int $value): void
    {
        // Postel's Law: EXIF 3.0 §4.6.5.1.11 restricts ResolutionUnit to {2, 3}
        // (inches, centimeters), but TIFF 6.0 §5 also defines value 1 ("No
        // Absolute Unit of Measurement") for aspect-ratio-only resolution.  Many
        // older scanners and image editors use value 1.  Accept the full TIFF
        // domain {1, 2, 3} to avoid aborting on otherwise valid images.  (GH-1537)
        if (!in_array($value, [1, 2, 3], true)) {
            throw new ParseError(sprintf(
                'ResolutionUnit value %d is outside the valid domain {1, 2, 3} per TIFF 6.0 §5.',
                $value,
            ), 1314);
        }
    }

    private function assertFocalPlaneResolutionUnitDomain(int $value): void
    {
        if ($value !== 2 && $value !== 3) {
            throw new ParseError(sprintf(
                'FocalPlaneResolutionUnit value %d is outside the valid domain {2, 3} per EXIF 3.0 §4.6.6.7.28.',
                $value,
            ), 1315);
        }
    }

    private function assertPlanarConfigurationDomain(int $value): void
    {
        if ($value !== 1 && $value !== 2) {
            throw new ParseError(sprintf(
                'PlanarConfiguration value %d is outside the valid domain {1, 2} per EXIF 3.0 §4.6.5.1.10.',
                $value,
            ), 1316);
        }
    }

    private function assertPredictorDomain(int $value): void
    {
        if ($value !== 1 && $value !== 2) {
            throw new ParseError(sprintf(
                'Predictor value %d is outside the valid domain {1, 2} per TIFF 6.0 §14.',
                $value,
            ), 1358);
        }
    }
}
