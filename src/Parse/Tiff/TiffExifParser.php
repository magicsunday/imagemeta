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
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
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
final class TiffExifParser implements TiffExifParserInterface
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
        DngTag::NOISE_REDUCTION_APPLIED => [
            'name'     => 'NoiseReductionApplied',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::PROFILE_EMBED_POLICY => [
            'name'     => 'ProfileEmbedPolicy',
            'count'    => 1,
            'type'     => TiffConst::TYPE_LONG,
            'typeName' => 'LONG',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::BASELINE_EXPOSURE_OFFSET => [
            'name'     => 'BaselineExposureOffset',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::RAW_TO_PREVIEW_GAIN => [
            'name'     => 'RawToPreviewGain',
            'count'    => 1,
            'type'     => TiffConst::TYPE_DOUBLE,
            'typeName' => 'DOUBLE',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::DEFAULT_USER_CROP => [
            'name'     => 'DefaultUserCrop',
            'count'    => 4,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::DEPTH_FORMAT => [
            'name'     => 'DepthFormat',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::DEPTH_NEAR => [
            'name'     => 'DepthNear',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::DEPTH_FAR => [
            'name'     => 'DepthFar',
            'count'    => 1,
            'type'     => TiffConst::TYPE_RATIONAL,
            'typeName' => 'RATIONAL',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::DEPTH_UNITS => [
            'name'     => 'DepthUnits',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'DNG 1.7.1.0',
        ],
        DngTag::DEPTH_MEASURE_TYPE => [
            'name'     => 'DepthMeasureType',
            'count'    => 1,
            'type'     => TiffConst::TYPE_SHORT,
            'typeName' => 'SHORT',
            'spec'     => 'DNG 1.7.1.0',
        ],
    ];

    /**
     * DNG tags that use ASCII or BYTE type with NUL-terminated UTF-8 semantics.
     *
     * @var list<int>
     */
    private const array DNG_UTF8_STRING_TAGS = [
        DngTag::CAMERA_CALIBRATION_SIGNATURE,
        DngTag::PROFILE_CALIBRATION_SIGNATURE,
        DngTag::AS_SHOT_PROFILE_NAME,
        DngTag::PROFILE_COPYRIGHT,
        DngTag::ORIGINAL_RAW_FILE_NAME,
        DngTag::PREVIEW_APPLICATION_NAME,
        DngTag::PREVIEW_APPLICATION_VERSION,
        DngTag::PREVIEW_SETTINGS_NAME,
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

        $isDngContainer = ($ifd0->get(DngTag::DNG_VERSION) instanceof IfdEntry)
            || ($ifd0->get(DngTag::DNG_BACKWARD_VERSION) instanceof IfdEntry)
            || ($ifd0->get(DngTag::UNIQUE_CAMERA_MODEL) instanceof IfdEntry);

        $this->validateDngRequiredVersion($ifd0);
        $this->validateEnhancedIfd($ifd0);
        foreach ($additionalIfds as $additionalIfd) {
            $this->validateEnhancedIfd($additionalIfd);
            $this->validateSubfileAndPageTags($additionalIfd, !$isDngContainer);
            $this->validatePositionTags($additionalIfd);
            $this->validateThreshholdingAndCellTags($additionalIfd);
            $this->validateFreeSpaceTags($additionalIfd);
            $this->validateFillOrderTag($additionalIfd);
            $this->validatePredictorTag($additionalIfd);
            $this->validateJpegProcTag($additionalIfd);
            $this->validateJpegLosslessTags($additionalIfd);
            $this->validateJpegTableTags($additionalIfd);
            $this->validateJpegInterchangePairTags($additionalIfd);
            $this->validateMinMaxSampleValueTags($additionalIfd);
            $this->validateSampleDomainTags($additionalIfd);
            $this->validateExtraSamplesTag($additionalIfd);
            $this->validateGrayResponseTags($additionalIfd);
            $this->validateHalftoneHintsTag($additionalIfd);
            $this->validateFaxOptionTags($additionalIfd);
            $this->validateSeparatedImageInkTags($additionalIfd);
            $this->validateSeparatedImageDotRange($additionalIfd);
            $this->validateTransferFamilyTags($additionalIfd);
            $this->validatePaletteColorMapTag($additionalIfd);
            $this->validateDngRolePhotometric($additionalIfd);
            $this->validateDngIfd0OnlyTags($additionalIfd);
            $this->validateDngJxlTags($additionalIfd);
            $this->validateDngCfaPhotometric($additionalIfd);
            $this->validateDngLinearizationTable($additionalIfd);
            $this->validateDngBayerGreenSplit($additionalIfd);
            $this->validateDngProfileGainTableMapLegacy($additionalIfd);
            $this->validateDngSemanticMaskIdentity($additionalIfd);
            $this->validateDngMaskSubArea($additionalIfd);
        }

        $this->validateDngMatrixTags($ifd0);
        $this->validateDngIlluminantDependencies($ifd0);
        $this->validateDngTripleIlluminant($ifd0);
        $this->validateDngWhiteBalanceExclusivity($ifd0);
        $this->validateDngWhiteBalanceLayout($ifd0);
        $this->validateDngAnalogBalance($ifd0);
        $this->validateDngIccProfilePairs($ifd0);
        $this->validateDngCalibrationIlluminantPairZero($ifd0);
        $this->validateDngProfileToneCurve($ifd0);
        $this->validateDngInterleaveVersionFloors($ifd0);
        $this->validateDngBackwardVersionGate($ifd0);
        $this->validateDngColorimetricReference($ifd0);
        $this->validateDngMultiProfileName($ifd0, $additionalIfds);
        $this->validateDngExtraCameraProfiles($ifd0);
        $this->validateDngNoiseProfile($ifd0);
        $this->validateDngHueSatMapDims($ifd0);
        $this->validateDngHueSatMapData($ifd0);
        $this->validateDngProfileLookTableDims($ifd0);
        $this->validateDngProfileLookTableData($ifd0);
        $this->validateDngEncodingTag(
            $ifd0,
            DngTag::PROFILE_HUE_SAT_MAP_ENCODING,
            DngTag::PROFILE_HUE_SAT_MAP_DIMS,
            'ProfileHueSatMapEncoding',
        );
        $this->validateDngEncodingTag(
            $ifd0,
            DngTag::PROFILE_LOOK_TABLE_ENCODING,
            DngTag::PROFILE_LOOK_TABLE_DIMS,
            'ProfileLookTableEncoding',
        );
        $this->validateDngDigestTags($ifd0);
        $this->validateDngPreviewDateTime($ifd0);
        $this->validateDngPreviewColorSpace($ifd0);
        $this->validateDngDefaultBlackRender($ifd0);
        $this->validateDngIlluminantData($ifd0);
        $this->validateDngProfileDynamicRange($ifd0);
        $this->validateDngProfileGainTableMap2($ifd0);
        $this->validateDngGainMapPlacement($ifd0);
        $this->validateDngProfileGainTableMapLegacy($ifd0);
        $this->validateDngImageStats($ifd0);
        $this->validateDngImageSequenceInfo($ifd0);
        $this->validateDngRgbTables($ifd0);
        $this->validateDngOpcodeLists($ifd0);
        $this->validateDngOriginalRawFileData($ifd0);
        $this->validateDngActiveAndMaskedAreas($ifd0);
        $this->validateDngBlackWhiteLevelFamily($ifd0);
        $this->validateDngDefaultCropScaleGeometry($ifd0);
        $this->validateDngLinearResponseLimit($ifd0);
        $this->validateDngLinearizationTable($ifd0);
        $this->validateDngBayerGreenSplit($ifd0);
        $this->validateDngRenderScalars($ifd0);
        $this->validateDngBaselineExposure($ifd0);
        $this->validateDngBaselineScalars($ifd0);
        $this->validateDngLensInfo($ifd0);
        $this->validateDngBestQualityScale($ifd0);
        $this->validateDngOriginalProxySizes($ifd0);
        $this->validateDngDefaultUserCrop($ifd0);
        $this->validateDngDepthEnums($ifd0);
        $this->validateDngNoiseReductionApplied($ifd0);
        $this->validateDngCfaLayoutDomain($ifd0);
        $this->validateDngProfileEmbedPolicy($ifd0);
        $this->validateDngEnhanceParams($ifd0);
        $this->validateDngSubTileBlockSize($ifd0);
        $this->validateDngRowInterleaveFactor($ifd0);
        $this->validateDngRequiredOrientation($ifd0);
        $this->validateResolutionEquality($ifd0);
        $this->validateCompressionDomain($ifd0, $ifd1);
        $this->validateSubfileAndPageTags($ifd0, !$isDngContainer);
        $this->validatePositionTags($ifd0);
        $this->validateThreshholdingAndCellTags($ifd0);
        $this->validateFreeSpaceTags($ifd0);
        $this->validateFillOrderTag($ifd0);
        $this->validatePredictorTag($ifd0);
        $this->validateJpegProcTag($ifd0);
        $this->validateJpegLosslessTags($ifd0);
        $this->validateJpegTableTags($ifd0);
        $this->validateJpegInterchangePairTags($ifd0);
        $this->validateMinMaxSampleValueTags($ifd0);
        $this->validateSampleDomainTags($ifd0);
        $this->validateExtraSamplesTag($ifd0);
        $this->validateGrayResponseTags($ifd0);
        $this->validateHalftoneHintsTag($ifd0);
        $this->validateFaxOptionTags($ifd0);
        $this->validateSeparatedImageInkTags($ifd0);
        $this->validateSeparatedImageDotRange($ifd0);
        $this->validateTransferFamilyTags($ifd0);
        $this->validatePaletteColorMapTag($ifd0);
        $this->validatePrimaryThumbnailStructureCompatibility($ifd0, $ifd1, $jpegContext);
        $this->validateCameraControlEnumDomains($ifd0, $exifIfd, $ifd1, ...$additionalIfds);
        $this->validateFlashBitfield($exifIfd);
        $this->validateJpegThumbnailStream($ifd1);

        if (!$jpegContext) {
            $this->validateImageDimensions($ifd0);
            $this->validateStripLayoutConsistency($ifd0);
            $this->validateTileLayoutConsistency($ifd0);
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

        // DNG 1.7.0.0: ProfileGroupName must be ASCII or BYTE with NUL terminator.
        if ($tag === DngTag::PROFILE_GROUP_NAME) {
            if ($type !== TiffConst::TYPE_ASCII && $type !== TiffConst::TYPE_BYTE) {
                throw new ParseError(
                    sprintf('ProfileGroupName must use ASCII or BYTE type, got %d.', $type),
                    1509,
                );
            }

            if ($type === TiffConst::TYPE_BYTE) {
                if ($rawBytes === '' || $rawBytes[strlen($rawBytes) - 1] !== "\0") {
                    throw new ParseError(
                        'ProfileGroupName BYTE payload must be NUL-terminated per DNG 1.7.0.0.',
                        1510,
                    );
                }

                $value = rtrim($rawBytes, "\0");
            }
        }

        // DNG 1.7.1.0: String tags that must be ASCII or BYTE, NUL-terminated UTF-8.
        if (in_array($tag, self::DNG_UTF8_STRING_TAGS, true)) {
            if ($type !== TiffConst::TYPE_ASCII && $type !== TiffConst::TYPE_BYTE) {
                throw new ParseError(
                    sprintf('DNG string tag 0x%04X must use ASCII or BYTE type, got %d.', $tag, $type),
                    1571,
                );
            }

            if ($type === TiffConst::TYPE_BYTE) {
                if ($rawBytes === '' || $rawBytes[strlen($rawBytes) - 1] !== "\0") {
                    throw new ParseError(
                        sprintf('DNG string tag 0x%04X BYTE payload must be NUL-terminated.', $tag),
                        1572,
                    );
                }

                $text = rtrim($rawBytes, "\0");

                if (!mb_check_encoding($text, 'UTF-8')) {
                    throw new ParseError(
                        sprintf('DNG string tag 0x%04X contains malformed UTF-8.', $tag),
                        1573,
                    );
                }

                $value = $text;
            }
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

        if (
            $entry instanceof IfdEntry
            && is_int($entry->value)
            && $entry->value !== 1
        ) {
            throw new ParseError(sprintf(
                'Compression value %d in IFD0 is invalid; only 1 (uncompressed) is allowed.',
                $entry->value,
            ), 1351);
        }

        if (!$ifd1 instanceof Ifd) {
            return;
        }

        $thumbEntry = $ifd1->get(ExifTag::COMPRESSION);

        if (
            $thumbEntry instanceof IfdEntry
            && is_int($thumbEntry->value)
            && $thumbEntry->value !== 1
            && $thumbEntry->value !== 6
        ) {
            throw new ParseError(sprintf(
                'Compression value %d in IFD1 is invalid; only 1 or 6 is allowed.',
                $thumbEntry->value,
            ), 1352);
        }
    }

    /**
     * Validates TIFF fax option tags T4Options/T6Options coupling and bitfield domains.
     *
     * TIFF 6.0:
     * - T4Options (Tag 292): LONG[1], only with Compression=3, bits 0..2 allowed.
     * - T6Options (Tag 293): LONG[1], only with Compression=4, bit 1 allowed; bit 0 and higher bits must be 0.
     */
    private function validateFaxOptionTags(Ifd $ifd): void
    {
        $t4Options = $ifd->get(TiffTag::T4_OPTIONS);

        if ($t4Options instanceof IfdEntry) {
            if (($t4Options->type !== TiffConst::TYPE_LONG) || ($t4Options->count !== 1) || !is_int($t4Options->value)) {
                throw new ParseError('T4Options must be LONG[1].', 1702);
            }

            $compression = $ifd->get(ExifTag::COMPRESSION);
            if (!($compression instanceof IfdEntry) || !is_int($compression->value) || ($compression->value !== 3)) {
                throw new ParseError('T4Options is only valid when Compression = 3 (CCITT Group 3).', 1703);
            }

            if (($t4Options->value & ~0b111) !== 0) {
                throw new ParseError(
                    sprintf('T4Options has reserved bits set (value=0x%X); only bits 0..2 are allowed.', $t4Options->value),
                    1704,
                );
            }
        }

        $t6Options = $ifd->get(TiffTag::T6_OPTIONS);

        if (!$t6Options instanceof IfdEntry) {
            return;
        }

        if (($t6Options->type !== TiffConst::TYPE_LONG) || ($t6Options->count !== 1) || !is_int($t6Options->value)) {
            throw new ParseError('T6Options must be LONG[1].', 1705);
        }

        $compression = $ifd->get(ExifTag::COMPRESSION);
        if (!($compression instanceof IfdEntry) || !is_int($compression->value) || ($compression->value !== 4)) {
            throw new ParseError('T6Options is only valid when Compression = 4 (CCITT Group 4).', 1706);
        }

        if (($t6Options->value & 0b1) !== 0) {
            throw new ParseError(
                sprintf('T6Options bit 0 is reserved and must be 0 (value=0x%X).', $t6Options->value),
                1707,
            );
        }

        if (($t6Options->value & ~0b10) !== 0) {
            throw new ParseError(
                sprintf('T6Options has reserved bits set (value=0x%X); only bit 1 is allowed.', $t6Options->value),
                1708,
            );
        }
    }

    /**
     * Validates TIFF FillOrder domain and usage constraints.
     *
     * TIFF 6.0 (Tag 266 / FillOrder):
     * - SHORT[1], values {1,2}, default 1.
     * - FillOrder=2 is intended for bilevel data (BitsPerSample=1) and
     *   uncompressed or CCITT compression families.
     */
    private function validateFillOrderTag(Ifd $ifd): void
    {
        $fillOrderEntry = $ifd->get(TiffTag::FILL_ORDER);

        if (!$fillOrderEntry instanceof IfdEntry) {
            return;
        }

        if (
            ($fillOrderEntry->type !== TiffConst::TYPE_SHORT)
            || ($fillOrderEntry->count !== 1)
            || !is_int($fillOrderEntry->value)
        ) {
            throw new ParseError('FillOrder must be SHORT[1].', 1752);
        }

        if (($fillOrderEntry->value !== 1) && ($fillOrderEntry->value !== 2)) {
            throw new ParseError(
                sprintf('FillOrder value %d is invalid; allowed values are 1 or 2.', $fillOrderEntry->value),
                1753,
            );
        }

        if ($fillOrderEntry->value !== 2) {
            return;
        }

        $bitsPerSampleEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

        if (!$bitsPerSampleEntry instanceof IfdEntry) {
            throw new ParseError('FillOrder=2 requires BitsPerSample=1.', 1754);
        }

        $bitDepth = null;

        if (is_int($bitsPerSampleEntry->value)) {
            $bitDepth = $bitsPerSampleEntry->value;
        } elseif ($bitsPerSampleEntry->value instanceof ExifNumericList) {
            $firstComponent = $bitsPerSampleEntry->value->values[0] ?? null;
            if (is_int($firstComponent)) {
                $bitDepth = $firstComponent;
            }
        }

        if ($bitDepth !== 1) {
            throw new ParseError(
                sprintf('FillOrder=2 requires BitsPerSample=1, got %s.', $bitDepth !== null ? (string) $bitDepth : 'missing'),
                1754,
            );
        }

        $compressionCode  = 1;
        $compressionEntry = $ifd->get(ExifTag::COMPRESSION);
        if ($compressionEntry instanceof IfdEntry && is_int($compressionEntry->value)) {
            $compressionCode = $compressionEntry->value;
        }

        if (!in_array($compressionCode, [1, 2, 3, 4], true)) {
            throw new ParseError(
                sprintf(
                    'FillOrder=2 is only compatible with Compression {1,2,3,4}, got %d.',
                    $compressionCode,
                ),
                1755,
            );
        }
    }

    /**
     * Validates TIFF subfile/page tags for baseline semantics.
     *
     * TIFF 6.0:
     * - NewSubfileType: LONG[1] bitfield (bits 0..2 only in baseline TIFF).
     * - SubfileType (deprecated): SHORT[1], value domain 1..3.
     * - PageNumber: SHORT[2], pageIndex < totalPages when totalPages != 0.
     * - Bit 2 (transparency mask) requires PhotometricInterpretation=4.
     *
     * @param bool $strictTiffNewSubfileType True to enforce TIFF-only bit constraints;
     *                                       false to allow extended DNG NewSubfileType values.
     */
    private function validateSubfileAndPageTags(Ifd $ifd, bool $strictTiffNewSubfileType): void
    {
        $newSubfileTypeEntry = $ifd->get(TiffTag::NEW_SUBFILE_TYPE);

        if ($newSubfileTypeEntry instanceof IfdEntry) {
            if (
                ($newSubfileTypeEntry->type !== TiffConst::TYPE_LONG)
                || ($newSubfileTypeEntry->count !== 1)
                || !is_int($newSubfileTypeEntry->value)
            ) {
                throw new ParseError('NewSubfileType must be LONG[1].', 1788);
            }

            $isDngExtendedNewSubfileType = in_array($newSubfileTypeEntry->value, [8, 9, 16, 65540], true);

            if (
                $strictTiffNewSubfileType
                && !$isDngExtendedNewSubfileType
                && (($newSubfileTypeEntry->value & ~0b111) !== 0)
            ) {
                throw new ParseError(
                    sprintf(
                        'NewSubfileType value %d contains reserved bits outside 0..2.',
                        $newSubfileTypeEntry->value,
                    ),
                    1789,
                );
            }

            if (
                $strictTiffNewSubfileType
                && !$isDngExtendedNewSubfileType
                && (($newSubfileTypeEntry->value & 0b100) !== 0)
            ) {
                $photometricEntry = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
                $photometricCode  = (($photometricEntry instanceof IfdEntry) && is_int($photometricEntry->value))
                    ? $photometricEntry->value
                    : null;

                if ($photometricCode !== 4) {
                    throw new ParseError(
                        sprintf(
                            'NewSubfileType transparency-mask bit requires PhotometricInterpretation=4, got %s.',
                            $photometricCode !== null ? (string) $photometricCode : 'missing',
                        ),
                        1790,
                    );
                }
            }
        }

        $subfileTypeEntry = $ifd->get(TiffTag::SUBFILE_TYPE);
        if ($subfileTypeEntry instanceof IfdEntry) {
            if (
                ($subfileTypeEntry->type !== TiffConst::TYPE_SHORT)
                || ($subfileTypeEntry->count !== 1)
                || !is_int($subfileTypeEntry->value)
            ) {
                throw new ParseError('SubfileType must be SHORT[1].', 1791);
            }

            if (($subfileTypeEntry->value < 1) || ($subfileTypeEntry->value > 3)) {
                throw new ParseError(
                    sprintf(
                        'SubfileType value %d is invalid; allowed values are 1..3.',
                        $subfileTypeEntry->value,
                    ),
                    1792,
                );
            }
        }

        if (
            $strictTiffNewSubfileType
            && ($newSubfileTypeEntry instanceof IfdEntry)
            && ($subfileTypeEntry instanceof IfdEntry)
            && !in_array($newSubfileTypeEntry->value, [8, 9, 16, 65540], true)
        ) {
            $expectedNewSubfileTypeLowBits = $subfileTypeEntry->value - 1;
            $actualNewSubfileTypeLowBits   = $newSubfileTypeEntry->value & 0b11;

            if ($actualNewSubfileTypeLowBits !== $expectedNewSubfileTypeLowBits) {
                throw new ParseError(
                    sprintf(
                        'SubfileType %d conflicts with NewSubfileType %d.',
                        $subfileTypeEntry->value,
                        $newSubfileTypeEntry->value,
                    ),
                    1793,
                );
            }
        }

        $pageNumberEntry = $ifd->get(TiffTag::PAGE_NUMBER);

        if (!$pageNumberEntry instanceof IfdEntry) {
            return;
        }

        if (($pageNumberEntry->type !== TiffConst::TYPE_SHORT) || ($pageNumberEntry->count !== 2)) {
            throw new ParseError('PageNumber must be SHORT[2].', 1794);
        }

        $pageComponents = $this->extractIntegerTagComponents($pageNumberEntry, 'PageNumber');

        if (count($pageComponents) !== 2) {
            throw new ParseError(
                sprintf('PageNumber expected 2 components, decoded %d.', count($pageComponents)),
                1795,
            );
        }

        $pageIndex  = $pageComponents[0];
        $totalPages = $pageComponents[1];

        if ($pageIndex < 0) {
            throw new ParseError(
                sprintf('PageNumber page index must be >= 0, got %d.', $pageIndex),
                1796,
            );
        }

        if (($totalPages !== 0) && ($pageIndex >= $totalPages)) {
            throw new ParseError(
                sprintf(
                    'PageNumber index %d must be less than total pages %d when total is known.',
                    $pageIndex,
                    $totalPages,
                ),
                1797,
            );
        }
    }

    /**
     * Validates TIFF Threshholding / CellWidth / CellLength semantic coupling.
     *
     * TIFF 6.0:
     * - Threshholding: SHORT[1], value domain 1..3.
     * - CellWidth/CellLength: SHORT[1], >0.
     * - CellWidth/CellLength are valid only when Threshholding=2.
     * - Threshholding=2 requires both cell tags together.
     */
    private function validateThreshholdingAndCellTags(Ifd $ifd): void
    {
        $threshholdingEntry = $ifd->get(TiffTag::THRESHHOLDING);
        $cellWidthEntry     = $ifd->get(TiffTag::CELL_WIDTH);
        $cellLengthEntry    = $ifd->get(TiffTag::CELL_LENGTH);

        if ($threshholdingEntry instanceof IfdEntry) {
            if (
                ($threshholdingEntry->type !== TiffConst::TYPE_SHORT)
                || ($threshholdingEntry->count !== 1)
                || !is_int($threshholdingEntry->value)
            ) {
                throw new ParseError('Threshholding must be SHORT[1].', 1798);
            }

            if (($threshholdingEntry->value < 1) || ($threshholdingEntry->value > 3)) {
                throw new ParseError(
                    sprintf(
                        'Threshholding value %d is invalid; allowed values are 1,2,3.',
                        $threshholdingEntry->value,
                    ),
                    1799,
                );
            }
        }

        $hasCellWidth  = $cellWidthEntry instanceof IfdEntry;
        $hasCellLength = $cellLengthEntry instanceof IfdEntry;

        if ($hasCellWidth) {
            if (($cellWidthEntry->type !== TiffConst::TYPE_SHORT) || ($cellWidthEntry->count !== 1) || !is_int($cellWidthEntry->value)) {
                throw new ParseError('CellWidth must be SHORT[1].', 1800);
            }

            if ($cellWidthEntry->value <= 0) {
                throw new ParseError(sprintf('CellWidth must be > 0, got %d.', $cellWidthEntry->value), 1801);
            }
        }

        if ($hasCellLength) {
            if (($cellLengthEntry->type !== TiffConst::TYPE_SHORT) || ($cellLengthEntry->count !== 1) || !is_int($cellLengthEntry->value)) {
                throw new ParseError('CellLength must be SHORT[1].', 1802);
            }

            if ($cellLengthEntry->value <= 0) {
                throw new ParseError(sprintf('CellLength must be > 0, got %d.', $cellLengthEntry->value), 1803);
            }
        }

        $threshholdingValue = $threshholdingEntry instanceof IfdEntry
            ? $threshholdingEntry->value
            : null;

        if (($threshholdingValue === 2) && (!$hasCellWidth || !$hasCellLength)) {
            throw new ParseError('Threshholding=2 requires both CellWidth and CellLength.', 1804);
        }

        if (($hasCellWidth || $hasCellLength) && ($threshholdingValue !== 2)) {
            throw new ParseError(
                sprintf(
                    'CellWidth/CellLength are only valid when Threshholding=2, got %s.',
                    $threshholdingValue !== null ? (string) $threshholdingValue : 'missing',
                ),
                1805,
            );
        }
    }

    /**
     * Validates TIFF XPosition/YPosition semantic constraints.
     *
     * TIFF 6.0:
     * - XPosition/YPosition are RATIONAL[1].
     * - Rational denominator must be non-zero.
     * - YPosition must be strictly positive.
     */
    private function validatePositionTags(Ifd $ifd): void
    {
        $xPosition = $ifd->get(TiffTag::X_POSITION);
        $yPosition = $ifd->get(TiffTag::Y_POSITION);

        if (!($xPosition instanceof IfdEntry) && !($yPosition instanceof IfdEntry)) {
            return;
        }

        if ($xPosition instanceof IfdEntry) {
            $this->validatePositionRational($xPosition, 'XPosition');
        }

        if (!$yPosition instanceof IfdEntry) {
            return;
        }

        $yPositionRational = $this->validatePositionRational($yPosition, 'YPosition');
        $yPositionValue    = $yPositionRational->numerator / $yPositionRational->denominator;

        if ($yPositionValue <= 0.0) {
            throw new ParseError(
                sprintf('YPosition must be > 0, got %.6F.', $yPositionValue),
                1808,
            );
        }
    }

    /**
     * Validates a position tag as RATIONAL[1] with non-zero denominator.
     */
    private function validatePositionRational(IfdEntry $entry, string $tagName): ExifRational
    {
        if (
            ($entry->type !== TiffConst::TYPE_RATIONAL)
            || ($entry->count !== 1)
            || !($entry->value instanceof ExifRational)
        ) {
            throw new ParseError(
                sprintf('%s must be RATIONAL[1].', $tagName),
                1806,
            );
        }

        if ($entry->value->denominator === 0) {
            throw new ParseError(
                sprintf('%s denominator must be non-zero.', $tagName),
                1807,
            );
        }

        return $entry->value;
    }

    /**
     * Validates paired TIFF free-space bookkeeping tags.
     *
     * TIFF 6.0 defines FreeOffsets (Tag 288) and FreeByteCounts (Tag 289) as a
     * paired map where each offset points to a free-byte range with a matching
     * positive byte-count entry.
     */
    private function validateFreeSpaceTags(Ifd $ifd): void
    {
        $freeOffsetsEntry    = $ifd->get(TiffTag::FREE_OFFSETS);
        $freeByteCountsEntry = $ifd->get(TiffTag::FREE_BYTE_COUNTS);

        if (!($freeOffsetsEntry instanceof IfdEntry) && !($freeByteCountsEntry instanceof IfdEntry)) {
            return;
        }

        if (!($freeOffsetsEntry instanceof IfdEntry) || !($freeByteCountsEntry instanceof IfdEntry)) {
            throw new ParseError('FreeOffsets and FreeByteCounts must both be present', 1809);
        }

        $freeOffsets    = $this->extractFreeSpaceComponents($freeOffsetsEntry, 'FreeOffsets');
        $freeByteCounts = $this->extractFreeSpaceComponents($freeByteCountsEntry, 'FreeByteCounts');

        if (count($freeOffsets) !== count($freeByteCounts)) {
            throw new ParseError(
                sprintf(
                    'FreeOffsets count %d must match FreeByteCounts count %d',
                    count($freeOffsets),
                    count($freeByteCounts),
                ),
                1810,
            );
        }

        $fileSize = $this->buffer->size();

        foreach ($freeOffsets as $index => $offset) {
            $byteCount = $freeByteCounts[$index] ?? 0;

            if ($byteCount <= 0) {
                throw new ParseError(
                    sprintf('FreeByteCounts index %d must be > 0', $index),
                    1811,
                );
            }

            if (($offset > $fileSize) || ($offset > PHP_INT_MAX - $byteCount)) {
                throw new ParseError(
                    sprintf('Free-space range index %d exceeds TIFF data length', $index),
                    1812,
                );
            }

            if (($offset + $byteCount) > $fileSize) {
                throw new ParseError(
                    sprintf('Free-space range index %d exceeds TIFF data length', $index),
                    1813,
                );
            }
        }
    }

    /**
     * Extracts validated integer components for a free-space bookkeeping tag.
     *
     * @return list<int>
     */
    private function extractFreeSpaceComponents(IfdEntry $entry, string $tagName): array
    {
        if ($entry->type !== TiffConst::TYPE_LONG && $entry->type !== TiffConst::TYPE_LONG8) {
            throw new ParseError(
                sprintf('%s must use LONG/LONG8 type.', $tagName),
                1814,
            );
        }

        if ($entry->count < 1) {
            throw new ParseError(
                sprintf('%s must contain at least one value.', $tagName),
                1815,
            );
        }

        $components = $this->extractIntegerTagComponents($entry, $tagName);

        if (count($components) !== $entry->count) {
            throw new ParseError(
                sprintf('%s value count does not match declared component count.', $tagName),
                1816,
            );
        }

        foreach ($components as $index => $component) {
            if ($component >= 0) {
                continue;
            }

            throw new ParseError(
                sprintf('%s index %d must be >= 0', $tagName, $index),
                1817,
            );
        }

        return $components;
    }

    /**
     * Validates TIFF MinSampleValue/MaxSampleValue structure and component ranges.
     *
     * TIFF 6.0 defines MinSampleValue/MaxSampleValue as SHORT vectors whose count
     * matches SamplesPerPixel and whose values are constrained by BitsPerSample.
     */
    private function validateMinMaxSampleValueTags(Ifd $ifd): void
    {
        $minSampleValueEntry = $ifd->get(TiffTag::MIN_SAMPLE_VALUE);
        $maxSampleValueEntry = $ifd->get(TiffTag::MAX_SAMPLE_VALUE);

        if (!($minSampleValueEntry instanceof IfdEntry) && !($maxSampleValueEntry instanceof IfdEntry)) {
            return;
        }

        $samplesPerPixel = 1;
        $samplesEntry    = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);
        if (($samplesEntry instanceof IfdEntry) && is_int($samplesEntry->value) && ($samplesEntry->value > 0)) {
            $samplesPerPixel = $samplesEntry->value;
        }

        $minSampleValues = null;
        $maxSampleValues = null;

        if ($minSampleValueEntry instanceof IfdEntry) {
            if ($minSampleValueEntry->type !== TiffConst::TYPE_SHORT) {
                throw new ParseError('MinSampleValue must be SHORT.', 1818);
            }

            if ($minSampleValueEntry->count !== $samplesPerPixel) {
                throw new ParseError(
                    sprintf(
                        'MinSampleValue count %d must match SamplesPerPixel %d.',
                        $minSampleValueEntry->count,
                        $samplesPerPixel,
                    ),
                    1819,
                );
            }

            $minSampleValues = $this->extractIntegerTagComponents($minSampleValueEntry, 'MinSampleValue');
            $this->validateMinMaxValueRangeAgainstBitsPerSample($ifd, 'MinSampleValue', $minSampleValues);
        }

        if ($maxSampleValueEntry instanceof IfdEntry) {
            if ($maxSampleValueEntry->type !== TiffConst::TYPE_SHORT) {
                throw new ParseError('MaxSampleValue must be SHORT.', 1820);
            }

            if ($maxSampleValueEntry->count !== $samplesPerPixel) {
                throw new ParseError(
                    sprintf(
                        'MaxSampleValue count %d must match SamplesPerPixel %d.',
                        $maxSampleValueEntry->count,
                        $samplesPerPixel,
                    ),
                    1821,
                );
            }

            $maxSampleValues = $this->extractIntegerTagComponents($maxSampleValueEntry, 'MaxSampleValue');
            $this->validateMinMaxValueRangeAgainstBitsPerSample($ifd, 'MaxSampleValue', $maxSampleValues);
        }

        if (($minSampleValues === null) || ($maxSampleValues === null)) {
            return;
        }

        foreach ($minSampleValues as $componentIndex => $minSampleValue) {
            $maxSampleValue = $maxSampleValues[$componentIndex] ?? null;
            if ($maxSampleValue === null) {
                continue;
            }

            if ($minSampleValue <= $maxSampleValue) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    'MinSampleValue component %d must be <= MaxSampleValue component %d.',
                    $componentIndex,
                    $componentIndex,
                ),
                1822,
            );
        }
    }

    /**
     * Validates MinSampleValue/MaxSampleValue components against BitsPerSample domain.
     *
     * @param list<int> $values
     */
    private function validateMinMaxValueRangeAgainstBitsPerSample(Ifd $ifd, string $tagName, array $values): void
    {
        $bitsPerSampleEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);
        if (!$bitsPerSampleEntry instanceof IfdEntry || ($bitsPerSampleEntry->type !== TiffConst::TYPE_SHORT)) {
            return;
        }

        $bitsPerSampleValues = $this->extractIntegerTagComponents($bitsPerSampleEntry, 'BitsPerSample');
        if ($bitsPerSampleValues === []) {
            return;
        }

        foreach ($values as $componentIndex => $value) {
            $bitsPerSample = $bitsPerSampleValues[0];
            if (count($bitsPerSampleValues) > 1) {
                if (!isset($bitsPerSampleValues[$componentIndex])) {
                    continue;
                }

                $bitsPerSample = $bitsPerSampleValues[$componentIndex];
            }

            if ($bitsPerSample >= 16) {
                continue;
            }

            if ($bitsPerSample <= 0) {
                throw new ParseError(
                    sprintf(
                        'BitsPerSample component %d must be > 0 when validating %s.',
                        $componentIndex,
                        $tagName,
                    ),
                    1823,
                );
            }

            $maxValue = (1 << $bitsPerSample) - 1;
            if ($value <= $maxValue) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    '%s component %d value %d exceeds %d-bit range 0..%d.',
                    $tagName,
                    $componentIndex,
                    $value,
                    $bitsPerSample,
                    $maxValue,
                ),
                1824,
            );
        }
    }

    /**
     * Validates Predictor semantic coupling to Compression.
     *
     * TIFF 6.0 Section 14 defines Predictor values {1,2} and describes horizontal
     * differencing (value 2) for LZW-compressed data.
     */
    private function validatePredictorTag(Ifd $ifd): void
    {
        $predictor = $ifd->get(TiffTag::PREDICTOR);
        if (!($predictor instanceof IfdEntry) || !is_int($predictor->value) || ($predictor->value !== 2)) {
            return;
        }

        $compression = $ifd->get(ExifTag::COMPRESSION);
        if (($compression instanceof IfdEntry) && is_int($compression->value) && ($compression->value === Compression::LZW->value)) {
            return;
        }

        throw new ParseError('Predictor=2 requires Compression=5 (LZW) per TIFF 6.0 Section 14.', 1825);
    }

    /**
     * Validates JPEGProc structural and cross-tag compression coupling rules.
     *
     * TIFF 6.0 Section 22 (JPEG Fields) defines JPEGProc as SHORT[1] with values
     * {1,14}, mandatory for JPEG-compressed image data and invalid otherwise.
     */
    private function validateJpegProcTag(Ifd $ifd): void
    {
        $jpegProc    = $ifd->get(TiffTag::JPEG_PROC);
        $compression = $ifd->get(ExifTag::COMPRESSION);

        $isJpegCompression = ($compression instanceof IfdEntry)
            && is_int($compression->value)
            && ($compression->value === Compression::JPEG->value);

        if ($jpegProc instanceof IfdEntry) {
            if (($jpegProc->type !== TiffConst::TYPE_SHORT) || ($jpegProc->count !== 1) || !is_int($jpegProc->value)) {
                throw new ParseError('JPEGProc must be SHORT[1].', 1826);
            }

            if (!in_array($jpegProc->value, [1, 14], true)) {
                throw new ParseError(
                    sprintf('JPEGProc value %d is invalid; allowed values are 1 or 14.', $jpegProc->value),
                    1827,
                );
            }

            if (!$isJpegCompression) {
                throw new ParseError('JPEGProc is only valid when Compression=6 (JPEG).', 1828);
            }

            return;
        }

        if ($isJpegCompression) {
            throw new ParseError('Compression=6 requires JPEGProc per TIFF 6.0 Section 22.', 1829);
        }
    }

    /**
     * Validates lossless JPEG predictor/point-transform semantics.
     *
     * TIFF 6.0 Section 22 defines JPEGLosslessPredictors and JPEGPointTransforms
     * as SHORT arrays with count SamplesPerPixel. JPEGLosslessPredictors is
     * mandatory for JPEGProc=14 and predictor values are limited to 1..7.
     * JPEGPointTransforms defaults to zero per component when omitted.
     */
    private function validateJpegLosslessTags(Ifd $ifd): void
    {
        $jpegProcEntry = $ifd->get(TiffTag::JPEG_PROC);
        $jpegProc      = (($jpegProcEntry instanceof IfdEntry) && is_int($jpegProcEntry->value))
            ? $jpegProcEntry->value
            : null;

        $samplesPerPixelEntry = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);
        $samplesPerPixel      = 1;

        if (($samplesPerPixelEntry instanceof IfdEntry) && is_int($samplesPerPixelEntry->value) && ($samplesPerPixelEntry->value > 0)) {
            $samplesPerPixel = $samplesPerPixelEntry->value;
        }

        $losslessPredictorsEntry = $ifd->get(TiffTag::JPEG_LOSSLESS_PREDICTORS);
        if ($losslessPredictorsEntry instanceof IfdEntry) {
            if (
                ($losslessPredictorsEntry->type !== TiffConst::TYPE_SHORT)
                || ($losslessPredictorsEntry->count !== $samplesPerPixel)
            ) {
                throw new ParseError('JPEGLosslessPredictors must be SHORT[SamplesPerPixel].', 1836);
            }

            $predictorValues = $this->extractIntegerTagComponents($losslessPredictorsEntry, 'JPEGLosslessPredictors');
            foreach ($predictorValues as $componentIndex => $predictorValue) {
                if (($predictorValue >= 1) && ($predictorValue <= 7)) {
                    continue;
                }

                throw new ParseError(
                    sprintf(
                        'JPEGLosslessPredictors component %d value %d is invalid; allowed values are 1..7.',
                        $componentIndex,
                        $predictorValue,
                    ),
                    1837,
                );
            }
        }

        $pointTransformsEntry = $ifd->get(TiffTag::JPEG_POINT_TRANSFORMS);
        if ($pointTransformsEntry instanceof IfdEntry) {
            if (
                ($pointTransformsEntry->type !== TiffConst::TYPE_SHORT)
                || ($pointTransformsEntry->count !== $samplesPerPixel)
            ) {
                throw new ParseError('JPEGPointTransforms must be SHORT[SamplesPerPixel].', 1838);
            }

            $this->extractIntegerTagComponents($pointTransformsEntry, 'JPEGPointTransforms');
        }

        if ($jpegProc === 14) {
            if (!$losslessPredictorsEntry instanceof IfdEntry) {
                throw new ParseError('JPEGProc=14 requires JPEGLosslessPredictors.', 1839);
            }

            return;
        }

        if ($losslessPredictorsEntry instanceof IfdEntry) {
            throw new ParseError('JPEGLosslessPredictors is only valid when JPEGProc=14.', 1840);
        }

        if ($pointTransformsEntry instanceof IfdEntry) {
            throw new ParseError('JPEGPointTransforms is only valid when JPEGProc=14.', 1841);
        }
    }

    /**
     * Validates JPEG table offset tags and process-specific requirements.
     *
     * TIFF 6.0 Section 22 defines JPEGQTables, JPEGDCTables and JPEGACTables as
     * LONG arrays with count SamplesPerPixel whose values are offsets within the
     * TIFF blob. Mandatory fields depend on the JPEG process (JPEGProc).
     */
    private function validateJpegTableTags(Ifd $ifd): void
    {
        $jpegProcEntry = $ifd->get(TiffTag::JPEG_PROC);
        $jpegProc      = (($jpegProcEntry instanceof IfdEntry) && is_int($jpegProcEntry->value))
            ? $jpegProcEntry->value
            : null;

        $samplesPerPixelEntry = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);
        $samplesPerPixel      = 1;

        if (($samplesPerPixelEntry instanceof IfdEntry) && is_int($samplesPerPixelEntry->value) && ($samplesPerPixelEntry->value > 0)) {
            $samplesPerPixel = $samplesPerPixelEntry->value;
        }

        $jpegQTablesEntry  = $ifd->get(TiffTag::JPEG_Q_TABLES);
        $jpegDcTablesEntry = $ifd->get(TiffTag::JPEG_DC_TABLES);
        $jpegAcTablesEntry = $ifd->get(TiffTag::JPEG_AC_TABLES);

        if ($jpegQTablesEntry instanceof IfdEntry) {
            if (($jpegQTablesEntry->type !== TiffConst::TYPE_LONG) || ($jpegQTablesEntry->count !== $samplesPerPixel)) {
                throw new ParseError('JPEGQTables must be LONG[SamplesPerPixel].', 1842);
            }

            $this->validateJpegTableOffsets($jpegQTablesEntry, 'JPEGQTables');
        }

        if ($jpegDcTablesEntry instanceof IfdEntry) {
            if (($jpegDcTablesEntry->type !== TiffConst::TYPE_LONG) || ($jpegDcTablesEntry->count !== $samplesPerPixel)) {
                throw new ParseError('JPEGDCTables must be LONG[SamplesPerPixel].', 1843);
            }

            $this->validateJpegTableOffsets($jpegDcTablesEntry, 'JPEGDCTables');
        }

        if ($jpegAcTablesEntry instanceof IfdEntry) {
            if (($jpegAcTablesEntry->type !== TiffConst::TYPE_LONG) || ($jpegAcTablesEntry->count !== $samplesPerPixel)) {
                throw new ParseError('JPEGACTables must be LONG[SamplesPerPixel].', 1844);
            }

            $this->validateJpegTableOffsets($jpegAcTablesEntry, 'JPEGACTables');
        }

        $hasJpegTableTags = ($jpegQTablesEntry instanceof IfdEntry)
            || ($jpegDcTablesEntry instanceof IfdEntry)
            || ($jpegAcTablesEntry instanceof IfdEntry);

        if (!$hasJpegTableTags) {
            return;
        }

        if ($jpegProc === 1) {
            if (!$jpegDcTablesEntry instanceof IfdEntry) {
                throw new ParseError('JPEGDCTables is required when JPEGProc=1.', 1845);
            }

            if (!($jpegQTablesEntry instanceof IfdEntry) || !($jpegAcTablesEntry instanceof IfdEntry)) {
                throw new ParseError('JPEGQTables and JPEGACTables are required when JPEGProc=1.', 1846);
            }

            return;
        }

        if ($jpegProc === 14) {
            if (!$jpegDcTablesEntry instanceof IfdEntry) {
                throw new ParseError('JPEGDCTables is required when JPEGProc=14.', 1847);
            }

            if ($jpegAcTablesEntry instanceof IfdEntry) {
                throw new ParseError('JPEGACTables are not used when JPEGProc=14.', 1848);
            }

            return;
        }

        throw new ParseError('JPEG table tags are only valid when JPEGProc is 1 or 14.', 1849);
    }

    /**
     * Validates that all JPEG table offsets point inside the TIFF blob.
     *
     * TIFF 6.0 Section 22 uses LONG offsets for JPEG table pointers.
     */
    private function validateJpegTableOffsets(IfdEntry $entry, string $tagName): void
    {
        $tableOffsets = $this->extractIntegerTagComponents($entry, $tagName);
        $blobSize     = $this->buffer->size();

        foreach ($tableOffsets as $componentIndex => $tableOffset) {
            if (($tableOffset > 0) && ($tableOffset < $blobSize)) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    '%s component %d offset %d is outside TIFF bounds 1..%d.',
                    $tagName,
                    $componentIndex,
                    $tableOffset,
                    $blobSize - 1,
                ),
                1850,
            );
        }
    }

    /**
     * Validates JPEGInterchangeFormat/JPEGInterchangeFormatLength pair semantics.
     *
     * TIFF 6.0 Section 22 defines these fields as a coupled offset/length pair
     * for embedded JPEG interchange streams.
     */
    private function validateJpegInterchangePairTags(Ifd $ifd): void
    {
        $offsetEntry = $ifd->get(ExifTag::JPEG_INTERCHANGE_FORMAT);
        $lengthEntry = $ifd->get(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);

        if (!($offsetEntry instanceof IfdEntry) && !($lengthEntry instanceof IfdEntry)) {
            return;
        }

        if ($lengthEntry instanceof IfdEntry && !($offsetEntry instanceof IfdEntry)) {
            throw new ParseError(
                'JPEGInterchangeFormatLength requires JPEGInterchangeFormat.',
                1830,
            );
        }

        if (!($offsetEntry instanceof IfdEntry) || !is_int($offsetEntry->value)) {
            throw new ParseError('JPEGInterchangeFormat must be LONG[1].', 1831);
        }

        if ($offsetEntry->value <= 0) {
            if ($lengthEntry instanceof IfdEntry) {
                throw new ParseError(
                    'JPEGInterchangeFormatLength is invalid when JPEGInterchangeFormat is zero.',
                    1832,
                );
            }

            return;
        }

        if (!($lengthEntry instanceof IfdEntry) || !is_int($lengthEntry->value)) {
            throw new ParseError(
                'Non-zero JPEGInterchangeFormat requires JPEGInterchangeFormatLength.',
                1833,
            );
        }

        if ($lengthEntry->value <= 0) {
            throw new ParseError(
                'JPEGInterchangeFormatLength must be > 0 when JPEGInterchangeFormat is non-zero.',
                1834,
            );
        }

        $blobSize = $this->buffer->size();
        if (
            ($offsetEntry->value > $blobSize)
            || ($lengthEntry->value > $blobSize)
            || ($offsetEntry->value > ($blobSize - $lengthEntry->value))
        ) {
            throw new ParseError('JPEGInterchangeFormat range exceeds TIFF data length.', 1835);
        }
    }

    /**
     * Validates TIFF SampleFormat / SMinSampleValue / SMaxSampleValue consistency.
     *
     * TIFF 6.0 §19:
     * - SampleFormat: SHORT[SamplesPerPixel], values {1,2,3,4}.
     * - SMinSampleValue/SMaxSampleValue: count = SamplesPerPixel.
     * - SMin/SMax types should match the declared sample representation.
     * - Per component, SMin must not exceed SMax.
     */
    private function validateSampleDomainTags(Ifd $ifd): void
    {
        $sampleFormatEntry = $ifd->get(TiffTag::SAMPLE_FORMAT);
        $sMinEntry         = $ifd->get(TiffTag::S_MIN_SAMPLE_VALUE);
        $sMaxEntry         = $ifd->get(TiffTag::S_MAX_SAMPLE_VALUE);

        if (
            !($sampleFormatEntry instanceof IfdEntry)
            && !($sMinEntry instanceof IfdEntry)
            && !($sMaxEntry instanceof IfdEntry)
        ) {
            return;
        }

        $samplesPerPixel = 1;
        $samplesEntry    = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);
        if (($samplesEntry instanceof IfdEntry) && is_int($samplesEntry->value) && ($samplesEntry->value > 0)) {
            $samplesPerPixel = $samplesEntry->value;
        }

        $sampleFormats = null;

        if ($sampleFormatEntry instanceof IfdEntry) {
            if ($sampleFormatEntry->type !== TiffConst::TYPE_SHORT) {
                throw new ParseError('SampleFormat must use SHORT type.', 1756);
            }

            if ($sampleFormatEntry->count !== $samplesPerPixel) {
                throw new ParseError(
                    sprintf(
                        'SampleFormat count %d must match SamplesPerPixel %d.',
                        $sampleFormatEntry->count,
                        $samplesPerPixel,
                    ),
                    1757,
                );
            }

            $sampleFormats = $this->extractIntegerTagComponents($sampleFormatEntry, 'SampleFormat');

            foreach ($sampleFormats as $componentIndex => $sampleFormat) {
                if (!in_array($sampleFormat, [1, 2, 3, 4], true)) {
                    throw new ParseError(
                        sprintf(
                            'SampleFormat component %d value %d is invalid; allowed values are 1,2,3,4.',
                            $componentIndex,
                            $sampleFormat,
                        ),
                        1758,
                    );
                }
            }
        }

        $sMinValues = null;
        if ($sMinEntry instanceof IfdEntry) {
            if ($sMinEntry->count !== $samplesPerPixel) {
                throw new ParseError(
                    sprintf(
                        'SMinSampleValue count %d must match SamplesPerPixel %d.',
                        $sMinEntry->count,
                        $samplesPerPixel,
                    ),
                    1759,
                );
            }

            $sMinValues = $this->extractNumericTagComponents($sMinEntry, 'SMinSampleValue');
        }

        $sMaxValues = null;
        if ($sMaxEntry instanceof IfdEntry) {
            if ($sMaxEntry->count !== $samplesPerPixel) {
                throw new ParseError(
                    sprintf(
                        'SMaxSampleValue count %d must match SamplesPerPixel %d.',
                        $sMaxEntry->count,
                        $samplesPerPixel,
                    ),
                    1760,
                );
            }

            $sMaxValues = $this->extractNumericTagComponents($sMaxEntry, 'SMaxSampleValue');
        }

        if ($sampleFormats !== null && ($sMinEntry instanceof IfdEntry)) {
            $this->validateSampleDomainTypeCompatibility('SMinSampleValue', $sMinEntry->type, $sampleFormats);
        }

        if ($sampleFormats !== null && ($sMaxEntry instanceof IfdEntry)) {
            $this->validateSampleDomainTypeCompatibility('SMaxSampleValue', $sMaxEntry->type, $sampleFormats);
        }

        if (($sMinValues === null) || ($sMaxValues === null)) {
            return;
        }

        foreach ($sMinValues as $componentIndex => $sMinValue) {
            $sMaxValue = $sMaxValues[$componentIndex] ?? null;
            if ($sMaxValue === null) {
                continue;
            }

            if ($sMinValue <= $sMaxValue) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    'SMinSampleValue component %d must be <= SMaxSampleValue, got %.6F > %.6F.',
                    $componentIndex,
                    $sMinValue,
                    $sMaxValue,
                ),
                1761,
            );
        }
    }

    /**
     * @return list<int>
     */
    private function extractIntegerTagComponents(IfdEntry $entry, string $tagName): array
    {
        $numericComponents = $this->extractNumericTagComponents($entry, $tagName);
        $integerComponents = [];

        foreach ($numericComponents as $componentIndex => $numericComponent) {
            if ((float) (int) $numericComponent !== $numericComponent) {
                throw new ParseError(
                    sprintf(
                        '%s component %d must be an integer, got %.6F.',
                        $tagName,
                        $componentIndex,
                        $numericComponent,
                    ),
                    1762,
                );
            }

            $integerComponents[] = (int) $numericComponent;
        }

        return $integerComponents;
    }

    /**
     * @return list<float>
     */
    private function extractNumericTagComponents(IfdEntry $entry, string $tagName): array
    {
        if (is_int($entry->value) || is_float($entry->value)) {
            return [(float) $entry->value];
        }

        if ($entry->value instanceof ExifNumericList) {
            $components = [];

            foreach ($entry->value->values as $component) {
                if (is_int($component) || is_float($component)) {
                    $components[] = (float) $component;
                    continue;
                }

                throw new ParseError(
                    sprintf('%s contains unsupported non-numeric component type.', $tagName),
                    1763,
                );
            }

            return $components;
        }

        throw new ParseError(
            sprintf('%s must decode to numeric components.', $tagName),
            1764,
        );
    }

    /**
     * @param list<int> $sampleFormats
     */
    private function validateSampleDomainTypeCompatibility(string $tagName, int $tagType, array $sampleFormats): void
    {
        foreach ($sampleFormats as $componentIndex => $sampleFormat) {
            $compatible = match ($sampleFormat) {
                // Unsigned integer samples.
                1 => in_array($tagType, [TiffConst::TYPE_BYTE, TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG, TiffConst::TYPE_LONG8], true),
                // Signed integer samples.
                2 => in_array($tagType, [TiffConst::TYPE_SBYTE, TiffConst::TYPE_SSHORT, TiffConst::TYPE_SLONG, TiffConst::TYPE_SLONG8], true),
                // Floating-point samples.
                3 => in_array($tagType, [TiffConst::TYPE_FLOAT, TiffConst::TYPE_DOUBLE], true),
                // Undefined samples do not constrain min/max type.
                4       => true,
                default => false,
            };

            if ($compatible) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    '%s type %d is incompatible with SampleFormat component %d value %d.',
                    $tagName,
                    $tagType,
                    $componentIndex,
                    $sampleFormat,
                ),
                1765,
            );
        }
    }

    /**
     * Validates TIFF 6.0 baseline ExtraSamples semantics.
     *
     * TIFF 6.0 baseline profile:
     * - ExtraSamples (Tag 338) must be SHORT[1]
     * - Value must be 1 (associated alpha)
     */
    private function validateExtraSamplesTag(Ifd $ifd): void
    {
        $extraSamplesEntry = $ifd->get(TiffTag::EXTRA_SAMPLES);

        if (!$extraSamplesEntry instanceof IfdEntry) {
            return;
        }

        if (
            ($extraSamplesEntry->type !== TiffConst::TYPE_SHORT)
            || ($extraSamplesEntry->count !== 1)
            || !is_int($extraSamplesEntry->value)
        ) {
            throw new ParseError('ExtraSamples must be SHORT[1].', 1766);
        }

        if ($extraSamplesEntry->value !== 1) {
            throw new ParseError(
                sprintf(
                    'ExtraSamples value %d is invalid; strict TIFF 6.0 baseline requires value 1.',
                    $extraSamplesEntry->value,
                ),
                1767,
            );
        }
    }

    /**
     * Validates TIFF gray-response tags GrayResponseUnit and GrayResponseCurve.
     *
     * TIFF 6.0:
     * - GrayResponseUnit: SHORT[1], value domain 1..5.
     * - GrayResponseCurve: SHORT, count = 1 << BitsPerSample.
     * - Tags apply to grayscale photometric modes (WhiteIsZero/BlackIsZero).
     */
    private function validateGrayResponseTags(Ifd $ifd): void
    {
        $grayResponseUnit  = $ifd->get(TiffTag::GRAY_RESPONSE_UNIT);
        $grayResponseCurve = $ifd->get(TiffTag::GRAY_RESPONSE_CURVE);

        if (!($grayResponseUnit instanceof IfdEntry) && !($grayResponseCurve instanceof IfdEntry)) {
            return;
        }

        $photometricEntry = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        $photometricCode  = (($photometricEntry instanceof IfdEntry) && is_int($photometricEntry->value))
            ? $photometricEntry->value
            : null;

        if (!in_array($photometricCode, [0, 1], true)) {
            throw new ParseError(
                sprintf(
                    'GrayResponse tags are only valid for grayscale PhotometricInterpretation {0,1}, got %s.',
                    $photometricCode !== null ? (string) $photometricCode : 'missing',
                ),
                1768,
            );
        }

        if ($grayResponseUnit instanceof IfdEntry) {
            if (
                ($grayResponseUnit->type !== TiffConst::TYPE_SHORT)
                || ($grayResponseUnit->count !== 1)
                || !is_int($grayResponseUnit->value)
            ) {
                throw new ParseError('GrayResponseUnit must be SHORT[1].', 1769);
            }

            if (($grayResponseUnit->value < 1) || ($grayResponseUnit->value > 5)) {
                throw new ParseError(
                    sprintf(
                        'GrayResponseUnit value %d is outside the valid domain 1..5.',
                        $grayResponseUnit->value,
                    ),
                    1770,
                );
            }
        }

        if (!$grayResponseCurve instanceof IfdEntry) {
            return;
        }

        if ($grayResponseCurve->type !== TiffConst::TYPE_SHORT) {
            throw new ParseError(
                sprintf('GrayResponseCurve must use SHORT type, got type %d.', $grayResponseCurve->type),
                1771,
            );
        }

        $bitsPerSample = $this->resolveGrayResponseBitsPerSample($ifd);
        $expectedCount = 2 ** $bitsPerSample;

        if ($grayResponseCurve->count !== $expectedCount) {
            throw new ParseError(
                sprintf(
                    'GrayResponseCurve count %d must be 1<<BitsPerSample (%d).',
                    $grayResponseCurve->count,
                    $expectedCount,
                ),
                1772,
            );
        }
    }

    /**
     * Resolves a uniform BitsPerSample scalar for gray-response count rules.
     */
    private function resolveGrayResponseBitsPerSample(Ifd $ifd): int
    {
        $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

        if (!$bitsEntry instanceof IfdEntry) {
            throw new ParseError('GrayResponseCurve requires BitsPerSample.', 1773);
        }

        $bitDepths = [];

        if (is_int($bitsEntry->value)) {
            $bitDepths[] = $bitsEntry->value;
        } elseif ($bitsEntry->value instanceof ExifNumericList) {
            foreach ($bitsEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('BitsPerSample must decode to integer components for GrayResponseCurve.', 1774);
                }

                $bitDepths[] = $component;
            }
        } else {
            throw new ParseError('BitsPerSample must decode to integer components for GrayResponseCurve.', 1774);
        }

        if ($bitDepths === []) {
            throw new ParseError('BitsPerSample must provide at least one value for GrayResponseCurve.', 1775);
        }

        $uniformBitDepth = $bitDepths[0];

        foreach ($bitDepths as $index => $bitDepth) {
            if ($bitDepth <= 0) {
                throw new ParseError(
                    sprintf('BitsPerSample component %d must be >= 1 for GrayResponseCurve.', $index),
                    1776,
                );
            }

            if ($bitDepth !== $uniformBitDepth) {
                throw new ParseError(
                    sprintf(
                        'GrayResponseCurve requires uniform BitsPerSample values; component 0=%d, component %d=%d.',
                        $uniformBitDepth,
                        $index,
                        $bitDepth,
                    ),
                    1777,
                );
            }
        }

        if ($uniformBitDepth > 16) {
            throw new ParseError(
                sprintf('GrayResponseCurve does not support BitsPerSample=%d (>16).', $uniformBitDepth),
                1778,
            );
        }

        return $uniformBitDepth;
    }

    /**
     * Validates HalftoneHints value range against BitsPerSample.
     *
     * TIFF 6.0 §17:
     * - HalftoneHints is SHORT[2].
     * - Both hint values are gray codes within [0, (1<<BitsPerSample)-1].
     */
    private function validateHalftoneHintsTag(Ifd $ifd): void
    {
        $halftoneHintsEntry = $ifd->get(TiffTag::HALFTONE_HINTS);

        if (!$halftoneHintsEntry instanceof IfdEntry) {
            return;
        }

        if (
            ($halftoneHintsEntry->type !== TiffConst::TYPE_SHORT)
            || ($halftoneHintsEntry->count !== 2)
        ) {
            throw new ParseError('HalftoneHints must be SHORT[2].', 1779);
        }

        $components = $this->extractIntegerTagComponents($halftoneHintsEntry, 'HalftoneHints');

        if (count($components) !== 2) {
            throw new ParseError(
                sprintf('HalftoneHints expected 2 components, decoded %d.', count($components)),
                1780,
            );
        }

        $bitsPerSample = $this->resolveHalftoneBitsPerSample($ifd);
        $maxValue      = (2 ** $bitsPerSample) - 1;

        foreach ($components as $componentIndex => $componentValue) {
            if (($componentValue >= 0) && ($componentValue <= $maxValue)) {
                continue;
            }

            throw new ParseError(
                sprintf(
                    'HalftoneHints component %d value %d exceeds max %d for BitsPerSample=%d.',
                    $componentIndex,
                    $componentValue,
                    $maxValue,
                    $bitsPerSample,
                ),
                1781,
            );
        }
    }

    /**
     * Resolves uniform BitsPerSample for HalftoneHints range checks.
     */
    private function resolveHalftoneBitsPerSample(Ifd $ifd): int
    {
        $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

        if (!$bitsEntry instanceof IfdEntry) {
            throw new ParseError('HalftoneHints validation requires BitsPerSample.', 1782);
        }

        $bitDepths = [];

        if (is_int($bitsEntry->value)) {
            $bitDepths[] = $bitsEntry->value;
        } elseif ($bitsEntry->value instanceof ExifNumericList) {
            foreach ($bitsEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('BitsPerSample must decode to integer components for HalftoneHints.', 1783);
                }

                $bitDepths[] = $component;
            }
        } else {
            throw new ParseError('BitsPerSample must decode to integer components for HalftoneHints.', 1783);
        }

        if ($bitDepths === []) {
            throw new ParseError('BitsPerSample must provide at least one value for HalftoneHints.', 1784);
        }

        $uniformBitDepth = $bitDepths[0];

        foreach ($bitDepths as $index => $bitDepth) {
            if ($bitDepth <= 0) {
                throw new ParseError(
                    sprintf('BitsPerSample component %d must be >= 1 for HalftoneHints.', $index),
                    1785,
                );
            }

            if ($bitDepth !== $uniformBitDepth) {
                throw new ParseError(
                    sprintf(
                        'HalftoneHints requires uniform BitsPerSample values; component 0=%d, component %d=%d.',
                        $uniformBitDepth,
                        $index,
                        $bitDepth,
                    ),
                    1786,
                );
            }
        }

        if ($uniformBitDepth > 16) {
            throw new ParseError(
                sprintf('HalftoneHints does not support BitsPerSample=%d (>16).', $uniformBitDepth),
                1787,
            );
        }

        return $uniformBitDepth;
    }

    /**
     * Validates TIFF separated-image ink tag semantics for PhotometricInterpretation=5.
     *
     * TIFF 6.0 separated images:
     * - InkSet: SHORT[1], domain {1,2}, default 1.
     * - NumberOfInks: SHORT[1], default 4.
     * - InkNames: ASCII NUL-separated list, count must match NumberOfInks.
     *
     * Cross-tag rules:
     * - InkSet=1 (CMYK): InkNames must not be present.
     * - InkSet=2: InkNames must be present and structurally valid.
     */
    private function validateSeparatedImageInkTags(Ifd $ifd): void
    {
        $photometric = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        if (!($photometric instanceof IfdEntry) || !is_int($photometric->value) || ($photometric->value !== 5)) {
            return;
        }

        $inkSet      = 1;
        $inkSetEntry = $ifd->get(TiffTag::INK_SET);
        if ($inkSetEntry instanceof IfdEntry) {
            if (($inkSetEntry->type !== TiffConst::TYPE_SHORT) || ($inkSetEntry->count !== 1) || !is_int($inkSetEntry->value)) {
                throw new ParseError('InkSet must be SHORT[1] for separated images.', 1709);
            }

            $inkSet = $inkSetEntry->value;
        }

        if (($inkSet !== 1) && ($inkSet !== 2)) {
            throw new ParseError(
                sprintf('InkSet value %d is invalid; allowed values are 1 (CMYK) or 2 (not CMYK).', $inkSet),
                1710,
            );
        }

        $numberOfInks      = 4;
        $numberOfInksEntry = $ifd->get(TiffTag::NUMBER_OF_INKS);
        if ($numberOfInksEntry instanceof IfdEntry) {
            if (($numberOfInksEntry->type !== TiffConst::TYPE_SHORT) || ($numberOfInksEntry->count !== 1) || !is_int($numberOfInksEntry->value)) {
                throw new ParseError('NumberOfInks must be SHORT[1] when present.', 1711);
            }

            if ($numberOfInksEntry->value < 1) {
                throw new ParseError(
                    sprintf('NumberOfInks must be >= 1, got %d.', $numberOfInksEntry->value),
                    1712,
                );
            }

            $numberOfInks = $numberOfInksEntry->value;
        }

        $inkNamesEntry = $ifd->get(TiffTag::INK_NAMES);
        if ($inkSet === 1) {
            if ($inkNamesEntry instanceof IfdEntry) {
                throw new ParseError('InkNames must not be present when InkSet=1 (CMYK).', 1713);
            }

            return;
        }

        if (!($inkNamesEntry instanceof IfdEntry) || !is_string($inkNamesEntry->value)) {
            throw new ParseError('InkSet=2 requires an InkNames ASCII list.', 1714);
        }

        if ($inkNamesEntry->type !== TiffConst::TYPE_ASCII) {
            throw new ParseError('InkNames must use ASCII field type.', 1714);
        }

        $names = explode("\0", $inkNamesEntry->value);

        foreach ($names as $index => $name) {
            if ($name === '') {
                throw new ParseError(
                    sprintf('InkNames contains an empty name entry at position %d.', $index),
                    1715,
                );
            }
        }

        if (count($names) !== $numberOfInks) {
            throw new ParseError(
                sprintf('InkNames string count %d must match NumberOfInks %d.', count($names), $numberOfInks),
                1716,
            );
        }
    }

    /**
     * Validates TIFF DotRange semantics for separated images.
     *
     * TIFF 6.0 §16 (Tag 336 / DotRange):
     * - Type must be BYTE or SHORT.
     * - Count must be 2 or 2*SamplesPerPixel.
     * - Values are (black, white) pairs with black < white.
     * - Values must be within [0, (2^BitsPerSample)-1].
     */
    private function validateSeparatedImageDotRange(Ifd $ifd): void
    {
        $dotRangeEntry = $ifd->get(TiffTag::DOT_RANGE);

        if (!$dotRangeEntry instanceof IfdEntry) {
            return;
        }

        $photometric = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        if (!($photometric instanceof IfdEntry) || !is_int($photometric->value) || ($photometric->value !== 5)) {
            return;
        }

        if (($dotRangeEntry->type !== TiffConst::TYPE_BYTE) && ($dotRangeEntry->type !== TiffConst::TYPE_SHORT)) {
            throw new ParseError(
                sprintf(
                    'DotRange (tag 336) expects type BYTE or SHORT, got type %d.',
                    $dotRangeEntry->type,
                ),
                1717,
            );
        }

        $samplesPerPixel = 1;
        $samplesEntry    = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);
        if ($samplesEntry instanceof IfdEntry) {
            if (!is_int($samplesEntry->value) || ($samplesEntry->value <= 0)) {
                throw new ParseError('DotRange requires SamplesPerPixel as a positive integer.', 1718);
            }

            $samplesPerPixel = $samplesEntry->value;
        }

        $expectedPerComponentCount = 2 * $samplesPerPixel;

        if (($dotRangeEntry->count !== 2) && ($dotRangeEntry->count !== $expectedPerComponentCount)) {
            throw new ParseError(
                sprintf(
                    'DotRange count %d must be 2 or 2*SamplesPerPixel (%d).',
                    $dotRangeEntry->count,
                    $expectedPerComponentCount,
                ),
                1719,
            );
        }

        $dotRangeValues = [];

        if (is_int($dotRangeEntry->value)) {
            $dotRangeValues[] = $dotRangeEntry->value;
        } elseif ($dotRangeEntry->value instanceof ExifNumericList) {
            foreach ($dotRangeEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('DotRange values must decode to integers.', 1720);
                }

                $dotRangeValues[] = $component;
            }
        } else {
            throw new ParseError('DotRange values must decode to integers.', 1720);
        }

        if (count($dotRangeValues) !== $dotRangeEntry->count) {
            throw new ParseError(
                sprintf(
                    'DotRange expected %d values, decoded %d.',
                    $dotRangeEntry->count,
                    count($dotRangeValues),
                ),
                1721,
            );
        }

        $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);
        if (!$bitsEntry instanceof IfdEntry) {
            throw new ParseError('DotRange validation requires BitsPerSample to be present.', 1722);
        }

        $bitDepths = [];

        if (is_int($bitsEntry->value)) {
            $bitDepths = array_fill(0, $samplesPerPixel, $bitsEntry->value);
        } elseif ($bitsEntry->value instanceof ExifNumericList) {
            foreach ($bitsEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('BitsPerSample must decode to integer components.', 1723);
                }

                $bitDepths[] = $component;
            }
        } else {
            throw new ParseError('BitsPerSample must decode to integer components.', 1723);
        }

        if (count($bitDepths) === 1) {
            $bitDepths = array_fill(0, $samplesPerPixel, $bitDepths[0]);
        }

        if (count($bitDepths) !== $samplesPerPixel) {
            throw new ParseError(
                sprintf(
                    'BitsPerSample count %d must be 1 or SamplesPerPixel (%d) for DotRange checks.',
                    count($bitDepths),
                    $samplesPerPixel,
                ),
                1724,
            );
        }

        $pairCount = intdiv($dotRangeEntry->count, 2);
        for ($pairIndex = 0; $pairIndex < $pairCount; ++$pairIndex) {
            $componentIndex = $dotRangeEntry->count === 2 ? 0 : $pairIndex;
            $bitDepth       = $bitDepths[$componentIndex];

            if ($bitDepth <= 0) {
                throw new ParseError(
                    sprintf('BitsPerSample component %d must be >= 1 for DotRange validation.', $componentIndex),
                    1725,
                );
            }

            $maxValue = (2 ** $bitDepth) - 1;
            $black    = $dotRangeValues[$pairIndex * 2];
            $white    = $dotRangeValues[($pairIndex * 2) + 1];

            if ($black >= $white) {
                throw new ParseError(
                    sprintf(
                        'DotRange pair index %d requires black < white, got %d >= %d.',
                        $pairIndex,
                        $black,
                        $white,
                    ),
                    1726,
                );
            }

            if (($black < 0) || ($black > $maxValue)) {
                throw new ParseError(
                    sprintf(
                        'DotRange pair index %d black value %d exceeds max %d (BitsPerSample=%d).',
                        $pairIndex,
                        $black,
                        $maxValue,
                        $bitDepth,
                    ),
                    1727,
                );
            }

            if (($white < 0) || ($white > $maxValue)) {
                throw new ParseError(
                    sprintf(
                        'DotRange pair index %d white value %d exceeds max %d (BitsPerSample=%d).',
                        $pairIndex,
                        $white,
                        $maxValue,
                        $bitDepth,
                    ),
                    1728,
                );
            }
        }
    }

    /**
     * Validates TIFF transfer/range tag-family semantics.
     *
     * TIFF 6.0:
     * - TransferFunction (301): SHORT, count = {1 or 3} * (1 << BitsPerSample)
     *   and valid only for WhiteIsZero/BlackIsZero/RGB/Palette/YCbCr photometric modes.
     * - TransferRange (342): SHORT[6], valid only for RGB or YCbCr.
     * - ReferenceBlackWhite (532): RATIONAL[6], valid only for RGB or YCbCr.
     */
    private function validateTransferFamilyTags(Ifd $ifd): void
    {
        $transferFunction = $ifd->get(ExifTag::TRANSFER_FUNCTION);
        $transferRange    = $ifd->get(TiffTag::TRANSFER_RANGE);
        $referenceBw      = $ifd->get(ExifTag::REFERENCE_BLACK_WHITE);

        if (
            !($transferFunction instanceof IfdEntry)
            && !($transferRange instanceof IfdEntry)
            && !($referenceBw instanceof IfdEntry)
        ) {
            return;
        }

        $photometricValue = null;
        $photometricEntry = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        if (($photometricEntry instanceof IfdEntry) && is_int($photometricEntry->value)) {
            $photometricValue = $photometricEntry->value;
        }

        if ($transferFunction instanceof IfdEntry) {
            if ($transferFunction->type !== TiffConst::TYPE_SHORT) {
                throw new ParseError(
                    sprintf(
                        'TransferFunction must use SHORT type, got type %d.',
                        $transferFunction->type,
                    ),
                    1729,
                );
            }

            if (($photometricValue !== null) && !in_array($photometricValue, [0, 1, 2, 3, 6], true)) {
                throw new ParseError(
                    sprintf(
                        'TransferFunction is only valid for PhotometricInterpretation {0,1,2,3,6}, got %s.',
                        (string) $photometricValue,
                    ),
                    1730,
                );
            }

            $bitsPerSample = $this->resolveTransferFunctionBitsPerSample($ifd);
            $tableCount    = 2 ** $bitsPerSample;

            if (($transferFunction->count !== $tableCount) && ($transferFunction->count !== (3 * $tableCount))) {
                throw new ParseError(
                    sprintf(
                        'TransferFunction count %d must be %d or %d for BitsPerSample=%d.',
                        $transferFunction->count,
                        $tableCount,
                        3 * $tableCount,
                        $bitsPerSample,
                    ),
                    1731,
                );
            }
        }

        if ($transferRange instanceof IfdEntry) {
            if (($transferRange->type !== TiffConst::TYPE_SHORT) || ($transferRange->count !== 6)) {
                throw new ParseError('TransferRange must be SHORT[6].', 1732);
            }

            if (!in_array($photometricValue, [null, 2, 6], true)) {
                throw new ParseError(
                    sprintf(
                        'TransferRange is only valid for PhotometricInterpretation RGB(2) or YCbCr(6), got %s.',
                        (string) $photometricValue,
                    ),
                    1733,
                );
            }
        }

        if (!$referenceBw instanceof IfdEntry) {
            return;
        }

        if (($referenceBw->type !== TiffConst::TYPE_RATIONAL) || ($referenceBw->count !== 6)) {
            throw new ParseError('ReferenceBlackWhite must be RATIONAL[6].', 1734);
        }

        if (!in_array($photometricValue, [null, 2, 6], true)) {
            throw new ParseError(
                sprintf(
                    'ReferenceBlackWhite is only valid for PhotometricInterpretation RGB(2) or YCbCr(6), got %s.',
                    (string) $photometricValue,
                ),
                1735,
            );
        }
    }

    /**
     * Resolves the uniform BitsPerSample scalar used by TransferFunction count rules.
     */
    private function resolveTransferFunctionBitsPerSample(Ifd $ifd): int
    {
        $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

        if (!$bitsEntry instanceof IfdEntry) {
            throw new ParseError('TransferFunction requires BitsPerSample.', 1736);
        }

        $bitDepths = [];

        if (is_int($bitsEntry->value)) {
            $bitDepths[] = $bitsEntry->value;
        } elseif ($bitsEntry->value instanceof ExifNumericList) {
            foreach ($bitsEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('BitsPerSample components must be integers.', 1737);
                }

                $bitDepths[] = $component;
            }
        } else {
            throw new ParseError('BitsPerSample components must be integers.', 1737);
        }

        if ($bitDepths === []) {
            throw new ParseError('BitsPerSample must provide at least one component value.', 1738);
        }

        $uniformBitDepth = $bitDepths[0];

        foreach ($bitDepths as $index => $bitDepth) {
            if ($bitDepth <= 0) {
                throw new ParseError(
                    sprintf('BitsPerSample component %d must be >= 1.', $index),
                    1739,
                );
            }

            if ($bitDepth !== $uniformBitDepth) {
                throw new ParseError(
                    sprintf(
                        'TransferFunction requires uniform BitsPerSample values; component 0=%d, component %d=%d.',
                        $uniformBitDepth,
                        $index,
                        $bitDepth,
                    ),
                    1740,
                );
            }
        }

        if ($uniformBitDepth > 16) {
            throw new ParseError(
                sprintf('TransferFunction does not support BitsPerSample=%d (>16).', $uniformBitDepth),
                1741,
            );
        }

        return $uniformBitDepth;
    }

    /**
     * Validates TIFF ColorMap (Tag 320) palette applicability and count formula.
     *
     * TIFF 6.0 §6:
     * - ColorMap is required when PhotometricInterpretation = 3 (palette color).
     * - ColorMap type is SHORT.
     * - ColorMap count is 3 * (1 << BitsPerSample).
     * - ColorMap shall not be used for non-palette photometric modes.
     */
    private function validatePaletteColorMapTag(Ifd $ifd): void
    {
        $colorMapEntry   = $ifd->get(TiffTag::COLOR_MAP);
        $photometric     = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        $photometricCode = (($photometric instanceof IfdEntry) && is_int($photometric->value))
            ? $photometric->value
            : null;

        if ($photometricCode === 3) {
            if (!$colorMapEntry instanceof IfdEntry) {
                throw new ParseError('Palette images (PhotometricInterpretation=3) require ColorMap.', 1742);
            }

            if ($colorMapEntry->type !== TiffConst::TYPE_SHORT) {
                throw new ParseError(
                    sprintf('ColorMap must use SHORT type for palette images, got type %d.', $colorMapEntry->type),
                    1743,
                );
            }

            $bitsPerSample = $this->resolvePaletteColorMapBitsPerSample($ifd);
            $expectedCount = 3 * (2 ** $bitsPerSample);

            if ($colorMapEntry->count !== $expectedCount) {
                throw new ParseError(
                    sprintf(
                        'ColorMap count %d must be 3*(1<<BitsPerSample) = %d.',
                        $colorMapEntry->count,
                        $expectedCount,
                    ),
                    1744,
                );
            }

            return;
        }

        if (!$colorMapEntry instanceof IfdEntry) {
            return;
        }

        throw new ParseError(
            sprintf(
                'ColorMap is only valid for palette images (PhotometricInterpretation=3), got %s.',
                $photometricCode !== null ? (string) $photometricCode : 'missing',
            ),
            1745,
        );
    }

    /**
     * Resolves a uniform BitsPerSample scalar for ColorMap count validation.
     */
    private function resolvePaletteColorMapBitsPerSample(Ifd $ifd): int
    {
        $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

        if (!$bitsEntry instanceof IfdEntry) {
            throw new ParseError('ColorMap validation requires BitsPerSample.', 1746);
        }

        $bitDepths = [];

        if (is_int($bitsEntry->value)) {
            $bitDepths[] = $bitsEntry->value;
        } elseif ($bitsEntry->value instanceof ExifNumericList) {
            foreach ($bitsEntry->value->values as $component) {
                if (!is_int($component)) {
                    throw new ParseError('BitsPerSample components must be integers for ColorMap.', 1747);
                }

                $bitDepths[] = $component;
            }
        } else {
            throw new ParseError('BitsPerSample components must be integers for ColorMap.', 1747);
        }

        if ($bitDepths === []) {
            throw new ParseError('BitsPerSample must provide at least one component value.', 1748);
        }

        $uniformBitDepth = $bitDepths[0];

        foreach ($bitDepths as $index => $bitDepth) {
            if ($bitDepth <= 0) {
                throw new ParseError(
                    sprintf('BitsPerSample component %d must be >= 1 for ColorMap.', $index),
                    1749,
                );
            }

            if ($bitDepth !== $uniformBitDepth) {
                throw new ParseError(
                    sprintf(
                        'ColorMap requires uniform BitsPerSample values; component 0=%d, component %d=%d.',
                        $uniformBitDepth,
                        $index,
                        $bitDepth,
                    ),
                    1750,
                );
            }
        }

        if ($uniformBitDepth > 16) {
            throw new ParseError(
                sprintf('ColorMap does not support BitsPerSample=%d (>16).', $uniformBitDepth),
                1751,
            );
        }

        return $uniformBitDepth;
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
     * Validates tiled TIFF layout consistency for non-JPEG primary image data.
     *
     * TIFF 6.0 tiled images require TileWidth/TileLength multiples of 16 and tile
     * offset/byte-count arrays sized to TilesPerImage. For planar separate images
     * (PlanarConfiguration=2), counts are multiplied by SamplesPerPixel.
     */
    private function validateTileLayoutConsistency(Ifd $ifd0): void
    {
        $tileWidthEntry      = $ifd0->get(TiffTag::TILE_WIDTH);
        $tileLengthEntry     = $ifd0->get(TiffTag::TILE_LENGTH);
        $tileOffsetsEntry    = $ifd0->get(TiffTag::TILE_OFFSETS);
        $tileByteCountsEntry = $ifd0->get(TiffTag::TILE_BYTE_COUNTS);

        $hasTileFields = ($tileWidthEntry instanceof IfdEntry)
            || ($tileLengthEntry instanceof IfdEntry)
            || ($tileOffsetsEntry instanceof IfdEntry)
            || ($tileByteCountsEntry instanceof IfdEntry);

        if (!$hasTileFields) {
            return;
        }

        $hasStripFields = ($ifd0->get(ExifTag::ROWS_PER_STRIP) instanceof IfdEntry)
            || ($ifd0->get(ExifTag::STRIP_OFFSETS) instanceof IfdEntry)
            || ($ifd0->get(ExifTag::STRIP_BYTE_COUNTS) instanceof IfdEntry);

        if ($hasStripFields) {
            throw new ParseError(
                'Strip and tile layout tags must not be mixed in the same IFD for one image organization.',
                1694,
            );
        }

        if (
            !$tileWidthEntry instanceof IfdEntry
            || !is_int($tileWidthEntry->value)
            || ($tileWidthEntry->value <= 0)
        ) {
            throw new ParseError('TileWidth must be a positive integer when tiled layout tags are present.', 1695);
        }

        if (
            !$tileLengthEntry instanceof IfdEntry
            || !is_int($tileLengthEntry->value)
            || ($tileLengthEntry->value <= 0)
        ) {
            throw new ParseError('TileLength must be a positive integer when tiled layout tags are present.', 1696);
        }

        if (($tileWidthEntry->value % 16) !== 0) {
            throw new ParseError(
                sprintf('TileWidth %d must be an integer multiple of 16.', $tileWidthEntry->value),
                1697,
            );
        }

        if (($tileLengthEntry->value % 16) !== 0) {
            throw new ParseError(
                sprintf('TileLength %d must be an integer multiple of 16.', $tileLengthEntry->value),
                1698,
            );
        }

        if (!$tileOffsetsEntry instanceof IfdEntry || !$tileByteCountsEntry instanceof IfdEntry) {
            throw new ParseError(
                'TileOffsets and TileByteCounts must both be present for tiled image layout.',
                1699,
            );
        }

        $imageWidthEntry  = $ifd0->get(ExifTag::IMAGE_WIDTH);
        $imageLengthEntry = $ifd0->get(ExifTag::IMAGE_LENGTH);
        if (
            !$imageWidthEntry instanceof IfdEntry
            || !is_int($imageWidthEntry->value)
            || ($imageWidthEntry->value <= 0)
            || !$imageLengthEntry instanceof IfdEntry
            || !is_int($imageLengthEntry->value)
            || ($imageLengthEntry->value <= 0)
        ) {
            return;
        }

        $tilesAcross = intdiv(
            $imageWidthEntry->value + $tileWidthEntry->value - 1,
            $tileWidthEntry->value,
        );
        $tilesDown = intdiv(
            $imageLengthEntry->value + $tileLengthEntry->value - 1,
            $tileLengthEntry->value,
        );

        $tilesPerImage = $tilesAcross * $tilesDown;

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

        $expectedCount = $tilesPerImage;
        if ($planarConfiguration === 2) {
            $expectedCount *= $samplesPerPixel;
        }

        $offsetCount = $this->countStripFieldValues($tileOffsetsEntry);
        if ($offsetCount !== $expectedCount) {
            throw new ParseError(
                sprintf(
                    'TileOffsets count %d does not match expected tile count %d (TilesAcross=%d, TilesDown=%d, PlanarConfiguration=%d).',
                    $offsetCount,
                    $expectedCount,
                    $tilesAcross,
                    $tilesDown,
                    $planarConfiguration,
                ),
                1700,
            );
        }

        $byteCountCount = $this->countStripFieldValues($tileByteCountsEntry);
        if ($byteCountCount !== $expectedCount) {
            throw new ParseError(
                sprintf(
                    'TileByteCounts count %d does not match expected tile count %d (TilesAcross=%d, TilesDown=%d, PlanarConfiguration=%d).',
                    $byteCountCount,
                    $expectedCount,
                    $tilesAcross,
                    $tilesDown,
                    $planarConfiguration,
                ),
                1701,
            );
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
        DngTag::CAMERA_CALIBRATION_SIGNATURE,
        DngTag::PROFILE_CALIBRATION_SIGNATURE,
        DngTag::AS_SHOT_PROFILE_NAME,
        DngTag::PROFILE_COPYRIGHT,
        DngTag::ORIGINAL_RAW_FILE_NAME,
        DngTag::PREVIEW_APPLICATION_NAME,
        DngTag::PREVIEW_APPLICATION_VERSION,
        DngTag::PREVIEW_SETTINGS_NAME,
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

    /**
     * Validates DNG white-balance tag type and count constraints.
     *
     * AsShotNeutral: SHORT or RATIONAL, count = ColorPlanes.
     * AsShotWhiteXY: RATIONAL, count = 2.
     */
    private function validateDngWhiteBalanceLayout(Ifd $ifd): void
    {
        $cfaEntry    = $ifd->get(DngTag::CFA_PLANE_COLOR);
        $colorPlanes = $cfaEntry instanceof IfdEntry ? $cfaEntry->count : null;

        $neutral = $ifd->get(DngTag::AS_SHOT_NEUTRAL);

        if ($neutral instanceof IfdEntry) {
            $validType = $neutral->type === TiffConst::TYPE_SHORT
                || $neutral->type === TiffConst::TYPE_RATIONAL;

            if (!$validType || ($colorPlanes !== null && $neutral->count !== $colorPlanes)) {
                throw new ParseError(
                    sprintf(
                        'AsShotNeutral must be SHORT or RATIONAL with count = ColorPlanes (%s) per DNG 1.7.1.0, got type %d count %d.',
                        $colorPlanes !== null ? (string) $colorPlanes : 'unknown',
                        $neutral->type,
                        $neutral->count,
                    ),
                    1486,
                );
            }
        }

        $whiteXY = $ifd->get(DngTag::AS_SHOT_WHITE_XY);

        if ($whiteXY instanceof IfdEntry && ($whiteXY->type !== TiffConst::TYPE_RATIONAL || $whiteXY->count !== 2)) {
            throw new ParseError(
                sprintf(
                    'AsShotWhiteXY must be RATIONAL with count 2 per DNG 1.7.1.0, got type %d count %d.',
                    $whiteXY->type,
                    $whiteXY->count,
                ),
                1487,
            );
        }
    }

    /**
     * Resolves DNG ColorPlanes from available in-IFD metadata.
     *
     * DNG 1.7.1.0 defines ColorPlanes as the number of color components.
     * This parser resolves it from CfaPlaneColor count first, then
     * SamplesPerPixel when available.
     */
    private function resolveDngColorPlanes(Ifd $ifd): ?int
    {
        $cfaEntry = $ifd->get(DngTag::CFA_PLANE_COLOR);

        if (($cfaEntry instanceof IfdEntry) && ($cfaEntry->count > 0)) {
            return $cfaEntry->count;
        }

        $samplesPerPixel = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);

        if (($samplesPerPixel instanceof IfdEntry) && is_int($samplesPerPixel->value) && ($samplesPerPixel->value > 0)) {
            return $samplesPerPixel->value;
        }

        return null;
    }

    /**
     * Validates AnalogBalance (0xC627) DNG layout and gain-vector semantics.
     *
     * DNG 1.7.1.0 defines AnalogBalance as RATIONAL[ColorPlanes] with
     * positive finite gain components.
     */
    private function validateDngAnalogBalance(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::ANALOG_BALANCE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        $colorPlanes = $this->resolveDngColorPlanes($ifd);

        if (
            ($entry->type !== TiffConst::TYPE_RATIONAL)
            || (($colorPlanes !== null) && ($entry->count !== $colorPlanes))
        ) {
            throw new ParseError(
                sprintf(
                    'AnalogBalance must be RATIONAL with count = ColorPlanes (%s), got type %d count %d.',
                    $colorPlanes !== null ? (string) $colorPlanes : 'unknown',
                    $entry->type,
                    $entry->count,
                ),
                1667,
            );
        }

        if (!$entry->value instanceof ExifRationalList || count($entry->value->values) !== $entry->count) {
            throw new ParseError('AnalogBalance must decode to a rational gain vector.', 1668);
        }

        foreach ($entry->value->values as $index => $component) {
            if ($component->denominator <= 0) {
                throw new ParseError(
                    sprintf('AnalogBalance component %d denominator must be > 0.', $index),
                    1669,
                );
            }

            $gain = $component->numerator / $component->denominator;

            if (!is_finite($gain) || ($gain <= 0.0)) {
                throw new ParseError(
                    sprintf('AnalogBalance component %d must be a positive finite gain, got %.6F.', $index, $gain),
                    1670,
                );
            }
        }
    }

    /**
     * Validates that when both CalibrationIlluminant1 and CalibrationIlluminant2
     * are present, neither has value 0 (unknown).
     */
    private function validateDngCalibrationIlluminantPairZero(Ifd $ifd): void
    {
        $illum1 = $ifd->get(DngTag::CALIBRATION_ILLUMINANT_1);
        $illum2 = $ifd->get(DngTag::CALIBRATION_ILLUMINANT_2);

        if (!$illum1 instanceof IfdEntry || !$illum2 instanceof IfdEntry) {
            return;
        }

        if (
            (is_int($illum1->value) && $illum1->value === 0)
            || (is_int($illum2->value) && $illum2->value === 0)
        ) {
            throw new ParseError(
                'CalibrationIlluminant1 and CalibrationIlluminant2 must not have value 0 (unknown) when both are present per DNG 1.7.1.0.',
                1479,
            );
        }
    }

    /**
     * Validates DNG ProfileToneCurve structure and values.
     */
    private function validateDngProfileToneCurve(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_TONE_CURVE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        $value = $entry->value;

        if (!$value instanceof ExifNumericList) {
            return;
        }

        $vals = $value->values;

        if (count($vals) % 2 !== 0) {
            throw new ParseError(
                'ProfileToneCurve FLOAT count must be even (x,y pairs) per DNG 1.7.1.0.',
                1480,
            );
        }

        // Extract typed float array, bail if any value is not numeric
        /** @var list<float> $floats */
        $floats = [];

        foreach ($vals as $v) {
            if (!is_float($v) && !is_int($v)) {
                return;
            }

            $floats[] = (float) $v;
        }

        // Check all values are finite and in [0.0, 1.0]
        foreach ($floats as $fv) {
            if (!is_finite($fv) || $fv < 0.0 || $fv > 1.0) {
                throw new ParseError(
                    'ProfileToneCurve values must be finite floats in [0.0, 1.0] per DNG 1.7.1.0.',
                    1482,
                );
            }
        }

        // Check x values are strictly increasing
        $prevX = -1.0;

        for ($i = 0, $n = count($floats); $i < $n; $i += 2) {
            if ($floats[$i] <= $prevX) {
                throw new ParseError(
                    'ProfileToneCurve x coordinates must be strictly increasing per DNG 1.7.1.0.',
                    1481,
                );
            }

            $prevX = $floats[$i];
        }

        // SDR endpoint check: if ProfileDynamicRange is absent or SDR, enforce (0,0) and (1,1)
        $isSdr    = true;
        $dynRange = $ifd->get(DngTag::PROFILE_DYNAMIC_RANGE);

        if ($dynRange instanceof IfdEntry && is_string($dynRange->value) && strlen($dynRange->value) >= 4) {
            // Bytes 2-3 are DynamicRange SHORT (LE): 0=SDR, 1=HDR
            $range = ord($dynRange->value[2]) | (ord($dynRange->value[3]) << 8);

            if ($range === 1) {
                $isSdr = false;
            }
        }

        if ($isSdr && count($floats) >= 4) {
            $lastIdx = count($floats) - 1;

            if (
                $floats[0] !== 0.0
                || $floats[1] !== 0.0
                || $floats[$lastIdx - 1] !== 1.0
                || $floats[$lastIdx] !== 1.0
            ) {
                throw new ParseError(
                    'SDR ProfileToneCurve must start at (0.0,0.0) and end at (1.0,1.0) per DNG 1.7.1.0.',
                    1483,
                );
            }
        }
    }

    /**
     * Minimum DNGBackwardVersion required for non-default interleave factor tags.
     *
     * @var array<int, list<int>>
     */
    private const array DNG_INTERLEAVE_MIN_VERSIONS = [
        DngTag::ROW_INTERLEAVE_FACTOR    => [1, 2, 0, 0],
        DngTag::COLUMN_INTERLEAVE_FACTOR => [1, 7, 1, 0],
    ];

    /**
     * Validates that non-default interleave factors have a sufficient DNGBackwardVersion.
     */
    private function validateDngInterleaveVersionFloors(Ifd $ifd): void
    {
        $bwEntry = $ifd->get(DngTag::DNG_BACKWARD_VERSION);

        if (!$bwEntry instanceof IfdEntry) {
            return;
        }

        $bwValue = $bwEntry->value;

        if (!$bwValue instanceof ExifNumericList || count($bwValue->values) !== 4) {
            return;
        }

        $bwVer = [];

        foreach ($bwValue->values as $c) {
            if (!is_int($c)) {
                return;
            }

            $bwVer[] = $c;
        }

        foreach (self::DNG_INTERLEAVE_MIN_VERSIONS as $tag => $minVer) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (!is_int($entry->value)) {
                continue;
            }

            if ($entry->value <= 1) {
                continue;
            }

            if ($this->dngVersionLessThan($bwVer, $minVer)) {
                throw new ParseError(
                    sprintf(
                        'DNG tag 0x%04X with non-default value %d requires DNGBackwardVersion >= %d.%d.%d.%d, got %d.%d.%d.%d per DNG 1.7.1.0.',
                        $tag,
                        $entry->value,
                        $minVer[0],
                        $minVer[1],
                        $minVer[2],
                        $minVer[3],
                        $bwVer[0],
                        $bwVer[1],
                        $bwVer[2],
                        $bwVer[3],
                    ),
                    1478,
                );
            }
        }
    }

    /**
     * Returns true if version tuple $a is strictly less than $b.
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    private function dngVersionLessThan(array $a, array $b): bool
    {
        for ($i = 0; $i < 4; ++$i) {
            if ($a[$i] < $b[$i]) {
                return true;
            }

            if ($a[$i] > $b[$i]) {
                return false;
            }
        }

        return false;
    }

    /**
     * Validates that DNG files include the required Orientation tag.
     */
    private function validateDngRequiredOrientation(Ifd $ifd): void
    {
        if (!$ifd->get(DngTag::DNG_VERSION) instanceof IfdEntry) {
            return;
        }

        if (!$ifd->get(ExifTag::ORIENTATION) instanceof IfdEntry) {
            throw new ParseError(
                'DNG requires Orientation tag in IFD0 per DNG 1.7.1.0.',
                1484,
            );
        }
    }

    /**
     * DNG NewSubFileType-to-PhotometricInterpretation rules.
     * Depth map IFDs (type 8/9) require 51177; semantic mask IFDs (type 65540) require 52527.
     *
     * @var array<int, int>
     */
    private const array DNG_ROLE_PHOTOMETRIC = [
        8     => 51177,
        9     => 51177,
        65540 => 52527,
    ];

    /**
     * Validates that depth map and semantic mask IFDs use their required PhotometricInterpretation.
     */
    private function validateDngRolePhotometric(Ifd $ifd): void
    {
        $subfileEntry = $ifd->get(TiffTag::NEW_SUBFILE_TYPE);

        if (!$subfileEntry instanceof IfdEntry || !is_int($subfileEntry->value)) {
            return;
        }

        $required = self::DNG_ROLE_PHOTOMETRIC[$subfileEntry->value] ?? null;

        if ($required === null) {
            return;
        }

        $photoEntry = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        $photoValue = $photoEntry instanceof IfdEntry && is_int($photoEntry->value) ? $photoEntry->value : null;

        if ($photoValue !== $required) {
            throw new ParseError(
                sprintf(
                    'DNG IFD with NewSubFileType %d requires PhotometricInterpretation %d per DNG 1.7.1.0, got %s.',
                    $subfileEntry->value,
                    $required,
                    $photoValue !== null ? (string) $photoValue : 'none',
                ),
                1485,
            );
        }
    }

    /**
     * DNG tags restricted to IFD 0 per DNG 1.7.1.0.
     *
     * @var list<int>
     */
    private const array DNG_IFD0_ONLY_TAGS = [
        DngTag::DNG_VERSION,
        DngTag::DNG_BACKWARD_VERSION,
        DngTag::UNIQUE_CAMERA_MODEL,
        DngTag::LOCALIZED_CAMERA_MODEL,
        DngTag::AS_SHOT_NEUTRAL,
        DngTag::AS_SHOT_WHITE_XY,
        DngTag::BASELINE_EXPOSURE,
        DngTag::BASELINE_NOISE,
        DngTag::BASELINE_SHARPNESS,
        DngTag::CAMERA_SERIAL_NUMBER,
        DngTag::DNG_PRIVATE_DATA,
        DngTag::MAKER_NOTE_SAFETY,
        DngTag::RAW_DATA_UNIQUE_ID,
        DngTag::ANALOG_BALANCE,
        DngTag::AS_SHOT_ICC_PROFILE,
        DngTag::AS_SHOT_PRE_PROFILE_MATRIX,
        DngTag::CURRENT_ICC_PROFILE,
        DngTag::CURRENT_PRE_PROFILE_MATRIX,
    ];

    /**
     * Rejects DNG IFD0-only tags found in additional IFDs.
     */
    private function validateDngIfd0OnlyTags(Ifd $ifd): void
    {
        foreach (self::DNG_IFD0_ONLY_TAGS as $tag) {
            if ($ifd->get($tag) instanceof IfdEntry) {
                throw new ParseError(
                    sprintf(
                        'DNG tag 0x%04X is restricted to IFD 0 per DNG 1.7.1.0 but found in additional IFD.',
                        $tag,
                    ),
                    1488,
                );
            }
        }
    }

    /**
     * Validates DNG JPEG XL tag constraints per DNG 1.7.1.0 §JXL tags.
     *
     * JXLEffort must be 1–9, JXLDecodeSpeed must be 1–4, and all three
     * JXL tags may only appear with Compression = 52546 (JPEG XL).
     */
    private function validateDngJxlTags(Ifd $ifd): void
    {
        $jxlDistance    = $ifd->get(DngTag::JXL_DISTANCE);
        $jxlEffort      = $ifd->get(DngTag::JXL_EFFORT);
        $jxlDecodeSpeed = $ifd->get(DngTag::JXL_DECODE_SPEED);

        $hasJxlTags = $jxlDistance instanceof IfdEntry
            || $jxlEffort instanceof IfdEntry
            || $jxlDecodeSpeed instanceof IfdEntry;

        if (!$hasJxlTags) {
            return;
        }

        $compression = $ifd->get(ExifTag::COMPRESSION);

        if (
            !$compression instanceof IfdEntry
            || !is_int($compression->value)
            || $compression->value !== Compression::JPEG_XL->value
        ) {
            throw new ParseError(
                'JXL tags (JXLDistance, JXLEffort, JXLDecodeSpeed) require Compression = 52546 (JPEG XL).',
                1490,
            );
        }

        if ($jxlEffort instanceof IfdEntry && is_int($jxlEffort->value) && ($jxlEffort->value < 1 || $jxlEffort->value > 9)) {
            throw new ParseError(
                sprintf('JXLEffort must be 1–9, got %d.', $jxlEffort->value),
                1489,
            );
        }

        if ($jxlDecodeSpeed instanceof IfdEntry && is_int($jxlDecodeSpeed->value) && ($jxlDecodeSpeed->value < 1 || $jxlDecodeSpeed->value > 4)) {
            throw new ParseError(
                sprintf('JXLDecodeSpeed must be 1–4, got %d.', $jxlDecodeSpeed->value),
                1489,
            );
        }

        $spp = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);

        if ($spp instanceof IfdEntry && is_int($spp->value) && $spp->value !== 1 && $spp->value !== 3) {
            throw new ParseError(
                sprintf('JPEG XL SamplesPerPixel must be 1 or 3, got %d.', $spp->value),
                1492,
            );
        }

        $photo = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if ($photo instanceof IfdEntry && is_int($photo->value) && !in_array($photo->value, [0, 1, 2, 4, 32803, 34892, 51177, 52527], true)) {
            throw new ParseError(
                sprintf('JPEG XL PhotometricInterpretation %d is not allowed.', $photo->value),
                1493,
            );
        }
    }

    /**
     * Validates CFA photometric cross-tag requirements per DNG 1.7.1.0.
     *
     * When PhotometricInterpretation is CFA (32803), both CFARepeatPatternDim
     * and CFAPattern must be present in the same IFD.
     */
    private function validateDngCfaPhotometric(Ifd $ifd): void
    {
        $photo = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (!$photo instanceof IfdEntry || !is_int($photo->value) || $photo->value !== 32803) {
            return;
        }

        if (!$ifd->get(DngTag::CFA_REPEAT_PATTERN_DIM) instanceof IfdEntry) {
            throw new ParseError(
                'CFA photometric (32803) requires CFARepeatPatternDim in the same IFD.',
                1491,
            );
        }

        $cfaEntry = $ifd->get(ExifTag::CFA_PATTERN);

        if (!$cfaEntry instanceof IfdEntry) {
            throw new ParseError(
                'CFA photometric (32803) requires CFAPattern in the same IFD.',
                1491,
            );
        }

        if ($ifd->get(DngTag::CFA_PLANE_COLOR) instanceof IfdEntry) {
            return;
        }

        $cfaValue = $cfaEntry->value;

        if (!$cfaValue instanceof ExifNumericList) {
            return;
        }

        foreach ($cfaValue->values as $color) {
            if (is_int($color) && $color > 2) {
                throw new ParseError(
                    'Non-RGB CFA images require CFAPlaneColor per DNG 1.7.1.0.',
                    1497,
                );
            }
        }
    }

    /**
     * Validates DNG ColorimetricReference value domain and version gating.
     *
     * Allowed values are 0, 1, 2. Value 2 requires DNGBackwardVersion >= 1.7.0.0.
     */
    private function validateDngColorimetricReference(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::COLORIMETRIC_REFERENCE);

        if (!$entry instanceof IfdEntry || !is_int($entry->value)) {
            return;
        }

        if (!in_array($entry->value, [0, 1, 2], true)) {
            throw new ParseError(
                sprintf('ColorimetricReference value %d is outside the allowed domain {0,1,2}.', $entry->value),
                1494,
            );
        }

        if ($entry->value !== 2) {
            return;
        }

        $bwEntry = $ifd->get(DngTag::DNG_BACKWARD_VERSION);

        if (!$bwEntry instanceof IfdEntry) {
            return;
        }

        $bwValue = $bwEntry->value;

        if (!$bwValue instanceof ExifNumericList || count($bwValue->values) !== 4) {
            return;
        }

        $bwVer = [];

        foreach ($bwValue->values as $c) {
            if (!is_int($c)) {
                return;
            }

            $bwVer[] = $c;
        }

        if ($this->dngVersionLessThan($bwVer, [1, 7, 0, 0])) {
            throw new ParseError(
                sprintf(
                    'ColorimetricReference value 2 requires DNGBackwardVersion >= 1.7.0.0, got %d.%d.%d.%d.',
                    $bwVer[0],
                    $bwVer[1],
                    $bwVer[2],
                    $bwVer[3],
                ),
                1495,
            );
        }
    }

    /**
     * Maximum DNG backward version this parser supports.
     *
     * @var list<int>
     */
    private const array SUPPORTED_DNG_VERSION = [1, 7, 1, 0];

    /**
     * Rejects DNG files whose DNGBackwardVersion exceeds the supported reader version.
     */
    private function validateDngBackwardVersionGate(Ifd $ifd): void
    {
        $bwEntry = $ifd->get(DngTag::DNG_BACKWARD_VERSION);

        if (!$bwEntry instanceof IfdEntry) {
            return;
        }

        $bwValue = $bwEntry->value;

        if (!$bwValue instanceof ExifNumericList || count($bwValue->values) !== 4) {
            return;
        }

        $bwVer = [];

        foreach ($bwValue->values as $c) {
            if (!is_int($c)) {
                return;
            }

            $bwVer[] = $c;
        }

        if ($this->dngVersionLessThan(self::SUPPORTED_DNG_VERSION, $bwVer)) {
            throw new ParseError(
                sprintf(
                    'DNGBackwardVersion %d.%d.%d.%d exceeds supported reader version %d.%d.%d.%d.',
                    $bwVer[0],
                    $bwVer[1],
                    $bwVer[2],
                    $bwVer[3],
                    ...self::SUPPORTED_DNG_VERSION,
                ),
                1496,
            );
        }
    }

    /**
     * DNG sentinel tags whose presence implies the file is a DNG document.
     *
     * @var list<int>
     */
    private const array DNG_SENTINEL_TAGS = [
        DngTag::UNIQUE_CAMERA_MODEL,
    ];

    /**
     * Requires DNGVersion in IFD0 when DNG-specific tags are present.
     */
    private function validateDngRequiredVersion(Ifd $ifd): void
    {
        if ($ifd->get(DngTag::DNG_VERSION) instanceof IfdEntry) {
            return;
        }

        foreach (self::DNG_SENTINEL_TAGS as $tag) {
            if ($ifd->get($tag) instanceof IfdEntry) {
                throw new ParseError(
                    sprintf(
                        'DNG tag 0x%04X found in IFD 0 but required DNGVersion tag is missing.',
                        $tag,
                    ),
                    1498,
                );
            }
        }
    }

    /**
     * Validates DNG multi-profile naming rule per DNG 1.7.1.0.
     *
     * When more than one camera profile exists (identified by ColorMatrix1),
     * every profile context must include a ProfileName tag.
     *
     * @param Ifd       $ifd0           Primary IFD.
     * @param list<Ifd> $additionalIfds Additional IFDs (IFD1+).
     */
    private function validateDngMultiProfileName(Ifd $ifd0, array $additionalIfds): void
    {
        $profileIfds = [];

        if ($ifd0->get(DngTag::COLOR_MATRIX_1) instanceof IfdEntry) {
            $profileIfds[] = $ifd0;
        }

        foreach ($additionalIfds as $additionalIfd) {
            if ($additionalIfd->get(DngTag::COLOR_MATRIX_1) instanceof IfdEntry) {
                $profileIfds[] = $additionalIfd;
            }
        }

        if (count($profileIfds) <= 1) {
            return;
        }

        foreach ($profileIfds as $index => $profileIfd) {
            if (!$profileIfd->get(DngTag::PROFILE_NAME) instanceof IfdEntry) {
                throw new ParseError(
                    sprintf('ProfileName is required for camera profile %d when multiple profiles exist per DNG 1.7.1.0.', $index),
                    1515,
                );
            }
        }
    }

    /**
     * Validates ExtraCameraProfiles offsets and embedded profile payload headers.
     *
     * DNG 1.7.1.0 "ExtraCameraProfiles" defines LONG[count] offsets to camera profile
     * payloads. Each payload starts with a byte-order marker ("II" or "MM"), magic
     * value 0x4352, and a 32-bit inner IFD offset relative to the payload start.
     */
    private function validateDngExtraCameraProfiles(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::EXTRA_CAMERA_PROFILES);
        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG) {
            throw new ParseError(
                'ExtraCameraProfiles must use LONG type per DNG 1.7.1.0.',
                1586,
            );
        }

        if ($entry->count < 1) {
            throw new ParseError(
                'ExtraCameraProfiles must contain at least one profile offset per DNG 1.7.1.0.',
                1587,
            );
        }

        $profileOffsets = $this->extractDngExtraCameraProfileOffsets($entry);
        if (count($profileOffsets) !== $entry->count) {
            throw new ParseError(
                'ExtraCameraProfiles count does not match the number of decoded offsets.',
                1588,
            );
        }

        $blobSize = $this->buffer->size();

        foreach ($profileOffsets as $profileIndex => $profileOffset) {
            if (($profileOffset < 0) || ($profileOffset > ($blobSize - 8))) {
                throw new ParseError(
                    sprintf(
                        'ExtraCameraProfiles offset #%d (%d) is outside TIFF payload bounds.',
                        $profileIndex + 1,
                        $profileOffset,
                    ),
                    1589,
                );
            }

            $cursorBeforeRead = $this->buffer->tell();
            $this->buffer->seek($profileOffset);
            $profileHeader = $this->buffer->read(8);
            $this->buffer->seek($cursorBeforeRead);

            $byteOrderMarker = substr($profileHeader, 0, 2);
            if ($byteOrderMarker === 'II') {
                $profileIsLittleEndian = true;
            } elseif ($byteOrderMarker === 'MM') {
                $profileIsLittleEndian = false;
            } else {
                throw new ParseError(
                    sprintf(
                        'ExtraCameraProfiles profile #%d has invalid byte-order marker 0x%02X%02X.',
                        $profileIndex + 1,
                        ord($byteOrderMarker[0]),
                        ord($byteOrderMarker[1]),
                    ),
                    1590,
                );
            }

            $magicFormat = $profileIsLittleEndian ? 'v' : 'n';
            $magicValue  = Unpack::int($magicFormat, substr($profileHeader, 2, 2), 'ExtraCameraProfiles magic');
            if ($magicValue !== 0x4352) {
                throw new ParseError(
                    sprintf(
                        'ExtraCameraProfiles profile #%d has invalid magic 0x%04X (expected 0x4352).',
                        $profileIndex + 1,
                        $magicValue,
                    ),
                    1591,
                );
            }

            $ifdOffsetFormat = $profileIsLittleEndian ? 'V' : 'N';
            $innerIfdOffset  = Unpack::int(
                $ifdOffsetFormat,
                substr($profileHeader, 4, 4),
                'ExtraCameraProfiles inner IFD offset',
            );

            if ($innerIfdOffset < 8) {
                throw new ParseError(
                    sprintf(
                        'ExtraCameraProfiles profile #%d inner IFD offset %d must be >= 8.',
                        $profileIndex + 1,
                        $innerIfdOffset,
                    ),
                    1592,
                );
            }

            $absoluteInnerIfdOffset = $profileOffset + $innerIfdOffset;
            if ($absoluteInnerIfdOffset > ($blobSize - 2)) {
                throw new ParseError(
                    sprintf(
                        'ExtraCameraProfiles profile #%d inner IFD offset %d is outside TIFF payload bounds.',
                        $profileIndex + 1,
                        $innerIfdOffset,
                    ),
                    1593,
                );
            }
        }
    }

    /**
     * Normalises ExtraCameraProfiles offset values into an integer list.
     *
     * @return list<int>
     */
    private function extractDngExtraCameraProfileOffsets(IfdEntry $entry): array
    {
        if (is_int($entry->value)) {
            return [$entry->value];
        }

        if ($entry->value instanceof ExifNumericList) {
            $offsets = [];

            foreach ($entry->value->values as $value) {
                if (!is_int($value)) {
                    throw new ParseError(
                        'ExtraCameraProfiles offsets must be LONG integers.',
                        1594,
                    );
                }

                $offsets[] = $value;
            }

            return $offsets;
        }

        throw new ParseError(
            'ExtraCameraProfiles must contain numeric LONG offsets.',
            1595,
        );
    }

    /**
     * Validates DNG NoiseProfile coefficient constraints per DNG 1.7.1.0.
     *
     * Count must be even (pairs of S_i, O_i). Each S_i must be > 0, each O_i must be >= 0.
     */
    private function validateDngNoiseProfile(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::NOISE_PROFILE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        $value = $entry->value;

        if (!$value instanceof ExifNumericList) {
            return;
        }

        $count = count($value->values);

        if ($count < 2 || $count % 2 !== 0) {
            throw new ParseError(
                sprintf('NoiseProfile count must be even (pairs of S,O), got %d.', $count),
                1500,
            );
        }

        for ($i = 0; $i < $count; $i += 2) {
            $s = $value->values[$i];
            $o = $value->values[$i + 1];

            if ((is_float($s) || is_int($s)) && $s <= 0.0) {
                throw new ParseError(
                    sprintf('NoiseProfile S_%d must be > 0, got %g.', $i / 2, $s),
                    1499,
                );
            }

            if ((is_float($o) || is_int($o)) && $o < 0.0) {
                throw new ParseError(
                    sprintf('NoiseProfile O_%d must be >= 0, got %g.', $i / 2, $o),
                    1499,
                );
            }
        }
    }

    /**
     * Validates DNG ProfileHueSatMapDims LONG[3] layout and minimum division constraints.
     *
     * HueDivisions >= 1, SaturationDivisions >= 2, ValueDivisions >= 1.
     */
    private function validateDngHueSatMapDims(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_HUE_SAT_MAP_DIMS);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 3) {
            throw new ParseError(
                sprintf('ProfileHueSatMapDims must be LONG[3], got type %d count %d.', $entry->type, $entry->count),
                1511,
            );
        }

        $value = $entry->value;

        if (!$value instanceof ExifNumericList || count($value->values) !== 3) {
            return;
        }

        $hueDivs = $value->values[0];
        $satDivs = $value->values[1];
        $valDivs = $value->values[2];

        if (!is_int($hueDivs) || !is_int($satDivs) || !is_int($valDivs)) {
            return;
        }

        if ($hueDivs < 1) {
            throw new ParseError(
                sprintf('ProfileHueSatMapDims HueDivisions must be >= 1, got %d.', $hueDivs),
                1512,
            );
        }

        if ($satDivs < 2) {
            throw new ParseError(
                sprintf('ProfileHueSatMapDims SaturationDivisions must be >= 2, got %d.', $satDivs),
                1513,
            );
        }

        if ($valDivs < 1) {
            throw new ParseError(
                sprintf('ProfileHueSatMapDims ValueDivisions must be >= 1, got %d.', $valDivs),
                1514,
            );
        }
    }

    /**
     * ProfileHueSatMapData tags to validate against ProfileHueSatMapDims.
     *
     * @var list<int>
     */
    private const array HUE_SAT_MAP_DATA_TAGS = [
        DngTag::PROFILE_HUE_SAT_MAP_DATA_1,
        DngTag::PROFILE_HUE_SAT_MAP_DATA_2,
        DngTag::PROFILE_HUE_SAT_MAP_DATA_3_V17,
    ];

    /**
     * Validates DNG ProfileHueSatMapData count/content against ProfileHueSatMapDims.
     *
     * Count must equal HueDivisions * SatDivisions * ValueDivisions * 3.
     * Zero-saturation entries (saturation index 0) must have valueScale == 1.0.
     */
    private function validateDngHueSatMapData(Ifd $ifd): void
    {
        $dimsEntry = $ifd->get(DngTag::PROFILE_HUE_SAT_MAP_DIMS);

        if (!$dimsEntry instanceof IfdEntry) {
            return;
        }

        $dimsValue = $dimsEntry->value;

        if (!$dimsValue instanceof ExifNumericList || count($dimsValue->values) !== 3) {
            return;
        }

        $hueDivs = $dimsValue->values[0];
        $satDivs = $dimsValue->values[1];
        $valDivs = $dimsValue->values[2];

        if (!is_int($hueDivs) || !is_int($satDivs) || !is_int($valDivs)) {
            return;
        }

        $expectedCount = $hueDivs * $satDivs * $valDivs * 3;

        foreach (self::HUE_SAT_MAP_DATA_TAGS as $tag) {
            $dataEntry = $ifd->get($tag);

            if (!$dataEntry instanceof IfdEntry) {
                continue;
            }

            $dataValue = $dataEntry->value;

            if (!$dataValue instanceof ExifNumericList) {
                continue;
            }

            $actualCount = count($dataValue->values);

            if ($actualCount !== $expectedCount) {
                throw new ParseError(
                    sprintf(
                        'ProfileHueSatMapData 0x%04X count %d does not match dims %d*%d*%d*3 = %d.',
                        $tag,
                        $actualCount,
                        $hueDivs,
                        $satDivs,
                        $valDivs,
                        $expectedCount,
                    ),
                    1501,
                );
            }

            for ($hue = 0; $hue < $hueDivs; ++$hue) {
                for ($val = 0; $val < $valDivs; ++$val) {
                    $tripleIndex = ($hue * $satDivs * $valDivs + 0 * $valDivs + $val) * 3;
                    $valueScale  = $dataValue->values[$tripleIndex + 2] ?? null;

                    if ((is_float($valueScale) || is_int($valueScale)) && (float) $valueScale !== 1.0) {
                        throw new ParseError(
                            sprintf(
                                'ProfileHueSatMapData 0x%04X zero-saturation entry at index %d has valueScale %g, must be 1.0.',
                                $tag,
                                $tripleIndex / 3,
                                $valueScale,
                            ),
                            1502,
                        );
                    }
                }
            }
        }
    }

    /**
     * IlluminantData tags to validate.
     *
     * @var list<int>
     */
    private const array ILLUMINANT_DATA_TAGS = [
        DngTag::ILLUMINANT_DATA_1,
        DngTag::ILLUMINANT_DATA_2,
        DngTag::ILLUMINANT_DATA_3,
    ];

    /**
     * Validates DNG IlluminantData payload structure per DNG 1.7.1.0.
     *
     * DataType 0 = chromaticity (x/y), DataType 1 = spectral (NumLambda >= 2).
     */
    private function validateDngIlluminantData(Ifd $ifd): void
    {
        foreach (self::ILLUMINANT_DATA_TAGS as $tag) {
            $entry = $ifd->get($tag);
            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (!is_string($entry->value)) {
                continue;
            }

            $payload = $entry->value;

            if (strlen($payload) < 2) {
                continue;
            }

            $dataType = $this->unpackU16(substr($payload, 0, 2));

            if ($dataType === 0) {
                continue;
            }

            if ($dataType !== 1) {
                throw new ParseError(
                    sprintf('IlluminantData 0x%04X has unknown DataType %d; expected 0 or 1.', $tag, $dataType),
                    1504,
                );
            }

            if (strlen($payload) < 6) {
                throw new ParseError(
                    sprintf('IlluminantData 0x%04X spectral payload too short for NumLambda field.', $tag),
                    1503,
                );
            }

            $numLambda = $this->unpackU32(substr($payload, 2, 4));

            if ($numLambda < 2) {
                throw new ParseError(
                    sprintf('IlluminantData 0x%04X spectral NumLambda must be >= 2, got %d.', $tag, $numLambda),
                    1503,
                );
            }
        }
    }

    /**
     * Validates DNG ProfileDynamicRange payload structure per DNG 1.7.1.0.
     *
     * Payload must be exactly 8 bytes: Version(SHORT)=1, DynamicRange(SHORT) in {0,1},
     * HintMaxOutputValue(FLOAT) <= 1.0 for SDR (DynamicRange=0).
     */
    private function validateDngProfileDynamicRange(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_DYNAMIC_RANGE);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload = $entry->value;

        if (strlen($payload) !== 8) {
            throw new ParseError(
                sprintf('ProfileDynamicRange payload must be 8 bytes, got %d.', strlen($payload)),
                1505,
            );
        }

        $version = $this->unpackU16(substr($payload, 0, 2));

        if ($version !== 1) {
            throw new ParseError(
                sprintf('ProfileDynamicRange Version must be 1, got %d.', $version),
                1506,
            );
        }

        $dynamicRange = $this->unpackU16(substr($payload, 2, 2));

        if ($dynamicRange !== 0 && $dynamicRange !== 1) {
            throw new ParseError(
                sprintf('ProfileDynamicRange DynamicRange must be 0 or 1, got %d.', $dynamicRange),
                1507,
            );
        }

        if ($dynamicRange === 0) {
            $hint = $this->unpackFloat(substr($payload, 4, 4));

            if ($hint > 1.0) {
                throw new ParseError(
                    sprintf('SDR ProfileDynamicRange HintMaxOutputValue must be <= 1.0, got %g.', $hint),
                    1508,
                );
            }
        }
    }

    /**
     * Bytes-per-element map keyed by ProfileGainTableMap2 DataType.
     *
     * @var array<int, int>
     */
    private const array GAIN_TABLE_MAP2_ELEMENT_BYTES = [
        0 => 1,
        1 => 2,
        2 => 2,
        3 => 4,
    ];

    /**
     * Validates DNG ProfileGainTableMap2 binary layout per DNG 1.7.1.0.
     *
     * 80-byte header followed by gain data whose size must match the count formula.
     */
    private function validateDngProfileGainTableMap2(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_GAIN_TABLE_MAP_2);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload = $entry->value;
        $length  = strlen($payload);

        if ($length < 80) {
            throw new ParseError(
                sprintf('ProfileGainTableMap2 payload must be at least 80 bytes, got %d.', $length),
                1516,
            );
        }

        $mapPointsV = $this->unpackU32(substr($payload, 0, 4));
        $mapPointsH = $this->unpackU32(substr($payload, 4, 4));
        $mapPointsN = $this->unpackU32(substr($payload, 40, 4));
        $dataType   = $this->unpackU32(substr($payload, 64, 4));
        $gamma      = $this->unpackFloat(substr($payload, 68, 4));

        if (!isset(self::GAIN_TABLE_MAP2_ELEMENT_BYTES[$dataType])) {
            throw new ParseError(
                sprintf('ProfileGainTableMap2 DataType must be 0..3, got %d.', $dataType),
                1517,
            );
        }

        if ($gamma < 0.25 || $gamma > 4.0) {
            throw new ParseError(
                sprintf('ProfileGainTableMap2 Gamma must be 0.25..4.0, got %g.', $gamma),
                1518,
            );
        }

        $bytesPerElement = self::GAIN_TABLE_MAP2_ELEMENT_BYTES[$dataType];
        $expectedLength  = 80 + ($bytesPerElement * $mapPointsV * $mapPointsH * $mapPointsN);

        if ($length !== $expectedLength) {
            throw new ParseError(
                sprintf(
                    'ProfileGainTableMap2 count mismatch: expected %d (80 + %d*%d*%d*%d), got %d.',
                    $expectedLength,
                    $bytesPerElement,
                    $mapPointsV,
                    $mapPointsH,
                    $mapPointsN,
                    $length,
                ),
                1519,
            );
        }
    }

    /**
     * Validates legacy DNG ProfileGainTableMap (0xCD2D) payload structure.
     *
     * DNG 1.7.1.0 legacy map layout:
     * - 64-byte header
     * - gain array of FLOAT32 entries
     * - total size = 64 + 4 * MapPointsV * MapPointsH * MapPointsN
     */
    private function validateDngProfileGainTableMapLegacy(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_GAIN_TABLE_MAP);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_UNDEFINED) || !is_string($entry->value)) {
            throw new ParseError(
                sprintf(
                    'ProfileGainTableMap must be UNDEFINED payload bytes, got type %d.',
                    $entry->type,
                ),
                1685,
            );
        }

        $payload = $entry->value;
        $length  = strlen($payload);

        if ($length < 64) {
            throw new ParseError(
                sprintf('ProfileGainTableMap payload must be at least 64 bytes, got %d.', $length),
                1686,
            );
        }

        $mapPointsV = $this->unpackU32(substr($payload, 0, 4));
        $mapPointsH = $this->unpackU32(substr($payload, 4, 4));
        $mapPointsN = $this->unpackU32(substr($payload, 40, 4));

        // Decode and validate fixed header scalar fields to enforce binary layout.
        $headerScalars = [
            $this->unpackDouble(substr($payload, 8, 8)),
            $this->unpackDouble(substr($payload, 16, 8)),
            $this->unpackDouble(substr($payload, 24, 8)),
            $this->unpackDouble(substr($payload, 32, 8)),
            $this->unpackFloat(substr($payload, 44, 4)),
            $this->unpackFloat(substr($payload, 48, 4)),
            $this->unpackFloat(substr($payload, 52, 4)),
            $this->unpackFloat(substr($payload, 56, 4)),
            $this->unpackFloat(substr($payload, 60, 4)),
        ];

        foreach ($headerScalars as $scalar) {
            if (!is_finite($scalar)) {
                throw new ParseError('ProfileGainTableMap header contains non-finite scalar fields.', 1687);
            }
        }

        if (($mapPointsV < 1) || ($mapPointsH < 1) || ($mapPointsN < 1)) {
            throw new ParseError(
                sprintf(
                    'ProfileGainTableMap MapPoints must be >= 1, got V=%d H=%d N=%d.',
                    $mapPointsV,
                    $mapPointsH,
                    $mapPointsN,
                ),
                1688,
            );
        }

        if ($mapPointsV > intdiv(PHP_INT_MAX, $mapPointsH)) {
            throw new ParseError('ProfileGainTableMap size multiplication overflow (V*H).', 1689);
        }

        $vh = $mapPointsV * $mapPointsH;

        if ($vh > intdiv(PHP_INT_MAX, $mapPointsN)) {
            throw new ParseError('ProfileGainTableMap size multiplication overflow (V*H*N).', 1690);
        }

        $entryCount = $vh * $mapPointsN;

        if ($entryCount > intdiv(PHP_INT_MAX - 64, 4)) {
            throw new ParseError('ProfileGainTableMap payload size overflow.', 1691);
        }

        $expectedLength = 64 + (4 * $entryCount);

        if ($length !== $expectedLength) {
            throw new ParseError(
                sprintf(
                    'ProfileGainTableMap payload length mismatch: expected %d (64 + 4*%d*%d*%d), got %d.',
                    $expectedLength,
                    $mapPointsV,
                    $mapPointsH,
                    $mapPointsN,
                    $length,
                ),
                1692,
            );
        }

        $offset = 64;

        for ($i = 0; $i < $entryCount; ++$i) {
            $gain = $this->unpackFloat(substr($payload, $offset, 4));
            $offset += 4;

            if (!is_finite($gain) || ($gain < 0.0)) {
                throw new ParseError(
                    sprintf('ProfileGainTableMap gain[%d] must be finite and >= 0, got %g.', $i, $gain),
                    1693,
                );
            }
        }
    }

    /**
     * Validates DNG gain-map placement rules per DNG 1.7.1.0.
     *
     * ProfileGainTableMap (0xCD2D) is restricted to Raw IFDs and must not appear
     * in IFD 0. When both ProfileGainTableMap and ProfileGainTableMap2 exist,
     * ProfileGainTableMap2 supersedes.
     */
    private function validateDngGainMapPlacement(Ifd $ifd): void
    {
        if ($ifd->get(DngTag::PROFILE_GAIN_TABLE_MAP) instanceof IfdEntry) {
            throw new ParseError(
                'ProfileGainTableMap (0xCD2D) must not appear in IFD 0; it is restricted to Raw IFDs per DNG 1.7.1.0.',
                1520,
            );
        }
    }

    /**
     * Validates DNG ImageSequenceInfo payload structure per DNG 1.7.1.0.
     *
     * Payload: SequenceID (NUL-terminated, min 8 chars), SequenceType (NUL-terminated, min 1 char),
     * FrameInfo (NUL-terminated), Index (uint32 big-endian), Count (uint32 big-endian), Final (uint8).
     */
    private function validateDngImageSequenceInfo(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::IMAGE_SEQUENCE_INFO);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload = $entry->value;
        $length  = strlen($payload);
        $offset  = 0;

        // SequenceID: NUL-terminated, minimum 8 chars before NUL
        $nulPos = strpos($payload, "\0", $offset);

        if ($nulPos === false) {
            throw new ParseError('ImageSequenceInfo SequenceID must be NUL-terminated.', 1521);
        }

        $seqIdLen = $nulPos - $offset;

        if ($seqIdLen < 8) {
            throw new ParseError(
                sprintf('ImageSequenceInfo SequenceID must be at least 8 characters, got %d.', $seqIdLen),
                1522,
            );
        }

        $offset = $nulPos + 1;

        // SequenceType: NUL-terminated, minimum 1 char
        $nulPos = strpos($payload, "\0", $offset);

        if ($nulPos === false) {
            throw new ParseError('ImageSequenceInfo SequenceType must be NUL-terminated.', 1523);
        }

        $seqTypeLen = $nulPos - $offset;

        if ($seqTypeLen < 1) {
            throw new ParseError('ImageSequenceInfo SequenceType must be at least 1 character.', 1524);
        }

        $offset = $nulPos + 1;

        // FrameInfo: NUL-terminated (may be empty)
        $nulPos = strpos($payload, "\0", $offset);

        if ($nulPos === false) {
            throw new ParseError('ImageSequenceInfo FrameInfo must be NUL-terminated.', 1525);
        }

        $offset = $nulPos + 1;

        // Index(4) + Count(4) + Final(1) = 9 bytes remaining
        if (($length - $offset) < 9) {
            throw new ParseError(
                sprintf('ImageSequenceInfo payload truncated: need 9 bytes for Index/Count/Final, got %d.', $length - $offset),
                1526,
            );
        }
    }

    /**
     * Opcode-list tags defined by DNG 1.7.1.0.
     *
     * @var array<int, string>
     */
    private const array DNG_OPCODE_LIST_TAGS = [
        DngTag::OPCODE_LIST_1 => 'OpcodeList1',
        DngTag::OPCODE_LIST_2 => 'OpcodeList2',
        DngTag::OPCODE_LIST_3 => 'OpcodeList3',
    ];

    /**
     * Validates DNG OpcodeList1/2/3 structural framing.
     *
     * DNG 1.7.1.0 Chapter 7 ("Opcode List Processing") defines big-endian list framing:
     * list count (uint32), then per opcode: OpcodeID (uint32), DNGVersion (uint32),
     * Flags (uint32), ParamByteCount (uint32), and ParamByteCount payload bytes.
     * The same framing was introduced with these tags in DNG 1.3.0.0 and remains
     * unchanged in later versions including DNG 1.7.1.0.
     */
    private function validateDngOpcodeLists(Ifd $ifd): void
    {
        foreach (self::DNG_OPCODE_LIST_TAGS as $tag => $tagName) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if ($entry->type !== TiffConst::TYPE_UNDEFINED) {
                throw new ParseError(
                    sprintf('%s must use UNDEFINED type, got %d.', $tagName, $entry->type),
                    1633,
                );
            }

            if (!is_string($entry->value)) {
                throw new ParseError(
                    sprintf('%s must decode to raw bytes.', $tagName),
                    1634,
                );
            }

            $this->validateDngOpcodeListPayload($tagName, $entry->value);
        }
    }

    /**
     * Validates one DNG opcode-list payload for structural integrity.
     *
     * @param string $tagName Human-readable opcode-list tag name.
     * @param string $payload Raw opcode-list bytes.
     */
    private function validateDngOpcodeListPayload(string $tagName, string $payload): void
    {
        $length = strlen($payload);

        if ($length < 4) {
            throw new ParseError(
                sprintf('%s payload is truncated before opcode count.', $tagName),
                1635,
            );
        }

        $opcodeCount = Unpack::int('N', substr($payload, 0, 4), sprintf('%s opcode count', $tagName));
        $offset      = 4;

        $maxOpcodeCount = intdiv($length - 4, 16);
        if ($opcodeCount > $maxOpcodeCount) {
            throw new ParseError(
                sprintf(
                    '%s opcode count %d exceeds structural maximum %d for payload length %d.',
                    $tagName,
                    $opcodeCount,
                    $maxOpcodeCount,
                    $length,
                ),
                1636,
            );
        }

        for ($index = 0; $index < $opcodeCount; ++$index) {
            if (($length - $offset) < 16) {
                throw new ParseError(
                    sprintf('%s opcode %d is truncated before fixed header.', $tagName, $index),
                    1637,
                );
            }

            $paramByteCount = Unpack::int(
                'N',
                substr($payload, $offset + 12, 4),
                sprintf('%s opcode %d parameter byte count', $tagName, $index),
            );
            $offset += 16;

            if (($length - $offset) < $paramByteCount) {
                throw new ParseError(
                    sprintf(
                        '%s opcode %d declares %d parameter bytes but only %d remain.',
                        $tagName,
                        $index,
                        $paramByteCount,
                        $length - $offset,
                    ),
                    1638,
                );
            }

            $offset += $paramByteCount;
        }
    }

    /**
     * Validates OriginalRawFileData payload framing.
     *
     * DNG 1.7.1.0 ("OriginalRawFileData") defines UNDEFINED payload bytes in big-endian
     * block order with four compressed forks and four 4-byte type/creator fields.
     * Trailing bytes are allowed for forward compatibility.
     */
    private function validateDngOriginalRawFileData(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::ORIGINAL_RAW_FILE_DATA);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_UNDEFINED) {
            throw new ParseError(
                sprintf('OriginalRawFileData must use UNDEFINED type, got %d.', $entry->type),
                1626,
            );
        }

        if (!is_string($entry->value)) {
            throw new ParseError('OriginalRawFileData must decode to raw bytes.', 1627);
        }

        $payload = $entry->value;
        $offset  = 0;

        $offset = $this->validateDngOriginalRawForkBlock($payload, $offset, 'original raw data fork');
        $offset = $this->validateDngOriginalRawForkBlock($payload, $offset, 'original raw resource fork');
        $offset = $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'original raw macOS file type');
        $offset = $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'original raw macOS file creator');
        $offset = $this->validateDngOriginalRawForkBlock($payload, $offset, 'sidecar THM data fork');
        $offset = $this->validateDngOriginalRawForkBlock($payload, $offset, 'sidecar THM resource fork');
        $offset = $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'sidecar THM macOS file type');
        $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'sidecar THM macOS file creator');
    }

    /**
     * Consumes a fixed 4-byte field from OriginalRawFileData.
     */
    private function consumeDngOriginalRawFixedBlock(string $payload, int $offset, string $blockName): int
    {
        if ((strlen($payload) - $offset) < 4) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated before %s block.', $blockName),
                1628,
            );
        }

        return $offset + 4;
    }

    /**
     * Validates one compressed-fork block in OriginalRawFileData and returns the next offset.
     *
     * @param string $payload   Raw OriginalRawFileData bytes.
     * @param int    $offset    Current parse cursor.
     * @param string $blockName Human-readable block name for error context.
     */
    private function validateDngOriginalRawForkBlock(string $payload, int $offset, string $blockName): int
    {
        $payloadLength = strlen($payload);

        if (($payloadLength - $offset) < 4) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated before %s length field.', $blockName),
                1629,
            );
        }

        $forkStart  = $offset;
        $forkLength = Unpack::int('N', substr($payload, $offset, 4), sprintf('%s length', $blockName));
        $offset += 4;

        if ($forkLength === 0) {
            return $offset;
        }

        $forkBlocks = intdiv($forkLength + 65535, 65536);
        $indexCount = $forkBlocks + 1;
        $indexBytes = $indexCount * 4;

        if (($payloadLength - $offset) < $indexBytes) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated in %s index table.', $blockName),
                1630,
            );
        }

        $minimumDataOffset = 4 + $indexBytes;
        $previousOffset    = -1;
        $forkDataEnd       = 0;

        for ($index = 0; $index < $indexCount; ++$index) {
            $relativeOffset = Unpack::int(
                'N',
                substr($payload, $offset + ($index * 4), 4),
                sprintf('%s index offset', $blockName),
            );

            if (($relativeOffset < $minimumDataOffset) || ($relativeOffset < $previousOffset)) {
                throw new ParseError(
                    sprintf('OriginalRawFileData has invalid %s index offsets.', $blockName),
                    1631,
                );
            }

            $previousOffset = $relativeOffset;
            $forkDataEnd    = $relativeOffset;
        }

        $forkEnd = $forkStart + $forkDataEnd;
        if ($forkEnd > $payloadLength) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated in %s compressed data.', $blockName),
                1632,
            );
        }

        return $forkEnd;
    }

    /**
     * Bytes per RGB entry keyed by RGBTables PixelType.
     *
     * @var array<int, int>
     */
    private const array RGB_TABLES_PIXEL_BYTES = [
        0 => 3,
        1 => 6,
        2 => 12,
    ];

    /**
     * Validates DNG RGBTables payload structure per DNG 1.7.1.0.
     *
     * Top-level: NumTables (1..20), CompositeMethod ({0,1}).
     * Per-table: Divisions (2..32), PixelType ({0,1,2}), GammaEncoding (0..4),
     * ColorPrimaries (0..4), GamutExtension ({0,1}), then Divisions^3 entries.
     */
    private function validateDngRgbTables(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::RGB_TABLES);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload = $entry->value;
        $length  = strlen($payload);

        if ($length < 8) {
            throw new ParseError(
                sprintf('RGBTables payload must be at least 8 bytes, got %d.', $length),
                1527,
            );
        }

        $numTables       = $this->unpackU32(substr($payload, 0, 4));
        $compositeMethod = $this->unpackU32(substr($payload, 4, 4));

        if ($numTables < 1 || $numTables > 20) {
            throw new ParseError(
                sprintf('RGBTables NumTables must be 1..20, got %d.', $numTables),
                1528,
            );
        }

        if ($compositeMethod !== 0 && $compositeMethod !== 1) {
            throw new ParseError(
                sprintf('RGBTables CompositeMethod must be 0 or 1, got %d.', $compositeMethod),
                1529,
            );
        }

        $offset        = 8;
        $zeroNameCount = 0;

        for ($t = 0; $t < $numTables; ++$t) {
            if (($length - $offset) < 2) {
                throw new ParseError(
                    sprintf('RGBTables payload truncated at table %d header.', $t),
                    1530,
                );
            }

            $nameLen = $this->unpackU16(substr($payload, $offset, 2));
            $offset += 2;

            if ($nameLen === 0) {
                ++$zeroNameCount;
            }

            $offset += $nameLen;

            if (($length - $offset) < 5) {
                throw new ParseError(
                    sprintf('RGBTables payload truncated at table %d fields.', $t),
                    1530,
                );
            }

            $divisions      = ord($payload[$offset]);
            $pixelType      = ord($payload[$offset + 1]);
            $gammaEncoding  = ord($payload[$offset + 2]);
            $colorPrimaries = ord($payload[$offset + 3]);
            $gamutExtension = ord($payload[$offset + 4]);
            $offset += 5;

            if ($divisions < 2 || $divisions > 32) {
                throw new ParseError(
                    sprintf('RGBTables table %d Divisions must be 2..32, got %d.', $t, $divisions),
                    1531,
                );
            }

            if (!isset(self::RGB_TABLES_PIXEL_BYTES[$pixelType])) {
                throw new ParseError(
                    sprintf('RGBTables table %d PixelType must be 0..2, got %d.', $t, $pixelType),
                    1532,
                );
            }

            if ($gammaEncoding > 4) {
                throw new ParseError(
                    sprintf('RGBTables table %d GammaEncoding must be 0..4, got %d.', $t, $gammaEncoding),
                    1533,
                );
            }

            if ($colorPrimaries > 4) {
                throw new ParseError(
                    sprintf('RGBTables table %d ColorPrimaries must be 0..4, got %d.', $t, $colorPrimaries),
                    1534,
                );
            }

            if ($gamutExtension > 1) {
                throw new ParseError(
                    sprintf('RGBTables table %d GamutExtension must be 0 or 1, got %d.', $t, $gamutExtension),
                    1535,
                );
            }

            $tableDataSize = $divisions * $divisions * $divisions * self::RGB_TABLES_PIXEL_BYTES[$pixelType];
            $offset += $tableDataSize;
        }

        if ($numTables > 1 && $zeroNameCount > 1) {
            throw new ParseError(
                sprintf('RGBTables allows at most one unnamed table when NumTables > 1, got %d.', $zeroNameCount),
                1536,
            );
        }

        if ($offset !== $length) {
            throw new ParseError(
                sprintf('RGBTables payload length mismatch: expected %d bytes, got %d.', $offset, $length),
                1537,
            );
        }
    }

    /**
     * Validates SemanticName/SemanticInstanceID conformance in Semantic Mask IFDs.
     *
     * A Semantic Mask IFD is identified by PhotometricInterpretation = 52527.
     * SemanticName is required in that context per DNG 1.6+.
     */
    private function validateDngSemanticMaskIdentity(Ifd $ifd): void
    {
        $photo = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (!$photo instanceof IfdEntry || !is_int($photo->value) || $photo->value !== Photometric::PHOTOMETRIC_MASK->value) {
            return;
        }

        $nameEntry = $ifd->get(DngTag::SEMANTIC_NAME);

        if (!$nameEntry instanceof IfdEntry) {
            throw new ParseError(
                'SemanticName is required in Semantic Mask IFD per DNG 1.6+.',
                1538,
            );
        }

        if ($nameEntry->type !== TiffConst::TYPE_ASCII) {
            throw new ParseError(
                sprintf('SemanticName must use ASCII type, got %d.', $nameEntry->type),
                1539,
            );
        }

        if (!is_string($nameEntry->value) || $nameEntry->value === '') {
            throw new ParseError(
                'SemanticName must not be empty in Semantic Mask IFD.',
                1540,
            );
        }
    }

    /**
     * Validates MaskSubArea (0xCD38) in Semantic Mask IFDs.
     *
     * MaskSubArea must use type LONG with count 4: (T_crop, L_crop, W_full, H_full).
     * Geometric constraints require T_crop + ImageLength <= H_full and
     * L_crop + ImageWidth <= W_full. If geometric constraints fail the tag
     * is ignored per DNG 1.6+ spec (no ParseError for geometry).
     */
    private function validateDngMaskSubArea(Ifd $ifd): void
    {
        $photo = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (!$photo instanceof IfdEntry || !is_int($photo->value) || $photo->value !== Photometric::PHOTOMETRIC_MASK->value) {
            return;
        }

        $entry = $ifd->get(DngTag::MASK_SUB_AREA);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG) {
            throw new ParseError(
                sprintf('MaskSubArea must use LONG type, got %d.', $entry->type),
                1541,
            );
        }

        if ($entry->count !== 4) {
            throw new ParseError(
                sprintf('MaskSubArea must have count 4, got %d.', $entry->count),
                1542,
            );
        }
    }

    /**
     * Validates DNG ImageStats (0xCD46) payload structure per DNG 1.7.1.0.
     *
     * All ImageStats data is stored in big-endian byte order regardless of TIFF
     * file byte order. Payload: LONG child-count N, then N child entries each
     * containing LONG childTagCode, LONG byteLength L, and L bytes of data.
     * Duplicate child tag codes are rejected.
     */
    private function validateDngImageStats(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::IMAGE_STATS);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload = $entry->value;
        $length  = strlen($payload);

        if ($length < 4) {
            throw new ParseError(
                sprintf('ImageStats payload too short for child count (%d bytes).', $length),
                1543,
            );
        }

        // ImageStats is always big-endian
        $childCount = Unpack::int('N', substr($payload, 0, 4), 'ImageStats child count');
        $offset     = 4;
        $seenTags   = [];

        for ($i = 0; $i < $childCount; ++$i) {
            if ($offset + 8 > $length) {
                throw new ParseError(
                    sprintf('ImageStats child entry %d truncated at header (offset %d, length %d).', $i, $offset, $length),
                    1544,
                );
            }

            $childTag    = Unpack::int('N', substr($payload, $offset, 4), 'ImageStats child tag');
            $childLength = Unpack::int('N', substr($payload, $offset + 4, 4), 'ImageStats child length');
            $offset += 8;

            if ($offset + $childLength > $length) {
                throw new ParseError(
                    sprintf('ImageStats child tag %d payload truncated (need %d bytes at offset %d, have %d).', $childTag, $childLength, $offset, $length),
                    1545,
                );
            }

            if (isset($seenTags[$childTag])) {
                throw new ParseError(
                    sprintf('ImageStats child tag %d appears more than once.', $childTag),
                    1546,
                );
            }

            $seenTags[$childTag] = true;
            $offset += $childLength;
        }
    }

    /**
     * Validates ProfileLookTableDims (0xC725) per DNG 1.7.1.0.
     *
     * Must be LONG[3]: HueDivisions >= 1, SaturationDivisions >= 2, ValueDivisions >= 1.
     */
    private function validateDngProfileLookTableDims(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_LOOK_TABLE_DIMS);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 3) {
            throw new ParseError(
                sprintf('ProfileLookTableDims must be LONG[3], got type %d count %d.', $entry->type, $entry->count),
                1547,
            );
        }

        $value = $entry->value;

        if (!$value instanceof ExifNumericList || count($value->values) !== 3) {
            return;
        }

        $hueDivs = $value->values[0];
        $satDivs = $value->values[1];
        $valDivs = $value->values[2];

        if (!is_int($hueDivs) || !is_int($satDivs) || !is_int($valDivs)) {
            return;
        }

        if ($hueDivs < 1) {
            throw new ParseError(
                sprintf('ProfileLookTableDims HueDivisions must be >= 1, got %d.', $hueDivs),
                1548,
            );
        }

        if ($satDivs < 2) {
            throw new ParseError(
                sprintf('ProfileLookTableDims SaturationDivisions must be >= 2, got %d.', $satDivs),
                1549,
            );
        }

        if ($valDivs < 1) {
            throw new ParseError(
                sprintf('ProfileLookTableDims ValueDivisions must be >= 1, got %d.', $valDivs),
                1550,
            );
        }
    }

    /**
     * Validates ProfileLookTableData (0xC726) count against ProfileLookTableDims per DNG 1.7.1.0.
     *
     * Type must be FLOAT. Count must equal HueDivisions * SaturationDivisions * ValueDivisions * 3.
     * If dims is present, data must also be present and vice versa.
     */
    private function validateDngProfileLookTableData(Ifd $ifd): void
    {
        $dimsEntry = $ifd->get(DngTag::PROFILE_LOOK_TABLE_DIMS);
        $dataEntry = $ifd->get(DngTag::PROFILE_LOOK_TABLE_DATA);

        // Pair consistency: both must be present or both absent
        if ($dimsEntry instanceof IfdEntry && !$dataEntry instanceof IfdEntry) {
            throw new ParseError(
                'ProfileLookTableDims is present but ProfileLookTableData is missing.',
                1551,
            );
        }

        if (!$dimsEntry instanceof IfdEntry && $dataEntry instanceof IfdEntry) {
            throw new ParseError(
                'ProfileLookTableData is present but ProfileLookTableDims is missing.',
                1552,
            );
        }

        if (!$dimsEntry instanceof IfdEntry || !$dataEntry instanceof IfdEntry) {
            return;
        }

        if ($dataEntry->type !== TiffConst::TYPE_FLOAT) {
            throw new ParseError(
                sprintf('ProfileLookTableData must use FLOAT type, got %d.', $dataEntry->type),
                1553,
            );
        }

        $dimsValue = $dimsEntry->value;

        if (!$dimsValue instanceof ExifNumericList || count($dimsValue->values) !== 3) {
            return;
        }

        $hueDivs = $dimsValue->values[0];
        $satDivs = $dimsValue->values[1];
        $valDivs = $dimsValue->values[2];

        if (!is_int($hueDivs) || !is_int($satDivs) || !is_int($valDivs)) {
            return;
        }

        $expectedCount = $hueDivs * $satDivs * $valDivs * 3;

        if ($dataEntry->count !== $expectedCount) {
            throw new ParseError(
                sprintf(
                    'ProfileLookTableData count %d does not match dims %d*%d*%d*3 = %d.',
                    $dataEntry->count,
                    $hueDivs,
                    $satDivs,
                    $valDivs,
                    $expectedCount,
                ),
                1554,
            );
        }
    }

    /**
     * Validates a DNG encoding tag (ProfileHueSatMapEncoding or ProfileLookTableEncoding).
     *
     * Must be LONG[1] with value 0 (Linear) or 1 (sRGB). Not applicable when the
     * associated dimensions tag has ValueDivisions == 1 (2.5D map/table).
     *
     * @param Ifd    $ifd     IFD to validate
     * @param int    $encTag  Encoding tag constant
     * @param int    $dimsTag Associated dimensions tag constant
     * @param string $name    Human-readable tag name for error messages
     */
    private function validateDngEncodingTag(Ifd $ifd, int $encTag, int $dimsTag, string $name): void
    {
        $entry = $ifd->get($encTag);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 1) {
            throw new ParseError(
                sprintf('%s must be LONG[1], got type %d count %d.', $name, $entry->type, $entry->count),
                1555,
            );
        }

        if (!is_int($entry->value) || ($entry->value !== 0 && $entry->value !== 1)) {
            throw new ParseError(
                sprintf('%s value must be 0 (Linear) or 1 (sRGB), got %d.', $name, is_int($entry->value) ? $entry->value : -1),
                1556,
            );
        }

        // Not applicable to 2.5D maps (ValueDivisions == 1)
        $dimsEntry = $ifd->get($dimsTag);

        if (!$dimsEntry instanceof IfdEntry) {
            return;
        }

        $dimsValue = $dimsEntry->value;

        if (!$dimsValue instanceof ExifNumericList || count($dimsValue->values) !== 3) {
            return;
        }

        $valDivs = $dimsValue->values[2];

        if (is_int($valDivs) && $valDivs === 1) {
            throw new ParseError(
                sprintf('%s must not be present for 2.5D tables (ValueDivisions == 1).', $name),
                1557,
            );
        }
    }

    /**
     * DNG digest tags that must be BYTE[16] per DNG 1.7.1.0.
     *
     * @var array<int, string>
     */
    private const array DIGEST_TAGS = [
        DngTag::PREVIEW_SETTINGS_DIGEST  => 'PreviewSettingsDigest',
        DngTag::RAW_IMAGE_DIGEST         => 'RawImageDigest',
        DngTag::ORIGINAL_RAW_FILE_DIGEST => 'OriginalRawFileDigest',
        DngTag::NEW_RAW_IMAGE_DIGEST     => 'NewRawImageDigest',
    ];

    /**
     * Validates DNG digest tags (RawImageDigest, OriginalRawFileDigest, NewRawImageDigest).
     *
     * Each must be BYTE[16] per DNG 1.7.1.0.
     */
    private function validateDngDigestTags(Ifd $ifd): void
    {
        foreach (self::DIGEST_TAGS as $tag => $name) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if ($entry->type !== TiffConst::TYPE_BYTE || $entry->count !== 16) {
                throw new ParseError(
                    sprintf('%s must be BYTE[16], got type %d count %d.', $name, $entry->type, $entry->count),
                    1558,
                );
            }
        }
    }

    /**
     * Validates PreviewColorSpace (0xC71A) per DNG 1.7.1.0.
     *
     * Must be LONG[1] with value in 0..4 (Unknown, Gray Gamma 2.2, sRGB, Adobe RGB, ProPhoto RGB).
     */
    private function validateDngPreviewColorSpace(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PREVIEW_COLOR_SPACE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 1) {
            throw new ParseError(
                sprintf('PreviewColorSpace must be LONG[1], got type %d count %d.', $entry->type, $entry->count),
                1559,
            );
        }

        if (!is_int($entry->value) || $entry->value < 0 || $entry->value > 4) {
            throw new ParseError(
                sprintf('PreviewColorSpace value must be 0..4, got %d.', is_int($entry->value) ? $entry->value : -1),
                1560,
            );
        }
    }

    /**
     * Validates PreviewDateTime (0xC71B) per DNG 1.7.1.0.
     *
     * Must be ASCII with a valid ISO 8601 date/time string.
     * NUL termination is already enforced by the generic ASCII decoder.
     */
    private function validateDngPreviewDateTime(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PREVIEW_DATE_TIME);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_ASCII) {
            throw new ParseError(
                sprintf('PreviewDateTime must use ASCII type, got %d.', $entry->type),
                1561,
            );
        }

        if (!is_string($entry->value) || $entry->value === '') {
            throw new ParseError(
                'PreviewDateTime must not be empty.',
                1562,
            );
        }

        // ISO 8601 basic validation: YYYY-MM-DDThh:mm:ss with optional timezone
        if (preg_match('/^\d{4}-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/', $entry->value, $m) !== 1) {
            throw new ParseError(
                sprintf('PreviewDateTime is not a valid ISO 8601 timestamp: %s.', $entry->value),
                1563,
            );
        }

        $month  = (int) $m[1];
        $day    = (int) $m[2];
        $hour   = (int) $m[3];
        $minute = (int) $m[4];
        $second = (int) $m[5];

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59 || $second > 59) {
            throw new ParseError(
                sprintf('PreviewDateTime contains out-of-range date/time components: %s.', $entry->value),
                1564,
            );
        }
    }

    /**
     * Validates ActiveArea and MaskedAreas rectangle layout and geometry.
     *
     * DNG 1.7.1.0 ("ActiveArea", "MaskedAreas"):
     * - ActiveArea: SHORT|LONG[4], order top,left,bottom,right with top<bottom and left<right
     * - MaskedAreas: SHORT|LONG[4*N], each rectangle uses the same ordering and must not overlap
     */
    private function validateDngActiveAndMaskedAreas(Ifd $ifd): void
    {
        $activeArea = $ifd->get(DngTag::ACTIVE_AREA);
        if ($activeArea instanceof IfdEntry) {
            if (
                !in_array($activeArea->type, [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG], true)
                || ($activeArea->count !== 4)
            ) {
                throw new ParseError(
                    sprintf(
                        'ActiveArea must be SHORT|LONG with count 4, got type %d count %d.',
                        $activeArea->type,
                        $activeArea->count,
                    ),
                    1605,
                );
            }

            $this->extractDngRectangles($activeArea, 'ActiveArea');
        }

        $maskedAreas = $ifd->get(DngTag::MASKED_AREAS);
        if ($maskedAreas instanceof IfdEntry) {
            if (
                !in_array($maskedAreas->type, [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG], true)
                || ($maskedAreas->count < 4)
                || ($maskedAreas->count % 4 !== 0)
            ) {
                throw new ParseError(
                    sprintf(
                        'MaskedAreas must be SHORT|LONG with count 4*N, got type %d count %d.',
                        $maskedAreas->type,
                        $maskedAreas->count,
                    ),
                    1606,
                );
            }

            $rectangles = $this->extractDngRectangles($maskedAreas, 'MaskedAreas');

            $rectangleCount = count($rectangles);
            for ($leftIndex = 0; $leftIndex < $rectangleCount; ++$leftIndex) {
                for ($rightIndex = $leftIndex + 1; $rightIndex < $rectangleCount; ++$rightIndex) {
                    if ($this->dngRectanglesOverlap($rectangles[$leftIndex], $rectangles[$rightIndex])) {
                        throw new ParseError(
                            sprintf(
                                'MaskedAreas rectangles %d and %d overlap.',
                                $leftIndex,
                                $rightIndex,
                            ),
                            1607,
                        );
                    }
                }
            }
        }
    }

    /**
     * Decodes a tag payload into rectangles (top, left, bottom, right).
     *
     * @return list<array{top: int, left: int, bottom: int, right: int}>
     */
    private function extractDngRectangles(IfdEntry $entry, string $tagName): array
    {
        if (!$entry->value instanceof ExifNumericList) {
            throw new ParseError(
                sprintf('%s must decode to a numeric list payload.', $tagName),
                1608,
            );
        }

        $values = [];
        foreach ($entry->value->values as $index => $component) {
            if ($component instanceof UInt64) {
                $values[] = $component->toInt(sprintf('%s component %d', $tagName, $index));
            } elseif (is_int($component)) {
                $values[] = $component;
            } else {
                if ((float) (int) $component !== $component) {
                    throw new ParseError(
                        sprintf('%s contains a non-integer rectangle component at index %d.', $tagName, $index),
                        1609,
                    );
                }

                $values[] = (int) $component;
            }
        }

        if (count($values) !== $entry->count) {
            throw new ParseError(
                sprintf('%s decoded component count mismatch (expected %d).', $tagName, $entry->count),
                1610,
            );
        }

        if (count($values) % 4 !== 0) {
            throw new ParseError(
                sprintf('%s must contain 4 components per rectangle.', $tagName),
                1611,
            );
        }

        $rectangles = [];
        $counter    = count($values);

        for ($index = 0; $index < $counter; $index += 4) {
            $top    = $values[$index];
            $left   = $values[$index + 1];
            $bottom = $values[$index + 2];
            $right  = $values[$index + 3];

            if (($top < 0) || ($left < 0) || ($bottom < 0) || ($right < 0)) {
                throw new ParseError(
                    sprintf('%s rectangle %d contains negative coordinates.', $tagName, intdiv($index, 4)),
                    1612,
                );
            }

            if (($top >= $bottom) || ($left >= $right)) {
                throw new ParseError(
                    sprintf(
                        '%s rectangle %d must satisfy top < bottom and left < right, got (%d,%d,%d,%d).',
                        $tagName,
                        intdiv($index, 4),
                        $top,
                        $left,
                        $bottom,
                        $right,
                    ),
                    1613,
                );
            }

            $rectangles[] = [
                'top'    => $top,
                'left'   => $left,
                'bottom' => $bottom,
                'right'  => $right,
            ];
        }

        return $rectangles;
    }

    /**
     * Returns true when two rectangles overlap with positive area.
     *
     * @param array{top: int, left: int, bottom: int, right: int} $leftRectangle
     * @param array{top: int, left: int, bottom: int, right: int} $rightRectangle
     */
    private function dngRectanglesOverlap(array $leftRectangle, array $rightRectangle): bool
    {
        return ($leftRectangle['top'] < $rightRectangle['bottom'])
            && ($rightRectangle['top'] < $leftRectangle['bottom'])
            && ($leftRectangle['left'] < $rightRectangle['right'])
            && ($rightRectangle['left'] < $leftRectangle['right']);
    }

    /**
     * Validates cross-tag formulas for the DNG black/white-level tag family.
     *
     * DNG 1.7.1.0 ("BlackLevelRepeatDim", "BlackLevel", "BlackLevelDeltaH",
     * "BlackLevelDeltaV", "WhiteLevel") defines type/count constraints and
     * count formulas based on SamplesPerPixel and ActiveArea geometry.
     */
    private function validateDngBlackWhiteLevelFamily(Ifd $ifd): void
    {
        $samplesPerPixel = null;
        $samplesEntry    = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);

        if ($samplesEntry instanceof IfdEntry && is_int($samplesEntry->value) && $samplesEntry->value > 0) {
            $samplesPerPixel = $samplesEntry->value;
        }

        $repeatRows = null;
        $repeatCols = null;
        $repeatDim  = $ifd->get(DngTag::BLACK_LEVEL_REPEAT_DIM);

        if ($repeatDim instanceof IfdEntry) {
            if (($repeatDim->type !== TiffConst::TYPE_SHORT) || ($repeatDim->count !== 2)) {
                throw new ParseError(
                    sprintf(
                        'BlackLevelRepeatDim must be SHORT[2], got type %d count %d.',
                        $repeatDim->type,
                        $repeatDim->count,
                    ),
                    1614,
                );
            }

            [$repeatRows, $repeatCols] = $this->extractDngPositivePairFromNumericList($repeatDim, 'BlackLevelRepeatDim');
        }

        $blackLevel = $ifd->get(DngTag::BLACK_LEVEL);
        if ($blackLevel instanceof IfdEntry) {
            if (
                !in_array(
                    $blackLevel->type,
                    [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG, TiffConst::TYPE_RATIONAL],
                    true,
                )
            ) {
                throw new ParseError(
                    sprintf(
                        'BlackLevel must be SHORT|LONG|RATIONAL, got type %d.',
                        $blackLevel->type,
                    ),
                    1615,
                );
            }

            if (($repeatRows !== null) && ($repeatCols !== null) && ($samplesPerPixel !== null)) {
                $expectedCount = $repeatRows * $repeatCols * $samplesPerPixel;
                if ($blackLevel->count !== $expectedCount) {
                    throw new ParseError(
                        sprintf(
                            'BlackLevel count %d does not match expected %d (rows=%d, cols=%d, SamplesPerPixel=%d).',
                            $blackLevel->count,
                            $expectedCount,
                            $repeatRows,
                            $repeatCols,
                            $samplesPerPixel,
                        ),
                        1616,
                    );
                }
            }
        }

        $activeWidth  = null;
        $activeLength = null;
        $activeArea   = $ifd->get(DngTag::ACTIVE_AREA);

        if (
            $activeArea instanceof IfdEntry
            && in_array($activeArea->type, [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG], true)
            && ($activeArea->count === 4)
        ) {
            $rectangles = $this->extractDngRectangles($activeArea, 'ActiveArea');
            if (count($rectangles) === 1) {
                $activeWidth  = $rectangles[0]['right'] - $rectangles[0]['left'];
                $activeLength = $rectangles[0]['bottom'] - $rectangles[0]['top'];
            }
        }

        $blackLevelDeltaH = $ifd->get(DngTag::BLACK_LEVEL_DELTA_H);
        if ($blackLevelDeltaH instanceof IfdEntry) {
            if ($blackLevelDeltaH->type !== TiffConst::TYPE_SRATIONAL) {
                throw new ParseError(
                    sprintf(
                        'BlackLevelDeltaH must be SRATIONAL, got type %d.',
                        $blackLevelDeltaH->type,
                    ),
                    1617,
                );
            }

            if (($activeWidth !== null) && ($blackLevelDeltaH->count !== $activeWidth)) {
                throw new ParseError(
                    sprintf(
                        'BlackLevelDeltaH count %d does not match ActiveArea width %d.',
                        $blackLevelDeltaH->count,
                        $activeWidth,
                    ),
                    1618,
                );
            }
        }

        $blackLevelDeltaV = $ifd->get(DngTag::BLACK_LEVEL_DELTA_V);
        if ($blackLevelDeltaV instanceof IfdEntry) {
            if ($blackLevelDeltaV->type !== TiffConst::TYPE_SRATIONAL) {
                throw new ParseError(
                    sprintf(
                        'BlackLevelDeltaV must be SRATIONAL, got type %d.',
                        $blackLevelDeltaV->type,
                    ),
                    1619,
                );
            }

            if (($activeLength !== null) && ($blackLevelDeltaV->count !== $activeLength)) {
                throw new ParseError(
                    sprintf(
                        'BlackLevelDeltaV count %d does not match ActiveArea length %d.',
                        $blackLevelDeltaV->count,
                        $activeLength,
                    ),
                    1620,
                );
            }
        }

        $whiteLevel = $ifd->get(DngTag::WHITE_LEVEL);
        if ($whiteLevel instanceof IfdEntry) {
            if (!in_array($whiteLevel->type, [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG], true)) {
                throw new ParseError(
                    sprintf(
                        'WhiteLevel must be SHORT|LONG, got type %d.',
                        $whiteLevel->type,
                    ),
                    1621,
                );
            }

            if (($samplesPerPixel !== null) && ($whiteLevel->count !== $samplesPerPixel)) {
                throw new ParseError(
                    sprintf(
                        'WhiteLevel count %d does not match SamplesPerPixel %d.',
                        $whiteLevel->count,
                        $samplesPerPixel,
                    ),
                    1622,
                );
            }
        }
    }

    /**
     * Extracts two strictly positive integer values from a numeric list payload.
     *
     * @return array{0: int, 1: int}
     */
    private function extractDngPositivePairFromNumericList(IfdEntry $entry, string $tagName): array
    {
        if (!$entry->value instanceof ExifNumericList || count($entry->value->values) !== 2) {
            throw new ParseError(
                sprintf('%s must decode to exactly two numeric components.', $tagName),
                1623,
            );
        }

        $components = [];
        foreach ($entry->value->values as $index => $value) {
            if ($value instanceof UInt64) {
                $components[] = $value->toInt(sprintf('%s component %d', $tagName, $index));
            } elseif (is_int($value)) {
                $components[] = $value;
            } else {
                if ((float) (int) $value !== $value) {
                    throw new ParseError(
                        sprintf('%s component %d must be an integer value.', $tagName, $index),
                        1624,
                    );
                }

                $components[] = (int) $value;
            }
        }

        if (($components[0] <= 0) || ($components[1] <= 0)) {
            throw new ParseError(
                sprintf('%s components must be > 0, got (%d, %d).', $tagName, $components[0], $components[1]),
                1625,
            );
        }

        return [$components[0], $components[1]];
    }

    /**
     * Validates DefaultScale, DefaultCropOrigin and DefaultCropSize layout and geometry.
     *
     * DNG 1.7.1.0 ("DefaultScale", "DefaultCropOrigin", "DefaultCropSize"):
     * - DefaultScale: RATIONAL[2], both components > 0
     * - DefaultCropOrigin: SHORT|LONG|RATIONAL with count 2, components >= 0
     * - DefaultCropSize: SHORT|LONG|RATIONAL with count 2, components > 0
     */
    private function validateDngDefaultCropScaleGeometry(Ifd $ifd): void
    {
        $defaultScale = $ifd->get(DngTag::DEFAULT_SCALE);
        if ($defaultScale instanceof IfdEntry) {
            if (($defaultScale->type !== TiffConst::TYPE_RATIONAL) || ($defaultScale->count !== 2)) {
                throw new ParseError(
                    sprintf(
                        'DefaultScale must be RATIONAL[2], got type %d count %d.',
                        $defaultScale->type,
                        $defaultScale->count,
                    ),
                    1596,
                );
            }

            [$scaleH, $scaleV] = $this->extractDngCropScalePair($defaultScale, 'DefaultScale');
            if (($scaleH <= 0.0) || ($scaleV <= 0.0)) {
                throw new ParseError(
                    sprintf(
                        'DefaultScale components must be > 0, got (%.6F, %.6F).',
                        $scaleH,
                        $scaleV,
                    ),
                    1597,
                );
            }
        }

        $defaultCropOrigin = $ifd->get(DngTag::DEFAULT_CROP_ORIGIN);
        if ($defaultCropOrigin instanceof IfdEntry) {
            if (
                !in_array(
                    $defaultCropOrigin->type,
                    [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG, TiffConst::TYPE_RATIONAL],
                    true,
                )
                || ($defaultCropOrigin->count !== 2)
            ) {
                throw new ParseError(
                    sprintf(
                        'DefaultCropOrigin must be SHORT|LONG|RATIONAL with count 2, got type %d count %d.',
                        $defaultCropOrigin->type,
                        $defaultCropOrigin->count,
                    ),
                    1598,
                );
            }

            [$originH, $originV] = $this->extractDngCropScalePair($defaultCropOrigin, 'DefaultCropOrigin');
            if (($originH < 0.0) || ($originV < 0.0)) {
                throw new ParseError(
                    sprintf(
                        'DefaultCropOrigin components must be >= 0, got (%.6F, %.6F).',
                        $originH,
                        $originV,
                    ),
                    1599,
                );
            }
        }

        $defaultCropSize = $ifd->get(DngTag::DEFAULT_CROP_SIZE);
        if ($defaultCropSize instanceof IfdEntry) {
            if (
                !in_array(
                    $defaultCropSize->type,
                    [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG, TiffConst::TYPE_RATIONAL],
                    true,
                )
                || ($defaultCropSize->count !== 2)
            ) {
                throw new ParseError(
                    sprintf(
                        'DefaultCropSize must be SHORT|LONG|RATIONAL with count 2, got type %d count %d.',
                        $defaultCropSize->type,
                        $defaultCropSize->count,
                    ),
                    1600,
                );
            }

            [$sizeH, $sizeV] = $this->extractDngCropScalePair($defaultCropSize, 'DefaultCropSize');
            if (($sizeH <= 0.0) || ($sizeV <= 0.0)) {
                throw new ParseError(
                    sprintf(
                        'DefaultCropSize components must be > 0, got (%.6F, %.6F).',
                        $sizeH,
                        $sizeV,
                    ),
                    1601,
                );
            }
        }
    }

    /**
     * Validates DNG original proxy-size tags and their fallback semantics.
     *
     * DNG 1.7.1.0 ("OriginalDefaultFinalSize", "OriginalBestQualityFinalSize",
     * "OriginalDefaultCropSize") defines:
     * - OriginalDefaultFinalSize: SHORT|LONG[2], width/length > 0
     * - OriginalBestQualityFinalSize: SHORT|LONG[2], width/length > 0
     * - OriginalDefaultCropSize: SHORT|LONG|RATIONAL[2], width/length > 0
     *
     * Defaults:
     * - OriginalBestQualityFinalSize defaults to OriginalDefaultFinalSize if specified.
     * - OriginalDefaultCropSize defaults to OriginalDefaultFinalSize if specified.
     * - If OriginalDefaultFinalSize is absent, defaults continue to current-file values.
     */
    private function validateDngOriginalProxySizes(Ifd $ifd): void
    {
        $originalDefaultFinalSize = $this->extractDngOriginalProxySize(
            $ifd,
            DngTag::ORIGINAL_DEFAULT_FINAL_SIZE,
            'OriginalDefaultFinalSize',
            [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG],
        );
        $originalBestQualityFinalSize = $this->extractDngOriginalProxySize(
            $ifd,
            DngTag::ORIGINAL_BEST_QUALITY_FINAL_SIZE,
            'OriginalBestQualityFinalSize',
            [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG],
        );
        $originalDefaultCropSize = $this->extractDngOriginalProxySize(
            $ifd,
            DngTag::ORIGINAL_DEFAULT_CROP_SIZE,
            'OriginalDefaultCropSize',
            [TiffConst::TYPE_SHORT, TiffConst::TYPE_LONG, TiffConst::TYPE_RATIONAL],
        );

        // Fallback semantics: missing best-quality/crop size inherit from
        // OriginalDefaultFinalSize when it is explicitly present.
        if (($originalBestQualityFinalSize === null) && ($originalDefaultFinalSize !== null)) {
            $originalBestQualityFinalSize = $originalDefaultFinalSize;
        }

        if (($originalDefaultCropSize === null) && ($originalDefaultFinalSize !== null)) {
            $originalDefaultCropSize = $originalDefaultFinalSize;
        }

        // When OriginalDefaultFinalSize is absent, defaults are based on current-file
        // size tags; omission is valid and intentionally non-fatal.
    }

    /**
     * Validates BestQualityScale (0xC65C) per DNG 1.7.1.0.
     *
     * Must be RATIONAL[1] with a strictly positive numeric value.
     */
    private function validateDngBestQualityScale(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::BEST_QUALITY_SCALE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_RATIONAL) || ($entry->count !== 1)) {
            throw new ParseError(
                sprintf(
                    'BestQualityScale must be RATIONAL[1], got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1641,
            );
        }

        $value = $entry->value;
        if (!$value instanceof ExifRational) {
            throw new ParseError('BestQualityScale must decode to one rational component.', 1642);
        }

        if ($value->denominator <= 0) {
            throw new ParseError('BestQualityScale denominator must be > 0.', 1643);
        }

        if (($value->numerator / $value->denominator) <= 0.0) {
            throw new ParseError('BestQualityScale value must be > 0.', 1644);
        }
    }

    /**
     * Validates LinearResponseLimit (0xC62E) per DNG 1.7.1.0.
     *
     * Must be RATIONAL[1] with fraction semantics: 0 < value <= 1.0.
     */
    private function validateDngLinearResponseLimit(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::LINEAR_RESPONSE_LIMIT);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_RATIONAL) || ($entry->count !== 1)) {
            throw new ParseError(
                sprintf(
                    'LinearResponseLimit must be RATIONAL[1], got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1645,
            );
        }

        $value = $entry->value;
        if (!$value instanceof ExifRational) {
            throw new ParseError('LinearResponseLimit must decode to one rational component.', 1646);
        }

        if ($value->denominator <= 0) {
            throw new ParseError('LinearResponseLimit denominator must be > 0.', 1647);
        }

        $limit = $value->numerator / $value->denominator;
        if (($limit <= 0.0) || ($limit > 1.0)) {
            throw new ParseError(
                sprintf('LinearResponseLimit must be in (0.0, 1.0], got %.6F.', $limit),
                1648,
            );
        }
    }

    /**
     * Validates LinearizationTable (0xC618) DNG layout.
     *
     * DNG 1.7.1.0 defines LinearizationTable as a non-empty SHORT lookup table.
     */
    private function validateDngLinearizationTable(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::LINEARIZATION_TABLE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_SHORT) || ($entry->count < 1)) {
            throw new ParseError(
                sprintf(
                    'LinearizationTable must be SHORT with count >= 1, got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1671,
            );
        }
    }

    /**
     * Validates BayerGreenSplit (0xC62D) in DNG contexts.
     *
     * DNG 1.7.1.0 defines BayerGreenSplit as LONG[1], non-negative, and
     * applicable to Bayer CFA images.
     *
     * Applicability is enforced when contextual tags are present:
     * - PhotometricInterpretation must be CFA (32803)
     * - CFARepeatPatternDim must be 2x2 for Bayer
     */
    private function validateDngBayerGreenSplit(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::BAYER_GREEN_SPLIT);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_LONG) || ($entry->count !== 1)) {
            throw new ParseError(
                sprintf(
                    'BayerGreenSplit must be LONG[1], got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1658,
            );
        }

        if (!is_int($entry->value) || ($entry->value < 0)) {
            throw new ParseError(
                sprintf('BayerGreenSplit must be a non-negative scalar, got %d.', is_int($entry->value) ? $entry->value : -1),
                1659,
            );
        }

        $photo = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (($photo instanceof IfdEntry) && is_int($photo->value) && ($photo->value !== Photometric::CFA->value)) {
            throw new ParseError(
                sprintf(
                    'BayerGreenSplit requires PhotometricInterpretation=%d, got %d.',
                    Photometric::CFA->value,
                    $photo->value,
                ),
                1660,
            );
        }

        $repeat = $ifd->get(DngTag::CFA_REPEAT_PATTERN_DIM);

        if (!$repeat instanceof IfdEntry || !$repeat->value instanceof ExifNumericList || count($repeat->value->values) !== 2) {
            return;
        }

        $rows = $repeat->value->values[0];
        $cols = $repeat->value->values[1];

        if (!is_int($rows) || !is_int($cols)) {
            return;
        }

        if (($rows !== 2) || ($cols !== 2)) {
            throw new ParseError(
                sprintf('BayerGreenSplit requires Bayer CFARepeatPatternDim=2x2, got %dx%d.', $rows, $cols),
                1661,
            );
        }
    }

    /**
     * Validates DNG rendering scalar tags.
     *
     * DNG 1.7.1.0 defines these tags as RATIONAL[1] processing controls:
     * - ChromaBlurRadius (>= 0)
     * - AntiAliasStrength (>= 0)
     * - ShadowScale (> 0)
     */
    private function validateDngRenderScalars(Ifd $ifd): void
    {
        /** @var array<int, array{name: string, strictPositive: bool}> $tagRules */
        $tagRules = [
            DngTag::CHROMA_BLUR_RADIUS  => ['name' => 'ChromaBlurRadius', 'strictPositive' => false],
            DngTag::ANTI_ALIAS_STRENGTH => ['name' => 'AntiAliasStrength', 'strictPositive' => false],
            DngTag::SHADOW_SCALE        => ['name' => 'ShadowScale', 'strictPositive' => true],
        ];

        foreach ($tagRules as $tag => $rule) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (($entry->type !== TiffConst::TYPE_RATIONAL) || ($entry->count !== 1)) {
                throw new ParseError(
                    sprintf(
                        '%s must be RATIONAL[1], got type %d count %d.',
                        $rule['name'],
                        $entry->type,
                        $entry->count,
                    ),
                    1662,
                );
            }

            if (!$entry->value instanceof ExifRational) {
                throw new ParseError(
                    sprintf('%s must decode to one rational component.', $rule['name']),
                    1663,
                );
            }

            if ($entry->value->denominator <= 0) {
                throw new ParseError(
                    sprintf('%s denominator must be > 0.', $rule['name']),
                    1664,
                );
            }

            $scalar = $entry->value->numerator / $entry->value->denominator;

            if (!is_finite($scalar)) {
                throw new ParseError(
                    sprintf('%s must be finite.', $rule['name']),
                    1665,
                );
            }

            if ($rule['strictPositive'] ? ($scalar <= 0.0) : ($scalar < 0.0)) {
                throw new ParseError(
                    sprintf(
                        '%s must be %s, got %.6F.',
                        $rule['name'],
                        $rule['strictPositive'] ? '> 0' : '>= 0',
                        $scalar,
                    ),
                    1666,
                );
            }
        }
    }

    /**
     * Validates LensInfo (0xC630) per DNG 1.7.1.0.
     *
     * Must be RATIONAL[4] in this order:
     * 1) min focal length, 2) max focal length, 3) min f-stop at min focal,
     * 4) min f-stop at max focal.
     *
     * Aperture fields may use 0/0 to indicate unknown values.
     */
    private function validateDngLensInfo(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::LENS_INFO);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_RATIONAL) || ($entry->count !== 4)) {
            throw new ParseError(
                sprintf(
                    'LensInfo must be RATIONAL[4], got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1649,
            );
        }

        $value = $entry->value;
        if (!$value instanceof ExifRationalList || count($value->values) !== 4) {
            throw new ParseError('LensInfo must decode to four rational components.', 1650);
        }

        $components = [];
        foreach ($value->values as $index => $component) {
            if ($component->denominator === 0) {
                $isApertureField = $index >= 2;
                if ($isApertureField && $component->numerator === 0) {
                    $components[] = null;
                    continue;
                }

                throw new ParseError(
                    sprintf('LensInfo component %d has invalid zero denominator.', $index),
                    1651,
                );
            }

            $components[] = $component->numerator / $component->denominator;
        }

        $minFocal = (float) $components[0];
        $maxFocal = (float) $components[1];

        if ($minFocal > $maxFocal) {
            throw new ParseError(
                sprintf(
                    'LensInfo minimum focal length %.6F must be <= maximum focal length %.6F.',
                    $minFocal,
                    $maxFocal,
                ),
                1653,
            );
        }
    }

    /**
     * Validates DNG AsShot/Current ICC profile and pre-profile matrix pairs.
     *
     * DNG 1.7.1.0 defines paired usage:
     * - AsShotICCProfile with AsShotPreProfileMatrix
     * - CurrentICCProfile with CurrentPreProfileMatrix
     *
     * ICC payload tags must be UNDEFINED and structurally valid ICC blobs.
     * Matrix tags must be SRATIONAL with count = (3 * ColorPlanes) or (ColorPlanes^2).
     */
    private function validateDngIccProfilePairs(Ifd $ifd): void
    {
        /** @var list<array{iccTag: int, iccName: string, matrixTag: int, matrixName: string}> $pairs */
        $pairs = [
            [
                'iccTag'     => DngTag::AS_SHOT_ICC_PROFILE,
                'iccName'    => 'AsShotICCProfile',
                'matrixTag'  => DngTag::AS_SHOT_PRE_PROFILE_MATRIX,
                'matrixName' => 'AsShotPreProfileMatrix',
            ],
            [
                'iccTag'     => DngTag::CURRENT_ICC_PROFILE,
                'iccName'    => 'CurrentICCProfile',
                'matrixTag'  => DngTag::CURRENT_PRE_PROFILE_MATRIX,
                'matrixName' => 'CurrentPreProfileMatrix',
            ],
        ];

        $colorPlanes = $this->resolveDngColorPlanes($ifd);
        $iccParser   = new IccParser();

        foreach ($pairs as $pair) {
            $iccEntry    = $ifd->get($pair['iccTag']);
            $matrixEntry = $ifd->get($pair['matrixTag']);
            $hasIcc      = $iccEntry instanceof IfdEntry;
            $hasMatrix   = $matrixEntry instanceof IfdEntry;

            if (!$hasIcc && !$hasMatrix) {
                continue;
            }

            if ($hasIcc !== $hasMatrix) {
                throw new ParseError(
                    sprintf(
                        '%s and %s must be present as a pair per DNG 1.7.1.0.',
                        $pair['iccName'],
                        $pair['matrixName'],
                    ),
                    1676,
                );
            }

            /** @var IfdEntry $iccEntry */
            /** @var IfdEntry $matrixEntry */
            if (
                ($iccEntry->type !== TiffConst::TYPE_UNDEFINED)
                || ($iccEntry->count < 1)
                || !is_string($iccEntry->value)
                || (strlen($iccEntry->value) !== $iccEntry->count)
            ) {
                throw new ParseError(
                    sprintf(
                        '%s must be UNDEFINED with byte-count matching payload length, got type %d count %d.',
                        $pair['iccName'],
                        $iccEntry->type,
                        $iccEntry->count,
                    ),
                    1677,
                );
            }

            try {
                $iccParser->decode($iccEntry->value);
            } catch (ParseError $exception) {
                throw new ParseError(
                    sprintf('%s payload is not a valid ICC profile: %s', $pair['iccName'], $exception->getMessage()),
                    1678,
                );
            }

            if ($colorPlanes === null) {
                throw new ParseError(
                    sprintf('%s requires resolvable ColorPlanes context.', $pair['matrixName']),
                    1679,
                );
            }

            if (($matrixEntry->type !== TiffConst::TYPE_SRATIONAL) || ($matrixEntry->count < 1)) {
                throw new ParseError(
                    sprintf(
                        '%s must be SRATIONAL with positive count, got type %d count %d.',
                        $pair['matrixName'],
                        $matrixEntry->type,
                        $matrixEntry->count,
                    ),
                    1680,
                );
            }

            $count3n = $colorPlanes * 3;
            $countNn = $colorPlanes * $colorPlanes;

            if (($matrixEntry->count !== $count3n) && ($matrixEntry->count !== $countNn)) {
                throw new ParseError(
                    sprintf(
                        '%s count %d must be 3*ColorPlanes (%d) or ColorPlanes^2 (%d).',
                        $pair['matrixName'],
                        $matrixEntry->count,
                        $count3n,
                        $countNn,
                    ),
                    1681,
                );
            }

            if (
                !$matrixEntry->value instanceof ExifRationalList
                || count($matrixEntry->value->values) !== $matrixEntry->count
            ) {
                throw new ParseError(
                    sprintf('%s must decode to SRATIONAL list with %d components.', $pair['matrixName'], $matrixEntry->count),
                    1682,
                );
            }

            foreach ($matrixEntry->value->values as $index => $component) {
                if ($component->denominator === 0) {
                    throw new ParseError(
                        sprintf('%s component %d denominator must not be zero.', $pair['matrixName'], $index),
                        1683,
                    );
                }

                $value = $component->numerator / $component->denominator;

                if (!is_finite($value)) {
                    throw new ParseError(
                        sprintf('%s component %d must be finite.', $pair['matrixName'], $index),
                        1684,
                    );
                }
            }
        }
    }

    /**
     * Validates BaselineExposure (0xC62A) DNG layout and scalar sanity.
     *
     * DNG 1.7.1.0 defines BaselineExposure as SRATIONAL[1] EV offset.
     */
    private function validateDngBaselineExposure(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::BASELINE_EXPOSURE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (($entry->type !== TiffConst::TYPE_SRATIONAL) || ($entry->count !== 1)) {
            throw new ParseError(
                sprintf(
                    'BaselineExposure must be SRATIONAL[1], got type %d count %d.',
                    $entry->type,
                    $entry->count,
                ),
                1672,
            );
        }

        $value = $entry->value;

        if (!$value instanceof ExifRational) {
            throw new ParseError('BaselineExposure must decode to one rational component.', 1673);
        }

        if ($value->denominator === 0) {
            throw new ParseError('BaselineExposure denominator must not be zero.', 1674);
        }

        $scalar = $value->numerator / $value->denominator;

        if (!is_finite($scalar)) {
            throw new ParseError('BaselineExposure must be finite.', 1675);
        }
    }

    /**
     * Validates BaselineNoise and BaselineSharpness scalar tags per DNG 1.7.1.0.
     *
     * Both tags must be RATIONAL[1] with strictly positive finite values.
     *
     * @return void
     */
    private function validateDngBaselineScalars(Ifd $ifd): void
    {
        $tagNames = [
            DngTag::BASELINE_NOISE     => 'BaselineNoise',
            DngTag::BASELINE_SHARPNESS => 'BaselineSharpness',
        ];

        foreach ($tagNames as $tag => $name) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (($entry->type !== TiffConst::TYPE_RATIONAL) || ($entry->count !== 1)) {
                throw new ParseError(
                    sprintf(
                        '%s must be RATIONAL[1], got type %d count %d.',
                        $name,
                        $entry->type,
                        $entry->count,
                    ),
                    1654,
                );
            }

            $value = $entry->value;
            if (!$value instanceof ExifRational) {
                throw new ParseError(
                    sprintf('%s must decode to one rational component.', $name),
                    1655,
                );
            }

            if ($value->denominator <= 0) {
                throw new ParseError(
                    sprintf('%s denominator must be > 0.', $name),
                    1656,
                );
            }

            $scalar = $value->numerator / $value->denominator;
            if (!is_finite($scalar) || ($scalar <= 0.0)) {
                throw new ParseError(
                    sprintf('%s must be a positive finite scalar, got %.6F.', $name, $scalar),
                    1657,
                );
            }
        }
    }

    /**
     * Extracts and validates one optional original proxy-size tag.
     *
     * @param list<int> $allowedTypes Allowed TIFF types for this tag.
     *
     * @return array{0: float, 1: float}|null
     */
    private function extractDngOriginalProxySize(
        Ifd $ifd,
        int $tag,
        string $tagName,
        array $allowedTypes,
    ): ?array {
        $entry = $ifd->get($tag);

        if (!$entry instanceof IfdEntry) {
            return null;
        }

        if (!in_array($entry->type, $allowedTypes, true) || ($entry->count !== 2)) {
            throw new ParseError(
                sprintf(
                    '%s must use %s with count 2, got type %d count %d.',
                    $tagName,
                    $this->describeAllowedTiffTypes($allowedTypes),
                    $entry->type,
                    $entry->count,
                ),
                1639,
            );
        }

        [$width, $length] = $this->extractDngCropScalePair($entry, $tagName);
        if (($width <= 0.0) || ($length <= 0.0)) {
            throw new ParseError(
                sprintf(
                    '%s components must be > 0, got (%.6F, %.6F).',
                    $tagName,
                    $width,
                    $length,
                ),
                1640,
            );
        }

        return [$width, $length];
    }

    /**
     * Builds a human-readable TIFF type list for validation errors.
     *
     * @param list<int> $types Allowed TIFF type identifiers.
     */
    private function describeAllowedTiffTypes(array $types): string
    {
        $names = [];
        foreach ($types as $type) {
            $names[] = match ($type) {
                TiffConst::TYPE_SHORT    => 'SHORT',
                TiffConst::TYPE_LONG     => 'LONG',
                TiffConst::TYPE_RATIONAL => 'RATIONAL',
                default                  => (string) $type,
            };
        }

        return implode('|', $names);
    }

    /**
     * Extracts two numeric components from crop/scale DNG tags.
     *
     * @return array{0: float, 1: float}
     */
    private function extractDngCropScalePair(IfdEntry $entry, string $tagName): array
    {
        $value = $entry->value;

        if ($value instanceof ExifRationalList) {
            if (count($value->values) !== 2) {
                throw new ParseError(
                    sprintf('%s must decode to exactly two components.', $tagName),
                    1602,
                );
            }

            $values = [];
            foreach ($value->values as $rational) {
                if ($rational->denominator <= 0) {
                    throw new ParseError(
                        sprintf('%s rational components must have denominator > 0.', $tagName),
                        1603,
                    );
                }

                $values[] = $rational->numerator / $rational->denominator;
            }

            return [$values[0], $values[1]];
        }

        if ($value instanceof ExifNumericList) {
            if (count($value->values) !== 2) {
                throw new ParseError(
                    sprintf('%s must decode to exactly two components.', $tagName),
                    1602,
                );
            }

            $values = [];
            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $values[] = (float) $component->toInt(sprintf('%s component', $tagName));
                } else {
                    $values[] = (float) $component;
                }
            }

            return [$values[0], $values[1]];
        }

        throw new ParseError(
            sprintf('%s must decode to a two-component numeric payload.', $tagName),
            1604,
        );
    }

    /**
     * Validates DefaultUserCrop (0xC7B5) per DNG 1.7.1.0.
     *
     * Must be RATIONAL[4]: (Top, Left, Bottom, Right) with 0 <= Top < Bottom <= 1.0
     * and 0 <= Left < Right <= 1.0.
     */
    private function validateDngDefaultUserCrop(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::DEFAULT_USER_CROP);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_RATIONAL || $entry->count !== 4) {
            throw new ParseError(
                sprintf('DefaultUserCrop must be RATIONAL[4], got type %d count %d.', $entry->type, $entry->count),
                1565,
            );
        }

        $value = $entry->value;

        if (!$value instanceof ExifRationalList || count($value->values) !== 4) {
            return;
        }

        $top    = $value->values[0]->denominator !== 0 ? $value->values[0]->numerator / $value->values[0]->denominator : -1.0;
        $left   = $value->values[1]->denominator !== 0 ? $value->values[1]->numerator / $value->values[1]->denominator : -1.0;
        $bottom = $value->values[2]->denominator !== 0 ? $value->values[2]->numerator / $value->values[2]->denominator : -1.0;
        $right  = $value->values[3]->denominator !== 0 ? $value->values[3]->numerator / $value->values[3]->denominator : -1.0;

        if ($top < 0.0 || $left < 0.0 || $bottom > 1.0 || $right > 1.0) {
            throw new ParseError(
                sprintf('DefaultUserCrop values must be in [0.0, 1.0], got (%.4f, %.4f, %.4f, %.4f).', $top, $left, $bottom, $right),
                1566,
            );
        }

        if ($top >= $bottom) {
            throw new ParseError(
                sprintf('DefaultUserCrop requires Top < Bottom, got %.4f >= %.4f.', $top, $bottom),
                1567,
            );
        }

        if ($left >= $right) {
            throw new ParseError(
                sprintf('DefaultUserCrop requires Left < Right, got %.4f >= %.4f.', $left, $right),
                1568,
            );
        }
    }

    /**
     * Validates DefaultBlackRender (0xC7A6) per DNG 1.7.1.0.
     *
     * Must be LONG[1] with value 0 (Auto) or 1 (None).
     */
    private function validateDngDefaultBlackRender(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::DEFAULT_BLACK_RENDER);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 1) {
            throw new ParseError(
                sprintf('DefaultBlackRender must be LONG[1], got type %d count %d.', $entry->type, $entry->count),
                1569,
            );
        }

        if (!is_int($entry->value) || ($entry->value !== 0 && $entry->value !== 1)) {
            throw new ParseError(
                sprintf('DefaultBlackRender value must be 0 (Auto) or 1 (None), got %d.', is_int($entry->value) ? $entry->value : -1),
                1570,
            );
        }
    }

    /**
     * Validates DNG depth enum tags per DNG 1.7.1.0.
     *
     * DepthFormat: SHORT[1], allowed {0,1,2}
     * DepthUnits: SHORT[1], allowed {0,1}
     * DepthMeasureType: SHORT[1], allowed {0,1,2}
     */
    private function validateDngDepthEnums(Ifd $ifd): void
    {
        $rules = [
            DngTag::DEPTH_FORMAT       => ['name' => 'DepthFormat', 'allowed' => [0, 1, 2]],
            DngTag::DEPTH_UNITS        => ['name' => 'DepthUnits', 'allowed' => [0, 1]],
            DngTag::DEPTH_MEASURE_TYPE => ['name' => 'DepthMeasureType', 'allowed' => [0, 1, 2]],
        ];

        foreach ($rules as $tag => $config) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (!is_int($entry->value) || !in_array($entry->value, $config['allowed'], true)) {
                throw new ParseError(
                    sprintf(
                        '%s value %d is out of domain per DNG 1.7.1.0.',
                        $config['name'],
                        is_int($entry->value) ? $entry->value : -1,
                    ),
                    1574,
                );
            }
        }
    }

    /**
     * Validates EnhanceParams (0xC7EE) per DNG 1.7.1.0.
     *
     * Must be ASCII type with a non-empty NUL-terminated string.
     */
    private function validateDngEnhanceParams(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::ENHANCE_PARAMS);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_ASCII) {
            throw new ParseError(
                sprintf('EnhanceParams must use ASCII type, got %d.', $entry->type),
                1575,
            );
        }

        if (!is_string($entry->value) || $entry->value === '') {
            throw new ParseError(
                'EnhanceParams must not be empty per DNG 1.7.1.0.',
                1576,
            );
        }
    }

    /**
     * Validates SubTileBlockSize (0xC71E) per DNG 1.7.1.0.
     *
     * Must be (SHORT|LONG)[2] with both components >= 1.
     */
    private function validateDngSubTileBlockSize(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::SUB_TILE_BLOCK_SIZE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (
            ($entry->type !== TiffConst::TYPE_SHORT && $entry->type !== TiffConst::TYPE_LONG)
            || $entry->count !== 2
        ) {
            throw new ParseError(
                sprintf('SubTileBlockSize must be (SHORT|LONG)[2], got type %d count %d.', $entry->type, $entry->count),
                1577,
            );
        }

        if (!$entry->value instanceof ExifNumericList) {
            return;
        }

        $rows = $entry->value->values[0];
        $cols = $entry->value->values[1];

        if (!is_int($rows) || !is_int($cols)) {
            return;
        }

        if ($rows < 1 || $cols < 1) {
            throw new ParseError(
                sprintf('SubTileBlockSize components must be >= 1, got %d, %d.', $rows, $cols),
                1578,
            );
        }
    }

    /**
     * Validates RowInterleaveFactor (0xC71F) per DNG 1.7.1.0.
     *
     * Must be (SHORT|LONG)[1] with value >= 1.
     */
    private function validateDngRowInterleaveFactor(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::ROW_INTERLEAVE_FACTOR);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (
            ($entry->type !== TiffConst::TYPE_SHORT && $entry->type !== TiffConst::TYPE_LONG)
            || $entry->count !== 1
        ) {
            throw new ParseError(
                sprintf('RowInterleaveFactor must be (SHORT|LONG)[1], got type %d count %d.', $entry->type, $entry->count),
                1579,
            );
        }

        if (!is_int($entry->value) || $entry->value < 1) {
            throw new ParseError(
                sprintf('RowInterleaveFactor must be >= 1, got %d.', is_int($entry->value) ? $entry->value : -1),
                1580,
            );
        }
    }

    /**
     * Validates NoiseReductionApplied (0xC6F7) per DNG 1.7.1.0.
     *
     * Must be RATIONAL[1]. Special sentinel 0/0 means unknown.
     * Otherwise the value must be in the range [0.0, 1.0].
     */
    private function validateDngNoiseReductionApplied(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::NOISE_REDUCTION_APPLIED);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (!$entry->value instanceof ExifRational) {
            return;
        }

        // 0/0 sentinel means unknown
        if ($entry->value->numerator === 0 && $entry->value->denominator === 0) {
            return;
        }

        if ($entry->value->denominator === 0) {
            throw new ParseError(
                'NoiseReductionApplied has zero denominator without 0/0 sentinel.',
                1581,
            );
        }

        $value = $entry->value->numerator / $entry->value->denominator;

        if ($value < 0.0 || $value > 1.0) {
            throw new ParseError(
                sprintf('NoiseReductionApplied must be in [0.0, 1.0], got %.4f.', $value),
                1582,
            );
        }
    }

    /**
     * Validates ProfileEmbedPolicy (0xC6FD) per DNG 1.7.1.0.
     *
     * Must be LONG[1] with value in {0,1,2,3}.
     */
    private function validateDngProfileEmbedPolicy(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PROFILE_EMBED_POLICY);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (!is_int($entry->value) || $entry->value < 0 || $entry->value > 3) {
            throw new ParseError(
                sprintf('ProfileEmbedPolicy value must be 0..3, got %d.', is_int($entry->value) ? $entry->value : -1),
                1583,
            );
        }
    }

    /**
     * Validates CFALayout (0xC617) value domain and version gating per DNG 1.7.1.0.
     *
     * Allowed values are 1..9. Values 6..9 require DNGBackwardVersion >= 1.3.0.0.
     */
    private function validateDngCfaLayoutDomain(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::CFA_LAYOUT);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (!is_int($entry->value) || $entry->value < 1 || $entry->value > 9) {
            throw new ParseError(
                sprintf('CFALayout value must be 1..9, got %d.', is_int($entry->value) ? $entry->value : -1),
                1584,
            );
        }

        if ($entry->value >= 6) {
            $bwEntry = $ifd->get(DngTag::DNG_BACKWARD_VERSION);

            if ($bwEntry instanceof IfdEntry && $bwEntry->value instanceof ExifNumericList) {
                $bwVer = [];

                foreach ($bwEntry->value->values as $c) {
                    if (!is_int($c)) {
                        return;
                    }

                    $bwVer[] = $c;
                }

                if (count($bwVer) === 4 && $this->dngVersionLessThan($bwVer, [1, 3, 0, 0])) {
                    throw new ParseError(
                        sprintf(
                            'CFALayout value %d requires DNGBackwardVersion >= 1.3.0.0, got %d.%d.%d.%d.',
                            $entry->value,
                            $bwVer[0],
                            $bwVer[1],
                            $bwVer[2],
                            $bwVer[3],
                        ),
                        1585,
                    );
                }
            }
        }
    }
}
