<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Value\SourceExposureTimes;

use function array_any;
use function array_slice;
use function chr;
use function count;
use function in_array;
use function intdiv;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function ltrim;
use function mb_check_encoding;
use function ord;
use function pack;
use function preg_match;
use function rtrim;
use function sha1;
use function sprintf;
use function strlen;
use function strpos;
use function strspn;
use function substr;

/**
 * Parses classic TIFF and BigTIFF structures embedded in EXIF payloads.
 *
 * EXIF 3.0 §4.5 outlines the TIFF header layout, data type handling, and IFD
 * traversal rules honoured by this reader. TIFF 6.0 §2.1 defines the file
 * structure and byte order, §2.2 defines field types, and §8 provides the
 * baseline directory semantics shared by both formats.
 */
final class TiffExifParser
{
    /**
     * GH-898: Maximum number of IFD entries to prevent DoS via pathologically large payloads.
     */
    private const int MAX_IFD_ENTRIES = 10_000;

    /**
     * Tag identifiers that store counted image data such as strips or tiles.
     *
     * EXIF 3.0 §4.6.4 (Table 3) describes these TIFF attributes for thumbnail and
     * primary image payloads, including the JPEG interchange fields. See also TIFF 6.0.
     *
     * @var list<int>
     */
    private const array COUNTED_IMAGE_DATA_TAGS = [
        ExifTag::STRIP_OFFSETS,
        ExifTag::STRIP_BYTE_COUNTS,
        TiffTag::TILE_OFFSETS,
        TiffTag::TILE_BYTE_COUNTS,
    ];

    /**
     * Tags whose values encode offsets within the TIFF blob.
     *
     * EXIF 3.0 §4.6.3 lists the Exif, GPS and Interoperability IFD pointer fields that
     * chain the directory hierarchy, with §4.6.3.1.1 clarifying that the Exif IFD pointer
     * is a single LONG offset to an IFD structured like TIFF but without embedded image data.
     *
     * @var list<int>
     */
    private const array POINTER_TAGS = [
        ExifTag::EXIF_IFD_POINTER,
        ExifTag::GPS_IFD_POINTER,
        ExifTag::INTEROPERABILITY_IFD_POINTER,
        ExifTag::JPEG_INTERCHANGE_FORMAT,
    ];

    /**
     * Fixed-length tags that must contain exactly four bytes.
     *
     * EXIF 3.0 §4.6.6.1.1 (ExifVersion), §4.6.6.1.2 (FlashpixVersion),
     * §4.6.6.1.3 (ComponentsConfiguration), and §4.6.8 (GPSVersionID) mandate
     * four-byte payloads. The requirements are unchanged in EXIF 2.32 for these tags.
     *
     * @var array<int, array{name: string, count: int, type: int, typeName: string, spec: string}>
     */
    private const array FIXED_LENGTH_TAGS = [
        // --- TIFF 6.0 Baseline Tags ---
        TiffTag::NEW_SUBFILE_TYPE => [
            'name'     => 'NewSubfileType',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::SUBFILE_TYPE => [
            'name'     => 'SubfileType',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::COMPRESSION => [
            'name'     => 'Compression',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::PHOTOMETRIC_INTERPRETATION => [
            'name'     => 'PhotometricInterpretation',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::THRESHHOLDING => [
            'name'     => 'Threshholding',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::CELL_WIDTH => [
            'name'     => 'CellWidth',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::CELL_LENGTH => [
            'name'     => 'CellLength',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::FILL_ORDER => [
            'name'     => 'FillOrder',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::ORIENTATION => [
            'name'     => 'Orientation',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::SAMPLES_PER_PIXEL => [
            'name'     => 'SamplesPerPixel',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::X_RESOLUTION => [
            'name'     => 'XResolution',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::Y_RESOLUTION => [
            'name'     => 'YResolution',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::PLANAR_CONFIGURATION => [
            'name'     => 'PlanarConfiguration',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::X_POSITION => [
            'name'     => 'XPosition',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::Y_POSITION => [
            'name'     => 'YPosition',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::GRAY_RESPONSE_UNIT => [
            'name'     => 'GrayResponseUnit',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::T4_OPTIONS => [
            'name'     => 'T4Options',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::T6_OPTIONS => [
            'name'     => 'T6Options',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::RESOLUTION_UNIT => [
            'name'     => 'ResolutionUnit',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::PAGE_NUMBER => [
            'name'     => 'PageNumber',
            'count'    => 2,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::DATETIME => [
            'name'     => 'DateTime',
            'count'    => 20,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.5.4.5',
        ],
        TiffTag::PREDICTOR => [
            'name'     => 'Predictor',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::WHITE_POINT => [
            'name'     => 'WhitePoint',
            'count'    => 2,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.5.3.2',
        ],
        ExifTag::PRIMARY_CHROMATICITIES => [
            'name'     => 'PrimaryChromaticities',
            'count'    => 6,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.5.3.3',
        ],
        TiffTag::HALFTONE_HINTS => [
            'name'     => 'HalftoneHints',
            'count'    => 2,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::INK_SET => [
            'name'     => 'InkSet',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::NUMBER_OF_INKS => [
            'name'     => 'NumberOfInks',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::TRANSFER_RANGE => [
            'name'     => 'TransferRange',
            'count'    => 6,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::JPEG_PROC => [
            'name'     => 'JPEGProc',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::JPEG_INTERCHANGE_FORMAT => [
            'name'     => 'JPEGInterchangeFormat',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => [
            'name'     => 'JPEGInterchangeFormatLength',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'TIFF 6.0',
        ],
        TiffTag::JPEG_RESTART_INTERVAL => [
            'name'     => 'JPEGRestartInterval',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::YCBCR_COEFFICIENTS => [
            'name'     => 'YCbCrCoefficients',
            'count'    => 3,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.5.3.4',
        ],
        ExifTag::YCBCR_SUB_SAMPLING => [
            'name'     => 'YCbCrSubSampling',
            'count'    => 2,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::YCBCR_POSITIONING => [
            'name'     => 'YCbCrPositioning',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'TIFF 6.0',
        ],
        ExifTag::REFERENCE_BLACK_WHITE => [
            'name'     => 'ReferenceBlackWhite',
            'count'    => 6,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.5.3.5',
        ],

        // --- EXIF 3.0 Exif IFD Tags ---
        ExifTag::EXPOSURE_TIME => [
            'name'     => 'ExposureTime',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.4.1',
        ],
        ExifTag::F_NUMBER => [
            'name'     => 'FNumber',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.4.2',
        ],
        ExifTag::EXPOSURE_PROGRAM => [
            'name'     => 'ExposureProgram',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.4.3',
        ],
        ExifTag::SENSITIVITY_TYPE => [
            'name'     => 'SensitivityType',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.4.5',
        ],
        ExifTag::STANDARD_OUTPUT_SENSITIVITY => [
            'name'     => 'StandardOutputSensitivity',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'EXIF 3.0 §4.6.6.4.6',
        ],
        ExifTag::RECOMMENDED_EXPOSURE_INDEX => [
            'name'     => 'RecommendedExposureIndex',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'EXIF 3.0 §4.6.6.4.7',
        ],
        ExifTag::ISO_SPEED => [
            'name'     => 'ISOSpeed',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'EXIF 3.0 §4.6.6.4.8',
        ],
        ExifTag::ISO_SPEED_LATITUDE_YYY => [
            'name'     => 'ISOSpeedLatitudeyyy',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'EXIF 3.0 §4.6.6.4.9',
        ],
        ExifTag::ISO_SPEED_LATITUDE_ZZZ => [
            'name'     => 'ISOSpeedLatitudezzz',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'EXIF 3.0 §4.6.6.4.10',
        ],
        ExifTag::EXIF_VERSION => [
            'name'     => 'ExifVersion',
            'count'    => 4,
            'type'     => TiffConst::TYPE_UNDEFINED,
            'typeName' => 'UNDEFINED',
            'spec'     => 'EXIF 3.0 §4.6.6.1.1',
        ],
        ExifTag::DATETIME_ORIGINAL => [
            'name'     => 'DateTimeOriginal',
            'count'    => 20,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.6.6.1',
        ],
        ExifTag::DATETIME_DIGITIZED => [
            'name'     => 'DateTimeDigitized',
            'count'    => 20,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.6.6.2',
        ],
        ExifTag::OFFSET_TIME => [
            'name'     => 'OffsetTime',
            'count'    => 7,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.6.6.3',
        ],
        ExifTag::OFFSET_TIME_ORIGINAL => [
            'name'     => 'OffsetTimeOriginal',
            'count'    => 7,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.6.6.4',
        ],
        ExifTag::OFFSET_TIME_DIGITIZED => [
            'name'     => 'OffsetTimeDigitized',
            'count'    => 7,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.6.6.5',
        ],
        ExifTag::COMPONENTS_CONFIGURATION => [
            'name'     => 'ComponentsConfiguration',
            'count'    => 4,
            'type'     => TiffConst::TYPE_UNDEFINED,
            'typeName' => 'UNDEFINED',
            'spec'     => 'EXIF 3.0 §4.6.6.1.3',
        ],
        ExifTag::COMPRESSED_BITS_PER_PIXEL => [
            'name'     => 'CompressedBitsPerPixel',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.1.4',
        ],
        ExifTag::SHUTTER_SPEED_VALUE => [
            'name'     => 'ShutterSpeedValue',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SRATIONAL,
            'typeName' => 'SRATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.5.1',
        ],
        ExifTag::APERTURE_VALUE => [
            'name'     => 'ApertureValue',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.5.2',
        ],
        ExifTag::BRIGHTNESS_VALUE => [
            'name'     => 'BrightnessValue',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SRATIONAL,
            'typeName' => 'SRATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.5.3',
        ],
        ExifTag::EXPOSURE_BIAS_VALUE => [
            'name'     => 'ExposureBiasValue',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SRATIONAL,
            'typeName' => 'SRATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.5.4',
        ],
        ExifTag::MAX_APERTURE_VALUE => [
            'name'     => 'MaxApertureValue',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.5.5',
        ],
        ExifTag::SUBJECT_DISTANCE => [
            'name'     => 'SubjectDistance',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.5.6',
        ],
        ExifTag::METERING_MODE => [
            'name'     => 'MeteringMode',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.1',
        ],
        ExifTag::LIGHT_SOURCE => [
            'name'     => 'LightSource',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.2',
        ],
        ExifTag::FLASH => [
            'name'     => 'Flash',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.3',
        ],
        ExifTag::FOCAL_LENGTH => [
            'name'     => 'FocalLength',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.7.4',
        ],
        ExifTag::SUBJECT_LOCATION => [
            'name'     => 'SubjectLocation',
            'count'    => 2,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.29',
        ],
        ExifTag::TEMPERATURE => [
            'name'     => 'Temperature',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SRATIONAL,
            'typeName' => 'SRATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.8.1',
        ],
        ExifTag::HUMIDITY => [
            'name'     => 'Humidity',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.8.2',
        ],
        ExifTag::PRESSURE => [
            'name'     => 'Pressure',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.8.3',
        ],
        ExifTag::WATER_DEPTH => [
            'name'     => 'WaterDepth',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SRATIONAL,
            'typeName' => 'SRATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.8.4',
        ],
        ExifTag::ACCELERATION => [
            'name'     => 'Acceleration',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.8.5',
        ],
        ExifTag::CAMERA_ELEVATION_ANGLE => [
            'name'     => 'CameraElevationAngle',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SRATIONAL,
            'typeName' => 'SRATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.8.6',
        ],
        ExifTag::FLASHPIX_VERSION => [
            'name'     => 'FlashpixVersion',
            'count'    => 4,
            'type'     => TiffConst::TYPE_UNDEFINED,
            'typeName' => 'UNDEFINED',
            'spec'     => 'EXIF 3.0 §4.6.6.1.2',
        ],
        ExifTag::COLOR_SPACE => [
            'name'     => 'ColorSpace',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.2.1',
        ],
        ExifTag::RELATED_SOUND_FILE => [
            'name'     => 'RelatedSoundFile',
            'count'    => 13,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.6.3.1',
        ],
        ExifTag::FLASH_ENERGY => [
            'name'     => 'FlashEnergy',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.7.5',
        ],
        ExifTag::FOCAL_PLANE_X_RESOLUTION => [
            'name'     => 'FocalPlaneXResolution',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.7.7',
        ],
        ExifTag::FOCAL_PLANE_Y_RESOLUTION => [
            'name'     => 'FocalPlaneYResolution',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.7.8',
        ],
        ExifTag::FOCAL_PLANE_RESOLUTION_UNIT => [
            'name'     => 'FocalPlaneResolutionUnit',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.9',
        ],
        ExifTag::EXPOSURE_INDEX => [
            'name'     => 'ExposureIndex',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.7.28',
        ],
        ExifTag::SENSING_METHOD => [
            'name'     => 'SensingMethod',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.30',
        ],
        ExifTag::FILE_SOURCE => [
            'name'     => 'FileSource',
            'count'    => 1,
            'type'     => TiffConst::TYPE_UNDEFINED,
            'typeName' => 'UNDEFINED',
            'spec'     => 'EXIF 3.0 §4.6.6.7.32',
        ],
        ExifTag::SCENE_TYPE => [
            'name'     => 'SceneType',
            'count'    => 1,
            'type'     => TiffConst::TYPE_UNDEFINED,
            'typeName' => 'UNDEFINED',
            'spec'     => 'EXIF 3.0 §4.6.6.7.33',
        ],
        ExifTag::CUSTOM_RENDERED => [
            'name'     => 'CustomRendered',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.35',
        ],
        ExifTag::EXPOSURE_MODE => [
            'name'     => 'ExposureMode',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.36',
        ],
        ExifTag::WHITE_BALANCE => [
            'name'     => 'WhiteBalance',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.37',
        ],
        ExifTag::DIGITAL_ZOOM_RATIO => [
            'name'     => 'DigitalZoomRatio',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.7.38',
        ],
        ExifTag::FOCAL_LENGTH_IN_35MM_FILM => [
            'name'     => 'FocalLengthIn35mmFilm',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.39',
        ],
        ExifTag::SCENE_CAPTURE_TYPE => [
            'name'     => 'SceneCaptureType',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.40',
        ],
        ExifTag::GAIN_CONTROL => [
            'name'     => 'GainControl',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.41',
        ],
        ExifTag::CONTRAST => [
            'name'     => 'Contrast',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.42',
        ],
        ExifTag::SATURATION => [
            'name'     => 'Saturation',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.43',
        ],
        ExifTag::SHARPNESS => [
            'name'     => 'Sharpness',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.44',
        ],
        ExifTag::SUBJECT_DISTANCE_RANGE => [
            'name'     => 'SubjectDistanceRange',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.7.46',
        ],
        ExifTag::IMAGE_UNIQUE_ID => [
            'name'     => 'ImageUniqueID',
            'count'    => 33,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.6.9.1',
        ],
        ExifTag::LENS_SPECIFICATION => [
            'name'     => 'LensSpecification',
            'count'    => 4,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.9.4',
        ],
        ExifTag::COMPOSITE_IMAGE => [
            'name'     => 'CompositeImage',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.10.1',
        ],
        ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE => [
            'name'     => 'SourceImageNumberOfCompositeImage',
            'count'    => 2,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.6.10.2',
        ],
        ExifTag::GAMMA => [
            'name'     => 'Gamma',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.6.2.2',
        ],

        // --- EXIF 3.0 GPS IFD Tags ---
        ExifTag::GPS_VERSION_ID => [
            'name'     => 'GPSVersionID',
            'count'    => 4,
            'type'     => TiffConst::TYPE_BYTE,
            'typeName' => 'BYTE',
            'spec'     => 'EXIF 3.0 §4.6.7.1.1',
        ],
        // GPS_LATITUDE_REF (0x0001) omitted: tag ID collides with INTEROPERABILITY_INDEX
        ExifTag::GPS_LATITUDE => [
            'name'     => 'GPSLatitude',
            'count'    => 3,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.3',
        ],
        ExifTag::GPS_LONGITUDE_REF => [
            'name'     => 'GPSLongitudeRef',
            'count'    => 2,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.7.1.4',
        ],
        ExifTag::GPS_LONGITUDE => [
            'name'     => 'GPSLongitude',
            'count'    => 3,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.5',
        ],
        ExifTag::GPS_ALTITUDE_REF => [
            'name'     => 'GPSAltitudeRef',
            'count'    => 1,
            'type'     => TiffConst::TYPE_BYTE,
            'typeName' => 'BYTE',
            'spec'     => 'EXIF 3.0 §4.6.7.1.6',
        ],
        ExifTag::GPS_ALTITUDE => [
            'name'     => 'GPSAltitude',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.7',
        ],
        ExifTag::GPS_TIME_STAMP => [
            'name'     => 'GPSTimeStamp',
            'count'    => 3,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.8',
        ],
        ExifTag::GPS_STATUS => [
            'name'     => 'GPSStatus',
            'count'    => 2,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.7.1.10',
        ],
        ExifTag::GPS_MEASURE_MODE => [
            'name'     => 'GPSMeasureMode',
            'count'    => 2,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.7.1.11',
        ],
        ExifTag::GPS_DOP => [
            'name'     => 'GPSDOP',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.12',
        ],
        ExifTag::GPS_SPEED_REF => [
            'name'     => 'GPSSpeedRef',
            'count'    => 2,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.7.1.13',
        ],
        ExifTag::GPS_SPEED => [
            'name'     => 'GPSSpeed',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.14',
        ],
        ExifTag::GPS_TRACK_REF => [
            'name'     => 'GPSTrackRef',
            'count'    => 2,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.7.1.15',
        ],
        ExifTag::GPS_TRACK => [
            'name'     => 'GPSTrack',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.16',
        ],
        ExifTag::GPS_IMG_DIRECTION_REF => [
            'name'     => 'GPSImgDirectionRef',
            'count'    => 2,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.7.1.17',
        ],
        ExifTag::GPS_IMG_DIRECTION => [
            'name'     => 'GPSImgDirection',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.18',
        ],
        ExifTag::GPS_DEST_LATITUDE_REF => [
            'name'     => 'GPSDestLatitudeRef',
            'count'    => 2,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.7.1.20',
        ],
        ExifTag::GPS_DEST_LATITUDE => [
            'name'     => 'GPSDestLatitude',
            'count'    => 3,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.21',
        ],
        ExifTag::GPS_DEST_LONGITUDE_REF => [
            'name'     => 'GPSDestLongitudeRef',
            'count'    => 2,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.7.1.22',
        ],
        ExifTag::GPS_DEST_LONGITUDE => [
            'name'     => 'GPSDestLongitude',
            'count'    => 3,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.23',
        ],
        ExifTag::GPS_DEST_BEARING_REF => [
            'name'     => 'GPSDestBearingRef',
            'count'    => 2,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.7.1.24',
        ],
        ExifTag::GPS_DEST_BEARING => [
            'name'     => 'GPSDestBearing',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.25',
        ],
        ExifTag::GPS_DEST_DISTANCE_REF => [
            'name'     => 'GPSDestDistanceRef',
            'count'    => 2,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.7.1.26',
        ],
        ExifTag::GPS_DEST_DISTANCE => [
            'name'     => 'GPSDestDistance',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.27',
        ],
        ExifTag::GPS_DATE_STAMP => [
            'name'     => 'GPSDateStamp',
            'count'    => 11,
            'type'     => TiffConst::TYPE_ASCII,
            'typeName' => 'ASCII',
            'spec'     => 'EXIF 3.0 §4.6.7.1.30',
        ],
        ExifTag::GPS_DIFFERENTIAL => [
            'name'     => 'GPSDifferential',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'EXIF 3.0 §4.6.7.1.31',
        ],
        ExifTag::GPS_H_POSITIONING_ERROR => [
            'name'     => 'GPSHPositioningError',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'EXIF 3.0 §4.6.7.1.32',
        ],

        // --- DNG Tags ---
        DngTag::DNG_VERSION => [
            'name'     => 'DNGVersion',
            'count'    => 4,
            'type'     => TiffConst::TYPE_BYTE,
            'typeName' => 'BYTE',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::DNG_BACKWARD_VERSION => [
            'name'     => 'DNGBackwardVersion',
            'count'    => 4,
            'type'     => TiffConst::TYPE_BYTE,
            'typeName' => 'BYTE',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::CFA_LAYOUT => [
            'name'     => 'CFALayout',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::BASELINE_EXPOSURE => [
            'name'     => 'BaselineExposure',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SRATIONAL,
            'typeName' => 'SRATIONAL',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::BAYER_GREEN_SPLIT => [
            'name'     => 'BayerGreenSplit',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::MAKER_NOTE_SAFETY => [
            'name'     => 'MakerNoteSafety',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::CALIBRATION_ILLUMINANT_1 => [
            'name'     => 'CalibrationIlluminant1',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::CALIBRATION_ILLUMINANT_2 => [
            'name'     => 'CalibrationIlluminant2',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::RAW_DATA_UNIQUE_ID => [
            'name'     => 'RawDataUniqueID',
            'count'    => 16,
            'type'     => TiffConst::TYPE_BYTE,
            'typeName' => 'BYTE',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::DEFAULT_USER_CROP => [
            'name'     => 'DefaultUserCrop',
            'count'    => 4,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'DNG 1.7.1.0',
        ],
    ];

    /**
     * EXIF camera-control tags with closed numeric value domains.
     *
     * EXIF 3.0 §4.6.6.7 defines these tags with explicit allowed values and
     * reserves remaining codes for future use.
     *
     * @var array<int, array{name:string, allowed:list<int>, spec:string}>
     */
    private const array CAMERA_CONTROL_ENUM_DOMAINS = [
        ExifTag::EXPOSURE_PROGRAM => [
            'name'    => 'ExposureProgram',
            'allowed' => [0, 1, 2, 3, 4, 5, 6, 7, 8],
            'spec'    => 'EXIF 3.0 §4.6.6.7.3',
        ],
        ExifTag::METERING_MODE => [
            'name'    => 'MeteringMode',
            'allowed' => [0, 1, 2, 3, 4, 5, 6, 255],
            'spec'    => 'EXIF 3.0 §4.6.6.7.19',
        ],
        ExifTag::LIGHT_SOURCE => [
            'name'    => 'LightSource',
            'allowed' => [0, 1, 2, 3, 4, 9, 10, 11, 12, 13, 14, 15, 17, 18, 19, 20, 21, 22, 23, 24, 255],
            'spec'    => 'EXIF 3.0 §4.6.6.7.20',
        ],
        ExifTag::SENSING_METHOD => [
            'name'    => 'SensingMethod',
            'allowed' => [1, 2, 3, 4, 5, 7, 8],
            'spec'    => 'EXIF 3.0 §4.6.6.7.31',
        ],
        ExifTag::EXPOSURE_MODE => [
            'name'    => 'ExposureMode',
            'allowed' => [0, 1, 2],
            'spec'    => 'EXIF 3.0 §4.6.6.7.36',
        ],
        ExifTag::WHITE_BALANCE => [
            'name'    => 'WhiteBalance',
            'allowed' => [0, 1],
            'spec'    => 'EXIF 3.0 §4.6.6.7.37',
        ],
        ExifTag::SCENE_CAPTURE_TYPE => [
            'name'    => 'SceneCaptureType',
            'allowed' => [0, 1, 2, 3],
            'spec'    => 'EXIF 3.0 §4.6.6.7.40',
        ],
        ExifTag::GAIN_CONTROL => [
            'name'    => 'GainControl',
            'allowed' => [0, 1, 2, 3, 4],
            'spec'    => 'EXIF 3.0 §4.6.6.7.41',
        ],
        ExifTag::CONTRAST => [
            'name'    => 'Contrast',
            'allowed' => [0, 1, 2],
            'spec'    => 'EXIF 3.0 §4.6.6.7.42',
        ],
        ExifTag::SATURATION => [
            'name'    => 'Saturation',
            'allowed' => [0, 1, 2],
            'spec'    => 'EXIF 3.0 §4.6.6.7.43',
        ],
        ExifTag::SHARPNESS => [
            'name'    => 'Sharpness',
            'allowed' => [0, 1, 2],
            'spec'    => 'EXIF 3.0 §4.6.6.7.44',
        ],
        ExifTag::SUBJECT_DISTANCE_RANGE => [
            'name'    => 'SubjectDistanceRange',
            'allowed' => [0, 1, 2, 3],
            'spec'    => 'EXIF 3.0 §4.6.6.7.46',
        ],
    ];

    private MemoryBuffer $buffer;

    private Endian $bo;

    private bool $bigTiff = false;

    private int $bigTiffOffsetSize = 8;

    private UInt64 $blobSize;

    private ?string $makerNoteRaw = null;

    /**
     * @var array<int, Ifd>
     */
    private array $ifdCache = [];

    /**
     * Tracks pointer offsets that have already been inspected while resolving interoperability IFDs.
     *
     * @var array<int, bool>
     */
    private array $interopVisitedOffsets = [];

    /**
     * Parses an EXIF TIFF blob into a structured document model.
     *
     * EXIF 3.0 §4.5 describes the TIFF header, byte-order markers, and IFD chaining strategy
     * applied while decoding embedded EXIF payloads.
     *
     * @param string        $tiffBlob Raw TIFF data including headers.
     * @param Registry|null $registry Optional registry used to decode manufacturer-specific maker notes.
     *
     * @return ParsedExif
     */
    public function parseFromBlob(string $tiffBlob, ?Registry $registry = null, bool $jpegContext = false): ParsedExif
    {
        $this->buffer = new MemoryBuffer($tiffBlob);
        $this->buffer->seek(0);

        $this->blobSize = UInt64::fromInt($this->buffer->size());

        $this->makerNoteRaw          = null;
        $this->ifdCache              = [];
        $this->bigTiffOffsetSize     = 8;
        $this->interopVisitedOffsets = [];

        // byte order
        // EXIF 3.0 §4.5.1 follows TIFF 6.0 §2.1 (Image File Header) in defining the
        // "II"/"MM" byte-order signatures used for byte-order detection.
        $boSig    = $this->buffer->read(2);
        $this->bo = match ($boSig) {
            'II'    => Endian::Little,
            'MM'    => Endian::Big,
            default => throw new ParseError('Bad TIFF byte order', 1301),
        };

        $magic = $this->readU16();
        // EXIF 3.0 §4.5.1 recognises 0x002A (classic TIFF) and 0x002B (BigTIFF)
        // magic identifiers.
        if ($magic === TiffConst::MAGIC_BIG) {
            $this->bigTiff = true;
            $this->parseBigTiffHeader();
            $firstIfd = $this->readBigTiffOffsetValue();

            // EXIF 3.0 §4.5.1: the 0th IFD offset must point past the header.
            if (($firstIfd instanceof UInt64) && $firstIfd->isZero()) {
                throw new ParseError('missing 0th IFD offset', 1302);
            }

            $ifd0 = $this->readIfd($firstIfd);
        } elseif ($magic === TiffConst::MAGIC_CLASSIC) {
            $this->bigTiff = false;
            // Classic TIFF header layout per EXIF 3.0 §4.5.1 and TIFF 6.0 §8
            // stores the first IFD offset as a 32-bit pointer immediately
            // after the byte-order and magic fields.
            $firstIfd = $this->readU32();

            // EXIF 3.0 §4.5.1: the 0th IFD offset must be non-zero and point
            // past the classic TIFF header.
            if ($firstIfd < TiffConst::HEADER_SIZE_CLASSIC) {
                throw new ParseError('missing 0th IFD offset', 1303);
            }

            $ifd0 = $this->readIfd($firstIfd);
        } else {
            throw new ParseError(
                sprintf(
                    'Unknown TIFF magic (expected 0x%04X or 0x%04X)',
                    TiffConst::MAGIC_CLASSIC,
                    TiffConst::MAGIC_BIG,
                ),
                1304,
            );
        }

        // follow pointers
        $exifIfd = null;
        $gpsIfd  = null;
        $ifd1    = null;

        $exifPointer = $ifd0->get(ExifTag::EXIF_IFD_POINTER);
        if ($exifPointer instanceof IfdEntry) {
            $offset = $this->pointerOffset($exifPointer);
            if ($offset !== null) {
                $exifIfd = $this->readIfd($offset);
            }
        }

        $interopIfd = $this->locateInteropIfd($exifIfd, $ifd0);

        $gpsPointer = $ifd0->get(ExifTag::GPS_IFD_POINTER);
        if ($gpsPointer instanceof IfdEntry) {
            $gpsOffset = $this->pointerOffset($gpsPointer);
            if ($gpsOffset !== null) {
                $gpsIfd = $this->readIfd($gpsOffset);
            }
        }

        $additionalIfds = [];
        $visitedOffsets = [];

        $nextOffset = $ifd0->nextIfdOffset;
        while ($nextOffset !== null && $nextOffset > 0) {
            if (isset($visitedOffsets[$nextOffset])) {
                throw new ParseError('Cyclic IFD chain detected at offset ' . $nextOffset . '.', 1359);
            }

            $visitedOffsets[$nextOffset] = true;

            $nextIfd          = $this->readIfd($nextOffset);
            $additionalIfds[] = $nextIfd;

            if (!$ifd1 instanceof Ifd) {
                $ifd1 = $nextIfd;
            }

            $nextOffset = $nextIfd->nextIfdOffset;
        }

        $this->validateEnhancedIfd($ifd0);
        foreach ($additionalIfds as $additionalIfd) {
            $this->validateEnhancedIfd($additionalIfd);
        }

        $this->validateDngMatrixTags($ifd0);
        $this->validateDngIlluminantDependencies($ifd0);
        $this->validateDngTripleIlluminant($ifd0);
        $this->validateDngWhiteBalanceExclusivity($ifd0);
        $this->validateResolutionEquality($ifd0);
        $this->validateCompressionDomain($ifd0, $ifd1);
        $this->validatePrimaryThumbnailStructureCompatibility($ifd0, $ifd1, $jpegContext);
        $this->validateCameraControlEnumDomains($ifd0, $exifIfd, $ifd1, ...$additionalIfds);
        $this->validateFlashBitfield($exifIfd);
        $this->validateJpegThumbnailStream($ifd1);

        if (!$jpegContext) {
            $this->validateImageDimensions($ifd0);
            $this->validateStripLayoutConsistency($ifd0);
        }

        if ($jpegContext) {
            $this->validateJpegContextProhibitions($ifd0);
        }

        $this->validateExifIfdPlacement($ifd0);

        if ($exifIfd instanceof Ifd) {
            $this->validateCompanionArtist($ifd0, $exifIfd);
            $this->validateCompanionSoftware($ifd0, $exifIfd);
            $this->validateSensitivityCombinations($exifIfd);
        }

        if (!($interopIfd instanceof Ifd) && ($additionalIfds !== [])) {
            $interopIfd = $this->locateInteropIfd(...$additionalIfds);
        }

        $makerNotes = $this->resolveMakerNotes($registry, $ifd0, $exifIfd);

        $parsedExif = new ParsedExif(
            $ifd0,
            $exifIfd,
            $gpsIfd,
            $interopIfd,
            $ifd1,
            $makerNotes,
            $additionalIfds,
        );

        $this->validateCompositeImageDependencies($exifIfd, $parsedExif);
        $this->validateSourceExposureTimesPayload($exifIfd, $parsedExif);

        return $parsedExif;
    }

    /**
     * Validates the BigTIFF header following the magic identifier.
     *
     * EXIF 3.0 §4.5.1 adopts the BigTIFF header layout and retains the reserved
     * word semantics aligned with TIFF 6.0 §8, constraining the offset-size and
     * reserved fields before the first IFD pointer.
     */
    private function parseBigTiffHeader(): void
    {
        // BigTIFF header after magic: 2 bytes offset size (8 or 16), 2 bytes reserved, then the first IFD offset
        $offSize  = $this->readU16();
        $reserved = $this->readU16();

        // EXIF 3.0 §4.5.1 restricts BigTIFF offset sizes to 8 or 16 bytes.
        if ($offSize !== 8 && $offSize !== 16) {
            throw new ParseError('Unsupported BigTIFF offset size (expected 8 or 16)', 1305);
        }

        // The reserved field must remain zero (EXIF 3.0 §4.5.1; TIFF 6.0 §8 legacy rule).
        if ($reserved !== 0) {
            throw new ParseError('Bad BigTIFF header (reserved != 0)', 1306);
        }

        $this->bigTiffOffsetSize = $offSize;
    }

    /**
     * Parses an image file directory starting at the given byte offset.
     *
     * EXIF 3.0 §4.5.2 details the layout of classic and BigTIFF IFD structures,
     * including entry counts, entry sizes, and next-pointer chaining.
     *
     * @param int|UInt64|string $offset Zero-based byte offset to the IFD structure.
     *
     * @return Ifd
     */
    private function readIfd(int|UInt64|string $offset): Ifd
    {
        if ($offset instanceof UInt64) {
            // A zero pointer denotes an absent directory (EXIF 3.0 §4.5.2 Note 1),
            // so return an empty IFD structure.
            if ($offset->isZero()) {
                return new Ifd([]);
            }

            $offsetInt = $this->ensureOffset($offset, 'IFD offset');
        } elseif (is_int($offset)) {
            // EXIF 3.0 §4.5.2 clarifies that null or non-positive offsets mean the
            // referenced directory is omitted.
            if ($offset <= 0) {
                return new Ifd([]);
            }

            $offsetInt = $this->ensureOffset($offset, 'IFD offset');
        } else {
            // BigTIFF offsets may arrive as decimal strings (§4.5.2, BigTIFF note),
            // with zero indicating that the referenced directory is absent.
            if ($this->decimalStringIsZero($offset)) {
                return new Ifd([]);
            }

            $offsetInt = $this->ensureOffset($offset, 'IFD offset');
        }

        if (isset($this->ifdCache[$offsetInt])) {
            return $this->ifdCache[$offsetInt];
        }

        $this->buffer->seek($offsetInt);
        $entryCount = $this->bigTiff ? $this->readU64()->toInt('IFD entry count') : $this->readU16();

        if ($entryCount === 0) {
            throw new ParseError('IFD must contain at least one entry per TIFF 6.0.', 1307);
        }

        // GH-898: enforce maximum IFD entry count to prevent DoS
        if ($entryCount > self::MAX_IFD_ENTRIES) {
            throw new ParseError(
                sprintf('IFD entry count %d exceeds maximum allowed %d', $entryCount, self::MAX_IFD_ENTRIES),
                1360,
            );
        }

        // EXIF 3.0 §4.5.2 and TIFF 6.0 §8 prescribe 12-byte (classic) and 20-byte
        // (BigTIFF) directory entries and the unsigned entry count preceding them.
        $entries   = [];
        $lastTagId = null;
        for ($i = 0; $i < $entryCount; ++$i) {
            $entry = $this->readDirEntry();

            // TIFF 6.0 §2 requires IFD entries to be sorted by tag identifier in
            // ascending order so readers can apply deterministic directory traversal.
            if (($lastTagId !== null) && ($entry->tag < $lastTagId)) {
                throw new ParseError('IFD entries must be sorted in ascending order by tag per TIFF 6.0 §2.', 1308);
            }

            // Reject duplicate tag IDs within a single IFD
            if (isset($entries[$entry->tag])) {
                throw new ParseError('Duplicate tag ID ' . $entry->tag . ' in IFD per TIFF 6.0 §2.', 1357);
            }

            $lastTagId            = $entry->tag;
            $entries[$entry->tag] = $entry;
        }

        if ($this->bigTiff) {
            $next = $this->normaliseBigTiffOptionalOffset(
                $this->readBigTiffOffsetValue(),
                'IFD next offset',
            );
        } else {
            // TIFF 6.0 §8 retains a 32-bit pointer to the next IFD; EXIF 3.0 §4.5.2
            // notes the value is zero when the chain terminates.
            $next = $this->readU32();
        }

        $ifd = new Ifd($entries, $next > 0 ? $next : null);

        $this->ifdCache[$offsetInt] = $ifd;

        return $ifd;
    }

    /**
     * Reads a single directory entry and returns it keyed by tag identifier.
     *
     * EXIF 3.0 §4.5.2 defines the tag, type, count, and value/offset fields mirrored
     * by this reader, aligning with the TIFF 6.0 §8 directory entry layout.
     *
     * @return IfdEntry
     */
    private function readDirEntry(): IfdEntry
    {
        $tag  = $this->readU16();
        $type = $this->readU16();

        if (
            !$this->bigTiff
            && in_array($type, [TiffConst::TYPE_LONG8, TiffConst::TYPE_SLONG8, TiffConst::TYPE_IFD8], true)
        ) {
            throw new ParseError('BigTIFF-only field type ' . $type . ' in classic TIFF', 1309);
        }

        $cnt = $this->bigTiff ? $this->readU64()->toInt('directory entry value count') : $this->readU32();

        $this->validateFixedLengthTagLayout($tag, $type, $cnt);

        // Read the Value/Offset field.  For inline values (data fits within the
        // field) the raw bytes are returned directly to avoid endianness-dependent
        // reinterpretation (TIFF 6.0 §2, EXIF 3.0 §4.5.2).
        [$valOrOff, $inlineBytes] = $this->readValueOrOffset($type, $cnt);

        [$rawBytes] = $this->valueBytes($type, $cnt, $valOrOff, $inlineBytes);
        $value      = $this->decodeBytes($tag, $type, $cnt, $rawBytes);
        $value      = $this->convertUInt64Values($tag, $value);

        // DNG 1.7.1.0: LocalizedCameraModel may be stored as BYTE instead of
        // ASCII. When type is BYTE, treat the raw bytes as a NUL-terminated
        // UTF-8 string rather than a numeric list.
        if ($tag === DngTag::LOCALIZED_CAMERA_MODEL && $type === TiffConst::TYPE_BYTE) {
            $value = rtrim($rawBytes, "\0");
        }

        if ($tag === ExifTag::CFA_PATTERN && is_string($value)) {
            $decodedPattern = $this->decodeCfaPatternPayload($rawBytes);

            if ($decodedPattern instanceof ExifNumericList) {
                $value = $decodedPattern;
            }
        }

        if ($tag === ExifTag::MAKER_NOTE) {
            $this->makerNoteRaw = $rawBytes;
        }

        if ($tag === DngTag::DNG_PRIVATE_DATA) {
            $this->validateDngPrivateData($rawBytes);
        }

        if ($tag === DngTag::MAKER_NOTE_SAFETY && is_int($value) && $value !== 0 && $value !== 1) {
            throw new ParseError(sprintf(
                'MakerNoteSafety value %d is outside the valid domain {0, 1} per DNG 1.7.1.0.',
                $value,
            ), 1310);
        }

        if ($tag === ExifTag::ORIENTATION && is_int($value) && ($value < 1 || $value > 8)) {
            throw new ParseError(sprintf(
                'Orientation value %d is outside the valid domain 1..8 per EXIF 3.0 §4.6.5.1.6.',
                $value,
            ), 1311);
        }

        if ($tag === ExifTag::YCBCR_POSITIONING && is_int($value) && $value !== 1 && $value !== 2) {
            throw new ParseError(sprintf(
                'YCbCrPositioning value %d is outside the valid domain {1, 2} per EXIF 3.0 §4.6.5.1.13.',
                $value,
            ), 1312);
        }

        if ($tag === ExifTag::COLOR_SPACE && is_int($value) && $value !== 1 && $value !== 0xFFFF) {
            throw new ParseError(sprintf(
                'ColorSpace value %d is outside the valid domain {1, 65535} per EXIF 3.0 §4.6.6.2.1.',
                $value,
            ), 1313);
        }

        if ($tag === ExifTag::RESOLUTION_UNIT && is_int($value) && $value !== 2 && $value !== 3) {
            throw new ParseError(sprintf(
                'ResolutionUnit value %d is outside the valid domain {2, 3} per EXIF 3.0 §4.6.5.1.11.',
                $value,
            ), 1314);
        }

        if ($tag === ExifTag::FOCAL_PLANE_RESOLUTION_UNIT && is_int($value) && $value !== 2 && $value !== 3) {
            throw new ParseError(sprintf(
                'FocalPlaneResolutionUnit value %d is outside the valid domain {2, 3} per EXIF 3.0 §4.6.6.7.28.',
                $value,
            ), 1315);
        }

        if ($tag === ExifTag::PLANAR_CONFIGURATION && is_int($value) && $value !== 1 && $value !== 2) {
            throw new ParseError(sprintf(
                'PlanarConfiguration value %d is outside the valid domain {1, 2} per EXIF 3.0 §4.6.5.1.10.',
                $value,
            ), 1316);
        }

        if ($tag === TiffTag::PREDICTOR && is_int($value) && $value !== 1 && $value !== 2) {
            throw new ParseError(sprintf(
                'Predictor value %d is outside the valid domain {1, 2} per TIFF 6.0 §14.',
                $value,
            ), 1358);
        }

        // GH-915: enforce 8.3 filename semantics for RelatedSoundFile per EXIF 3.0 §4.6.6.5.1
        if ($tag === ExifTag::RELATED_SOUND_FILE && is_string($value)) {
            if (preg_match('/[\\\\\\/]/', $value) === 1) {
                throw new ParseError(
                    'RelatedSoundFile must not contain path separators per EXIF 3.0 §4.6.6.5.1.',
                    1450,
                );
            }

            if (preg_match('/\A[^\\\\\\/]{1,8}\.[^\\\\\\/]{3}\z/', $value) !== 1) {
                throw new ParseError(
                    'RelatedSoundFile must use 8.3 filename form per EXIF 3.0 §4.6.6.5.1.',
                    1451,
                );
            }
        }

        if (in_array($tag, self::COUNTED_IMAGE_DATA_TAGS, true)) {
            $value = $this->normaliseCountedImageDataField($tag, $type, $cnt, $rawBytes, $value);
        }

        return new IfdEntry($tag, $type, $cnt, $value);
    }

    /**
     * Validates tag layouts with fixed byte counts mandated by EXIF.
     */
    private function validateFixedLengthTagLayout(int $tag, int $type, int $count): void
    {
        if (!isset(self::FIXED_LENGTH_TAGS[$tag])) {
            return;
        }

        $rule = self::FIXED_LENGTH_TAGS[$tag];

        if ($type !== $rule['type']) {
            // EXIF 3.0 §4.6.6.1.1/§4.6.6.1.2 specify UNDEFINED for version tags,
            // but many cameras use ASCII. Accept both directions for compatibility.
            if (
                ($type === TiffConst::TYPE_UNDEFINED && $rule['type'] === TiffConst::TYPE_ASCII)
                || ($type === TiffConst::TYPE_ASCII && $rule['type'] === TiffConst::TYPE_UNDEFINED)
            ) {
                return;
            }

            throw new ParseError(sprintf(
                '%s must use TIFF type %s per %s.',
                $rule['name'],
                $rule['typeName'],
                $rule['spec'],
            ), 1317);
        }

        if ($count !== $rule['count']) {
            throw new ParseError(sprintf(
                '%s must contain exactly %d bytes per %s.',
                $rule['name'],
                $rule['count'],
                $rule['spec'],
            ), 1318);
        }
    }

    /**
     * Validates the DNGPrivateData block structure.
     *
     * DNG 1.7.1.0 (p. 41): the block must start with a NUL-terminated ASCII
     * string identifying the manufacturer. All bytes before the terminator
     * must be printable ASCII (0x20–0x7E). The block must be at least two
     * bytes (one character plus NUL terminator).
     *
     * @param string $rawBytes Raw tag payload.
     */
    private function validateDngPrivateData(string $rawBytes): void
    {
        $length = strlen($rawBytes);

        if ($length < 2) {
            throw new ParseError('DNGPrivateData block must be at least 2 bytes per DNG 1.7.1.0.', 1319);
        }

        $nulPos = strpos($rawBytes, "\0");

        if ($nulPos === false) {
            throw new ParseError('DNGPrivateData block must start with a NUL-terminated ASCII string per DNG 1.7.1.0.', 1320);
        }

        if ($nulPos === 0) {
            throw new ParseError('DNGPrivateData manufacturer name must not be empty per DNG 1.7.1.0.', 1321);
        }

        $prefix    = substr($rawBytes, 0, $nulPos);
        $asciiSpan = strspn($prefix, ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~');

        if ($asciiSpan !== $nulPos) {
            throw new ParseError(sprintf(
                'DNGPrivateData manufacturer name contains non-ASCII byte 0x%02X at offset %d per DNG 1.7.1.0.',
                ord($prefix[$asciiSpan]),
                $asciiSpan,
            ), 1322);
        }
    }

    /**
     * Validates that an Enhanced Image IFD (NewSubfileType bit 4) carries a
     * non-empty EnhanceParams tag as required by DNG 1.5+.
     */
    private function validateEnhancedIfd(Ifd $ifd): void
    {
        $entry = $ifd->get(TiffTag::NEW_SUBFILE_TYPE);
        if (!$entry instanceof IfdEntry || !is_int($entry->value)) {
            return;
        }

        if (($entry->value & 16) === 0) {
            return;
        }

        $enhance = $ifd->get(DngTag::ENHANCE_PARAMS);
        if (!$enhance instanceof IfdEntry || !is_string($enhance->value)) {
            throw new ParseError('Enhanced IFD (NewSubfileType bit 4) requires an EnhanceParams tag per DNG 1.5.', 1323);
        }

        if (rtrim($enhance->value, "\0") === '') {
            throw new ParseError('EnhanceParams must not be empty for an Enhanced IFD per DNG 1.5.', 1324);
        }
    }

    /**
     * Validates that XResolution and YResolution carry the same value when both present.
     *
     * EXIF 3.0 §4.6.5.1.8/§4.6.5.1.9 require identical values in both tags.
     */
    private function validateResolutionEquality(Ifd $ifd): void
    {
        $xRes = $ifd->get(ExifTag::X_RESOLUTION);
        $yRes = $ifd->get(ExifTag::Y_RESOLUTION);

        if (!$xRes instanceof IfdEntry || !$yRes instanceof IfdEntry) {
            return;
        }

        if (!$xRes->value instanceof ExifRational || !$yRes->value instanceof ExifRational) {
            return;
        }

        if ($xRes->value->numerator !== $yRes->value->numerator || $xRes->value->denominator !== $yRes->value->denominator) {
            throw new ParseError(sprintf(
                'XResolution (%d/%d) must equal YResolution (%d/%d) per EXIF 3.0 §4.6.5.1.8.',
                $xRes->value->numerator,
                $xRes->value->denominator,
                $yRes->value->numerator,
                $yRes->value->denominator,
            ), 1325);
        }
    }

    /**
     * Validates Compression tag values per EXIF-specific domain rules.
     *
     * EXIF 3.0 §4.6.5.1.4: IFD0 allows only 1 (uncompressed); IFD1 allows 1 or 6.
     */
    private function validateCompressionDomain(Ifd $ifd0, ?Ifd $ifd1): void
    {
        $entry = $ifd0->get(ExifTag::COMPRESSION);
        if ($entry instanceof IfdEntry && is_int($entry->value) && $entry->value !== 1) {
            throw new ParseError(sprintf(
                'Compression value %d in IFD0 is invalid; only 1 (uncompressed) is allowed per EXIF 3.0 §4.6.5.1.4.',
                $entry->value,
            ), 1351);
        }

        if (!$ifd1 instanceof Ifd) {
            return;
        }

        $thumbEntry = $ifd1->get(ExifTag::COMPRESSION);
        if ($thumbEntry instanceof IfdEntry && is_int($thumbEntry->value) && $thumbEntry->value !== 1 && $thumbEntry->value !== 6) {
            throw new ParseError(sprintf(
                'Compression value %d in IFD1 is invalid; only 1 or 6 allowed per EXIF 3.0 §4.6.5.1.4.',
                $thumbEntry->value,
            ), 1352);
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
    private function validatePrimaryThumbnailStructureCompatibility(Ifd $ifd0, ?Ifd $ifd1, bool $jpegContext): void
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
     */
    private function validateCameraControlEnumDomains(?Ifd ...$ifds): void
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
     * Validates strict structural decoding for SourceExposureTimesOfCompositeImage.
     *
     * EXIF 3.0 §4.6.6.7.49 Figure 25 defines a complete binary layout. Partial,
     * truncated, or trailing payload bytes are non-conformant and rejected.
     */
    private function validateSourceExposureTimesPayload(?Ifd $exifIfd, ParsedExif $parsedExif): void
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
     * Validates CompositeImage value domain and required companion tags.
     *
     * EXIF 3.0 §4.6.6.7.47 defines CompositeImage values 0..3 and reserves
     * all other codes. When value 3 is present, §4.6.6.7.48 and §4.6.6.7.49
     * require both companion tags with valid payload structures.
     */
    private function validateCompositeImageDependencies(?Ifd $exifIfd, ParsedExif $parsedExif): void
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
     * Validates EXIF Flash tag bitfield semantics and reserved combinations.
     *
     * EXIF 3.0 §4.6.6.7.21 defines bit 0 (fired), bits 1-2 (return status),
     * bits 3-4 (mode), bit 5 (function present flag), and bit 6 (red-eye).
     * Bit 7 and above are reserved and must remain zero in strict conformance.
     */
    private function validateFlashBitfield(?Ifd $exifIfd): void
    {
        if (!$exifIfd instanceof Ifd) {
            return;
        }

        $entry = $exifIfd->get(ExifTag::FLASH);
        if (!($entry instanceof IfdEntry) || !is_int($entry->value)) {
            return;
        }

        $flashBits = $entry->value;
        if (($flashBits < 0) || ($flashBits > 0x7F)) {
            throw new ParseError(
                sprintf('Flash value %d uses reserved high-order bits per EXIF 3.0 §4.6.6.7.21', $flashBits),
                1417,
            );
        }

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
     */
    private function validateJpegThumbnailStream(?Ifd $ifd1): void
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

        $this->validateJpegThumbnailDisallowedMarkers($thumbnailBytes, $thumbnailOffset);
    }

    /**
     * Validates that disallowed markers are absent from JPEG thumbnail stream bytes.
     *
     * EXIF 3.0 §4.6.5.1.6 and §4.7 disallow APPn/COM marker usage for this embedded
     * thumbnail stream profile; restart markers are rejected in strict conformance mode.
     *
     * @param string $thumbnailBytes  Raw JPEG thumbnail stream bytes.
     * @param int    $thumbnailOffset Absolute TIFF offset of the thumbnail stream.
     */
    private function validateJpegThumbnailDisallowedMarkers(string $thumbnailBytes, int $thumbnailOffset): void
    {
        $lastIndex = strlen($thumbnailBytes) - 1;

        for ($index = 0; $index < $lastIndex; ++$index) {
            if ($thumbnailBytes[$index] !== "\xFF") {
                continue;
            }

            $marker = ord($thumbnailBytes[$index + 1]);
            if ($marker === 0x00) {
                continue;
            }

            if ($marker === 0xFF) {
                continue;
            }

            if ($marker >= 0xE0 && $marker <= 0xEF) {
                throw new ParseError(
                    sprintf(
                        'JPEG thumbnail stream at offset %d contains disallowed APP marker 0x%02X at offset %d',
                        $thumbnailOffset,
                        $marker,
                        $thumbnailOffset + $index,
                    ),
                    1414,
                );
            }

            if ($marker === 0xFE) {
                throw new ParseError(
                    sprintf(
                        'JPEG thumbnail stream at offset %d contains disallowed COM marker at offset %d',
                        $thumbnailOffset,
                        $thumbnailOffset + $index,
                    ),
                    1414,
                );
            }

            if ($marker >= 0xD0 && $marker <= 0xD7) {
                throw new ParseError(
                    sprintf(
                        'JPEG thumbnail stream at offset %d contains disallowed restart marker 0x%02X at offset %d',
                        $thumbnailOffset,
                        $marker,
                        $thumbnailOffset + $index,
                    ),
                    1415,
                );
            }
        }
    }

    /**
     * Validates that JPEG-prohibited tags are not present in IFD0.
     *
     * EXIF 3.0 §4.6.5.1 specifies several tags that shall not be used when the
     * primary image data is JPEG-compressed (carried via JPEG markers instead),
     * including JPEGInterchangeFormat/JPEGInterchangeFormatLength for thumbnail-only
     * JPEG payload addressing (EXIF 3.0 §4.6.5.2.4-§4.6.5.2.5).
     *
     * @var list<array{int, string}> JPEG_PROHIBITED_TAGS
     */
    private const array JPEG_PROHIBITED_TAGS = [
        [ExifTag::IMAGE_WIDTH, 'ImageWidth'],
        [ExifTag::IMAGE_LENGTH, 'ImageLength'],
        [ExifTag::BITS_PER_SAMPLE, 'BitsPerSample'],
        [ExifTag::SAMPLES_PER_PIXEL, 'SamplesPerPixel'],
        [ExifTag::PHOTOMETRIC_INTERPRETATION, 'PhotometricInterpretation'],
        [ExifTag::STRIP_OFFSETS, 'StripOffsets'],
        [ExifTag::ROWS_PER_STRIP, 'RowsPerStrip'],
        [ExifTag::STRIP_BYTE_COUNTS, 'StripByteCounts'],
        [ExifTag::PLANAR_CONFIGURATION, 'PlanarConfiguration'],
        [ExifTag::COMPRESSION, 'Compression'],
        [ExifTag::JPEG_INTERCHANGE_FORMAT, 'JPEGInterchangeFormat'],
        [ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 'JPEGInterchangeFormatLength'],
    ];

    private function validateJpegContextProhibitions(Ifd $ifd0): void
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
     * Validates that ImageWidth and ImageLength tags exist with valid positive values.
     *
     * EXIF 3.0 §4.6.4 requires both tags in IFD0 for non-JPEG primary images.
     */
    private function validateImageDimensions(Ifd $ifd0): void
    {
        $widthEntry  = $ifd0->get(ExifTag::IMAGE_WIDTH);
        $lengthEntry = $ifd0->get(ExifTag::IMAGE_LENGTH);

        if (!$widthEntry instanceof IfdEntry) {
            throw new ParseError(
                'ImageWidth tag is required in IFD0 for non-JPEG primary image per EXIF 3.0 §4.6.4.',
                1355,
            );
        }

        if (is_int($widthEntry->value) && $widthEntry->value <= 0) {
            throw new ParseError(sprintf(
                'ImageWidth value %d is invalid; must be a positive integer per EXIF 3.0 §4.6.4.',
                $widthEntry->value,
            ), 1355);
        }

        if (!$lengthEntry instanceof IfdEntry) {
            throw new ParseError(
                'ImageLength tag is required in IFD0 for non-JPEG primary image per EXIF 3.0 §4.6.4.',
                1356,
            );
        }

        if (is_int($lengthEntry->value) && $lengthEntry->value <= 0) {
            throw new ParseError(sprintf(
                'ImageLength value %d is invalid; must be a positive integer per EXIF 3.0 §4.6.4.',
                $lengthEntry->value,
            ), 1356);
        }
    }

    /**
     * Validates strip layout consistency for non-JPEG primary image data.
     *
     * EXIF 3.0 §4.6.5.2.2 and §4.6.5.2.3 require RowsPerStrip and tie strip tag
     * counts to StripsPerImage, with planar-separate layout multiplying by
     * SamplesPerPixel (EXIF 3.0 §4.6.5.1.10).
     */
    private function validateStripLayoutConsistency(Ifd $ifd0): void
    {
        $stripOffsetsEntry    = $ifd0->get(ExifTag::STRIP_OFFSETS);
        $stripByteCountsEntry = $ifd0->get(ExifTag::STRIP_BYTE_COUNTS);

        $hasStripFields = ($stripOffsetsEntry instanceof IfdEntry)
            || ($stripByteCountsEntry instanceof IfdEntry);

        if (!$hasStripFields) {
            return;
        }

        $rowsPerStripEntry = $ifd0->get(ExifTag::ROWS_PER_STRIP);
        if (!$rowsPerStripEntry instanceof IfdEntry || !is_int($rowsPerStripEntry->value) || $rowsPerStripEntry->value <= 0) {
            throw new ParseError(
                'RowsPerStrip must be a positive integer when strip tags are present per EXIF 3.0 §4.6.5.2.2.',
                1452,
            );
        }

        $imageLengthEntry = $ifd0->get(ExifTag::IMAGE_LENGTH);
        if (!$imageLengthEntry instanceof IfdEntry || !is_int($imageLengthEntry->value) || $imageLengthEntry->value <= 0) {
            return;
        }

        $stripsPerImage = intdiv($imageLengthEntry->value + $rowsPerStripEntry->value - 1, $rowsPerStripEntry->value);

        $planarConfiguration = 1;
        $planarEntry         = $ifd0->get(ExifTag::PLANAR_CONFIGURATION);
        if ($planarEntry instanceof IfdEntry && is_int($planarEntry->value)) {
            $planarConfiguration = $planarEntry->value;
        }

        $samplesPerPixel = 1;
        $samplesEntry    = $ifd0->get(ExifTag::SAMPLES_PER_PIXEL);
        if ($samplesEntry instanceof IfdEntry && is_int($samplesEntry->value) && $samplesEntry->value > 0) {
            $samplesPerPixel = $samplesEntry->value;
        }

        $expectedCount = $stripsPerImage;
        if ($planarConfiguration === 2) {
            $expectedCount *= $samplesPerPixel;
        }

        if ($stripOffsetsEntry instanceof IfdEntry) {
            $offsetCount = $this->countStripFieldValues($stripOffsetsEntry);
            if ($offsetCount !== $expectedCount) {
                throw new ParseError(sprintf(
                    'StripOffsets count %d does not match expected strip count %d per EXIF 3.0 §4.6.5.2.1/§4.6.5.2.2.',
                    $offsetCount,
                    $expectedCount,
                ), 1453);
            }
        }

        if ($stripByteCountsEntry instanceof IfdEntry) {
            $byteCountCount = $this->countStripFieldValues($stripByteCountsEntry);
            if ($byteCountCount !== $expectedCount) {
                throw new ParseError(sprintf(
                    'StripByteCounts count %d does not match expected strip count %d per EXIF 3.0 §4.6.5.2.3/§4.6.5.2.2.',
                    $byteCountCount,
                    $expectedCount,
                ), 1454);
            }
        }
    }

    /**
     * Returns the number of values encoded in a strip offset/count field.
     */
    private function countStripFieldValues(IfdEntry $entry): int
    {
        if (is_int($entry->value)) {
            return 1;
        }

        if ($entry->value instanceof ExifNumericList) {
            return count($entry->value->values);
        }

        return 0;
    }

    /**
     * EXIF 3.0 §4.6.5.4.6: Artist shall be recorded when CameraOwnerName,
     * Photographer or ImageEditor is present.
     */
    private function validateCompanionArtist(Ifd $ifd0, Ifd $exifIfd): void
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
     */
    private function validateCompanionSoftware(Ifd $ifd0, Ifd $exifIfd): void
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
     */
    private function validateSensitivityCombinations(Ifd $exifIfd): void
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
     * Validates that EXIF 3.0 §4.6.6.9 tags are not placed in IFD0.
     */
    private function validateExifIfdPlacement(Ifd $ifd0): void
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
     * Normalises numeric list fields that describe strip or tile data.
     *
     * EXIF 3.0 §4.6.2 and §4.6.4 enumerate the strip/tile offset and byte-count
     * tags whose component counts are normalised here.
     *
     * @param int                                                                   $tag
     * @param int                                                                   $type     TIFF field type code.
     * @param int                                                                   $count    Number of values represented.
     * @param string                                                                $rawBytes Raw value bytes read for the entry.
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value
     *
     * @return int|ExifNumericList
     */
    private function normaliseCountedImageDataField(
        int $tag,
        int $type,
        int $count,
        string $rawBytes,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value,
    ): int|ExifNumericList {
        if ($count <= 0) {
            return new ExifNumericList([]);
        }

        if ($count === 1) {
            if ($value instanceof ExifNumericList) {
                $first = $value->values[0] ?? null;

                if (is_int($first)) {
                    return $first;
                }

                if (is_float($first)) {
                    return (int) $first;
                }

                if ($first instanceof UInt64) {
                    return $first->toInt('counted image data field');
                }
            }

            if (is_int($value)) {
                return $value;
            }

            if (is_float($value)) {
                return (int) $value;
            }

            if ($value instanceof UInt64) {
                return $value->toInt('counted image data field');
            }

            $components = $this->decodeCountedComponents($tag, $type, $rawBytes, $count);

            return $components[0] ?? 0;
        }

        if ($value instanceof ExifNumericList) {
            $normalised = [];

            foreach ($value->values as $component) {
                if (is_int($component)) {
                    $normalised[] = $component;
                } elseif (is_float($component)) {
                    $normalised[] = (int) $component;
                } else {
                    // UInt64 (BigTIFF) - convert to int
                    $normalised[] = $component->toInt('counted image data field');
                }
            }

            return new ExifNumericList($normalised);
        }

        $components = $this->decodeCountedComponents($tag, $type, $rawBytes, $count);

        return new ExifNumericList($components);
    }

    /**
     * Decodes numeric components for counted strip/tile entries into integers.
     *
     * EXIF 3.0 §4.6.2 documents the strip/tile tags whose value counts and component
     * types are interpreted here.
     *
     * @param int    $tag      TIFF tag identifier used to determine bounds checks.
     * @param int    $type     TIFF field type code.
     * @param string $rawBytes Raw bytes representing the values.
     * @param int    $count    Number of values represented.
     *
     * @return list<int>
     */
    private function decodeCountedComponents(int $tag, int $type, string $rawBytes, int $count): array
    {
        $componentSize   = $this->bytesPerComponent($type);
        $expectedLength  = $componentSize * $count;
        $availableLength = strlen($rawBytes);

        if ($availableLength < $expectedLength) {
            throw new ParseError('Truncated numeric components for TIFF entry.', 1326);
        }

        $components = [];

        for ($i = 0; $i < $count; ++$i) {
            $chunk = substr($rawBytes, $i * $componentSize, $componentSize);

            $value = match ($type) {
                TiffConst::TYPE_SHORT  => $this->unpackU16($chunk),
                TiffConst::TYPE_SSHORT => $this->unpackS16($chunk),
                TiffConst::TYPE_LONG,
                TiffConst::TYPE_IFD   => $this->unpackU32($chunk),
                TiffConst::TYPE_SLONG => $this->unpackS32($chunk),
                TiffConst::TYPE_LONG8,
                TiffConst::TYPE_IFD8   => $this->unpackU64($chunk),
                TiffConst::TYPE_SLONG8 => $this->unpackS64($chunk),
                default                => throw new ParseError('Unsupported numeric type for strip/tile field: ' . $type, 1327),
            };

            if ($value instanceof UInt64) {
                $value = ($tag === ExifTag::STRIP_OFFSETS || $tag === TiffTag::TILE_OFFSETS)
                    ? $this->ensureOffset($value, sprintf('IFD tag 0x%04X', $tag))
                    : $value->toInt(sprintf('IFD tag 0x%04X', $tag));
            }

            $components[] = $value;
        }

        return $components;
    }

    /**
     * Converts decoded UInt64 values into integers when possible, preserving oversize pointer offsets.
     */
    private function convertUInt64Values(
        int $tag,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 $value,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64 {
        if ($value instanceof UInt64) {
            return $this->normaliseScalarUInt64($tag, $value);
        }

        if ($value instanceof ExifNumericList) {
            $converted       = [];
            $needsConversion = false;
            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $converted[]     = $this->normaliseScalarUInt64($tag, $component);
                    $needsConversion = true;

                    continue;
                }

                $converted[] = $component;
            }

            if ($needsConversion) {
                return new ExifNumericList($converted);
            }
        }

        return $value;
    }

    /**
     * Normalises a UInt64 scalar into an integer when possible, preserving oversized pointer values.
     *
     * @param int    $tag
     * @param UInt64 $value
     *
     * @return int|UInt64
     */
    private function normaliseScalarUInt64(int $tag, UInt64 $value): int|UInt64
    {
        if ($this->isPointerTag($tag)) {
            if ($value->fitsSignedInt()) {
                return $value->toInt(sprintf('IFD pointer tag 0x%04X', $tag));
            }

            return $value;
        }

        return $value->toInt(sprintf('IFD tag 0x%04X value', $tag));
    }

    /**
     * Indicates whether the tag points to another IFD location.
     *
     * @param int $tag Tag identifier.
     *
     * @return bool True when the tag represents an IFD pointer.
     */
    private function isPointerTag(int $tag): bool
    {
        return in_array($tag, self::POINTER_TAGS, true);
    }

    /**
     * Attempts to resolve an interoperability IFD from the provided directories.
     *
     * EXIF 3.0 §4.6.3 specifies that the Interoperability IFD is located via the
     * pointer tag 0xA005 stored within the Exif IFD.
     */
    private function locateInteropIfd(?Ifd ...$ifds): ?Ifd
    {
        $deferred = [];

        foreach ($ifds as $ifd) {
            if (!$ifd instanceof Ifd) {
                continue;
            }

            if ($this->ifdLooksLikeInterop($ifd)) {
                return $ifd;
            }

            $entry = $ifd->get(ExifTag::INTEROPERABILITY_IFD_POINTER);
            if (!$entry instanceof IfdEntry) {
                continue;
            }

            $offset = $this->pointerOffset($entry);
            if ($offset === null) {
                continue;
            }

            if (isset($this->interopVisitedOffsets[$offset])) {
                continue;
            }

            $this->interopVisitedOffsets[$offset] = true;

            $candidate = $this->readIfd($offset);

            if ($this->ifdLooksLikeInterop($candidate)) {
                return $candidate;
            }

            $deferred[] = $candidate;
        }

        if ($deferred !== []) {
            $resolved = $this->locateInteropIfd(...$deferred);

            if ($resolved instanceof Ifd) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Determines whether the provided directory contains interoperability tags.
     *
     * EXIF 3.0 §4.6.4 enumerates the interoperability tag set checked by this
     * helper to recognise interoperability directories.
     */
    private function ifdLooksLikeInterop(Ifd $ifd): bool
    {
        $interopTags = [
            ExifTag::INTEROPERABILITY_INDEX,
        ];

        return array_any($interopTags, fn (int $tag): bool => $ifd->get($tag) instanceof IfdEntry);
    }

    /**
     * EXIF 3.0 text tags that allow UTF-8 in addition to ASCII.
     *
     * EXIF 3.0 §4.6.5.4.4 (Software), §4.6.5.4.6 (Artist), §4.6.6.9.2
     * (CameraOwnerName), §4.6.6.9.9–§4.6.6.9.14 (Photographer through
     * MetadataEditingSoftware).
     *
     * @var list<int>
     */
    private const array EXIF_30_UTF8_TAGS = [
        ExifTag::SOFTWARE,
        ExifTag::ARTIST,
        ExifTag::CAMERA_OWNER_NAME,
        ExifTag::PHOTOGRAPHER,
        ExifTag::IMAGE_EDITOR,
        ExifTag::CAMERA_FIRMWARE,
        ExifTag::RAW_DEVELOPING_SOFTWARE,
        ExifTag::IMAGE_EDITING_SOFTWARE,
        ExifTag::METADATA_EDITING_SOFTWARE,
    ];

    /**
     * Converts raw bytes into PHP scalar values based on the TIFF type.
     *
     * TIFF 6.0 §2.2 defines the field type encodings (BYTE through DOUBLE) mapped
     * to PHP scalars in this helper. EXIF 3.0 §4.5.2 Table 3 mirrors these definitions
     * with additional context for EXIF usage.
     *
     * @param int    $tag   Tag identifier for encoding-specific rules.
     * @param int    $type  TIFF field type code.
     * @param int    $count Number of values represented.
     * @param string $bytes Raw value bytes read from the blob.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64
     */
    private function decodeBytes(int $tag, int $type, int $count, string $bytes): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64
    {
        $componentSize = $this->bytesPerComponent($type);
        $bytesLength   = strlen($bytes);
        $expectedBytes = $componentSize * $count;

        if ($bytesLength < $expectedBytes) {
            throw new ParseError(
                sprintf(
                    'Truncated value for TIFF type %d (expected %d bytes, got %d)',
                    $type,
                    $expectedBytes,
                    $bytesLength,
                ),
                1328,
            );
        }

        // ASCII
        if ($type === TiffConst::TYPE_ASCII) {
            // EXIF 3.0 §4.6.2 and TIFF 6.0 §2 require ASCII values to be
            // NUL-terminated, with the declared count including that terminator.
            if (($count > 0) && ($bytes[$count - 1] !== "\0")) {
                throw new ParseError(
                    'ASCII values must be NUL-terminated and include the terminator in count per EXIF 3.0 §4.6.2; TIFF 6.0 §2.',
                    1329,
                );
            }

            // EXIF 3.0 text tags allow UTF-8; strict ASCII applies to all others.
            $firstHighByteOffset = -1;
            for ($i = 0; $i < $count; ++$i) {
                if (ord($bytes[$i]) > 0x7F) {
                    $firstHighByteOffset = $i;

                    break;
                }
            }

            if ($firstHighByteOffset >= 0) {
                if (in_array($tag, self::EXIF_30_UTF8_TAGS, true)) {
                    // GH-927: EXIF 3.0 UTF-8-capable tag — validate well-formedness.
                    $text = rtrim($bytes, "\0");
                    if (!mb_check_encoding($text, 'UTF-8')) {
                        throw new ParseError(
                            'EXIF 3.0 text tag contains malformed UTF-8 per EXIF 3.0 §4.6.5.4.',
                            1459,
                        );
                    }

                    return $text;
                }

                // TIFF 6.0 §2.2: ASCII bytes must be in the 7-bit domain (0x00–0x7F).
                throw new ParseError(sprintf(
                    'ASCII value contains non-7-bit byte 0x%02X at offset %d per TIFF 6.0 §2.2.',
                    ord($bytes[$firstHighByteOffset]),
                    $firstHighByteOffset,
                ), 1330);
            }

            return rtrim($bytes, "\0");
        }

        if ($type === TiffConst::TYPE_UNDEFINED) {
            // TIFF 6.0 §2.2 defines UNDEFINED as uninterpreted bytes. Any
            // textual interpretation must happen in tag-specific converters.
            return $bytes;
        }

        // RATIONAL / SRATIONAL
        if ($type === TiffConst::TYPE_RATIONAL || $type === TiffConst::TYPE_SRATIONAL) {
            $rationalValues = [];
            for ($i = 0; $i < $count; ++$i) {
                $num              = $this->read32FromBytes($bytes, $i * 8, $type === TiffConst::TYPE_SRATIONAL);
                $den              = $this->read32FromBytes($bytes, $i * 8 + 4, $type === TiffConst::TYPE_SRATIONAL);
                $rationalValues[] = new ExifRational($num, $den);
            }

            return $count === 1
                ? $rationalValues[0]
                : new ExifRationalList($rationalValues);
        }

        $vals   = [];
        $cursor = 0;
        for ($i = 0; $i < $count; ++$i) {
            $vals[] = match ($type) {
                // BYTE
                TiffConst::TYPE_BYTE => ord($bytes[$cursor]),
                // SBYTE
                TiffConst::TYPE_SBYTE => $this->toSigned(ord($bytes[$cursor]), 8),
                // SHORT
                TiffConst::TYPE_SHORT => $this->unpackU16(substr($bytes, $cursor, 2)),
                // SSHORT
                TiffConst::TYPE_SSHORT => $this->unpackS16(substr($bytes, $cursor, 2)),
                // LONG
                TiffConst::TYPE_LONG,
                TiffConst::TYPE_IFD => $this->unpackU32(substr($bytes, $cursor, 4)),
                // SLONG
                TiffConst::TYPE_SLONG => $this->unpackS32(substr($bytes, $cursor, 4)),
                // LONG8 / IFD8
                TiffConst::TYPE_LONG8,
                TiffConst::TYPE_IFD8 => $this->unpackU64(substr($bytes, $cursor, 8)),
                // SLONG8
                TiffConst::TYPE_SLONG8 => $this->unpackS64(substr($bytes, $cursor, 8)),
                // FLOAT
                TiffConst::TYPE_FLOAT => $this->unpackFloat(substr($bytes, $cursor, 4)),
                // DOUBLE
                TiffConst::TYPE_DOUBLE => $this->unpackDouble(substr($bytes, $cursor, 8)),

                default => throw new ParseError('Unsupported type in decodeBytes: ' . $type, 1331),
            };
            $cursor += $componentSize;
        }

        return $count === 1 ? $vals[0] : new ExifNumericList($vals);
    }

    /**
     * Decodes the CFA pattern (UNDEFINED) payload into numeric components.
     *
     * EXIF 3.0 §4.6.6.7.34 defines the CFA pattern as two SHORT repeat units followed by m×n
     * bytes describing the colour filter layout.
     */
    private function decodeCfaPatternPayload(string $bytes): ?ExifNumericList
    {
        if (strlen($bytes) < 4) {
            return null;
        }

        $horizontalRepeatPixelUnit = $this->unpackU16(substr($bytes, 0, 2));
        $verticalRepeatPixelUnit   = $this->unpackU16(substr($bytes, 2, 2));

        if ($horizontalRepeatPixelUnit <= 0 || $verticalRepeatPixelUnit <= 0) {
            return null;
        }

        $expectedPatternValues = $horizontalRepeatPixelUnit * $verticalRepeatPixelUnit;
        $availableBytes        = strlen($bytes) - 4;

        if ($availableBytes < $expectedPatternValues) {
            return null;
        }

        $components = [$horizontalRepeatPixelUnit, $verticalRepeatPixelUnit];
        for ($index = 0; $index < $expectedPatternValues; ++$index) {
            $components[] = ord($bytes[4 + $index]);
        }

        return new ExifNumericList($components);
    }

    /**
     * Reads the 4- or 8-byte value/offset field for a directory entry.
     *
     * TIFF 6.0 §8 specifies that values fitting within 4 bytes are stored inline in the
     * directory entry for classic TIFF. BigTIFF extends this to 8 bytes or the configured
     * offset size.
     *
     * @param int $type  TIFF field type code.
     * @param int $count Number of values represented.
     *
     * @return array{0:int|UInt64|string,1:string|null}
     */
    private function readValueOrOffset(int $type, int $count): array
    {
        $componentSize   = $this->bytesPerComponent($type);
        $inlineThreshold = $this->bigTiff ? $this->bigTiffOffsetSize : 4;
        $valueBytes      = $componentSize * $count;

        // TIFF 6.0 §2: if the value fits in the Value/Offset field it is
        // stored left-justified in the lower-numbered bytes.  We read the
        // raw field bytes to avoid endianness-dependent reinterpretation.
        if ($valueBytes <= $inlineThreshold) {
            $rawField    = $this->buffer->read($inlineThreshold);
            $inlineBytes = $valueBytes === $inlineThreshold
                ? $rawField
                : substr($rawField, 0, $valueBytes);

            return [$inlineBytes, $inlineBytes];
        }

        if ($this->bigTiff) {
            return [$this->readBigTiffOffsetValue(), null];
        }

        return [$this->readU32(), null];
    }

    /**
     * Ensures that an offset lies within the TIFF blob and returns it as an integer.
     */
    private function ensureOffset(int|UInt64|string $offset, string $context, int $length = 0): int
    {
        if (is_string($offset)) {
            return $this->ensureDecimalOffset($offset, $context, $length);
        }

        $offset64 = $offset instanceof UInt64 ? $offset : UInt64::fromInt($offset);

        $this->assertOffsetRange($offset64, $length, $context);

        $result = $offset64->toInt($context);

        // TIFF 6.0 §2: offsets must begin on a word boundary (even byte offset).
        if ($result % 2 !== 0) {
            throw new ParseError(sprintf('%s is not word-aligned (offset %d) per TIFF 6.0.', $context, $result), 1332);
        }

        return $result;
    }

    /**
     * Normalises an optional offset that may be zero.
     */
    private function normaliseOptionalOffset(UInt64 $offset, string $context): int
    {
        if ($offset->isZero()) {
            return 0;
        }

        return $this->ensureOffset($offset, $context);
    }

    /**
     * Normalises a BigTIFF optional offset according to the configured field width.
     */
    private function normaliseBigTiffOptionalOffset(int|UInt64|string $offset, string $context): int
    {
        if ($offset instanceof UInt64) {
            return $this->normaliseOptionalOffset($offset, $context);
        }

        if (is_int($offset)) {
            if ($offset <= 0) {
                return 0;
            }

            return $this->ensureOffset($offset, $context);
        }

        if ($this->decimalStringIsZero($offset)) {
            return 0;
        }

        return $this->ensureOffset($offset, $context);
    }

    /**
     * Verifies that an offset and optional length are contained within the TIFF blob.
     */
    private function assertOffsetRange(UInt64 $offset, int $length, string $context): void
    {
        if ($offset->compare($this->blobSize) > 0) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1333);
        }

        $size = $this->buffer->size();

        if ($length > $size) {
            throw new BoundsError(sprintf('%s length %d exceeds TIFF data length.', $context, $length), 1334);
        }

        $offsetInt = $offset->toInt($context);

        if (($length > 0) && ($offsetInt > ($size - $length))) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1335);
        }
    }

    /**
     * Extracts the raw bytes addressed by a directory entry.
     *
     * TIFF 6.0 §8 defines that values ≤4 bytes are stored inline in the value/offset
     * field of directory entries for classic TIFF. For larger values, the field contains
     * a file offset to the actual data. BigTIFF extends the inline threshold to 8 bytes.
     *
     * @param int               $type          TIFF field type code.
     * @param int               $count         Number of values represented.
     * @param int|UInt64|string $valueOrOffset Inline value bytes or an offset into the blob.
     * @param string|null       $inlineBytes   Raw bytes captured from the value/offset field.
     *
     * @return array{0: string, 1: int|null}
     */
    private function valueBytes(int $type, int $count, int|UInt64|string $valueOrOffset, ?string $inlineBytes = null): array
    {
        $unitSize        = $this->bytesPerComponent($type);
        $dataSize        = $unitSize * $count;
        $inlineThreshold = $this->bigTiff ? 8 : 4;

        if ($inlineBytes !== null) {
            if (strlen($inlineBytes) < $dataSize) {
                throw new ParseError(
                    sprintf(
                        'Inline value for TIFF type %d truncated (expected %d bytes, got %d)',
                        $type,
                        $dataSize,
                        strlen($inlineBytes),
                    ),
                    1336,
                );
            }

            return [substr($inlineBytes, 0, $dataSize), null];
        }

        if ($dataSize <= $inlineThreshold) {
            if (is_string($valueOrOffset)) {
                if (strlen($valueOrOffset) < $dataSize) {
                    throw new ParseError(
                        sprintf(
                            'Inline value for TIFF type %d truncated (expected %d bytes, got %d)',
                            $type,
                            $dataSize,
                            strlen($valueOrOffset),
                        ),
                        1337,
                    );
                }

                return [substr($valueOrOffset, 0, $dataSize), null];
            }

            $raw = $this->uXToBytes($valueOrOffset, $inlineThreshold);

            return [substr($raw, 0, $dataSize), null];
        }

        $offset  = $this->ensureOffset($valueOrOffset, sprintf('Value offset for TIFF type %d', $type), $dataSize);
        $current = $this->buffer->tell();
        $this->buffer->seek($offset);
        $bytes = $this->buffer->read($dataSize);
        $this->buffer->seek($current);

        return [$bytes, $offset];
    }

    /**
     * Resolves maker note metadata using the provided registry when available.
     *
     * EXIF 3.0 §4.6.6.4.1 (Table 4) defines the MakerNote tag semantics and the MakerNoteSafety
     * flag used to indicate whether in-place modification is safe.
     */
    private function resolveMakerNotes(?Registry $registry, Ifd $ifd0, ?Ifd $exifIfd): ?MakerNotesRecord
    {
        if ($this->makerNoteRaw === null) {
            return null;
        }

        if (!($registry instanceof Registry) || !($exifIfd instanceof Ifd)) {
            return $this->applyMakerNoteSafety($this->makerNotesDigest(), $ifd0);
        }

        $make = $this->stringFromIfd($ifd0, ExifTag::MAKE);

        if ($make === null || $make === '') {
            return $this->applyMakerNoteSafety($this->makerNotesDigest(), $ifd0);
        }

        $decoder = $registry->find($make);

        if (!$decoder instanceof MakerNotesDecoderInterface) {
            return $this->applyMakerNoteSafety($this->makerNotesDigest(), $ifd0);
        }

        $model    = $this->stringFromIfd($ifd0, ExifTag::MODEL);
        $metadata = $decoder->decode($this->makerNoteRaw, $make, $model);

        return $this->applyMakerNoteSafety($metadata, $ifd0);
    }

    /**
     * Creates a digest metadata instance for unknown maker notes.
     */
    private function makerNotesDigest(): MakerNotesRecord
    {
        $raw = $this->makerNoteRaw ?? '';

        return new MakerNotesRecord(
            'Unknown',
            strlen($raw),
            sha1($raw)
        );
    }

    /**
     * Applies the maker note safety flag to the provided metadata instance.
     */
    private function applyMakerNoteSafety(MakerNotesRecord $metadata, Ifd $ifd0): MakerNotesRecord
    {
        $entry = $ifd0->get(DngTag::MAKER_NOTE_SAFETY);
        $safe  = $entry instanceof IfdEntry ? ($entry->value === 1) : null;

        return new MakerNotesRecord(
            $metadata->vendor,
            $metadata->length,
            $metadata->sha1,
            $metadata->apple,
            $metadata->samsung,
            $safe,
        );
    }

    /**
     * Returns the trimmed string value for a specific tag within an IFD.
     */
    private function stringFromIfd(?Ifd $ifd, int $tag): ?string
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        $entry = $ifd->get($tag);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $value = $entry->value;
        if (!is_string($value)) {
            return null;
        }

        return rtrim($value, "\0");
    }

    /**
     * Returns the number of bytes used per component for a TIFF field type.
     *
     * TIFF 6.0 §2.2 defines the byte sizes for each field type. BigTIFF extends
     * this with 64-bit types (LONG8, SLONG8, IFD8).
     *
     * @param int $type TIFF field type code.
     *
     * @return int
     */
    private function bytesPerComponent(int $type): int
    {
        return match ($type) {
            // BYTE, ASCII, SBYTE, UNDEFINED
            TiffConst::TYPE_BYTE,
            TiffConst::TYPE_ASCII,
            TiffConst::TYPE_SBYTE,
            TiffConst::TYPE_UNDEFINED => 1,

            // SHORT, SSHORT
            TiffConst::TYPE_SHORT,
            TiffConst::TYPE_SSHORT => 2,

            // LONG, SLONG, FLOAT
            TiffConst::TYPE_LONG,
            TiffConst::TYPE_IFD,
            TiffConst::TYPE_SLONG,
            TiffConst::TYPE_FLOAT => 4,

            // RATIONAL, SRATIONAL, DOUBLE
            TiffConst::TYPE_RATIONAL,
            TiffConst::TYPE_SRATIONAL,
            TiffConst::TYPE_DOUBLE,
            TiffConst::TYPE_LONG8,
            TiffConst::TYPE_SLONG8,
            TiffConst::TYPE_IFD8 => 8,

            default => throw new ParseError('Unsupported TIFF type: ' . $type, 1338),
        };
    }

    /**
     * Reads an unsigned 16-bit integer using the file byte order.
     *
     * @return int
     */
    private function readU16(): int
    {
        return $this->bo === Endian::Little ? $this->buffer->readU16LE() : $this->buffer->readU16BE();
    }

    /**
     * Reads an unsigned 32-bit integer using the file byte order.
     *
     * @return int
     */
    private function readU32(): int
    {
        return $this->bo === Endian::Little ? $this->buffer->readU32LE() : $this->buffer->readU32BE();
    }

    /**
     * Reads an unsigned 64-bit integer using the file byte order.
     *
     * @return UInt64
     */
    private function readU64(): UInt64
    {
        return $this->bo === Endian::Little ? $this->buffer->readU64LE() : $this->buffer->readU64BE();
    }

    /**
     * Converts an integer into a byte string respecting the configured endianness.
     *
     * @param int|UInt64 $v     Integer value to convert.
     * @param int        $bytes Number of bytes to output.
     *
     * @return string
     */
    private function uXToBytes(int|UInt64 $v, int $bytes): string
    {
        // Convert integer to a byte string of specific length using current endianness
        if ($bytes === 4) {
            $value = $v instanceof UInt64 ? $v->toInt('Inline 32-bit value') : $v;

            return $this->bo === Endian::Little ? pack('V', $value) : pack('N', $value);
        }

        if ($bytes === 8) {
            if ($v instanceof UInt64) {
                $hi = $v->high();
                $lo = $v->low();
            } else {
                $lo = $v & BitMask::UINT32_MAX;
                $hi = intdiv($v, BitMask::UINT32_BASE);
            }

            return $this->bo === Endian::Little ? pack('V2', $lo, $hi) : pack('N2', $hi, $lo);
        }

        // fallback (shouldn't happen here)
        $bin   = '';
        $value = $v instanceof UInt64 ? $v->toInt('Inline value') : $v;
        for ($i = 0; $i < $bytes; ++$i) {
            $bin = chr(($value >> ($this->bo === Endian::Little ? ($i * 8) : (($bytes - 1 - $i) * 8))) & BitMask::LOW_BYTE) . $bin;
        }

        return $bin;
    }

    /**
     * Reads a 32-bit integer from a byte buffer using the configured endianness.
     *
     * @param string $bytes  Source buffer containing the integer.
     * @param int    $offset Byte offset within the buffer.
     * @param bool   $signed Whether to interpret the value as signed.
     *
     * @return int
     */
    private function read32FromBytes(string $bytes, int $offset, bool $signed): int
    {
        $chunk = substr($bytes, $offset, 4);

        return $signed ? $this->unpackS32($chunk) : $this->unpackU32($chunk);
    }

    /**
     * Unpacks an unsigned 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackU16(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'v' : 'n';

        return Unpack::int($format, $b, '16-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackS16(string $b): int
    {
        $u = $this->unpackU16($b);

        return $u >= BitMask::SIGN_BIT_16 ? $u - BitMask::UINT16_BASE : $u;
    }

    /**
     * Unpacks an unsigned 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackU32(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'V' : 'N';

        return Unpack::int($format, $b, '32-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackS32(string $b): int
    {
        $u = $this->unpackU32($b);

        return (($u & BitMask::SIGN_BIT_32) !== 0) ? -((~$u & BitMask::UINT32_MAX) + 1) : $u;
    }

    /**
     * Unpacks an IEEE-754 single-precision float from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return float
     */
    private function unpackFloat(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'g' : 'G';

        return Unpack::float($format, $b, '32-bit float from TIFF bytes');
    }

    /**
     * Unpacks an IEEE-754 double-precision float from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return float
     */
    private function unpackDouble(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'e' : 'E';

        return Unpack::float($format, $b, '64-bit float from TIFF bytes');
    }

    /**
     * Unpacks an unsigned 64-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return UInt64
     */
    private function unpackU64(string $b): UInt64
    {
        return Unpack::uint64($b, $this->bo === Endian::Little, '64-bit value from TIFF bytes');
    }

    /**
     * Unpacks a signed 64-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     *
     * @return int
     */
    private function unpackS64(string $b): int
    {
        $unsigned = $this->unpackU64($b);
        $hi       = $unsigned->high();
        $lo       = $unsigned->low();

        if (($hi & BitMask::SIGN_BIT_32) === 0) {
            return $unsigned->toInt('Signed 64-bit integer');
        }

        $hiComplement = (~$hi) & BitMask::UINT32_MAX;
        $loComplement = (~$lo) & BitMask::UINT32_MAX;

        $magnitude = Unpack::combineUint32($hiComplement, $loComplement)
            ->addSmall(1)
            ->toInt('Signed 64-bit integer magnitude');

        return -$magnitude;
    }

    /**
     * Converts an unsigned integer to its signed representation for the given width.
     *
     * @param int $u    Unsigned integer value.
     * @param int $bits Bit width of the target signed representation.
     *
     * @return int
     */
    private function toSigned(int $u, int $bits): int
    {
        $sign = 1 << ($bits - 1);

        return (($u & $sign) !== 0) ? $u - (1 << $bits) : $u;
    }

    /**
     * Ensures that an IFD entry encodes a valid offset and returns it as an integer.
     *
     * EXIF 3.0 §4.6.3 requires pointer tags to reference additional directories
     * by absolute offsets. The helper normalises the supported numeric representations
     * into validated offsets within the EXIF payload.
     *
     * @param IfdEntry $entry Entry that should contain a pointer/offset value.
     *
     * @return int|null
     */
    private function pointerOffset(IfdEntry $entry): ?int
    {
        $this->assertIfdPointerLayout($entry);

        $value = $entry->value;

        if (is_int($value)) {
            return $this->validatePointerOffset($value, $entry->tag);
        }

        if ($value instanceof UInt64) {
            if ($value->isZero()) {
                return null;
            }

            return $this->ensureOffset($value, sprintf('IFD pointer tag 0x%04X', $entry->tag));
        }

        if (is_float($value)) {
            return $this->pointerOffsetFromFloat($value, $entry->tag);
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;
            if (is_int($first)) {
                return $this->validatePointerOffset($first, $entry->tag);
            }

            if ($first instanceof UInt64) {
                if ($first->isZero()) {
                    return null;
                }

                return $this->ensureOffset($first, sprintf('IFD pointer tag 0x%04X', $entry->tag));
            }

            if (is_float($first)) {
                return $this->pointerOffsetFromFloat($first, $entry->tag);
            }
        }

        throw new ParseError(sprintf('IFD pointer tag 0x%04X must contain a numeric offset.', $entry->tag), 1339);
    }

    /**
     * Validates the layout of IFD pointer tags mandated by the EXIF spec.
     *
     * EXIF 3.0 §4.6.3.1.1 (ExifIFDPointer), §4.6.3.2.1 (GPSInfoIFDPointer) and
     * §4.6.3.3.1 (InteroperabilityIFDPointer) all require a single LONG (or
     * LONG8/IFD8 in BigTIFF) offset value.
     */
    private function assertIfdPointerLayout(IfdEntry $entry): void
    {
        $specRefs = [
            ExifTag::EXIF_IFD_POINTER             => 'EXIF 3.0 §4.6.3.1.1',
            ExifTag::GPS_IFD_POINTER              => 'EXIF 3.0 §4.6.3.2.1',
            ExifTag::INTEROPERABILITY_IFD_POINTER => 'EXIF 3.0 §4.6.3.3.1',
        ];

        $specRef = $specRefs[$entry->tag] ?? null;
        if ($specRef === null) {
            return;
        }

        if ($entry->count !== 1) {
            throw new ParseError(
                sprintf('IFD pointer tag 0x%04X must contain exactly one offset per %s.', $entry->tag, $specRef),
                1340,
            );
        }

        $allowedTypes = $this->bigTiff
            ? [
                TiffConst::TYPE_LONG,
                TiffConst::TYPE_IFD,
                TiffConst::TYPE_LONG8,
                TiffConst::TYPE_IFD8,
            ]
            : [
                TiffConst::TYPE_LONG,
                TiffConst::TYPE_IFD,
            ];

        if (!in_array($entry->type, $allowedTypes, true)) {
            throw new ParseError(
                sprintf('IFD pointer tag 0x%04X must use a LONG/IFD field type per %s.', $entry->tag, $specRef),
                1341,
            );
        }
    }

    /**
     * Validates that an offset fits within the supported integer range.
     *
     * @param int $offset Candidate offset.
     * @param int $tag    Tag identifier emitting the offset.
     *
     * @return int|null
     */
    private function validatePointerOffset(int $offset, int $tag): ?int
    {
        if ($offset <= 0) {
            return null;
        }

        // TIFF 6.0 §8: the TIFF header occupies bytes 0..7, so any non-zero
        // IFD pointer offset must be >= 8 to reference a valid IFD structure.
        if ($offset < 8) {
            throw new ParseError(
                sprintf('IFD pointer tag 0x%04X offset %d points into TIFF header', $tag, $offset),
                1407,
            );
        }

        return $this->ensureOffset($offset, sprintf('IFD pointer tag 0x%04X', $tag));
    }

    /**
     * Normalises a floating-point offset representation to a validated integer.
     *
     * @param float $value Floating-point representation to normalise.
     * @param int   $tag   Tag identifier emitting the offset.
     *
     * @return int|null
     */
    private function pointerOffsetFromFloat(float $value, int $tag): ?int
    {
        if (!is_finite($value) || (float) (int) $value !== $value) {
            throw new ParseError(sprintf('IFD pointer tag 0x%04X must contain an integer offset.', $tag), 1342);
        }

        if ($value <= 0.0) {
            return null;
        }

        return $this->ensureOffset((int) $value, sprintf('IFD pointer tag 0x%04X', $tag));
    }

    /**
     * Reads a BigTIFF offset using the configured field width.
     *
     * EXIF 3.0 §4.5.2 and TIFF 6.0 §8 define the BigTIFF offset field
     * width (8 or 16 bytes), null-pointer semantics, and the handling of offsets that
     * exceed native integer precision, so this helper normalises the raw value into
     * the closest PHP representation.
     */
    private function readBigTiffOffsetValue(): int|UInt64|string
    {
        if ($this->bigTiffOffsetSize === 8) {
            return $this->readU64();
        }

        if ($this->bigTiffOffsetSize !== 16) {
            throw new ParseError('Unsupported BigTIFF offset size.', 1343);
        }

        $required  = $this->bigTiffOffsetSize;
        $remaining = $this->buffer->size() - $this->buffer->tell();

        // GH-907: reject truncated BigTIFF offset fields instead of zero-padding
        if ($remaining < $required) {
            throw new ParseError(
                sprintf(
                    'Truncated BigTIFF offset field: need %d bytes, only %d available',
                    $required,
                    $remaining,
                ),
                1361,
            );
        }

        $raw = $this->buffer->read($required);

        $little = $this->bo === Endian::Little;

        $lowBytes  = $little ? substr($raw, 0, 8) : substr($raw, 8, 8);
        $highBytes = $little ? substr($raw, 8, 8) : substr($raw, 0, 8);

        $low  = Unpack::uint64($lowBytes, $little, 'BigTIFF offset (low)');
        $high = Unpack::uint64($highBytes, $little, 'BigTIFF offset (high)');

        if (!$high->isZero()) {
            return $this->uint128ToDecimal($high, $low);
        }

        if ($low->fitsSignedInt()) {
            return $low->toInt('BigTIFF offset');
        }

        return $this->uint64ToDecimal($low);
    }

    /**
     * Converts an unsigned 64-bit integer into its decimal string representation.
     */
    private function uint64ToDecimal(UInt64 $value): string
    {
        return $this->wordsToDecimal([
            $value->high(),
            $value->low(),
        ]);
    }

    /**
     * Converts a 128-bit unsigned integer into a decimal string.
     */
    private function uint128ToDecimal(UInt64 $high, UInt64 $low): string
    {
        return $this->wordsToDecimal([
            $high->high(),
            $high->low(),
            $low->high(),
            $low->low(),
        ]);
    }

    /**
     * Converts an array of base-2^32 words (most significant first) into a decimal string.
     *
     * @param array<int> $words
     */
    private function wordsToDecimal(array $words): string
    {
        $words = $this->trimLeadingZeroWords($words);

        if ($words === []) {
            return '0';
        }

        $digits = '';

        while ($words !== []) {
            [$words, $remainder] = $this->divModWordsBy10($words);
            $digits              = $remainder . $digits;
            $words               = $this->trimLeadingZeroWords($words);
        }

        return $digits;
    }

    /**
     * Divides a base-2^32 big integer by 10.
     *
     * @param array<int> $words
     *
     * @return array{0: array<int>, 1: int}
     */
    private function divModWordsBy10(array $words): array
    {
        $quotient = [];
        $carry    = 0;

        foreach ($words as $word) {
            $value = ($carry << 32) + $word;
            $q     = intdiv($value, 10);
            $r     = $value - ($q * 10);

            if ($quotient !== [] || $q !== 0) {
                $quotient[] = $q;
            }

            $carry = $r;
        }

        return [$quotient, $carry];
    }

    /**
     * Removes leading zero words from a base-2^32 representation.
     *
     * @param array<int> $words
     *
     * @return array<int>
     */
    private function trimLeadingZeroWords(array $words): array
    {
        $index = 0;
        $count = count($words);

        while ($index < $count && $words[$index] === 0) {
            ++$index;
        }

        if ($index === 0) {
            return $words;
        }

        if ($index >= $count) {
            return [];
        }

        return array_slice($words, $index);
    }

    /**
     * Ensures that a decimal offset lies within the TIFF blob and returns it as an integer.
     */
    private function ensureDecimalOffset(string $offset, string $context, int $length): int
    {
        $normalised = $this->normaliseDecimalString($offset);
        $size       = $this->buffer->size();

        if ($this->compareDecimalStringToInt($normalised, $size) > 0) {
            throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1344);
        }

        if ($length > $size) {
            throw new BoundsError(sprintf('%s length %d exceeds TIFF data length.', $context, $length), 1345);
        }

        if ($length > 0) {
            $limit = $size - $length;
            if ($this->compareDecimalStringToInt($normalised, $limit) > 0) {
                throw new BoundsError(sprintf('%s exceeds TIFF data length.', $context), 1346);
            }
        }

        $result = (int) $normalised;

        // TIFF 6.0 §2: offsets must begin on a word boundary (even byte offset).
        if ($result % 2 !== 0) {
            throw new ParseError(sprintf('%s is not word-aligned (offset %d) per TIFF 6.0.', $context, $result), 1347);
        }

        return $result;
    }

    /**
     * Normalises a decimal string by validating its characters and removing leading zeros.
     */
    private function normaliseDecimalString(string $value): string
    {
        if ($value === '') {
            throw new ParseError('Decimal offset must not be empty.', 1348);
        }

        if (strspn($value, '0123456789') !== strlen($value)) {
            throw new ParseError('Decimal offset contains invalid characters.', 1349);
        }

        $trimmed = ltrim($value, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * Compares a decimal string against a non-negative integer.
     */
    private function compareDecimalStringToInt(string $decimal, int $int): int
    {
        if ($int < 0) {
            return 1;
        }

        $intString = $int === 0 ? '0' : ltrim((string) $int, '0');
        $decLen    = strlen($decimal);
        $intLen    = strlen($intString);

        if ($decLen !== $intLen) {
            return $decLen <=> $intLen;
        }

        return $decimal <=> $intString;
    }

    /**
     * Determines whether a decimal string represents zero.
     */
    private function decimalStringIsZero(string $value): bool
    {
        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            if ($value[$i] !== '0') {
                return false;
            }
        }

        return true;
    }

    /**
     * DNG matrix tag count formulas keyed by tag constant.
     *
     * Each entry maps to either 'colorTimesThree' (ColorPlanes × 3) or
     * 'colorSquared' (ColorPlanes × ColorPlanes).
     *
     * DNG 1.7.1.0 pp. 32–42 (ColorMatrix/CameraCalibration/ReductionMatrix),
     * pp. 58–61 (ForwardMatrix), pp. 87–90 (tertiary tags).
     *
     * @var array<int, 'colorTimesThree'|'colorSquared'>
     */
    private const array DNG_MATRIX_COUNT_RULES = [
        DngTag::COLOR_MATRIX_1       => 'colorTimesThree',
        DngTag::COLOR_MATRIX_2       => 'colorTimesThree',
        DngTag::COLOR_MATRIX_3       => 'colorTimesThree',
        DngTag::CAMERA_CALIBRATION_1 => 'colorSquared',
        DngTag::CAMERA_CALIBRATION_2 => 'colorSquared',
        DngTag::CAMERA_CALIBRATION_3 => 'colorSquared',
        DngTag::REDUCTION_MATRIX_1   => 'colorTimesThree',
        DngTag::REDUCTION_MATRIX_2   => 'colorTimesThree',
        DngTag::REDUCTION_MATRIX_3   => 'colorTimesThree',
        DngTag::FORWARD_MATRIX_1     => 'colorTimesThree',
        DngTag::FORWARD_MATRIX_2     => 'colorTimesThree',
        DngTag::FORWARD_MATRIX_3     => 'colorTimesThree',
    ];

    /**
     * Validates DNG matrix tags against ColorPlanes-driven count and SRATIONAL type rules.
     *
     * DNG 1.7.1.0 pp. 32–42 defines matrix dimensional rules driven by the number of
     * color planes derived from CfaPlaneColor (Tag 0xC616). Each matrix tag must use
     * SRATIONAL type and match the expected element count.
     */
    private function validateDngMatrixTags(Ifd $ifd): void
    {
        $cfaEntry = $ifd->get(DngTag::CFA_PLANE_COLOR);

        if (!$cfaEntry instanceof IfdEntry) {
            return;
        }

        $colorPlanes = $cfaEntry->count;

        // DNG 1.7.1.0 p. 32: ColorMatrix1 is required for all non-monochrome DNG files.
        if ($colorPlanes > 1 && !$ifd->get(DngTag::COLOR_MATRIX_1) instanceof IfdEntry) {
            throw new ParseError(
                'ColorMatrix1 is required for non-monochrome DNG files per DNG 1.7.1.0.',
                1472,
            );
        }

        foreach (self::DNG_MATRIX_COUNT_RULES as $tag => $formula) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if ($entry->type !== TiffConst::TYPE_SRATIONAL) {
                throw new ParseError(
                    sprintf(
                        'DNG matrix tag 0x%04X requires SRATIONAL type, got %d per DNG 1.7.1.0.',
                        $tag,
                        $entry->type,
                    ),
                    1469,
                );
            }

            $expected = $formula === 'colorSquared'
                ? $colorPlanes * $colorPlanes
                : $colorPlanes * 3;

            if ($entry->count !== $expected) {
                throw new ParseError(
                    sprintf(
                        'DNG matrix tag 0x%04X count %d does not match expected %d (ColorPlanes=%d) per DNG 1.7.1.0.',
                        $tag,
                        $entry->count,
                        $expected,
                        $colorPlanes,
                    ),
                    1470,
                );
            }
        }
    }

    /**
     * DNG calibration illuminant → illuminant data dependency pairs.
     *
     * DNG 1.7.1.0 pp. 43–44, 86, 91–93: when a CalibrationIlluminant tag has value
     * 255 (Other), the corresponding IlluminantData tag is required.
     *
     * @var array<int, int>
     */
    private const array DNG_ILLUMINANT_DATA_DEPS = [
        DngTag::CALIBRATION_ILLUMINANT_1 => DngTag::ILLUMINANT_DATA_1,
        DngTag::CALIBRATION_ILLUMINANT_2 => DngTag::ILLUMINANT_DATA_2,
        DngTag::CALIBRATION_ILLUMINANT_3 => DngTag::ILLUMINANT_DATA_3,
    ];

    /**
     * Validates DNG calibration illuminant conditional dependencies.
     *
     * DNG 1.7.1.0 pp. 43–44, 91–93: when CalibrationIlluminant{1,2,3} = 255 (Other),
     * the corresponding IlluminantData{1,2,3} tag must be present.
     */
    private function validateDngIlluminantDependencies(Ifd $ifd): void
    {
        foreach (self::DNG_ILLUMINANT_DATA_DEPS as $illuminantTag => $dataTag) {
            $entry = $ifd->get($illuminantTag);
            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (!is_int($entry->value)) {
                continue;
            }

            if ($entry->value !== 255) {
                continue;
            }

            if (!$ifd->get($dataTag) instanceof IfdEntry) {
                throw new ParseError(
                    sprintf(
                        'CalibrationIlluminant 0x%04X = 255 (Other) requires IlluminantData 0x%04X per DNG 1.7.1.0.',
                        $illuminantTag,
                        $dataTag,
                    ),
                    1471,
                );
            }
        }
    }

    /**
     * All-or-none DNG tag sets for triple-illuminant validation.
     *
     * DNG 1.7.1.0 "Requirements for three calibrations": ForwardMatrix,
     * ReductionMatrix, and ProfileHueSatMapData must be present for all
     * three illuminants or none.
     *
     * @var list<array{int, int, int}>
     */
    private const array DNG_TRIPLE_ALL_OR_NONE_SETS = [
        [DngTag::FORWARD_MATRIX_1, DngTag::FORWARD_MATRIX_2, DngTag::FORWARD_MATRIX_3],
        [DngTag::REDUCTION_MATRIX_1, DngTag::REDUCTION_MATRIX_2, DngTag::REDUCTION_MATRIX_3],
    ];

    /**
     * Validates DNG triple-illuminant cross-tag dependencies.
     *
     * DNG 1.7.1.0 "Requirements for three calibrations": when CalibrationIlluminant3
     * is present, CalibrationIlluminant1/2, ColorMatrix3, and all-or-none tag sets
     * must be structurally complete with distinct illuminant values.
     */
    private function validateDngTripleIlluminant(Ifd $ifd): void
    {
        $illum3 = $ifd->get(DngTag::CALIBRATION_ILLUMINANT_3);

        if (!$illum3 instanceof IfdEntry) {
            return;
        }

        // CalibrationIlluminant1 and CalibrationIlluminant2 must also be present
        if (!$ifd->get(DngTag::CALIBRATION_ILLUMINANT_1) instanceof IfdEntry
            || !$ifd->get(DngTag::CALIBRATION_ILLUMINANT_2) instanceof IfdEntry
        ) {
            throw new ParseError(
                'CalibrationIlluminant3 requires CalibrationIlluminant1 and CalibrationIlluminant2 per DNG 1.7.1.0.',
                1473,
            );
        }

        // ColorMatrix3 must be present
        if (!$ifd->get(DngTag::COLOR_MATRIX_3) instanceof IfdEntry) {
            throw new ParseError(
                'CalibrationIlluminant3 requires ColorMatrix3 per DNG 1.7.1.0.',
                1474,
            );
        }

        // All-or-none tag sets
        foreach (self::DNG_TRIPLE_ALL_OR_NONE_SETS as $set) {
            $present = 0;
            foreach ($set as $tag) {
                if ($ifd->get($tag) instanceof IfdEntry) {
                    ++$present;
                }
            }

            if ($present !== 0 && $present !== 3) {
                throw new ParseError(
                    sprintf(
                        'DNG triple-illuminant tag set 0x%04X/0x%04X/0x%04X must be all-or-none per DNG 1.7.1.0.',
                        $set[0],
                        $set[1],
                        $set[2],
                    ),
                    1475,
                );
            }
        }

        // Illuminant values must be distinct (illum1/illum2 guaranteed present above)
        /** @var IfdEntry $illum1 */
        $illum1 = $ifd->get(DngTag::CALIBRATION_ILLUMINANT_1);
        /** @var IfdEntry $illum2 */
        $illum2 = $ifd->get(DngTag::CALIBRATION_ILLUMINANT_2);

        if (
            is_int($illum1->value) && is_int($illum2->value) && is_int($illum3->value)
            && ($illum1->value === $illum2->value || $illum1->value === $illum3->value || $illum2->value === $illum3->value)
        ) {
            throw new ParseError(
                'Triple-illuminant CalibrationIlluminant values must be distinct per DNG 1.7.1.0.',
                1476,
            );
        }
    }

    /**
     * Validates DNG white-balance tag mutual exclusivity.
     *
     * DNG 1.7.1.0 pp. 36–37: AsShotNeutral and AsShotWhiteXY are mutually
     * exclusive; both must not be present in the same IFD.
     */
    private function validateDngWhiteBalanceExclusivity(Ifd $ifd): void
    {
        if (
            $ifd->get(DngTag::AS_SHOT_NEUTRAL) instanceof IfdEntry
            && $ifd->get(DngTag::AS_SHOT_WHITE_XY) instanceof IfdEntry
        ) {
            throw new ParseError(
                'AsShotNeutral and AsShotWhiteXY are mutually exclusive per DNG 1.7.1.0.',
                1477,
            );
        }
    }
}
