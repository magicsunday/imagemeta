#!/usr/bin/env php
<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Scripts;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Icc\IccTag;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\Mpf\MpfEntry;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Exif\Text\UndefinedTextMarker;
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\CorrectionApplied;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDifferential;
use MagicSunday\ImageMeta\Value\Enum\GpsDirectionRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDistanceRef;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use MagicSunday\ImageMeta\Value\Enum\GpsMeasureMode;
use MagicSunday\ImageMeta\Value\Enum\GpsSpeedRef;
use MagicSunday\ImageMeta\Value\Enum\GpsStatus;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\NoiseReduction;
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
use MagicSunday\ImageMeta\Value\DeviceSettingDescription;
use MagicSunday\ImageMeta\Model\Xmp\XmpLanguageAlternative;
use MagicSunday\ImageMeta\Exif\Converters\ExifFlash;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Mpf\MpfAttributes;
use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\FlashInfo;
use MagicSunday\ImageMeta\Value\LearningOptOutIn;
use MagicSunday\ImageMeta\Value\RunTime;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

use function count;
use function dirname;
use function file_exists;
use function fileatime;
use function filectime;
use function filemtime;
use function fileperms;
use function filesize;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function number_format;
use function round;
use function rtrim;
use function sprintf;
use function strlen;
use function realpath;

// Check if we're using composer or standalone
$autoloadPaths = [
    __DIR__ . '/../.build/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

/**
 * Formats metadata in an exiftool-like output format.
 *
 * This script generates output similar to 'exiftool -H -a -u -g1' for comparing
 * metadata extraction results between this library and exiftool.
 *
 * The output format mimics exiftool's grouped display with:
 * - Hexadecimal tag IDs for EXIF tags (e.g., 0x010f)
 * - Tag names and values aligned with colons
 * - Sections grouped by IFD (IFD0, ExifIFD, GPS, etc.)
 * - File system metadata (System section)
 * - Container information (File section)
 * - XMP data grouped by namespace
 * - Apple MakerNotes details
 * - Composite/derived values
 *
 * Usage:
 *   php scripts/imagemeta-format.php <image-file>
 *
 * Examples:
 *   php scripts/imagemeta-format.php photo.jpg
 *   php scripts/imagemeta-format.php /path/to/image.heic
 *
 * To compare with actual exiftool output:
 *   exiftool -H -a -u -g1 photo.jpg > exiftool.txt
 *   php scripts/imagemeta-format.php photo.jpg > imagemeta.txt
 *   diff -u exiftool.txt imagemeta.txt
 */
final class MetadataFormatter
{
    private const string VERSION = '1.0.0';

    /**
     * exiftool-compatible labels for enum case names that are not cleanly derived
     * from generic case conversion rules.
     *
     * @var array<string, string>
     */
    private const array ENUM_LABEL_OVERRIDES = [
        'Srgb'                     => 'sRGB',
        'Pattern'                  => 'Multi-segment',
        'CoSited'                  => 'Co-sited',
        'OneChipColorArea'         => 'One-chip Color Area',
        'AboveEllipsoidalSurface'  => 'Above Sea Level',
        'BelowEllipsoidalSurface'  => 'Below Sea Level',
        'DirectlyPhotographedImage' => 'Directly Photographed',
        'CompulsoryFire'           => 'Compulsory Fire',
        'CompulsorySuppress'       => 'Compulsory Suppress',
        'NoStrobeDetection'        => 'No Strobe Detection',
        'NormalProcess'            => 'Normal',
    ];

    /**
     * Maps QuickTime metadata keys to numeric QuickTime tag labels for exiftool-like output.
     *
     * @var array<string, string>
     */
    private const array QUICKTIME_KEY_LABELS = [
        QuickTimeMeta::MAJOR_BRAND_KEY       => '0x0000 Major Brand',
        QuickTimeMeta::MINOR_VERSION_KEY     => '0x0001 Minor Version',
        QuickTimeMeta::COMPATIBLE_BRANDS_KEY => '0x0002 Compatible Brands',
    ];

    /**
     * Maps tag IDs to their human-readable names for EXIF tags.
     *
     * @var array<int, string>
     */
    private array $exifTagNames = [];

    /**
     * Maps tag IDs to their human-readable names for TIFF tags.
     *
     * @var array<int, string>
     */
    private array $tiffTagNames = [];

    /**
     * Maps tag IDs to their human-readable names for GPS IFD tags.
     *
     * @var array<int, string>
     */
    private array $gpsTagNames = [];

    /**
     * Maps tag IDs to their human-readable names for Interoperability IFD tags.
     *
     * @var array<int, string>
     */
    private array $interopTagNames = [];

    /**
     * Maps XMP properties (namespace + local name) to their corresponding enum class names.
     *
     * This mapping enables conversion of raw XMP values to typed enum instances
     * for better formatting and display, similar to EXIF enum mapping.
     *
     * @var array<string, class-string<BackedEnum>>
     */
    private array $xmpPropertyToEnumMap = [];

    private ValueConverters $converters;

    public function __construct()
    {
        $this->converters = new ValueConverters();
        $this->buildTagMaps();
        $this->buildXmpEnumMap();
    }

    /**
     * Maps EXIF tag IDs to their corresponding enum class names.
     *
     * This mapping enables conversion of raw EXIF values (typically integers)
     * to typed enum instances for better formatting and display.
     *
     * EXIF 3.0 §4.6 and earlier specifications define these enumerated values.
     *
     * @var array<int, class-string<BackedEnum>>
     */
    private array $tagToEnumMap = [];

    /**
     * Builds reverse mapping from tag IDs to tag names.
     */
    private function buildTagMaps(): void
    {
        // Build EXIF tag map
        $exifReflection = new ReflectionClass(ExifTag::class);
        foreach ($exifReflection->getConstants() as $name => $value) {
            if (is_int($value)) {
                $tagName = $this->constantNameToTagName($name);

                // IFD pointer tags appear in IFD0, not in their target IFDs
                // So we keep them in the main EXIF tag map
                $isIfdPointer = str_ends_with($name, '_IFD_POINTER');

                // Separate GPS and Interoperability tags into their own maps
                // unless they're IFD pointers
                if (!$isIfdPointer && str_starts_with($name, 'GPS_')) {
                    $this->gpsTagNames[$value] = $tagName;
                } elseif (!$isIfdPointer && str_starts_with($name, 'INTEROPERABILITY_')) {
                    $this->interopTagNames[$value] = $tagName;
                } else {
                    $this->exifTagNames[$value] = $tagName;
                }
            }
        }

        // Build TIFF tag map
        $tiffReflection = new ReflectionClass(TiffTag::class);
        foreach ($tiffReflection->getConstants() as $name => $value) {
            if (is_int($value)) {
                $this->tiffTagNames[$value] = $this->constantNameToTagName($name);
            }
        }

        // Build tag-to-enum mapping
        // Maps EXIF tag IDs to their corresponding enum classes
        $this->tagToEnumMap = [
            // IFD0 / TIFF tags - EXIF 3.0 §4.6.2, TIFF 6.0 §8
            ExifTag::ORIENTATION                => Orientation::class,
            ExifTag::COMPRESSION                => Compression::class,
            ExifTag::PHOTOMETRIC_INTERPRETATION => Photometric::class,
            ExifTag::PLANAR_CONFIGURATION       => PlanarConfiguration::class,
            ExifTag::RESOLUTION_UNIT            => ResolutionUnit::class,
            ExifTag::YCBCR_POSITIONING          => YCbCrPositioning::class,

            // ExifIFD tags - EXIF 3.0 §4.6.3, §4.6.4
            ExifTag::COLOR_SPACE            => ColorSpace::class,
            ExifTag::EXPOSURE_PROGRAM       => ExposureProgram::class,
            ExifTag::METERING_MODE          => MeteringMode::class,
            ExifTag::LIGHT_SOURCE           => LightSource::class,
            ExifTag::WHITE_BALANCE          => WhiteBalance::class,
            ExifTag::EXPOSURE_MODE          => ExposureMode::class,
            ExifTag::SCENE_CAPTURE_TYPE     => SceneCaptureType::class,
            ExifTag::GAIN_CONTROL           => GainControl::class,
            ExifTag::CONTRAST               => Contrast::class,
            ExifTag::SATURATION             => Saturation::class,
            ExifTag::SHARPNESS              => Sharpness::class,
            ExifTag::SUBJECT_DISTANCE_RANGE => SubjectDistanceRange::class,
            ExifTag::SENSING_METHOD         => SensingMethod::class,
            ExifTag::FILE_SOURCE            => FileSource::class,
            ExifTag::SCENE_TYPE             => SceneType::class,
            ExifTag::CUSTOM_RENDERED                => CustomRendered::class,
            ExifTag::COMPOSITE_IMAGE                => CompositeImage::class,
            ExifTag::DISTORTION_CORRECTION          => CorrectionApplied::class,
            ExifTag::CHROMATIC_ABERRATION_CORRECTION => CorrectionApplied::class,
            ExifTag::SHADING_CORRECTION             => CorrectionApplied::class,
            ExifTag::NOISE_REDUCTION                => NoiseReduction::class,

            // GPS tags - EXIF 3.0 §4.6.6 Table 27
            ExifTag::GPS_LATITUDE_REF       => GpsLatLonRef::class,
            ExifTag::GPS_LONGITUDE_REF      => GpsLatLonRef::class,
            ExifTag::GPS_ALTITUDE_REF       => GpsAltitudeRef::class,
            ExifTag::GPS_STATUS             => GpsStatus::class,
            ExifTag::GPS_MEASURE_MODE       => GpsMeasureMode::class,
            ExifTag::GPS_SPEED_REF          => GpsSpeedRef::class,
            ExifTag::GPS_TRACK_REF          => GpsDirectionRef::class,
            ExifTag::GPS_IMG_DIRECTION_REF  => GpsDirectionRef::class,
            ExifTag::GPS_DEST_LATITUDE_REF  => GpsLatLonRef::class,
            ExifTag::GPS_DEST_LONGITUDE_REF => GpsLatLonRef::class,
            ExifTag::GPS_DEST_BEARING_REF   => GpsDirectionRef::class,
            ExifTag::GPS_DEST_DISTANCE_REF  => GpsDistanceRef::class,
            ExifTag::GPS_DIFFERENTIAL       => GpsDifferential::class,
        ];
    }

    /**
     * Builds mapping from XMP properties to enum classes.
     *
     * XMP properties that correspond to EXIF/TIFF tags use the same enum values.
     * This method maps common XMP namespace+localName combinations to their enums.
     */
    private function buildXmpEnumMap(): void
    {
        // XMP EXIF namespace properties - correspond to EXIF tags
        // http://ns.adobe.com/exif/1.0/
        $exifNs = 'http://ns.adobe.com/exif/1.0/';
        
        $this->xmpPropertyToEnumMap = [
            // EXIF namespace - shooting conditions
            $exifNs . 'ColorSpace'         => ColorSpace::class,
            $exifNs . 'ExposureProgram'    => ExposureProgram::class,
            $exifNs . 'MeteringMode'       => MeteringMode::class,
            $exifNs . 'LightSource'        => LightSource::class,
            $exifNs . 'WhiteBalance'       => WhiteBalance::class,
            $exifNs . 'ExposureMode'       => ExposureMode::class,
            $exifNs . 'SceneCaptureType'   => SceneCaptureType::class,
            $exifNs . 'GainControl'        => GainControl::class,
            $exifNs . 'Contrast'           => Contrast::class,
            $exifNs . 'Saturation'         => Saturation::class,
            $exifNs . 'Sharpness'          => Sharpness::class,
            $exifNs . 'SubjectDistanceRange' => SubjectDistanceRange::class,
            $exifNs . 'SensingMethod'      => SensingMethod::class,
            $exifNs . 'FileSource'         => FileSource::class,
            $exifNs . 'SceneType'          => SceneType::class,
            $exifNs . 'CustomRendered'     => CustomRendered::class,
            
            // TIFF namespace properties - correspond to TIFF/IFD0 tags
            // http://ns.adobe.com/tiff/1.0/
            'http://ns.adobe.com/tiff/1.0/' . 'Orientation'             => Orientation::class,
            'http://ns.adobe.com/tiff/1.0/' . 'Compression'             => Compression::class,
            'http://ns.adobe.com/tiff/1.0/' . 'PhotometricInterpretation' => Photometric::class,
            'http://ns.adobe.com/tiff/1.0/' . 'PlanarConfiguration'     => PlanarConfiguration::class,
            'http://ns.adobe.com/tiff/1.0/' . 'ResolutionUnit'          => ResolutionUnit::class,
            'http://ns.adobe.com/tiff/1.0/' . 'YCbCrPositioning'        => YCbCrPositioning::class,
        ];
    }

    /**
     * Converts constant name to tag name (e.g., GPS_LATITUDE_REF -> GPS Latitude Ref).
     */
    private function constantNameToTagName(string $constantName): string
    {
        // Special cases mapping to match exiftool naming
        $specialCases = [
            'MAKE'                      => 'Make',
            'MODEL'                     => 'Camera Model Name',
            'ORIENTATION'               => 'Orientation',
            'X_RESOLUTION'              => 'X Resolution',
            'Y_RESOLUTION'              => 'Y Resolution',
            'RESOLUTION_UNIT'           => 'Resolution Unit',
            'SOFTWARE'                  => 'Software',
            'MODIFY_DATE'               => 'Modify Date',
            'HOST_COMPUTER'             => 'Host Computer',
            'TILE_WIDTH'                => 'Tile Width',
            'TILE_LENGTH'               => 'Tile Length',
            'YCBCR_POSITIONING'         => 'Y Cb Cr Positioning',
            'EXPOSURE_TIME'             => 'Exposure Time',
            'F_NUMBER'                  => 'F Number',
            'EXPOSURE_PROGRAM'          => 'Exposure Program',
            'ISO'                       => 'ISO',
            'PHOTOGRAPHIC_SENSITIVITY'  => 'ISO',
            'EXIF_VERSION'              => 'Exif Version',
            'DATETIME_ORIGINAL'         => 'Date/Time Original',
            'DATETIME_DIGITIZED'        => 'Create Date',
            'OFFSET_TIME'               => 'Offset Time',
            'OFFSET_TIME_ORIGINAL'      => 'Offset Time Original',
            'OFFSET_TIME_DIGITIZED'     => 'Offset Time Digitized',
            'COMPONENTS_CONFIGURATION'  => 'Components Configuration',
            'SHUTTER_SPEED_VALUE'       => 'Shutter Speed Value',
            'APERTURE_VALUE'            => 'Aperture Value',
            'BRIGHTNESS_VALUE'          => 'Brightness Value',
            'EXPOSURE_BIAS_VALUE'       => 'Exposure Compensation',
            'METERING_MODE'             => 'Metering Mode',
            'FLASH'                     => 'Flash',
            'FOCAL_LENGTH'              => 'Focal Length',
            'SUBJECT_AREA'              => 'Subject Area',
            'SUB_SEC_TIME_ORIGINAL'     => 'Sub Sec Time Original',
            'SUB_SEC_TIME_DIGITIZED'    => 'Sub Sec Time Digitized',
            'FLASHPIX_VERSION'          => 'Flashpix Version',
            'COLOR_SPACE'               => 'Color Space',
            'PIXEL_X_DIMENSION'         => 'Exif Image Width',
            'PIXEL_Y_DIMENSION'         => 'Exif Image Height',
            'SENSING_METHOD'            => 'Sensing Method',
            'SCENE_TYPE'                => 'Scene Type',
            'EXPOSURE_MODE'             => 'Exposure Mode',
            'WHITE_BALANCE'             => 'White Balance',
            'FOCAL_LENGTH_IN_35MM_FILM' => 'Focal Length In 35mm Format',
            'SCENE_CAPTURE_TYPE'        => 'Scene Capture Type',
            'LENS_SPECIFICATION'        => 'Lens Info',
            'LENS_MAKE'                 => 'Lens Make',
            'LENS_MODEL'                => 'Lens Model',
            'COMPOSITE_IMAGE'           => 'Composite Image',
            'GPS_IFD_POINTER'           => 'GPS IFD Pointer',
            'EXIF_IFD_POINTER'          => 'Exif IFD Pointer',
            'XP_TITLE'                  => 'XP Title',
            'XP_COMMENT'                => 'XP Comment',
            'XP_AUTHOR'                 => 'XP Author',
            'XP_KEYWORDS'               => 'XP Keywords',
            'XP_SUBJECT'                => 'XP Subject',
        ];

        if (isset($specialCases[$constantName])) {
            return $specialCases[$constantName];
        }

        // Handle GPS tags
        if (str_starts_with($constantName, 'GPS_')) {
            $gpsName = substr($constantName, 4);

            // Special GPS cases
            $gpsSpecialCases = [
                'LATITUDE_REF'        => 'GPS Latitude Ref',
                'LATITUDE'            => 'GPS Latitude',
                'LONGITUDE_REF'       => 'GPS Longitude Ref',
                'LONGITUDE'           => 'GPS Longitude',
                'ALTITUDE_REF'        => 'GPS Altitude Ref',
                'ALTITUDE'            => 'GPS Altitude',
                'TIME_STAMP'          => 'GPS Time Stamp',
                'SPEED_REF'           => 'GPS Speed Ref',
                'SPEED'               => 'GPS Speed',
                'IMG_DIRECTION_REF'   => 'GPS Img Direction Ref',
                'IMG_DIRECTION'       => 'GPS Img Direction',
                'DEST_BEARING_REF'    => 'GPS Dest Bearing Ref',
                'DEST_BEARING'        => 'GPS Dest Bearing',
                'DATE_STAMP'          => 'GPS Date Stamp',
                'H_POSITIONING_ERROR' => 'GPS Horizontal Positioning Error',
            ];

            if (isset($gpsSpecialCases[$gpsName])) {
                return $gpsSpecialCases[$gpsName];
            }

            $constantName = 'GPS ' . $gpsName;
        }

        // Convert SNAKE_CASE to Title Case
        $parts = explode('_', $constantName);
        $parts = array_map(static fn ($part): string => ucfirst(strtolower($part)), $parts);

        return implode(' ', $parts);
    }

    /**
     * Converts a raw EXIF value to an enum instance if the tag ID has an enum mapping.
     *
     * This method enables the display of enum values in exiftool-like format by
     * converting raw integer/string values to their typed enum representations.
     *
     * @param int|null $tagId The EXIF tag identifier
     * @param mixed    $value The raw value from the IFD entry
     *
     * @return mixed The converted enum instance or the original value if no mapping exists
     */
    private function convertToEnumIfApplicable(?int $tagId, mixed $value): mixed
    {
        if ($tagId === null || !isset($this->tagToEnumMap[$tagId])) {
            return $value;
        }

        $enumClass = $this->tagToEnumMap[$tagId];

        // Extract scalar value if it's wrapped in a list
        $scalarValue = $value;
        if ($value instanceof ExifNumericList) {
            $scalarValue = $value->values[0] ?? null;
        } elseif ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;
            if ($first instanceof ExifRational) {
                if ($first->denominator === 0) {
                    return $value;
                }
                $scalarValue = (int) round((float) $first->numerator / (float) $first->denominator);
            } else {
                return $value;
            }
        }

        // Only attempt conversion for int and string values
        if (!is_int($scalarValue) && !is_string($scalarValue)) {
            return $value;
        }

        // Call the enum's fromExifValue method if it exists
        if (method_exists($enumClass, 'fromExifValue')) {
            return $enumClass::fromExifValue($scalarValue) ?? $value;
        }

        return $value;
    }

    /**
     * Converts a raw XMP value to an enum instance if the property has an enum mapping.
     *
     * XMP properties often store the same enumerated values as their EXIF counterparts.
     * This method applies enum conversion to XMP values for consistent display.
     *
     * @param string $namespace The XMP namespace URI
     * @param string $localName The XMP property local name
     * @param mixed  $value     The raw value from XMP
     *
     * @return mixed The converted enum instance or the original value if no mapping exists
     */
    private function convertXmpValueToEnum(string $namespace, string $localName, mixed $value): mixed
    {
        $propertyKey = $namespace . $localName;
        
        if (!isset($this->xmpPropertyToEnumMap[$propertyKey])) {
            return $value;
        }
        
        $enumClass = $this->xmpPropertyToEnumMap[$propertyKey];
        
        // Extract scalar value - XMP values are typically strings or arrays of strings
        $scalarValue = $value;
        
        if (is_array($value)) {
            $scalarValue = $value[0] ?? null;
        }
        
        if ($scalarValue === null) {
            return $value;
        }
        
        // Parse numeric strings to int/float for enum matching
        if (is_string($scalarValue) && is_numeric($scalarValue)) {
            // Try to preserve type - use int if no decimal point
            if (str_contains($scalarValue, '.')) {
                $scalarValue = (float) $scalarValue;
            } else {
                $scalarValue = (int) $scalarValue;
            }
        }
        
        // Only attempt conversion for int and string values
        if (!is_int($scalarValue) && !is_string($scalarValue)) {
            return $value;
        }
        
        // Call the enum's fromExifValue method if it exists
        // XMP and EXIF use the same enum value systems
        if (method_exists($enumClass, 'fromExifValue')) {
            return $enumClass::fromExifValue($scalarValue) ?? $value;
        }
        
        return $value;
    }

    /**
     * Converts enum case names to exiftool-like labels.
     *
     * Examples:
     *   AUTO_BRACKET -> Auto Bracket
     *   OneChipColorArea -> One Chip Color Area
     *
     * @param string $enumName The enum case name.
     */
    private function formatEnumName(string $enumName): string
    {
        if (isset(self::ENUM_LABEL_OVERRIDES[$enumName])) {
            return self::ENUM_LABEL_OVERRIDES[$enumName];
        }

        // Convert both SCREAMING_SNAKE_CASE and PascalCase/camelCase enum names.
        $normalized = str_replace('_', ' ', $enumName);
        $normalized = preg_replace('/(?<=[a-z])([A-Z])/', ' $1', $normalized) ?? $normalized;
        $normalized = preg_replace('/(?<=[A-Z])([A-Z][a-z])/', ' $1', $normalized) ?? $normalized;

        return ucwords(strtolower($normalized));
    }

    /**
     * Formats the output for a given image file.
     */
    public function format(string $filePath): void
    {
        if (!file_exists($filePath)) {
            echo sprintf('Error: File not found: %s%s', $filePath, PHP_EOL);
            exit(1);
        }

        if (is_dir($filePath)) {
            echo sprintf('Error: Path is a directory, not a file: %s%s', $filePath, PHP_EOL);
            exit(1);
        }

        $reader   = MetadataReader::createDefault();
        $start    = hrtime(true);
        $metadata = $reader->read($filePath, withDigests: true);
        $parseMs  = (hrtime(true) - $start) / 1e6;

        // ImageMeta section
        $this->printSection('ImageMeta', [
            'ImageMeta Version Number' => self::VERSION,
            'Parsing Time'             => sprintf('%.2f ms', $parseMs),
        ]);

        // System section
        $this->printSystemSection($filePath);

        // File section
        $this->printFileSection($metadata, $filePath);

        $jfifData = $this->readJfifData($filePath);
        if ($jfifData !== null) {
            $this->printJfifSection($jfifData);
        }

        // IFD0 section
        if ($metadata->exifDoc instanceof ParsedExif) {
            $this->printIfd0Section($metadata->exifDoc);
        }

        // ExifIFD section
        if ($metadata->exifDoc instanceof ParsedExif && $metadata->exifDoc->exifIfd instanceof Ifd) {
            $this->printExifIfdSection($metadata->exifDoc->exifIfd, $metadata->exifDoc, $metadata->makerNotes);
        }

        // MakerNotes section
        if ($metadata->makerNotes instanceof MakerNotesRecord) {
            $this->printMakerNotesSection($metadata->makerNotes);
        }

        // GPS section
        if ($metadata->exifDoc instanceof ParsedExif && $metadata->exifDoc->gpsIfd instanceof Ifd) {
            $this->printGpsSection($metadata->exifDoc->gpsIfd, $metadata->exifDoc);
        }

        // InteropIFD section
        if ($metadata->exifDoc instanceof ParsedExif && $metadata->exifDoc->interopIfd instanceof Ifd) {
            $this->printInteropIfdSection($metadata->exifDoc->interopIfd);
        }

        // IFD1 section (thumbnail metadata)
        if ($metadata->exifDoc instanceof ParsedExif && $metadata->exifDoc->ifd1 instanceof Ifd) {
            $this->printIfd1Section($metadata->exifDoc->ifd1);
        }

        // IPTC section
        $iptcDoc = $metadata->iptcDoc ?? $metadata->selectiveIptcDocument();
        if ($iptcDoc instanceof IptcDocument && $iptcDoc->datasets !== []) {
            $this->printIptcSection($iptcDoc);
        }

        // XMP sections
        $this->printXmpSections($metadata->xmpDoc ?? $metadata->selectiveXmpDocument(), $metadata->exifDoc);

        // QuickTime section
        if ($metadata->quickTime instanceof QuickTimeMeta) {
            $this->printQuickTimeSection($metadata->quickTime);
        }

        // MPF section
        if ($metadata->mpfDocument instanceof MpfDocument) {
            $this->printMpfSection($metadata->mpfDocument);
        }

        // FlashPix section
        if ($metadata->flashPixStreams !== []) {
            $this->printFlashPixSection($metadata->flashPixStreams);
        }

        // JPEG Audio section
        if ($metadata->jpegAudioStreams !== []) {
            $this->printJpegAudioSection($metadata->jpegAudioStreams);
        }

        // JPEG Details section
        if ($metadata->jpegFrameSamplingFactors !== null) {
            $this->printJpegDetailsSection($metadata);
        }

        // ICC Profile section
        if ($metadata->iccProfile !== null) {
            $this->printIccSection($metadata->iccProfile);
        }

        // HDR Gain Map section
        $this->printHdrGainMapSection($metadata);

        // Composite section
        $this->printCompositeSection($metadata);
    }

    /**
     * Prints a section header and its data.
     *
     * @param array<string, mixed> $data
     * @param string|null          $ifdContext The IFD context ('GPS', 'InteropIFD', etc.) for correct tag name resolution
     */
    private function printSection(string $sectionName, array $data, bool $showHex = false, ?string $ifdContext = null): void
    {
        echo "---- $sectionName ----\n";

        foreach ($data as $key => $value) {
            $tagId          = is_numeric($key) ? (int) $key : null;
            $formattedValue = $this->formatValue($value, $ifdContext, $tagId);

            if ($showHex && is_numeric($key)) {
                if (
                    $tagId === ExifTag::EXIF_IFD_POINTER
                    || $tagId === ExifTag::GPS_IFD_POINTER
                    || $tagId === ExifTag::INTEROPERABILITY_IFD_POINTER
                ) {
                    // Suppress internal IFD offsets from output:
                    // 0x8769 Exif IFD Pointer, 0x8825 GPS IFD Pointer, 0xA005 Interoperability IFD Pointer.
                    continue;
                }

                $hexKey  = sprintf('0x%04x', (int) $key);
                $tagName = $this->getTagName((int) $key, $ifdContext);
                // Format with exactly 40 characters before the colon
                // hex(6) + space(1) + tag name (padded to fill remaining 33 chars) = 40 total
                $label = sprintf('%s %s', $hexKey, $tagName);
            } elseif (!$showHex && is_string($key) && preg_match('/^0x[0-9a-fA-F]{4}\\s/', $key) === 1) {
                $label = $key;
            } elseif (!$showHex && is_string($key) && preg_match('/^[a-z0-9]{4}\\s/', $key) === 1) {
                $label = $key;
            } else {
                // Format with exactly 40 characters before the colon
                // "     - " (7 chars) + key name (padded to fill remaining 33 chars) = 40 total
                $label = sprintf('     - %s', $key);
            }

            printf("%-39s: %s\n", $label, $formattedValue);
        }
    }

    /**
     * Gets the tag name for a given tag ID.
     *
     * @param int         $tagId      The tag ID to look up
     * @param string|null $ifdContext The IFD context ('GPS', 'InteropIFD', etc.) for correct tag name resolution
     */
    private function getTagName(int $tagId, ?string $ifdContext = null): string
    {
        // Check IFD-specific maps first based on context
        if ($ifdContext === 'GPS' && isset($this->gpsTagNames[$tagId])) {
            return $this->gpsTagNames[$tagId];
        }

        if ($ifdContext === 'InteropIFD' && isset($this->interopTagNames[$tagId])) {
            return $this->interopTagNames[$tagId];
        }

        if ($ifdContext === 'IFD1') {
            if ($tagId === ExifTag::JPEG_INTERCHANGE_FORMAT) {
                return 'Thumbnail Offset';
            }

            if ($tagId === ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH) {
                return 'Thumbnail Length';
            }
        }

        // Fall back to general TIFF and EXIF tag maps
        return $this->tiffTagNames[$tagId]
            ?? $this->exifTagNames[$tagId]
            ?? sprintf('Unknown 0x%04x', $tagId);
    }

    /**
     * Formats a value for display.
     *
     * @param mixed       $value      The value to format
     * @param string|null $ifdContext The IFD context for tag-specific formatting
     * @param int|null    $tagId      The tag ID for tag-specific formatting
     */
    private function formatValue(mixed $value, ?string $ifdContext = null, ?int $tagId = null): string
    {
        if ($value === null) {
            return '(none)';
        }

        if ($ifdContext === 'IFD1' && $tagId === ExifTag::COMPRESSION) {
            if ($value instanceof Compression && $value === Compression::Jpeg) {
                return 'JPEG (old-style)';
            }

            if ((is_int($value) || is_string($value)) && (int) $value === Compression::Jpeg->value) {
                return 'JPEG (old-style)';
            }
        }

        // Special handling for Flash tag (0x9209) - EXIF 3.0 §4.6.4
        if ($tagId === ExifTag::FLASH) {
            $rawValue = $value;
            if ($value instanceof ExifNumericList) {
                $rawValue = $value->values[0] ?? null;
            }

            if (is_int($rawValue) || is_string($rawValue)) {
                $flashInfo = ExifFlash::fromExifValue($rawValue);
                if ($flashInfo instanceof FlashInfo) {
                    $parts = [];

                    $parts[] = $flashInfo->fired ? 'Flash Fired' : 'Flash Did Not Fire';

                    if ($flashInfo->mode instanceof FlashMode) {
                        $modeName = $this->formatEnumName($flashInfo->mode->name);
                        $parts[]  = $modeName . ' Mode';
                    }

                    if ($flashInfo->returnDetection instanceof FlashReturn && $flashInfo->fired) {
                        $returnName = $this->formatEnumName($flashInfo->returnDetection->name);
                        if ($returnName !== 'No Strobe Detection') {
                            $parts[] = $returnName;
                        }
                    }

                    if ($flashInfo->functionPresence instanceof FlashFunction && $flashInfo->functionPresence->name === 'ABSENT') {
                        $parts[] = 'No Flash Function';
                    }

                    if ($flashInfo->redEyeReduction) {
                        $parts[] = 'Red-eye Reduction';
                    }

                    return implode(', ', $parts);
                }
            }
        }

        // Special handling for DeviceSettingDescription value object - EXIF 3.0 §4.6.6.7.45
        if ($value instanceof DeviceSettingDescription) {
            $parts = [];
            $parts[] = sprintf('Columns: %d', $value->columns);
            $parts[] = sprintf('Rows: %d', $value->rows);

            if ($value->settings !== []) {
                $parts[] = sprintf('Settings: %s', implode('; ', $value->settings));
            }

            return implode(', ', $parts);
        }

        // XMP LanguageAlternative values (dc:title, dc:description, etc.) are
        // structured objects with lang/value pairs.  Extract the x-default entry
        // or fall back to the first available entry.  (GH-1536)
        if ($value instanceof XmpLanguageAlternative) {
            if ($value->entries === []) {
                return '(none)';
            }

            foreach ($value->entries as $entry) {
                if ($entry['lang'] === 'x-default') {
                    return $entry['value'];
                }
            }

            return $value->entries[0]['value'];
        }

        if ($value instanceof ExifRational) {
            // Format as fraction or decimal
            if ($value->denominator === 0) {
                return 'inf';
            }

            $decimal = $value->numerator / $value->denominator;

            // Tag-specific formatting with units for GPS tags
            if ($ifdContext === 'GPS' && $tagId !== null) {
                return $this->formatGpsValue($tagId, $decimal);
            }

            if ($value->denominator === 1) {
                return (string) $value->numerator;
            }

            // If it's a simple fraction, show as fraction
            if (abs($decimal) < 10) {
                return sprintf('%d/%d', $value->numerator, $value->denominator);
            }

            return number_format($decimal, 6, '.', '');
        }

        if ($value instanceof ExifRationalList) {
            $parts = [];
            foreach ($value->values as $rational) {
                $parts[] = $this->formatValue($rational, $ifdContext, $tagId);
            }

            return implode(' ', $parts);
        }

        if ($value instanceof ExifNumericList) {
            $parts = [];
            foreach ($value->values as $num) {
                $parts[] = $this->formatValue($num, $ifdContext, $tagId);
            }

            if ($ifdContext === 'GPS' && $tagId === ExifTag::GPS_VERSION_ID) {
                // EXIF 3.0 §4.6.7.1.1 defines GPSVersionID as dotted version bytes (e.g. 2.4.0.0).
                return implode('.', $parts);
            }

            return implode(' ', $parts);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y:m:d H:i:s');
        }

        if ($value instanceof BackedEnum) {
            // Match exiftool: known enum mappings are shown by label only.
            // Unmapped values stay raw because they are not BackedEnum instances.
            return $this->formatEnumName($value->name);
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '(none)';
            }

            // Check if it's a simple numeric array
            if (array_is_list($value)) {
                $parts = array_map(fn ($v): string => $this->formatValue($v, $ifdContext, $tagId), $value);

                if ($ifdContext === 'GPS' && $tagId === ExifTag::GPS_VERSION_ID) {
                    // EXIF 3.0 §4.6.7.1.1 defines GPSVersionID as dotted version bytes (e.g. 2.4.0.0).
                    return implode('.', $parts);
                }

                return implode(' ', $parts);
            }

            return json_encode($value);
        }

        if (is_float($value)) {
            if ($tagId === ExifTag::FOCAL_LENGTH) {
                return number_format($value, 1, '.', '') . ' mm';
            }

            if ($tagId === ExifTag::F_NUMBER || $tagId === ExifTag::APERTURE_VALUE || $tagId === ExifTag::MAX_APERTURE_VALUE) {
                return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
            }

            // Clean up unnecessary decimals
            return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }

        if (is_string($value)) {
            // Check for binary data
            if ($this->isBinary($value)) {
                $length = strlen($value);
                $result = sprintf('(Binary data %d bytes)', $length);

                // Show first 32 bytes as hex values
                $hexBytes = min(32, $length);
                if ($hexBytes > 0) {
                    $hexString = bin2hex(substr($value, 0, $hexBytes));
                    // Format as space-separated pairs (e.g., "4d 4d 00 2a")
                    $hexFormatted = implode(' ', str_split($hexString, 2));
                    $result .= ': ' . $hexFormatted;
                    if ($length > 32) {
                        $result .= '...';
                    }
                }

                return $result;
            }

            // Limit string length for display
            if (strlen($value) > 100) {
                return substr($value, 0, 100) . '...';
            }

            return $value;
        }

        return (string) $value;
    }

    /**
     * Formats GPS-specific values with appropriate units.
     *
     * EXIF 3.0 §4.6.6 Table 27 defines GPS tag value formats and units.
     *
     * @param int   $tagId The GPS tag ID
     * @param float $value The calculated decimal value
     */
    private function formatGpsValue(int $tagId, float $value): string
    {
        return match ($tagId) {
            // GPS Horizontal Positioning Error - EXIF 3.0 §4.6.6
            ExifTag::GPS_H_POSITIONING_ERROR => sprintf('%.9f m', $value),

            // GPS Altitude - EXIF 3.0 §4.6.6
            ExifTag::GPS_ALTITUDE => sprintf('%.6f m', $value),

            // GPS Speed - EXIF 3.0 §4.6.6 (unit depends on SpeedRef, shown in original units)
            ExifTag::GPS_SPEED => number_format($value, 6, '.', ''),

            // GPS DOP (Dilution of Precision) - EXIF 3.0 §4.6.6
            ExifTag::GPS_DOP => number_format($value, 2, '.', ''),

            // GPS Direction/Bearing values in degrees - EXIF 3.0 §4.6.6
            ExifTag::GPS_TRACK,
            ExifTag::GPS_IMG_DIRECTION,
            ExifTag::GPS_DEST_BEARING => number_format($value, 6, '.', ''),

            // GPS Distance - EXIF 3.0 §4.6.6 (unit depends on DistanceRef)
            ExifTag::GPS_DEST_DISTANCE => number_format($value, 6, '.', ''),

            // Default: return as calculated decimal
            default => number_format($value, 6, '.', ''),
        };
    }

    /**
     * Formats YCbCr subsampling factors into an ExifTool-like label.
     */
    private function formatYcbcrSubSampling(int $horizontal, int $vertical): string
    {
        $label = match (true) {
            ($horizontal === 2) && ($vertical === 2) => 'YCbCr4:2:0',
            ($horizontal === 2) && ($vertical === 1) => 'YCbCr4:2:2',
            ($horizontal === 1) && ($vertical === 1) => 'YCbCr4:4:4',
            default => sprintf('YCbCr %d:%d', $horizontal, $vertical),
        };

        return sprintf('%s (%d %d)', $label, $horizontal, $vertical);
    }

    /**
     * Formats component configuration labels as a comma-separated string.
     *
     * @param list<string>|null $labels
     */
    private function formatComponentsConfiguration(?array $labels): ?string
    {
        if ($labels === null || $labels === []) {
            return null;
        }

        if (count($labels) < 4) {
            // ComponentsConfiguration mapping is:
            // 0 => '-', 1 => 'Y', 2 => 'Cb', 3 => 'Cr', 4 => 'R', 5 => 'G', 6 => 'B'.
            // Preserve EXIF byte order and right-pad missing trailing components.
            $labels = array_pad($labels, 4, '-');
        }

        return implode(', ', $labels);
    }

    /**
     * Formats XYZ triplets for ICC output.
     *
     * @param array{x: float, y: float, z: float} $xyz
     */
    private function formatXyzTriplet(array $xyz): string
    {
        return sprintf(
            '%.5f %.5f %.5f',
            $xyz['x'],
            $xyz['y'],
            $xyz['z'],
        );
    }

    /**
     * Formats ICC header keys with offsets.
     */
    private function formatIccHeaderKey(int $offset, string $label): string
    {
        return sprintf('0x%04x %s', $offset, $label);
    }

    /**
     * Decodes ICC profile flags into human-readable labels.
     *
     * ICC.1:2022 Table 21 — Profile flags.
     */
    private function decodeIccProfileFlags(string $hex): string
    {
        $hex = strtolower(trim($hex));
        $lastNibble = $hex === '' ? 0 : hexdec($hex[strlen($hex) - 1]);

        $embedded = ($lastNibble & 0x1) !== 0 ? 'Embedded' : 'Not Embedded';
        $dependent = ($lastNibble & 0x2) !== 0 ? 'Dependent' : 'Independent';

        return $embedded . ', ' . $dependent . ' (' . strtoupper($hex) . ')';
    }

    /**
     * Decodes ICC device attributes into human-readable labels.
     *
     * ICC.1:2022 Table 22 — Device attributes.
     */
    private function decodeIccDeviceAttributes(string $hex): string
    {
        $hex = strtolower(trim($hex));
        $lastNibble = $hex === '' ? 0 : hexdec($hex[strlen($hex) - 1]);

        $reflective = ($lastNibble & 0x1) !== 0 ? 'Transparency' : 'Reflective';
        $glossy = ($lastNibble & 0x2) !== 0 ? 'Matte' : 'Glossy';
        $polarity = ($lastNibble & 0x4) !== 0 ? 'Negative' : 'Positive';
        $color = ($lastNibble & 0x8) !== 0 ? 'Black & White' : 'Color';

        return $reflective . ', ' . $glossy . ', ' . $polarity . ', ' . $color . ' (' . strtoupper($hex) . ')';
    }

    /**
     * Normalises XMP values to match ExifTool-style output for key fields.
     */
    private function formatXmpValue(string $prefix, string $localName, mixed $value): mixed
    {
        if ($prefix !== 'exif') {
            return $value;
        }

        if ($localName === 'ExposureTime' && is_numeric($value)) {
            return $this->converters->formatExposureTime((float) $value) ?? $value;
        }

        if ($localName === 'ShutterSpeedValue' && is_numeric($value)) {
            $floatVal = (float) $value;
            $formatted = $this->converters->formatShutterSpeedFromApex($floatVal);
            if ($formatted !== null) {
                return $formatted;
            }

            return rtrim(rtrim(number_format($floatVal, 6, '.', ''), '0'), '.');
        }

        if (($localName === 'ExposureBiasValue' || $localName === 'DigitalZoomRatio') && is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');
        }

        return $value;
    }

    /**
     * Checks if a string contains binary data.
     */
    private function isBinary(string $data): bool
    {
        // Check for null bytes or other control characters
        return preg_match('/[\x00-\x08\x0B-\x0C\x0E-\x1F]/', $data) === 1;
    }


    /**
     * Prints the System section with file system metadata.
     */
    private function printSystemSection(string $filePath): void
    {
        $fileName   = basename($filePath);
        $directory  = dirname($filePath);
        $fileSize   = filesize($filePath);
        $modTime    = filemtime($filePath);
        $accessTime = fileatime($filePath);
        $changeTime = filectime($filePath);
        $perms      = fileperms($filePath);

        $sizeFormatted  = $this->formatFileSize($fileSize);
        $permsFormatted = $this->formatPermissions($perms);

        // Format timestamps with local timezone (matching exiftool behavior)
        $timezone       = new DateTimeZone('Europe/Berlin');
        $modDateTime    = (new DateTime('@' . $modTime))->setTimezone($timezone);
        $accessDateTime = (new DateTime('@' . $accessTime))->setTimezone($timezone);
        $changeDateTime = (new DateTime('@' . $changeTime))->setTimezone($timezone);

        $this->printSection('System', [
            'File Name'                   => $fileName,
            'Directory'                   => $directory,
            'File Size'                   => $sizeFormatted,
            'File Modification Date/Time' => $modDateTime->format('Y:m:d H:i:sP'),
            'File Access Date/Time'       => $accessDateTime->format('Y:m:d H:i:sP'),
            'File Inode Change Date/Time' => $changeDateTime->format('Y:m:d H:i:sP'),
            'File Permissions'            => $permsFormatted,
        ]);
    }

    /**
     * Gets decoded value from ParsedExif accessor methods for special tags.
     *
     * This method leverages the existing decoding logic in ParsedExif rather than
     * duplicating it in the formatter. For tags with accessor methods that return
     * typed/decoded values, we use those instead of the raw IFD entry value.
     *
     * @param int             $tagId    The EXIF tag identifier
     * @param mixed           $rawValue The raw value from the IFD entry
     * @param ParsedExif|null $exifDoc  The ParsedExif document for accessing decoded values
     *
     * @return mixed|null The decoded value from ParsedExif, or null if no special accessor exists
     */
    private function getDecodedValueFromParsedExif(int $tagId, mixed $rawValue, ?ParsedExif $exifDoc): mixed
    {
        if (!$exifDoc instanceof ParsedExif) {
            return null;
        }

        return match ($tagId) {
            // ComponentsConfiguration - Use ParsedExif accessor that returns formatted description
            // EXIF 3.0 §4.6.4 Table 17
            ExifTag::COMPONENTS_CONFIGURATION => $this->formatComponentsConfiguration($exifDoc->componentsConfigurationLabels()),

            // SceneType - Use ParsedExif accessor that returns typed enum
            // EXIF 3.0 §4.6.3 Table 13
            ExifTag::SCENE_TYPE => $exifDoc->sceneType(),

            // FileSource - Use ParsedExif accessor that returns typed enum
            // EXIF 3.0 §4.6.3 Table 12
            ExifTag::FILE_SOURCE => $exifDoc->fileSource(),

            // DeviceSettingDescription - Use ParsedExif accessor that returns structured value object
            // EXIF 3.0 §4.6.6.7.45
            ExifTag::DEVICE_SETTING_DESCRIPTION => $exifDoc->deviceSettingDescription(),

            // Orientation - Use rotation description instead of enum name
            // EXIF 3.0 §4.6.5.1.6
            ExifTag::ORIENTATION => $exifDoc->orientationDescription(),

            // ShutterSpeedValue - Convert APEX value to human-readable fraction
            // EXIF 3.0 §4.6.6.7.13
            ExifTag::SHUTTER_SPEED_VALUE => $exifDoc->shutterSpeedFormatted(),

            // ApertureValue - Convert APEX value to f-number
            // EXIF 3.0 §4.6.6.7.14
            ExifTag::APERTURE_VALUE => $this->converters->apexToFNumber($exifDoc->apertureValue()),

            // BrightnessValue - Convert APEX value to decimal EV
            // EXIF 3.0 §4.6.6.7.15
            ExifTag::BRIGHTNESS_VALUE => $exifDoc->brightnessValueFormatted(),

            // ExposureTime - Format as human-readable fraction
            // EXIF 3.0 §4.6.6.7.1
            ExifTag::EXPOSURE_TIME => $exifDoc->exposureTimeFormatted(),

            // FNumber - Format as decimal f-number
            // EXIF 3.0 §4.6.6.7.2
            ExifTag::F_NUMBER => $exifDoc->fNumber(),

            // MaxApertureValue - Convert APEX to f-number
            // EXIF 3.0 §4.6.6.7.17
            ExifTag::MAX_APERTURE_VALUE => $this->converters->apexToFNumber($exifDoc->maxApertureApex()),

            // FocalLength - Format in millimetres
            // EXIF 3.0 §4.6.6.7.23
            ExifTag::FOCAL_LENGTH => $exifDoc->focalLengthMm(),

            // UserComment - Decode multicode prefix and payload
            // EXIF 3.0 §4.6.6.4.2
            ExifTag::USER_COMMENT => $this->formatUserCommentValue($rawValue, $exifDoc),

            // Windows XP tags - Decode UCS-2 (UTF-16LE) to UTF-8
            ExifTag::XP_TITLE    => $exifDoc->xpTitle(),
            ExifTag::XP_COMMENT  => $exifDoc->xpComment(),
            ExifTag::XP_AUTHOR   => $exifDoc->xpAuthor(),
            ExifTag::XP_KEYWORDS => $exifDoc->xpKeywords(),
            ExifTag::XP_SUBJECT  => $exifDoc->xpSubject(),

            // LearningOptOutIn - Decode pair-based opt-out/opt-in entries
            // EXIF 3.1 §4.6.5.4
            ExifTag::LEARNING_OPT_OUT_IN => $this->formatLearningOptOutIn($exifDoc->learningOptOutIn()),

            // DevelopmentType - Decompose packed SHORT into characteristic and default
            // EXIF 3.1 §4.6.6.7.47
            ExifTag::DEVELOPMENT_TYPE => $this->formatDevelopmentType($exifDoc),

            // No special accessor available
            default => null,
        };
    }

    /**
     * Formats UserComment values and keeps null-filled marker payloads blank.
     *
     * EXIF 3.0 §4.6.4 / §4.6.6.4.2 define an 8-byte charset marker prefix.
     */
    private function formatUserCommentValue(mixed $rawValue, ParsedExif $exifDoc): ?string
    {
        $decoded = $exifDoc->userComment();
        if ($decoded !== null) {
            return $decoded;
        }

        if (is_string($rawValue) && $this->isEmptyUserCommentRaw($rawValue)) {
            return '';
        }

        return null;
    }

    /**
     * Detects UserComment payloads that carry only marker+padding bytes.
     */
    private function isEmptyUserCommentRaw(string $raw): bool
    {
        if (strlen($raw) < 8) {
            return false;
        }

        $prefix = substr($raw, 0, 8);
        $marker = UndefinedTextMarker::canonicalMarkerFromPrefix($prefix);
        if ($marker === '') {
            return false;
        }

        $content = substr($raw, 8);

        return trim($content, "\0 ") === '';
    }

    /**
     * Formats LearningOptOutIn entries as "Usage: Intention" pairs.
     */
    private function formatLearningOptOutIn(?LearningOptOutIn $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $parts = [];

        foreach ($value->entries as $entry) {
            $parts[] = $entry->usage->name . ': ' . $entry->intention->name;
        }

        return implode('; ', $parts);
    }

    /**
     * Formats DevelopmentType as "Characteristic / Default".
     */
    private function formatDevelopmentType(ParsedExif $exifDoc): ?string
    {
        $characteristic = $exifDoc->developmentCharacteristic();
        $default        = $exifDoc->developmentDefault();

        if ($characteristic === null && $default === null) {
            return null;
        }

        $parts = [];

        if ($characteristic !== null) {
            $parts[] = $characteristic->name;
        }

        if ($default !== null) {
            $parts[] = $default->name;
        }

        return implode(' / ', $parts);
    }

    /**
     * Formats file size in human-readable format.
     */
    private function formatFileSize(int|false $bytes): string
    {
        if ($bytes === false || $bytes < 0) {
            return 'unknown';
        }

        if ($bytes < 1000) {
            return $bytes . ' bytes';
        }

        if ($bytes < 10 * 1000 * 1000) {
            return round($bytes / 1000) . ' kB';
        }

        if ($bytes < 1000 * 1000 * 1000) {
            return round($bytes / (1000 * 1000), 1) . ' MB';
        }

        return round($bytes / (1000 * 1000 * 1000), 1) . ' GB';
    }

    /**
     * Formats file permissions in Unix format.
     */
    private function formatPermissions(int|false $perms): string
    {
        if ($perms === false) {
            return 'unknown';
        }

        // File type
        if (($perms & 0xC000) === 0xC000) {
            $info = 's'; // Socket
        } elseif (($perms & 0xA000) === 0xA000) {
            $info = 'l'; // Symbolic Link
        } elseif (($perms & 0x8000) === 0x8000) {
            $info = '-'; // Regular
        } elseif (($perms & 0x6000) === 0x6000) {
            $info = 'b'; // Block special
        } elseif (($perms & 0x4000) === 0x4000) {
            $info = 'd'; // Directory
        } elseif (($perms & 0x2000) === 0x2000) {
            $info = 'c'; // Character special
        } elseif (($perms & 0x1000) === 0x1000) {
            $info = 'p'; // FIFO pipe
        } else {
            $info = 'u'; // Unknown
        }

        // Owner permissions
        $info .= ((($perms & 0x0100) !== 0) ? 'r' : '-');
        $info .= ((($perms & 0x0080) !== 0) ? 'w' : '-');
        $info .= ((($perms & 0x0040) !== 0) ?
            ((($perms & 0x0800) !== 0) ? 's' : 'x') :
            ((($perms & 0x0800) !== 0) ? 'S' : '-'));

        // Group permissions
        $info .= ((($perms & 0x0020) !== 0) ? 'r' : '-');
        $info .= ((($perms & 0x0010) !== 0) ? 'w' : '-');
        $info .= ((($perms & 0x0008) !== 0) ?
            ((($perms & 0x0400) !== 0) ? 's' : 'x') :
            ((($perms & 0x0400) !== 0) ? 'S' : '-'));

        // Other permissions
        $info .= ((($perms & 0x0004) !== 0) ? 'r' : '-');
        $info .= ((($perms & 0x0002) !== 0) ? 'w' : '-');
        $info .= ((($perms & 0x0001) !== 0) ?
            ((($perms & 0x0200) !== 0) ? 't' : 'x') :
            ((($perms & 0x0200) !== 0) ? 'T' : '-'));

        return $info;
    }

    /**
     * Prints the File section with container metadata.
     */
    private function printFileSection(Metadata $metadata, string $filePath): void
    {
        $data = [
            'File Type'           => $this->detectFileType($filePath),
            'File Type Extension' => $metadata->extension ?? 'unknown',
            'MIME Type'           => $metadata->mimeType ?? 'unknown',
        ];

        // Add file size if available
        if ($metadata->fileSize !== null) {
            $data['File Size'] = $this->formatFileSize($metadata->fileSize);
        }

        // Add EXIF byte order if available
        if ($metadata->exifDoc instanceof ParsedExif) {
            $endianness = $metadata->exifDoc->endianness ?? null;
            if ($endianness !== null) {
                $data['Exif Byte Order'] = $endianness->value === 'MM'
                    ? 'Big-endian (Motorola, MM)'
                    : 'Little-endian (Intel, II)';
            }
        }

        // Add image dimensions if available from JPEG or EXIF
        if ($metadata->jpegFrameWidth !== null && $metadata->jpegFrameHeight !== null) {
            $data['Image Width']  = $metadata->jpegFrameWidth;
            $data['Image Height'] = $metadata->jpegFrameHeight;
        }

        // Add JPEG-specific information
        if ($metadata->jpegBitsPerSample !== null) {
            $data['Bits Per Sample'] = $metadata->jpegBitsPerSample;
        }

        // Add color components count if available
        if ($metadata->jpegFrameSamplingFactors !== null) {
            $data['Color Components'] = count($metadata->jpegFrameSamplingFactors);
        }

        // Add YCbCr subsampling if available
        if ($metadata->jpegYCbCrSubSampling !== null) {
            $data['YCbCr Sub Sampling'] = $this->formatYcbcrSubSampling(
                $metadata->jpegYCbCrSubSampling[0],
                $metadata->jpegYCbCrSubSampling[1],
            );
        }

        // Add blob counts
        if ($metadata->exifBlobs !== []) {
            $data['EXIF Blob Count'] = count($metadata->exifBlobs);
        }

        if ($metadata->xmpBlobs !== []) {
            $data['XMP Blob Count'] = count($metadata->xmpBlobs);
        }

        if ($metadata->iccSegments !== []) {
            $data['ICC Segment Count'] = count($metadata->iccSegments);
        }

        // Add file digests if available
        if ($metadata->digestSha1 !== null) {
            $data['SHA1 Digest'] = $metadata->digestSha1;
        }

        if ($metadata->digestMd5 !== null) {
            $data['MD5 Digest'] = $metadata->digestMd5;
        }

        $this->printSection('File', $data);
    }

    /**
     * Prints the JFIF section when APP0 metadata is present.
     *
     * @param array{version:string,unit:string,xResolution:int,yResolution:int} $jfifData
     */
    private function printJfifSection(array $jfifData): void
    {
        $data = [
            '0x0000 JFIF Version' => $jfifData['version'],
            '0x0002 Resolution Unit' => $jfifData['unit'],
            '0x0003 X Resolution' => $jfifData['xResolution'],
            '0x0005 Y Resolution' => $jfifData['yResolution'],
        ];

        $this->printSection('JFIF', $data);
    }

    /**
     * Attempts to read JFIF APP0 metadata from a JPEG file.
     *
     * @return array{version:string,unit:string,xResolution:int,yResolution:int}|null
     */
    private function readJfifData(string $filePath): ?array
    {
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return null;
        }

        $start = fread($handle, 2);
        if ($start !== "\xFF\xD8") {
            fclose($handle);
            return null;
        }

        while (!feof($handle)) {
            $markerStart = fread($handle, 1);
            if ($markerStart === '' || $markerStart === false) {
                break;
            }

            if ($markerStart !== "\xFF") {
                continue;
            }

            $marker = fread($handle, 1);
            if ($marker === '' || $marker === false) {
                break;
            }

            $markerByte = ord($marker);
            if ($markerByte === 0xD9 || $markerByte === 0xDA) {
                break;
            }

            $lengthBytes = fread($handle, 2);
            if ($lengthBytes === '' || $lengthBytes === false || strlen($lengthBytes) !== 2) {
                break;
            }

            $length = unpack('nlen', $lengthBytes)['len'] ?? 0;
            if ($length < 2) {
                break;
            }

            $payloadLength = $length - 2;
            $payload       = $payloadLength > 0 ? fread($handle, $payloadLength) : '';

            if ($markerByte === 0xE0 && is_string($payload) && str_starts_with($payload, "JFIF\0")) {
                if (strlen($payload) < 14) {
                    break;
                }

                $major = ord($payload[5]);
                $minor = ord($payload[6]);
                $unitCode = ord($payload[7]);
                $xDensity = unpack('n', substr($payload, 8, 2))[1] ?? 0;
                $yDensity = unpack('n', substr($payload, 10, 2))[1] ?? 0;

                $unit = match ($unitCode) {
                    1 => 'inches',
                    2 => 'cm',
                    default => 'None',
                };

                fclose($handle);

                return [
                    'version' => sprintf('%d.%02d', $major, $minor),
                    'unit' => $unit,
                    'xResolution' => $xDensity,
                    'yResolution' => $yDensity,
                ];
            }
        }

        fclose($handle);

        return null;
    }

    /**
     * Detects file type from extension.
     */
    private function detectFileType(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'JPEG',
            'heic'  => 'HEIC',
            'heif'  => 'HEIF',
            'avif'  => 'AVIF',
            'mov'   => 'MOV',
            'mp4'   => 'MP4',
            default => strtoupper($ext),
        };
    }

    /**
     * Prints the IFD0 section.
     */
    private function printIfd0Section(ParsedExif $exif): void
    {
        $data = [];

        // Collect IFD0 tags from the ifd0 entries
        foreach ($exif->ifd0->entries as $tagId => $entry) {
            // Use ParsedExif accessor methods for tags with special decoding
            $value = $this->getDecodedValueFromParsedExif($tagId, $entry->value, $exif);

            // Convert raw value to enum if applicable (for tags without special accessors)
            if ($value === null) {
                $value = $this->convertToEnumIfApplicable($tagId, $entry->value);
            }

            $data[$tagId] = $value;
        }

        if ($data !== []) {
            $this->printSection('IFD0', $data, showHex: true);
        }
    }

    /**
     * Prints the ExifIFD section.
     */
    private function printExifIfdSection(?Ifd $exifIfd, ?ParsedExif $exifDoc = null, ?MakerNotesRecord $makerNotes = null): void
    {
        $data = [];

        // Collect ExifIFD tags
        if (($exifIfd instanceof Ifd) && isset($exifIfd->entries)) {
            foreach ($exifIfd->entries as $tagId => $entry) {
                // MakerNote: show vendor summary instead of raw binary blob
                if ($tagId === ExifTag::MAKER_NOTE) {
                    $data[$tagId] = $this->formatMakerNoteSummary($makerNotes, $entry->value);
                    continue;
                }

                // Use ParsedExif accessor methods for tags with special decoding
                $value = $this->getDecodedValueFromParsedExif($tagId, $entry->value, $exifDoc);

                // Convert raw value to enum if applicable (for tags without special accessors)
                if ($value === null) {
                    $value = $this->convertToEnumIfApplicable($tagId, $entry->value);
                }

                $data[$tagId] = $value;
            }
        }

        if ($data !== []) {
            $this->printSection('ExifIFD', $data, showHex: true);
        }
    }

    /**
     * Returns a human-readable MakerNote summary line.
     */
    private function formatMakerNoteSummary(?MakerNotesRecord $makerNotes, mixed $rawValue): string
    {
        if ($makerNotes instanceof MakerNotesRecord) {
            return sprintf('%s (%d bytes)', $makerNotes->vendor, $makerNotes->length);
        }

        $length = is_string($rawValue) ? strlen($rawValue) : 0;

        return sprintf('(Binary data, %d bytes)', $length);
    }

    /**
     * Prints the GPS section.
     *
     * @param Ifd|null       $gpsIfd  GPS IFD containing raw GPS tag entries.
     * @param ParsedExif|null $exifDoc ParsedExif document for formatted accessor methods.
     */
    private function printGpsSection(?Ifd $gpsIfd, ?ParsedExif $exifDoc = null): void
    {
        $data = [];

        // Collect GPS tags
        if (($gpsIfd instanceof Ifd) && isset($gpsIfd->entries)) {
            foreach ($gpsIfd->entries as $tagId => $entry) {
                // GPS TimeStamp - format as HH:MM:SS.s instead of raw rationals
                // EXIF 3.0 §4.6.6 Table 27
                if (($tagId === ExifTag::GPS_TIME_STAMP) && ($exifDoc instanceof ParsedExif)) {
                    $formatted = $exifDoc->gpsTimeStampString();
                    if ($formatted !== null) {
                        $data[$tagId] = $formatted;

                        continue;
                    }
                }

                // Convert raw value to enum if applicable
                $data[$tagId] = $this->convertToEnumIfApplicable($tagId, $entry->value);
            }
        }

        if ($data !== []) {
            $this->printSection('GPS', $data, showHex: true, ifdContext: 'GPS');
        }
    }

    /**
     * Prints MakerNotes sections.
     */
    private function printMakerNotesSection(MakerNotesRecord $makerNotes): void
    {
        // For Apple maker notes, extract detailed information
        if ($makerNotes->apple instanceof AppleMakerNotes) {
            $data  = [];
            $apple = $makerNotes->apple;

            $this->flattenObjectProperties($apple, '', $data);

            if ($data !== []) {
                $this->printSection('Apple', $data);
            }
        }
    }

    /**
     * Recursively flattens public properties of value objects into a display-ready array.
     *
     * Scalar, enum, and array values are stored directly. Nested objects are expanded
     * recursively with a prefixed label (e.g. "Identity Content Identifier").
     *
     * @param object               $object Value object to extract from.
     * @param string               $prefix Label prefix for nested objects.
     * @param array<string, mixed> $data   Accumulator for flattened key/value pairs.
     */
    private function flattenObjectProperties(object $object, string $prefix, array &$data): void
    {
        $reflection = new ReflectionClass($object);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $name  = $property->getName();
            $value = $property->getValue($object);

            if ($value === null) {
                continue;
            }

            $displayName = $prefix . $this->propertyNameToDisplayName($name);

            if (is_object($value) && !($value instanceof BackedEnum) && !($value instanceof DateTimeInterface)) {
                $this->flattenObjectProperties($value, $displayName . ' ', $data);
                continue;
            }

            $data[$displayName] = $value;
        }
    }

    /**
     * Converts property name to display name (camelCase to Title Case).
     */
    private function propertyNameToDisplayName(string $propertyName): string
    {
        // Insert space before uppercase letters
        $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $propertyName);

        // Capitalize first letter of each word
        return ucwords($spaced ?? $propertyName);
    }

    /**
     * Prints XMP sections.
     */
    private function printXmpSections(?XmpDocument $xmpDoc, ?ParsedExif $exifDoc = null): void
    {
        if ((!$xmpDoc instanceof XmpDocument) || !isset($xmpDoc->data)) {
            return;
        }

        // Group XMP data by namespace, applying enum conversion
        $grouped = [];

        foreach ($xmpDoc->data as $clarkNotation => $value) {
            // Clark notation format: {namespace}localName or just localName
            if (preg_match('/^\{([^}]+)\}(.+)$/', $clarkNotation, $matches)) {
                $namespace = $matches[1];
                $localName = $matches[2];

                // Convert XMP value to enum if applicable
                $convertedValue = $this->convertXmpValueToEnum($namespace, $localName, $value);

                // Use extracted namespace prefix from the document
                $prefix = $xmpDoc->namespacePrefixes[$namespace] ?? 'unknown';

                if (!isset($grouped[$prefix])) {
                    $grouped[$prefix] = [];
                }

                $grouped[$prefix][$localName] = $this->formatXmpValue($prefix, $localName, $convertedValue);
            } else {
                // No namespace - use 'unknown' prefix
                if (!isset($grouped['unknown'])) {
                    $grouped['unknown'] = [];
                }

                $grouped['unknown'][$clarkNotation] = $this->formatXmpValue('unknown', $clarkNotation, $value);
            }
        }

        // Print each namespace section
        foreach ($grouped as $prefix => $data) {
            if ($data !== []) {
                $this->printSection('XMP-' . $prefix, $data);
            }
        }
    }

    /**
     * Prints ICC Profile section.
     *
     * Decodes and displays ICC profile metadata including the profile description.
     * See docs/ICC.pdf for ICC profile format specifications.
     *
     * @param string $iccProfile The raw ICC profile binary data
     */
    private function printIccSection(string $iccProfile): void
    {
        $parser = new IccParser();

        foreach ($this->splitIccProfiles($iccProfile) as $index => $profilePayload) {
            $iccData      = $parser->decode($profilePayload);
            $header       = [];
            $profile      = [];
            $view         = [];
            $measurement  = [];
            $sectionIndex = $index + 1;

            if ($iccData !== null) {
                if ($iccData->cmmType !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::CMM_TYPE, 'Profile CMM Type')] = $iccData->cmmType;
                }

                if ($iccData->version !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::PROFILE_VERSION, 'Profile Version')] = $iccData->version;
                }

                if ($iccData->profileClass !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::PROFILE_CLASS, 'Profile Class')] = $iccData->profileClass;
                }

                if ($iccData->colorSpace !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::COLOR_SPACE, 'Color Space Data')] = $iccData->colorSpace;
                }

                if ($iccData->pcs !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::PCS, 'Profile Connection Space')] = $iccData->pcs;
                }

                if ($iccData->profileDateTime !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::PROFILE_DATE_TIME, 'Profile Date Time')] = $iccData->profileDateTime;
                }

                if ($iccData->profileSignature !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::PROFILE_SIGNATURE, 'Profile File Signature')] = $iccData->profileSignature;
                }

                if ($iccData->profileFlags !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::PROFILE_FLAGS, 'Profile Flags')] = $this->decodeIccProfileFlags(
                        $iccData->profileFlags
                    );
                }

                if ($iccData->primaryPlatform !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::PRIMARY_PLATFORM, 'Primary Platform')] = $iccData->primaryPlatform;
                }

                if ($iccData->deviceManufacturer !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::DEVICE_MANUFACTURER, 'Device Manufacturer')] = $iccData->deviceManufacturer;
                }

                if ($iccData->deviceModel !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::DEVICE_MODEL, 'Device Model')] = $iccData->deviceModel;
                }

                if ($iccData->deviceAttributes !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::DEVICE_ATTRIBUTES, 'Device Attributes')] = $this->decodeIccDeviceAttributes(
                        $iccData->deviceAttributes
                    );
                }

                if ($iccData->renderingIntent !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::RENDERING_INTENT, 'Rendering Intent')] = $iccData->renderingIntent;
                }

                if ($iccData->illuminant !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::CONNECTION_SPACE_ILLUMINANT, 'Connection Space Illuminant')] = $this->formatXyzTriplet($iccData->illuminant);
                }

                if ($iccData->profileCreator !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::PROFILE_CREATOR, 'Profile Creator')] = $iccData->profileCreator;
                }

                if ($iccData->profileId !== null) {
                    $header[$this->formatIccHeaderKey(IccTag::PROFILE_ID, 'Profile ID')] = $iccData->profileId;
                }

                if ($iccData->description !== null) {
                    $profile['Profile Description'] = $iccData->description;
                }

                if ($iccData->copyright !== null) {
                    $profile['Profile Copyright'] = $iccData->copyright;
                }

                if ($iccData->whitePoint !== null) {
                    $profile['Media White Point'] = $this->formatXyzTriplet($iccData->whitePoint);
                }

                if ($iccData->blackPoint !== null) {
                    $profile['Media Black Point'] = $this->formatXyzTriplet($iccData->blackPoint);
                }

                if ($iccData->redMatrixColumn !== null) {
                    $profile['Red Matrix Column'] = $this->formatXyzTriplet($iccData->redMatrixColumn);
                }

                if ($iccData->greenMatrixColumn !== null) {
                    $profile['Green Matrix Column'] = $this->formatXyzTriplet($iccData->greenMatrixColumn);
                }

                if ($iccData->blueMatrixColumn !== null) {
                    $profile['Blue Matrix Column'] = $this->formatXyzTriplet($iccData->blueMatrixColumn);
                }

                if ($iccData->luminance !== null) {
                    $profile['Luminance'] = $this->formatXyzTriplet($iccData->luminance);
                }

                if ($iccData->deviceMfgDesc !== null) {
                    $profile['Device Mfg Desc'] = $iccData->deviceMfgDesc;
                }

                if ($iccData->deviceModelDesc !== null) {
                    $profile['Device Model Desc'] = $iccData->deviceModelDesc;
                }

                if ($iccData->technology !== null) {
                    $profile['Technology'] = $iccData->technology;
                }

                if ($iccData->redTRC !== null) {
                    $profile['Red TRC'] = $this->formatIccTrc($iccData->redTRC);
                }

                if ($iccData->greenTRC !== null) {
                    $profile['Green TRC'] = $this->formatIccTrc($iccData->greenTRC);
                }

                if ($iccData->blueTRC !== null) {
                    $profile['Blue TRC'] = $this->formatIccTrc($iccData->blueTRC);
                }

                if ($iccData->viewingConditions !== null) {
                    $view['Illuminant XYZ'] = $this->formatXyzTriplet($iccData->viewingConditions['illuminant']);
                    $view['Surround XYZ'] = $this->formatXyzTriplet($iccData->viewingConditions['surround']);
                    $view['Illuminant Type'] = (string) $iccData->viewingConditions['illuminantType'];
                }

                if ($iccData->measurement !== null) {
                    $measurement['Observer'] = (string) $iccData->measurement['observer'];
                    $measurement['Backing XYZ'] = $this->formatXyzTriplet($iccData->measurement['backing']);
                    $measurement['Geometry'] = (string) $iccData->measurement['geometry'];
                    $measurement['Flare'] = number_format($iccData->measurement['flare'], 6, '.', '');
                    $measurement['Illuminant'] = (string) $iccData->measurement['illuminant'];
                }
            }

            // Fallback if decoder couldn't parse the profile
            if ($header === [] && $profile === [] && $view === [] && $measurement === []) {
                $header['Profile Description'] = '(Binary data)';
            }

            if ($header !== []) {
                $this->printSection($this->formatIndexedIccSectionName('ICC-header', $sectionIndex), $header);
            }

            if ($profile !== []) {
                $this->printSection($this->formatIndexedIccSectionName('ICC_Profile', $sectionIndex), $profile);
            }

            if ($view !== []) {
                $this->printSection($this->formatIndexedIccSectionName('ICC-view', $sectionIndex), $view);
            }

            if ($measurement !== []) {
                $this->printSection($this->formatIndexedIccSectionName('ICC-meas', $sectionIndex), $measurement);
            }
        }
    }

    /**
     * Formats ICC section names with exiftool-style suffixes for additional profiles.
     */
    private function formatIndexedIccSectionName(string $baseName, int $sectionIndex): string
    {
        return $sectionIndex === 1 ? $baseName : ($baseName . $sectionIndex);
    }

    /**
     * Splits concatenated ICC profile payloads into individual profiles.
     *
     * ICC.1:2022 §7.2.2 and §7.2.9 define profile size at bytes 0..3 and "acsp"
     * signature at bytes 36..39 for each profile.
     *
     * @return list<string>
     */
    private function splitIccProfiles(string $payload): array
    {
        $profiles = [];
        $offset   = 0;
        $length   = strlen($payload);

        while (($offset + 128) <= $length) {
            $profileSizeData = unpack('Nsize', substr($payload, $offset, 4));
            if (!is_array($profileSizeData) || !isset($profileSizeData['size']) || !is_int($profileSizeData['size'])) {
                break;
            }

            $profileSize = $profileSizeData['size'];

            if ($profileSize < 128) {
                break;
            }

            if (($offset + 40) > $length || substr($payload, $offset + 36, 4) !== 'acsp') {
                break;
            }

            if (($offset + $profileSize) > $length) {
                break;
            }

            $profiles[] = substr($payload, $offset, $profileSize);
            $offset += $profileSize;
        }

        return $profiles !== [] ? $profiles : [$payload];
    }

    /**
     * Formats ICC TRC payloads as binary-size placeholders similar to exiftool.
     *
     * @param array{gamma: float}|array{table: list<int>} $trc
     */
    private function formatIccTrc(array $trc): string
    {
        if (isset($trc['table']) && is_array($trc['table'])) {
            return sprintf('(Binary data %d bytes)', 8 + (count($trc['table']) * 2));
        }

        if (isset($trc['gamma']) && is_float($trc['gamma'])) {
            return '(Binary data 12 bytes)';
        }

        return '(Binary data)';
    }

    /**
     * Prints the InteropIFD section.
     */
    private function printInteropIfdSection(?Ifd $interopIfd): void
    {
        $data = [];

        // Collect InteropIFD tags
        if (($interopIfd instanceof Ifd) && isset($interopIfd->entries)) {
            foreach ($interopIfd->entries as $tagId => $entry) {
                // Convert raw value to enum if applicable
                $data[$tagId] = $this->convertToEnumIfApplicable($tagId, $entry->value);
            }
        }

        if ($data !== []) {
            $this->printSection('InteropIFD', $data, showHex: true, ifdContext: 'InteropIFD');
        }
    }

    /**
     * Prints the IFD1 section (thumbnail metadata).
     */
    private function printIfd1Section(?Ifd $ifd1): void
    {
        $data            = [];
        $thumbnailLength = null;

        // Collect IFD1 tags
        if (($ifd1 instanceof Ifd) && isset($ifd1->entries)) {
            foreach ($ifd1->entries as $tagId => $entry) {
                // Convert raw value to enum if applicable
                $data[$tagId] = $this->convertToEnumIfApplicable($tagId, $entry->value);

                if ($tagId === ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH && is_int($entry->value) && $entry->value >= 0) {
                    $thumbnailLength = $entry->value;
                }
            }

            if ($thumbnailLength !== null) {
                $data['Thumbnail Image'] = sprintf('(Binary data %d bytes)', $thumbnailLength);
            }
        }

        if ($data !== []) {
            $this->printSection('IFD1', $data, showHex: true, ifdContext: 'IFD1');
        }
    }

    /**
     * Prints the IPTC IIM section with Application Record datasets.
     *
     * IPTC IIM (Information Interchange Model) datasets from APP13 are displayed
     * with human-readable labels matching the IPTC standard field names.
     * Multi-valued datasets (e.g., Keywords) are joined with semicolons.
     */
    private function printIptcSection(IptcDocument $iptcDoc): void
    {
        /** @var array<int, array{label: string, multi: bool}> $fields */
        $fields = [
            5   => ['label' => 'Object Name',       'multi' => false],
            25  => ['label' => 'Keywords',           'multi' => true],
            55  => ['label' => 'Date Created',       'multi' => false],
            60  => ['label' => 'Time Created',       'multi' => false],
            80  => ['label' => 'By-line',            'multi' => false],
            85  => ['label' => 'By-line Title',      'multi' => false],
            90  => ['label' => 'City',               'multi' => false],
            95  => ['label' => 'Province-State',     'multi' => false],
            101 => ['label' => 'Country-Primary Location Name', 'multi' => false],
            105 => ['label' => 'Headline',           'multi' => false],
            110 => ['label' => 'Credit',             'multi' => false],
            115 => ['label' => 'Source',             'multi' => false],
            116 => ['label' => 'Copyright Notice',   'multi' => false],
            120 => ['label' => 'Caption-Abstract',   'multi' => false],
        ];

        $data = [];

        foreach ($fields as $dataset => $meta) {
            if (!$iptcDoc->has(2, $dataset)) {
                continue;
            }

            if ($meta['multi']) {
                $values             = $iptcDoc->values(2, $dataset);
                $data[$meta['label']] = implode('; ', $values);
            } else {
                $first = $iptcDoc->first(2, $dataset);
                if ($first !== null) {
                    $data[$meta['label']] = $first;
                }
            }
        }

        if ($data !== []) {
            $this->printSection('IPTC', $data);
        }
    }

    /**
     * Prints QuickTime metadata section.
     */
    private function printQuickTimeSection(?QuickTimeMeta $quickTime): void
    {
        if ((!$quickTime instanceof QuickTimeMeta) || ($quickTime->keys === [])) {
            return;
        }

        $data = [];

        foreach ($quickTime->keys as $key => $value) {
            $label        = $this->formatQuickTimeLabel($key);
            $data[$label] = $value;
        }

        $this->printSection('QuickTime', $data);
    }

    /**
     * Formats a QuickTime metadata key into a tagged output label.
     */
    private function formatQuickTimeLabel(string $key): string
    {
        if (isset(self::QUICKTIME_KEY_LABELS[$key])) {
            return self::QUICKTIME_KEY_LABELS[$key];
        }

        return $this->quickTimeKeyToDisplayName($key);
    }

    /**
     * Converts QuickTime metadata key to display name.
     */
    private function quickTimeKeyToDisplayName(string $key): string
    {
        // Remove common prefix
        $key = preg_replace('/^com\.apple\.quicktime\./', '', $key);

        // Convert camelCase to Title Case
        $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', (string) $key);

        return ucwords((string) ($spaced ?? $key));
    }

    /**
     * Prints MPF (Multi-Picture Format) section.
     */
    private function printMpfSection(?MpfDocument $mpfDocument): void
    {
        if (!$mpfDocument instanceof MpfDocument) {
            return;
        }

        $data = [];

        if ($mpfDocument->version !== null) {
            $data['MPF Version'] = $mpfDocument->version;
        }

        $data['Image Count'] = $mpfDocument->imageCount;

        if ($mpfDocument->totalFrames !== null) {
            $data['Total Frames'] = $mpfDocument->totalFrames;
        }

        if ($mpfDocument->imageUidList !== null) {
            $data['Image UID List'] = $mpfDocument->imageUidList;
        }

        if ($mpfDocument->attributes instanceof MpfAttributes) {
            $attrs      = $mpfDocument->attributes;
            $reflection = new ReflectionClass($attrs);
            $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

            foreach ($properties as $property) {
                $name  = $property->getName();
                $value = $property->getValue($attrs);

                if ($value !== null) {
                    $data[$this->propertyNameToDisplayName($name)] = $value;
                }
            }
        }

        // Add entry information
        foreach ($mpfDocument->entries as $index => $entry) {
            $data[sprintf('Entry %d Type', $index)] = $this->formatMpfEntryType($entry);
        }

        $this->printSection('MPF', $data);
    }

    /**
     * Formats MPF entry type information.
     */
    private function formatMpfEntryType(MpfEntry $entry): string
    {
        $parts = [];

        if ($entry->imageType !== null) {
            $parts[] = $entry->imageType->name;
        } else {
            $parts[] = sprintf('Unknown type (0x%06X)', ($entry->attributes >> 16) & 0x07FF);
        }

        if ($entry->isRepresentativeImage) {
            $parts[] = 'Representative';
        }

        if ($entry->isDependentParent) {
            $parts[] = 'Parent';
        }

        if ($entry->isDependentChild) {
            $parts[] = 'Child';
        }

        if ($entry->imageDataFormat !== null) {
            $parts[] = $entry->imageDataFormat->name;
        }

        $parts[] = sprintf('size=%d', $entry->imageSize);

        return implode(', ', $parts);
    }

    /**
     * Prints FlashPix streams section.
     */
    private function printFlashPixSection(array $flashPixStreams): void
    {
        $data = [];

        foreach ($flashPixStreams as $identifier => $stream) {
            $data['Stream ' . $identifier] = sprintf('(Binary data %d bytes)', strlen((string) $stream));
        }

        if ($data !== []) {
            $this->printSection('FlashPix', $data);
        }
    }

    /**
     * Prints JPEG Audio streams section.
     */
    private function printJpegAudioSection(array $jpegAudioStreams): void
    {
        if ($jpegAudioStreams === []) {
            return;
        }

        foreach ($jpegAudioStreams as $index => $audioStream) {
            $data = [
                'Format'      => $audioStream->format,
                'Channels'    => $audioStream->channels,
                'Sample Rate' => sprintf('%d Hz', $audioStream->sampleRate),
                'Bit Depth'   => sprintf('%d bits', $audioStream->bitDepth),
                'Data Size'   => sprintf('%d bytes', strlen((string) $audioStream->data)),
                'Version'     => $audioStream->version,
            ];

            $sectionName = count($jpegAudioStreams) > 1
                ? 'JPEG Audio Stream ' . $index
                : 'JPEG Audio';

            $this->printSection($sectionName, $data);
        }
    }

    /**
     * Prints JPEG sampling factor details.
     */
    private function printJpegDetailsSection(Metadata $metadata): void
    {
        $data = [];

        if ($metadata->jpegFrameSamplingFactors !== null) {
            foreach ($metadata->jpegFrameSamplingFactors as $componentId => $factors) {
                $data[sprintf('Component %d Sampling', $componentId)] = sprintf(
                    '%dx%d',
                    $factors['horizontal'],
                    $factors['vertical']
                );
            }
        }

        if ($data !== []) {
            $this->printSection('JPEG Details', $data);
        }
    }

    /**
     * Prints HDR Gain Map section from structured metadata.
     */
    private function printHdrGainMapSection(Metadata $metadata): void
    {
        $hdrGainMap = $metadata->structured()->content->hdrGainMap;
        $data       = [];

        if ($hdrGainMap->hasGainMap) {
            $data['Has Gain Map'] = 'True';
        }

        if ($hdrGainMap->version !== null) {
            $data['Version'] = $hdrGainMap->version;
        }

        if ($hdrGainMap->baseRenditionIsHdr !== null) {
            $data['Base Rendition Is HDR'] = $hdrGainMap->baseRenditionIsHdr ? 'True' : 'False';
        }

        if ($hdrGainMap->hdrCapacityMin !== null) {
            $data['HDR Capacity Min'] = $hdrGainMap->hdrCapacityMin;
        }

        if ($hdrGainMap->hdrCapacityMax !== null) {
            $data['HDR Capacity Max'] = $hdrGainMap->hdrCapacityMax;
        }

        if ($hdrGainMap->gainMapMin !== null) {
            $data['Gain Map Min'] = $hdrGainMap->gainMapMin;
        }

        if ($hdrGainMap->gainMapMax !== null) {
            $data['Gain Map Max'] = $hdrGainMap->gainMapMax;
        }

        if ($hdrGainMap->gamma !== null) {
            $data['Gamma'] = $hdrGainMap->gamma;
        }

        if ($hdrGainMap->offsetSdr !== null) {
            $data['Offset SDR'] = $hdrGainMap->offsetSdr;
        }

        if ($hdrGainMap->offsetHdr !== null) {
            $data['Offset HDR'] = $hdrGainMap->offsetHdr;
        }

        if ($hdrGainMap->auxiliaryImageType !== null) {
            $data['Auxiliary Image Type'] = $hdrGainMap->auxiliaryImageType;
        }

        if ($metadata->gainMapBlob !== null) {
            $data['Gain Map Size'] = strlen($metadata->gainMapBlob) . ' bytes';
        }

        if ($data !== []) {
            $this->printSection('HDR Gain Map', $data);
        }
    }

    /**
     * Prints the Composite section with derived values.
     */
    private function printCompositeSection(Metadata $metadata): void
    {
        $structured = $metadata->structured();
        $exifDoc    = $metadata->exifDoc;
        $data       = [];

        $focalLengthMm = $structured->hardware->lens?->focalLengthMm ?? $exifDoc?->focalLengthMm();
        $focalLength35 = $structured->hardware->lens?->focalLengthIn35mm ?? $exifDoc?->focalLengthIn35mmFilm();
        $focalPlaneXResolution = $structured->hardware->sensor?->focalPlaneXResolution ?? $exifDoc?->focalPlaneXResolution();
        $focalPlaneResolutionUnit = $structured->hardware->sensor?->focalPlaneResolutionUnit?->value
            ?? $exifDoc?->focalPlaneResolutionUnit();
        $imageWidthPx = $exifDoc?->pixelXDimension() ?? $exifDoc?->imageWidth() ?? $metadata->jpegFrameWidth;
        $scaleFactor35 = $structured->technical->derived?->cropFactor
            ?? $this->calcScaleFactorTo35MmEquivalent(
                $focalLength35,
                $focalLengthMm,
                $focalPlaneXResolution,
                $imageWidthPx,
                $focalPlaneResolutionUnit,
            );
        $focalLength35Equivalent = $focalLength35 !== null
            ? (float) $focalLength35
            : $this->calcFocalLength35MmEquivalent($focalLengthMm, $scaleFactor35);
        $fNumber = $structured->settings->exposure?->settings?->fNumber ?? $exifDoc?->fNumber();
        $circleOfConfusionMm = $structured->technical->derived?->circleOfConfusionMm
            ?? $this->converters->calcCircleOfConfusionMm($scaleFactor35);
        $fieldOfViewHorizontalDeg = $structured->technical->derived?->fieldOfViewHorizontalDeg
            ?? $this->converters->calcHorizontalFovDeg($focalLength35, $scaleFactor35, $focalLengthMm);
        $hyperfocalDistanceMetres = $structured->technical->derived?->hyperfocalDistanceMetres
            ?? $this->converters->calcHyperfocalM($focalLengthMm, $fNumber, $circleOfConfusionMm);

        // Run Time Since Power Up (from Apple maker notes)
        if ($structured->makerNotesApple?->livePhoto?->runTime instanceof RunTime) {
            $runTime = $structured->makerNotesApple->livePhoto->runTime;
            if ($runTime->value !== null && $runTime->timescale !== null && $runTime->timescale > 0) {
                $seconds                         = $runTime->value / $runTime->timescale;
                $data['Run Time Since Power Up'] = $this->formatDuration($seconds);
            }
        }

        // Aperture
        if ($structured->settings->exposure?->settings?->fNumber !== null) {
            $data['Aperture'] = $structured->settings->exposure->settings->fNumber;
        }

        // Image Size
        if ($metadata->jpegFrameWidth !== null && $metadata->jpegFrameHeight !== null) {
            $data['Image Size'] = sprintf(
                '%dx%d',
                $metadata->jpegFrameWidth,
                $metadata->jpegFrameHeight
            );
            $data['Megapixels'] = $this->formatCompositeMegapixels(
                ($metadata->jpegFrameWidth * $metadata->jpegFrameHeight) / 1000000,
            );
        }

        // Scale Factor To 35mm Equivalent
        if ($scaleFactor35 !== null) {
            $data['Scale Factor To 35 mm Equivalent'] = round($scaleFactor35, 1);
        }

        // Shutter Speed
        if ($structured->settings->exposure?->settings?->exposureTimeSec !== null) {
            $data['Shutter Speed'] = $this->formatShutterSpeed($structured->settings->exposure->settings->exposureTimeSec);
        }

        // Composite sub-second timestamps from EXIF date strings and SubSecTime* values.
        // Formula: "<DateTime*>.<SubSecTime*>" (EXIF 3.0 §4.6.6.7.27-33).
        $subSecCreateDate = $this->formatCompositeDate(
            $exifDoc?->dateTimeDigitizedRaw(),
            $exifDoc?->subSecTimeDigitized(),
        );
        if ($subSecCreateDate !== null) {
            $data['Sub Sec Create Date'] = $subSecCreateDate;
        }

        $subSecDateTimeOriginal = $this->formatCompositeDate(
            $exifDoc?->dateTimeOriginalRaw(),
            $exifDoc?->subSecTimeOriginal(),
        );
        if ($subSecDateTimeOriginal !== null) {
            $data['Sub Sec Date/Time Original'] = $subSecDateTimeOriginal;
        }

        $subSecModifyDate = $this->formatCompositeDate(
            $exifDoc?->dateTimeRaw(),
            $exifDoc?->subSecTime(),
        );
        if ($subSecModifyDate !== null) {
            $data['Sub Sec Modify Date'] = $subSecModifyDate;
        }

        // GPS Altitude
        if ($structured->locationTime->gps?->position?->altitude !== null) {
            $altRef               = $structured->locationTime->gps->position->altitudeRef?->name ?? 'Above Sea Level';
            $data['GPS Altitude'] = sprintf('%.1f m (%s)', $structured->locationTime->gps->position->altitude, $altRef);
        }

        // GPS Date/Time
        if ($structured->locationTime->gps?->timing?->timestamp instanceof DateTimeImmutable) {
            $data['GPS Date/Time'] = $structured->locationTime->gps->timing->timestamp->format('Y:m:d H:i:s') . 'Z';
        }

        // GPS Position
        if ($structured->locationTime->gps?->position?->latitude !== null && $structured->locationTime->gps->position->longitude !== null) {
            $data['GPS Latitude'] = $this->formatGpsCoordinate(
                $structured->locationTime->gps->position->latitude,
                $structured->locationTime->gps->position->latitudeRef?->value ?? 'N'
            );
            $data['GPS Longitude'] = $this->formatGpsCoordinate(
                $structured->locationTime->gps->position->longitude,
                $structured->locationTime->gps->position->longitudeRef?->value ?? 'E'
            );

            // Combined GPS Position
            $data['GPS Position'] = sprintf(
                '%s, %s',
                $this->formatGpsCoordinate(
                    $structured->locationTime->gps->position->latitude,
                    $structured->locationTime->gps->position->latitudeRef?->value ?? 'N'
                ),
                $this->formatGpsCoordinate(
                    $structured->locationTime->gps->position->longitude,
                    $structured->locationTime->gps->position->longitudeRef?->value ?? 'E'
                )
            );
        }

        // Circle Of Confusion
        if ($circleOfConfusionMm !== null) {
            $data['Circle Of Confusion'] = sprintf('%.3f mm', $circleOfConfusionMm);
        }

        // Field Of View
        if ($fieldOfViewHorizontalDeg !== null) {
            $data['Field Of View'] = sprintf('%.1f deg', $fieldOfViewHorizontalDeg);
        }

        // Focal Length with 35mm equivalent
        if ($focalLengthMm !== null) {
            $focalStr = sprintf('%.1f mm', $focalLengthMm);
            if ($focalLength35Equivalent !== null) {
                $focalStr .= sprintf(' (35 mm equivalent: %.1f mm)', $focalLength35Equivalent);
            }
            $data['Focal Length'] = $focalStr;
        }

        // Hyperfocal Distance
        if ($hyperfocalDistanceMetres !== null) {
            $data['Hyperfocal Distance'] = sprintf('%.2f m', $hyperfocalDistanceMetres);
        }

        // Light Value
        if ($structured->technical->derived?->ev100 !== null) {
            $data['Light Value'] = round($structured->technical->derived->ev100, 1);
        }

        // Lens ID (combining lens make and model)
        if ($structured->hardware->lens?->lensModel !== null) {
            $data['Lens ID'] = $structured->hardware->lens->lensModel;
        }

        if ($data !== []) {
            $this->printSection('Composite', $data);
        }
    }

    /**
     * Calculates the 35mm scale factor from focal length and 35mm-equivalent focal length.
     *
     * Formula fallback:
     * - scaleFactor = focalLength35mm / focalLengthMm
     * - sensorWidthMm = (imageWidthPx / focalPlaneXResolution) * mmPerResolutionUnit
     * - scaleFactor = 36.0 / sensorWidthMm
     */
    private function calcScaleFactorTo35MmEquivalent(
        ?int $focalLength35mm,
        ?float $focalLengthMm,
        ?float $focalPlaneXResolution = null,
        ?int $imageWidthPx = null,
        ?int $focalPlaneResolutionUnit = null,
    ): ?float
    {
        $scaleFactor = $this->converters->calcCropFactor($focalLength35mm, $focalLengthMm);
        if ($scaleFactor !== null) {
            return $scaleFactor;
        }

        if ($focalLengthMm === null || $focalLengthMm <= 0.0 || $focalPlaneXResolution === null || $focalPlaneXResolution <= 0.0 || $imageWidthPx === null || $imageWidthPx <= 0) {
            return null;
        }

        $millimetersPerResolutionUnit = $this->millimetersPerResolutionUnit($focalPlaneResolutionUnit);
        if ($millimetersPerResolutionUnit === null) {
            return null;
        }

        $sensorWidthMm = ((float) $imageWidthPx / $focalPlaneXResolution) * $millimetersPerResolutionUnit;
        if ($sensorWidthMm <= 0.0) {
            return null;
        }

        $scaleFactor = 36.0 / $sensorWidthMm;
        if ($scaleFactor <= 0.0 || $scaleFactor > 10.0) {
            return null;
        }

        return $scaleFactor;
    }

    private function millimetersPerResolutionUnit(?int $resolutionUnit): ?float
    {
        return match ($resolutionUnit) {
            ResolutionUnit::Inches->value => 25.4,
            ResolutionUnit::Centimeter->value => 10.0,
            default                        => null,
        };
    }

    /**
     * Calculates the 35mm-equivalent focal length from focal length and scale factor.
     *
     * Formula: focalLength35mm = focalLengthMm * scaleFactor.
     */
    private function calcFocalLength35MmEquivalent(?float $focalLengthMm, ?float $scaleFactor): ?float
    {
        if ($focalLengthMm === null || $focalLengthMm <= 0.0 || $scaleFactor === null || $scaleFactor <= 0.0) {
            return null;
        }

        return $focalLengthMm * $scaleFactor;
    }

    /**
     * Formats a composite timestamp string by appending sub-second precision when available.
     */
    private function formatCompositeDate(?string $date, ?string $subSeconds): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($subSeconds === null || $subSeconds === '') {
            return $date;
        }

        return sprintf('%s.%s', $date, $subSeconds);
    }

    /**
     * Formats composite megapixels close to exiftool output precision.
     */
    private function formatCompositeMegapixels(float $megapixels): string
    {
        if ($megapixels < 0.001) {
            return number_format($megapixels, 6, '.', '');
        }

        if ($megapixels < 1.0) {
            return number_format($megapixels, 3, '.', '');
        }

        return number_format($megapixels, 1, '.', '');
    }

    /**
     * Formats duration in days/hours/minutes/seconds.
     */
    private function formatDuration(float $totalSeconds): string
    {
        $days    = (int) ($totalSeconds / 86400);
        $hours   = (int) (($totalSeconds % 86400) / 3600);
        $minutes = (int) (($totalSeconds % 3600) / 60);
        $seconds = $totalSeconds % 60;

        if ($days > 0) {
            return sprintf('%d days %d:%02d:%02d', $days, $hours, $minutes, $seconds);
        }

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Formats shutter speed as a fraction.
     */
    private function formatShutterSpeed(float $exposureTime): string
    {
        // Some files carry malformed EXIF ExposureTime values of 0; treat them
        // as an unknown/zero shutter speed instead of dividing by zero.
        if ($exposureTime <= 0.0) {
            return '0';
        }

        if ($exposureTime >= 1) {
            return number_format($exposureTime, 1);
        }

        $denominator = (int) round(1 / $exposureTime);

        return '1/' . $denominator;
    }

    /**
     * Formats GPS coordinate in degrees/minutes/seconds.
     */
    private function formatGpsCoordinate(float $decimal, string $ref): string
    {
        $degrees      = (int) abs($decimal);
        $minutesFloat = (abs($decimal) - $degrees) * 60;
        $minutes      = (int) $minutesFloat;
        $seconds      = ($minutesFloat - $minutes) * 60;
        $refString    = $ref;

        return sprintf(
            '%d deg %d\' %.2f" %s',
            $degrees,
            $minutes,
            $seconds,
            $refString
        );
    }
}

// Main execution
if ((PHP_SAPI === 'cli') && (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__)) {
    if ($argc < 2) {
        echo "Usage: php scripts/exiftool-format.php <image-file>\n";
        echo "\n";
        echo "Example:\n";
        echo "  php scripts/exiftool-format.php photo.jpg\n";
        exit(1);
    }

    $filePath = $argv[1];

    try {
        $formatter = new MetadataFormatter();
        $formatter->format($filePath);
    } catch (Throwable $e) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
        exit(1);
    }
}
