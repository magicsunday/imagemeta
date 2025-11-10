#!/usr/bin/env php
<?php

/**
 * ExifTool-like Output Formatter
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
 *   php scripts/exiftool-format.php <image-file>
 *
 * Examples:
 *   php scripts/exiftool-format.php photo.jpg
 *   php scripts/exiftool-format.php /path/to/image.heic
 *
 * To compare with actual exiftool output:
 *   exiftool -H -a -u -g1 photo.jpg > exiftool.txt
 *   php scripts/exiftool-format.php photo.jpg > imagemeta.txt
 *   diff -u exiftool.txt imagemeta.txt
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Scripts;

use DateTimeInterface;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\RegionsFactory;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\ExifFlash;
use MagicSunday\ImageMeta\Value\FlashInfo;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDirectionRef;
use MagicSunday\ImageMeta\Value\Enum\GpsDifferential;
use MagicSunday\ImageMeta\Value\Enum\GpsDistanceRef;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use MagicSunday\ImageMeta\Value\Enum\GpsMeasureMode;
use MagicSunday\ImageMeta\Value\Enum\GpsSpeedRef;
use MagicSunday\ImageMeta\Value\Enum\GpsStatus;
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

use function count;
use function date;
use function file_exists;
use function fileperms;
use function fileatime;
use function filectime;
use function filemtime;
use function filesize;
use function is_array;
use function is_numeric;
use function is_string;
use function number_format;
use function round;
use function sprintf;
use function str_pad;

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
 */
final class ExifToolFormatter
{
    private const string VERSION = '1.0.0';

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

    public function __construct()
    {
        $this->buildTagMaps();
    }

    /**
     * Maps EXIF tag IDs to their corresponding enum class names.
     *
     * This mapping enables conversion of raw EXIF values (typically integers)
     * to typed enum instances for better formatting and display.
     *
     * EXIF 3.0 §4.6 and earlier specifications define these enumerated values.
     *
     * @var array<int, class-string<\BackedEnum>>
     */
    private array $tagToEnumMap = [];

    /**
     * Builds reverse mapping from tag IDs to tag names.
     */
    private function buildTagMaps(): void
    {
        // Build EXIF tag map
        $exifReflection = new \ReflectionClass(ExifTag::class);
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
        $tiffReflection = new \ReflectionClass(TiffTag::class);
        foreach ($tiffReflection->getConstants() as $name => $value) {
            if (is_int($value)) {
                $this->tiffTagNames[$value] = $this->constantNameToTagName($name);
            }
        }

        // Build tag-to-enum mapping
        // Maps EXIF tag IDs to their corresponding enum classes
        $this->tagToEnumMap = [
            // IFD0 / TIFF tags - EXIF 3.0 §4.6.2, TIFF 6.0 §8
            ExifTag::ORIENTATION => Orientation::class,
            ExifTag::COMPRESSION => Compression::class,
            ExifTag::PHOTOMETRIC_INTERPRETATION => Photometric::class,
            ExifTag::PLANAR_CONFIGURATION => PlanarConfiguration::class,
            ExifTag::RESOLUTION_UNIT => ResolutionUnit::class,
            ExifTag::YCBCR_POSITIONING => YCbCrPositioning::class,

            // ExifIFD tags - EXIF 3.0 §4.6.3, §4.6.4
            ExifTag::COLOR_SPACE => ColorSpace::class,
            ExifTag::EXPOSURE_PROGRAM => ExposureProgram::class,
            ExifTag::METERING_MODE => MeteringMode::class,
            ExifTag::LIGHT_SOURCE => LightSource::class,
            ExifTag::WHITE_BALANCE => WhiteBalance::class,
            ExifTag::EXPOSURE_MODE => ExposureMode::class,
            ExifTag::SCENE_CAPTURE_TYPE => SceneCaptureType::class,
            ExifTag::GAIN_CONTROL => GainControl::class,
            ExifTag::CONTRAST => Contrast::class,
            ExifTag::SATURATION => Saturation::class,
            ExifTag::SHARPNESS => Sharpness::class,
            ExifTag::SUBJECT_DISTANCE_RANGE => SubjectDistanceRange::class,
            ExifTag::SENSING_METHOD => SensingMethod::class,
            ExifTag::FILE_SOURCE => FileSource::class,
            ExifTag::SCENE_TYPE => SceneType::class,
            ExifTag::CUSTOM_RENDERED => CustomRendered::class,

            // GPS tags - EXIF 3.0 §4.6.6 Table 27
            ExifTag::GPS_LATITUDE_REF => GpsLatLonRef::class,
            ExifTag::GPS_LONGITUDE_REF => GpsLatLonRef::class,
            ExifTag::GPS_ALTITUDE_REF => GpsAltitudeRef::class,
            ExifTag::GPS_STATUS => GpsStatus::class,
            ExifTag::GPS_MEASURE_MODE => GpsMeasureMode::class,
            ExifTag::GPS_SPEED_REF => GpsSpeedRef::class,
            ExifTag::GPS_TRACK_REF => GpsDirectionRef::class,
            ExifTag::GPS_IMG_DIRECTION_REF => GpsDirectionRef::class,
            ExifTag::GPS_DEST_LATITUDE_REF => GpsLatLonRef::class,
            ExifTag::GPS_DEST_LONGITUDE_REF => GpsLatLonRef::class,
            ExifTag::GPS_DEST_BEARING_REF => GpsDirectionRef::class,
            ExifTag::GPS_DEST_DISTANCE_REF => GpsDistanceRef::class,
            ExifTag::GPS_DIFFERENTIAL => GpsDifferential::class,
        ];
    }

    /**
     * Converts constant name to tag name (e.g., GPS_LATITUDE_REF -> GPS Latitude Ref).
     */
    private function constantNameToTagName(string $constantName): string
    {
        // Special cases mapping to match exiftool naming
        $specialCases = [
            'MAKE' => 'Make',
            'MODEL' => 'Camera Model Name',
            'ORIENTATION' => 'Orientation',
            'X_RESOLUTION' => 'X Resolution',
            'Y_RESOLUTION' => 'Y Resolution',
            'RESOLUTION_UNIT' => 'Resolution Unit',
            'SOFTWARE' => 'Software',
            'MODIFY_DATE' => 'Modify Date',
            'HOST_COMPUTER' => 'Host Computer',
            'TILE_WIDTH' => 'Tile Width',
            'TILE_LENGTH' => 'Tile Length',
            'YCBCR_POSITIONING' => 'Y Cb Cr Positioning',
            'EXPOSURE_TIME' => 'Exposure Time',
            'F_NUMBER' => 'F Number',
            'EXPOSURE_PROGRAM' => 'Exposure Program',
            'ISO' => 'ISO',
            'PHOTOGRAPHIC_SENSITIVITY' => 'ISO',
            'EXIF_VERSION' => 'Exif Version',
            'DATETIME_ORIGINAL' => 'Date/Time Original',
            'DATETIME_DIGITIZED' => 'Create Date',
            'OFFSET_TIME' => 'Offset Time',
            'OFFSET_TIME_ORIGINAL' => 'Offset Time Original',
            'OFFSET_TIME_DIGITIZED' => 'Offset Time Digitized',
            'COMPONENTS_CONFIGURATION' => 'Components Configuration',
            'SHUTTER_SPEED_VALUE' => 'Shutter Speed Value',
            'APERTURE_VALUE' => 'Aperture Value',
            'BRIGHTNESS_VALUE' => 'Brightness Value',
            'EXPOSURE_BIAS_VALUE' => 'Exposure Compensation',
            'METERING_MODE' => 'Metering Mode',
            'FLASH' => 'Flash',
            'FOCAL_LENGTH' => 'Focal Length',
            'SUBJECT_AREA' => 'Subject Area',
            'SUB_SEC_TIME_ORIGINAL' => 'Sub Sec Time Original',
            'SUB_SEC_TIME_DIGITIZED' => 'Sub Sec Time Digitized',
            'FLASHPIX_VERSION' => 'Flashpix Version',
            'COLOR_SPACE' => 'Color Space',
            'PIXEL_X_DIMENSION' => 'Exif Image Width',
            'PIXEL_Y_DIMENSION' => 'Exif Image Height',
            'SENSING_METHOD' => 'Sensing Method',
            'SCENE_TYPE' => 'Scene Type',
            'EXPOSURE_MODE' => 'Exposure Mode',
            'WHITE_BALANCE' => 'White Balance',
            'FOCAL_LENGTH_IN_35MM_FILM' => 'Focal Length In 35mm Format',
            'SCENE_CAPTURE_TYPE' => 'Scene Capture Type',
            'LENS_SPECIFICATION' => 'Lens Info',
            'LENS_MAKE' => 'Lens Make',
            'LENS_MODEL' => 'Lens Model',
            'COMPOSITE_IMAGE' => 'Composite Image',
            'GPS_IFD_POINTER' => 'GPS IFD Pointer',
            'EXIF_IFD_POINTER' => 'Exif IFD Pointer',
        ];

        if (isset($specialCases[$constantName])) {
            return $specialCases[$constantName];
        }

        // Handle GPS tags
        if (str_starts_with($constantName, 'GPS_')) {
            $gpsName = substr($constantName, 4);

            // Special GPS cases
            $gpsSpecialCases = [
                'LATITUDE_REF' => 'GPS Latitude Ref',
                'LATITUDE' => 'GPS Latitude',
                'LONGITUDE_REF' => 'GPS Longitude Ref',
                'LONGITUDE' => 'GPS Longitude',
                'ALTITUDE_REF' => 'GPS Altitude Ref',
                'ALTITUDE' => 'GPS Altitude',
                'TIME_STAMP' => 'GPS Time Stamp',
                'SPEED_REF' => 'GPS Speed Ref',
                'SPEED' => 'GPS Speed',
                'IMG_DIRECTION_REF' => 'GPS Img Direction Ref',
                'IMG_DIRECTION' => 'GPS Img Direction',
                'DEST_BEARING_REF' => 'GPS Dest Bearing Ref',
                'DEST_BEARING' => 'GPS Dest Bearing',
                'DATE_STAMP' => 'GPS Date Stamp',
                'H_POSITIONING_ERROR' => 'GPS Horizontal Positioning Error',
            ];

            if (isset($gpsSpecialCases[$gpsName])) {
                return $gpsSpecialCases[$gpsName];
            }

            $constantName = 'GPS ' . $gpsName;
        }

        // Convert SNAKE_CASE to Title Case
        $parts = explode('_', $constantName);
        $parts = array_map(function ($part) {
            return ucfirst(strtolower($part));
        }, $parts);

        return implode(' ', $parts);
    }

    /**
     * Converts a raw EXIF value to an enum instance if the tag ID has an enum mapping.
     *
     * This method enables the display of enum values in exiftool-like format by
     * converting raw integer/string values to their typed enum representations.
     *
     * @param int|null $tagId The EXIF tag identifier
     * @param mixed $value The raw value from the IFD entry
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
            $enumInstance = $enumClass::fromExifValue($scalarValue);
            return $enumInstance ?? $value;
        }

        return $value;
    }

    /**
     * Converts enum name from SCREAMING_SNAKE_CASE to Title Case.
     *
     * Examples:
     *   AUTO -> Auto
     *   AUTO_BRACKET -> Auto Bracket
     *   MANUAL -> Manual
     *
     * @param string $enumName The enum case name in SCREAMING_SNAKE_CASE
     */
    private function formatEnumName(string $enumName): string
    {
        // Split by underscore and convert each part to title case
        $parts = explode('_', $enumName);
        $parts = array_map(fn(string $part) => ucfirst(strtolower($part)), $parts);

        return implode(' ', $parts);
    }

    /**
     * Formats the output for a given image file.
     */
    public function format(string $filePath): void
    {
        if (!file_exists($filePath)) {
            echo "Error: File not found: {$filePath}\n";
            exit(1);
        }

        $reader = new MetadataReader();
        $metadata = $reader->read($filePath, withDigests: true);

        // ExifTool section
        $this->printSection('ExifTool', [
            'ExifTool Version Number' => self::VERSION,
        ]);

        // System section
        $this->printSystemSection($filePath);

        // File section
        $this->printFileSection($metadata, $filePath);

        // IFD0 section
        if ($metadata->exifDoc !== null) {
            $this->printIfd0Section($metadata->exifDoc);
        }

        // ExifIFD section
        if ($metadata->exifDoc !== null && $metadata->exifDoc->exifIfd !== null) {
            $this->printExifIfdSection($metadata->exifDoc->exifIfd);
        }

        // MakerNotes section
        if ($metadata->makerNotes !== null) {
            $this->printMakerNotesSection($metadata->makerNotes);
        }

        // GPS section
        if ($metadata->exifDoc !== null && $metadata->exifDoc->gpsIfd !== null) {
            $this->printGpsSection($metadata->exifDoc->gpsIfd);
        }

        // InteropIFD section
        if ($metadata->exifDoc !== null && $metadata->exifDoc->interopIfd !== null) {
            $this->printInteropIfdSection($metadata->exifDoc->interopIfd);
        }

        // IFD1 section (thumbnail metadata)
        if ($metadata->exifDoc !== null && $metadata->exifDoc->ifd1 !== null) {
            $this->printIfd1Section($metadata->exifDoc->ifd1);
        }

        // XMP sections
        if ($metadata->xmpDoc !== null) {
            $this->printXmpSections($metadata->xmpDoc);
        }

        // QuickTime section
        if ($metadata->quickTime !== null) {
            $this->printQuickTimeSection($metadata->quickTime);
        }

        // MPF section
        if ($metadata->mpfDocument !== null) {
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

        // Composite section
        $this->printCompositeSection($metadata);
    }

    /**
     * Prints a section header and its data.
     *
     * @param array<string, mixed> $data
     * @param string|null $ifdContext The IFD context ('GPS', 'InteropIFD', etc.) for correct tag name resolution
     */
    private function printSection(string $sectionName, array $data, bool $showHex = false, ?string $ifdContext = null): void
    {
        echo "---- {$sectionName} ----\n";

        foreach ($data as $key => $value) {
            $tagId = is_numeric($key) ? (int) $key : null;
            $formattedValue = $this->formatValue($value, $ifdContext, $tagId);

            if ($showHex && is_numeric($key)) {
                $hexKey = sprintf('0x%04x', (int) $key);
                $tagName = $this->getTagName((int) $key, $ifdContext);
                // Format with exactly 40 characters before the colon
                // hex(6) + space(1) + tag name (padded to fill remaining 33 chars) = 40 total
                $label = sprintf('%s %s', $hexKey, $tagName);
                printf("%-39s: %s\n", $label, $formattedValue);
            } else {
                // Format with exactly 40 characters before the colon
                // "     - " (7 chars) + key name (padded to fill remaining 33 chars) = 40 total
                $label = sprintf('     - %s', $key);
                printf("%-39s: %s\n", $label, $formattedValue);
            }
        }
    }

    /**
     * Gets the tag name for a given tag ID.
     *
     * @param int $tagId The tag ID to look up
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

        // Fall back to general TIFF and EXIF tag maps
        return $this->tiffTagNames[$tagId]
            ?? $this->exifTagNames[$tagId]
            ?? sprintf('Unknown 0x%04x', $tagId);
    }

    /**
     * Formats a value for display.
     *
     * @param mixed $value The value to format
     * @param string|null $ifdContext The IFD context for tag-specific formatting
     * @param int|null $tagId The tag ID for tag-specific formatting
     */
    private function formatValue(mixed $value, ?string $ifdContext = null, ?int $tagId = null): string
    {
        if ($value === null) {
            return '(none)';
        }

        // Special handling for Flash tag (0x9209) - EXIF 3.0 §4.6.4
        if ($tagId === ExifTag::FLASH) {
            $rawValue = $value;
            if ($value instanceof ExifNumericList) {
                $rawValue = $value->values[0] ?? null;
            }
            
            if (is_int($rawValue) || is_string($rawValue)) {
                $flashInfo = ExifFlash::fromExifValue($rawValue);
                if ($flashInfo !== null) {
                    $parts = [];
                    
                    if ($flashInfo->fired) {
                        $parts[] = 'Flash Fired';
                    } else {
                        $parts[] = 'Flash Did Not Fire';
                    }
                    
                    if ($flashInfo->mode !== null) {
                        $modeName = $this->formatEnumName($flashInfo->mode->name);
                        $parts[] = $modeName . ' Mode';
                    }
                    
                    if ($flashInfo->returnDetection !== null && $flashInfo->fired) {
                        $returnName = $this->formatEnumName($flashInfo->returnDetection->name);
                        if ($returnName !== 'No Strobe Detection') {
                            $parts[] = $returnName;
                        }
                    }
                    
                    if ($flashInfo->functionPresence !== null && $flashInfo->functionPresence->name === 'ABSENT') {
                        $parts[] = 'No Flash Function';
                    }
                    
                    if ($flashInfo->redEyeReduction) {
                        $parts[] = 'Red-eye Reduction';
                    }
                    
                    return $rawValue . ' (' . implode(', ', $parts) . ')';
                }
            }
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

            // If it's a simple fraction, show as fraction
            if ($value->denominator !== 1 && abs($decimal) < 10) {
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
            return implode(' ', $parts);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y:m:d H:i:s');
        }

        if ($value instanceof \BackedEnum) {
            // For enums, show both value and name in parentheses
            // Example: "0 (Auto)" instead of "0 (AUTO)"
            $enumValue = $value->value ?? $value->name;
            $enumName = $this->formatEnumName($value->name);

            // Only add name in parentheses if it's different from the value
            if ((string) $enumValue !== $enumName) {
                return "{$enumValue} ({$enumName})";
            }

            return (string) $enumValue;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            if (empty($value)) {
                return '(none)';
            }

            // Check if it's a simple numeric array
            if (array_is_list($value)) {
                return implode(' ', array_map(fn($v) => $this->formatValue($v, $ifdContext, $tagId), $value));
            }

            return json_encode($value);
        }

        if (is_float($value)) {
            // Clean up unnecessary decimals
            $formatted = number_format($value, 10, '.', '');
            return rtrim(rtrim($formatted, '0'), '.');
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
     * @param int $tagId The GPS tag ID
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
        $fileName = basename($filePath);
        $directory = dirname($filePath);
        $fileSize = filesize($filePath);
        $modTime = filemtime($filePath);
        $accessTime = fileatime($filePath);
        $changeTime = filectime($filePath);
        $perms = fileperms($filePath);

        $sizeFormatted = $this->formatFileSize($fileSize);
        $permsFormatted = $this->formatPermissions($perms);

        // Format timestamps with local timezone (matching exiftool behavior)
        $timezone = new \DateTimeZone('Europe/Berlin');
        $modDateTime = (new \DateTime('@' . $modTime))->setTimezone($timezone);
        $accessDateTime = (new \DateTime('@' . $accessTime))->setTimezone($timezone);
        $changeDateTime = (new \DateTime('@' . $changeTime))->setTimezone($timezone);

        $this->printSection('System', [
            'File Name' => $fileName,
            'Directory' => $directory,
            'File Size' => $sizeFormatted,
            'File Modification Date/Time' => $modDateTime->format('Y:m:d H:i:sP'),
            'File Access Date/Time' => $accessDateTime->format('Y:m:d H:i:sP'),
            'File Inode Change Date/Time' => $changeDateTime->format('Y:m:d H:i:sP'),
            'File Permissions' => $permsFormatted,
        ]);
    }

    /**
     * Formats file size in human-readable format.
     */
    private function formatFileSize(int|false $bytes): string
    {
        if ($bytes === false) {
            return 'unknown';
        }

        $units = ['bytes', 'kB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1) . ' ' . $units[$i];
    }

    /**
     * Formats file permissions in Unix format.
     */
    private function formatPermissions(int|false $perms): string
    {
        if ($perms === false) {
            return 'unknown';
        }

        $info = '';

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
        $info .= (($perms & 0x0100) ? 'r' : '-');
        $info .= (($perms & 0x0080) ? 'w' : '-');
        $info .= (($perms & 0x0040) ?
            (($perms & 0x0800) ? 's' : 'x') :
            (($perms & 0x0800) ? 'S' : '-'));

        // Group permissions
        $info .= (($perms & 0x0020) ? 'r' : '-');
        $info .= (($perms & 0x0010) ? 'w' : '-');
        $info .= (($perms & 0x0008) ?
            (($perms & 0x0400) ? 's' : 'x') :
            (($perms & 0x0400) ? 'S' : '-'));

        // Other permissions
        $info .= (($perms & 0x0004) ? 'r' : '-');
        $info .= (($perms & 0x0002) ? 'w' : '-');
        $info .= (($perms & 0x0001) ?
            (($perms & 0x0200) ? 't' : 'x') :
            (($perms & 0x0200) ? 'T' : '-'));

        return $info;
    }

    /**
     * Prints the File section with container metadata.
     */
    private function printFileSection(Metadata $metadata, string $filePath): void
    {
        $data = [
            'File Type' => $this->detectFileType($filePath),
            'File Type Extension' => $metadata->extension ?? 'unknown',
            'MIME Type' => $metadata->mimeType ?? 'unknown',
        ];

        // Add file size if available
        if ($metadata->fileSize !== null) {
            $data['File Size'] = $this->formatFileSize($metadata->fileSize);
        }

        // Add EXIF byte order if available
        if ($metadata->exifDoc !== null) {
            $endianness = $metadata->exifDoc->endianness ?? null;
            if ($endianness !== null) {
                $data['Exif Byte Order'] = $endianness->value === 'MM'
                    ? 'Big-endian (Motorola, MM)'
                    : 'Little-endian (Intel, II)';
            }
        }

        // Add image dimensions if available from JPEG or EXIF
        if ($metadata->jpegFrameWidth !== null && $metadata->jpegFrameHeight !== null) {
            $data['Image Width'] = $metadata->jpegFrameWidth;
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
            $data['YCbCr Sub Sampling'] = sprintf(
                'YCbCr %d:%d',
                $metadata->jpegYCbCrSubSampling[0],
                $metadata->jpegYCbCrSubSampling[1]
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
     * Detects file type from extension.
     */
    private function detectFileType(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'JPEG',
            'heic' => 'HEIC',
            'heif' => 'HEIF',
            'avif' => 'AVIF',
            'mov' => 'MOV',
            'mp4' => 'MP4',
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
            // Convert raw value to enum if applicable
            $data[$tagId] = $this->convertToEnumIfApplicable($tagId, $entry->value);
        }

        if (!empty($data)) {
            $this->printSection('IFD0', $data, showHex: true);
        }
    }

    /**
     * Prints the ExifIFD section.
     */
    private function printExifIfdSection(?Ifd $exifIfd): void
    {
        $data = [];

        // Collect ExifIFD tags
        if (($exifIfd !== null) && isset($exifIfd->entries)) {
            foreach ($exifIfd->entries as $tagId => $entry) {
                // Convert raw value to enum if applicable
                $data[$tagId] = $this->convertToEnumIfApplicable($tagId, $entry->value);
            }
        }

        if (!empty($data)) {
            $this->printSection('ExifIFD', $data, showHex: true);
        }
    }

    /**
     * Prints the GPS section.
     */
    private function printGpsSection(?Ifd $gpsIfd): void
    {
        $data = [];

        // Collect GPS tags
        if (($gpsIfd !== null) && isset($gpsIfd->entries)) {
            foreach ($gpsIfd->entries as $tagId => $entry) {
                // Convert raw value to enum if applicable
                $data[$tagId] = $this->convertToEnumIfApplicable($tagId, $entry->value);
            }
        }

        if (!empty($data)) {
            $this->printSection('GPS', $data, showHex: true, ifdContext: 'GPS');
        }
    }

    /**
     * Prints MakerNotes sections.
     */
    private function printMakerNotesSection($makerNotes): void
    {
        if ($makerNotes === null) {
            return;
        }

        // Determine vendor name
        $vendor = $makerNotes->vendor ?? 'Unknown';

        // For Apple maker notes, extract detailed information
        if ($makerNotes->apple !== null) {
            $data = [];
            $apple = $makerNotes->apple;

            // Use reflection to get all properties
            $reflection = new \ReflectionClass($apple);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

            foreach ($properties as $property) {
                $name = $property->getName();
                $value = $property->getValue($apple);

                if ($value !== null) {
                    // Convert property name to title case
                    $displayName = $this->propertyNameToDisplayName($name);
                    $data[$displayName] = $value;
                }
            }

            if (!empty($data)) {
                $this->printSection('Apple', $data);
            }
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
    private function printXmpSections(?XmpDocument $xmpDoc): void
    {
        if (($xmpDoc === null) || !isset($xmpDoc->data)) {
            return;
        }

        // Group XMP data by namespace
        $grouped = [];

        foreach ($xmpDoc->data as $clarkNotation => $value) {
            // Clark notation format: {namespace}localName or just localName
            if (preg_match('/^\{([^}]+)\}(.+)$/', $clarkNotation, $matches)) {
                $namespace = $matches[1];
                $localName = $matches[2];

                // Simplify namespace for display
                $prefix = $this->namespaceToPrefix($namespace);

                if (!isset($grouped[$prefix])) {
                    $grouped[$prefix] = [];
                }

                $grouped[$prefix][$localName] = $value;
            } else {
                // No namespace - use 'unknown' prefix
                if (!isset($grouped['unknown'])) {
                    $grouped['unknown'] = [];
                }

                $grouped['unknown'][$clarkNotation] = $value;
            }
        }

        // Print each namespace section
        foreach ($grouped as $prefix => $data) {
            if (!empty($data)) {
                $this->printSection("XMP-{$prefix}", $data);
            }
        }
    }

    /**
     * Converts XMP namespace URI to common prefix.
     */
    private function namespaceToPrefix(string $namespace): string
    {
        $prefixMap = [
            'http://ns.adobe.com/xap/1.0/' => 'xmp',
            'http://purl.org/dc/elements/1.1/' => 'dc',
            'http://ns.adobe.com/photoshop/1.0/' => 'photoshop',
            'http://ns.adobe.com/tiff/1.0/' => 'tiff',
            'http://ns.adobe.com/exif/1.0/' => 'exif',
            'http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/' => 'Iptc4xmpCore',
            RegionsFactory::NS_MWG_REGIONS => 'mwg-rs',
            RegionsFactory::NS_ST_AREA => 'mwg-rs',
            RegionsFactory::NS_ST_DIMENSIONS => 'mwg-rs',
            'http://ns.apple.com/adjustment-settings/1.0/' => 'apple-fi',
            RegionsFactory::NS_APPLE_FACEINFO => 'mwg-rs',
            'adobe:ns:meta/' => 'x',
        ];

        return $prefixMap[$namespace] ?? 'unknown';
    }

    /**
     * Prints ICC Profile section.
     */
    private function printIccSection(string $iccProfile): void
    {
        // Simplified ICC output
        $this->printSection('ICC-header', [
            'Profile Description' => '(Binary data)',
        ]);
    }

    /**
     * Prints the InteropIFD section.
     */
    private function printInteropIfdSection(?Ifd $interopIfd): void
    {
        $data = [];

        // Collect InteropIFD tags
        if (($interopIfd !== null) && isset($interopIfd->entries)) {
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
        $data = [];

        // Collect IFD1 tags
        if (($ifd1 !== null) && isset($ifd1->entries)) {
            foreach ($ifd1->entries as $tagId => $entry) {
                // Convert raw value to enum if applicable
                $data[$tagId] = $this->convertToEnumIfApplicable($tagId, $entry->value);
            }
        }

        if ($data !== []) {
            $this->printSection('IFD1', $data, showHex: true);
        }
    }

    /**
     * Prints QuickTime metadata section.
     */
    private function printQuickTimeSection(?QuickTimeMeta $quickTime): void
    {
        if (($quickTime === null) || ($quickTime->keys === [])) {
            return;
        }

        $data = [];

        foreach ($quickTime->keys as $key => $value) {
            // Convert key to display name
            $displayKey = $this->quickTimeKeyToDisplayName($key);
            $data[$displayKey] = $value;
        }

        if ($data !== []) {
            $this->printSection('QuickTime', $data);
        }
    }

    /**
     * Converts QuickTime metadata key to display name.
     */
    private function quickTimeKeyToDisplayName(string $key): string
    {
        // Remove common prefix
        $key = preg_replace('/^com\.apple\.quicktime\./', '', $key);

        // Convert camelCase to Title Case
        $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
        return ucwords($spaced ?? $key);
    }

    /**
     * Prints MPF (Multi-Picture Format) section.
     */
    private function printMpfSection(?MpfDocument $mpfDocument): void
    {
        if ($mpfDocument === null) {
            return;
        }

        $data = [];

        if ($mpfDocument->version !== null) {
            $data['MPF Version'] = $mpfDocument->version;
        }

        $data['Image Count'] = $mpfDocument->imageCount;

        if ($mpfDocument->attributes !== null) {
            $attrs = $mpfDocument->attributes;
            $reflection = new \ReflectionClass($attrs);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

            foreach ($properties as $property) {
                $name = $property->getName();
                $value = $property->getValue($attrs);

                if ($value !== null) {
                    $displayName = $this->propertyNameToDisplayName($name);
                    $data[$displayName] = $value;
                }
            }
        }

        // Add entry information
        foreach ($mpfDocument->entries as $index => $entry) {
            $data["Entry {$index} Type"] = $this->formatMpfEntryType($entry);
        }

        if ($data !== []) {
            $this->printSection('MPF', $data);
        }
    }

    /**
     * Formats MPF entry type information.
     */
    private function formatMpfEntryType($entry): string
    {
        $reflection = new \ReflectionClass($entry);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        $parts = [];

        foreach ($properties as $property) {
            $name = $property->getName();
            $value = $property->getValue($entry);

            if (($value !== null) && ($name !== 'dataOffset') && ($name !== 'size')) {
                $parts[] = "$name=$value";
            }
        }

        return $parts !== [] ? implode(', ', $parts) : 'Unknown';
    }

    /**
     * Prints FlashPix streams section.
     */
    private function printFlashPixSection(array $flashPixStreams): void
    {
        $data = [];

        foreach ($flashPixStreams as $identifier => $stream) {
            $data["Stream {$identifier}"] = sprintf('(Binary data %d bytes)', strlen($stream));
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
                'Format' => $audioStream->format,
                'Channels' => $audioStream->channels,
                'Sample Rate' => sprintf('%d Hz', $audioStream->sampleRate),
                'Bit Depth' => sprintf('%d bits', $audioStream->bitDepth),
                'Data Size' => sprintf('%d bytes', strlen($audioStream->data)),
                'Version' => $audioStream->version,
            ];

            $sectionName = count($jpegAudioStreams) > 1
                ? "JPEG Audio Stream {$index}"
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
                $data["Component {$componentId} Sampling"] = sprintf(
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
     * Prints the Composite section with derived values.
     */
    private function printCompositeSection(Metadata $metadata): void
    {
        $structured = $metadata->structured();
        $data = [];

        // Run Time Since Power Up (from Apple maker notes)
        if ($structured->makerNotesApple?->runTime !== null) {
            $runTime = $structured->makerNotesApple->runTime;
            if ($runTime->value !== null && $runTime->timescale !== null && $runTime->timescale > 0) {
                $seconds = $runTime->value / $runTime->timescale;
                $data['Run Time Since Power Up'] = $this->formatDuration($seconds);
            }
        }

        // Aperture
        if ($structured->exposure->fNumber !== null) {
            $data['Aperture'] = $structured->exposure->fNumber;
        }

        // Image Size
        if ($metadata->jpegFrameWidth !== null && $metadata->jpegFrameHeight !== null) {
            $data['Image Size'] = sprintf(
                '%dx%d',
                $metadata->jpegFrameWidth,
                $metadata->jpegFrameHeight
            );
            $data['Megapixels'] = round(
                ($metadata->jpegFrameWidth * $metadata->jpegFrameHeight) / 1000000,
                1
            );
        }

        // Scale Factor To 35mm Equivalent
        if ($structured->derived->cropFactor !== null) {
            $data['Scale Factor To 35 mm Equivalent'] = round($structured->derived->cropFactor, 1);
        }

        // Shutter Speed
        if ($structured->exposure->exposureTimeSec !== null) {
            $data['Shutter Speed'] = $this->formatShutterSpeed($structured->exposure->exposureTimeSec);
        }

        // Create Date with subseconds
        if ($structured->temporal->create !== null) {
            $dateStr = $structured->temporal->create->format('Y:m:d H:i:s');
            if ($structured->temporal->subSecTimeOriginal !== null) {
                $dateStr .= '.' . $structured->temporal->subSecTimeOriginal;
            }
            if ($structured->temporal->offsetTimeOriginal !== null) {
                $dateStr .= $structured->temporal->offsetTimeOriginal;
            }
            $data['Create Date'] = $dateStr;
        }

        // Date/Time Original
        if ($structured->temporal->original !== null) {
            $dateStr = $structured->temporal->original->format('Y:m:d H:i:s');
            if ($structured->temporal->subSecTimeOriginal !== null) {
                $dateStr .= '.' . $structured->temporal->subSecTimeOriginal;
            }
            if ($structured->temporal->offsetTimeOriginal !== null) {
                $dateStr .= $structured->temporal->offsetTimeOriginal;
            }
            $data['Date/Time Original'] = $dateStr;
        }

        // Modify Date
        if ($structured->temporal->modify !== null) {
            $dateStr = $structured->temporal->modify->format('Y:m:d H:i:s');
            if ($structured->temporal->offsetTime !== null) {
                $dateStr .= $structured->temporal->offsetTime;
            }
            $data['Modify Date'] = $dateStr;
        }

        // GPS Altitude
        if ($structured->gps->altitude !== null) {
            $altRef = $structured->gps->altitudeRef->name ?? 'Above Sea Level';
            $data['GPS Altitude'] = sprintf('%.1f m (%s)', $structured->gps->altitude, $altRef);
        }

        // GPS Date/Time
        if ($structured->gps->timestamp !== null) {
            $data['GPS Date/Time'] = $structured->gps->timestamp->format('Y:m:d H:i:s') . 'Z';
        }

        // GPS Position
        if ($structured->gps->latitude !== null && $structured->gps->longitude !== null) {
            $data['GPS Latitude'] = $this->formatGpsCoordinate(
                $structured->gps->latitude,
                $structured->gps->latitudeRef ?? 'N'
            );
            $data['GPS Longitude'] = $this->formatGpsCoordinate(
                $structured->gps->longitude,
                $structured->gps->longitudeRef ?? 'E'
            );

            // Combined GPS Position
            $data['GPS Position'] = sprintf(
                '%s, %s',
                $this->formatGpsCoordinate($structured->gps->latitude, $structured->gps->latitudeRef ?? 'N'),
                $this->formatGpsCoordinate($structured->gps->longitude, $structured->gps->longitudeRef ?? 'E')
            );
        }

        // Circle Of Confusion
        if ($structured->derived->circleOfConfusionMm !== null) {
            $data['Circle Of Confusion'] = sprintf('%.3f mm', $structured->derived->circleOfConfusionMm);
        }

        // Field Of View
        if ($structured->derived->fieldOfViewHorizontalDeg !== null) {
            $data['Field Of View'] = sprintf('%.1f deg', $structured->derived->fieldOfViewHorizontalDeg);
        }

        // Focal Length with 35mm equivalent
        if ($structured->lens->focalLengthMm !== null) {
            $focalStr = sprintf('%.1f mm', $structured->lens->focalLengthMm);
            if ($structured->lens->focalLengthIn35mm !== null) {
                $focalStr .= sprintf(' (35 mm equivalent: %.1f mm)', $structured->lens->focalLengthIn35mm);
            }
            $data['Focal Length'] = $focalStr;
        }

        // Hyperfocal Distance
        if ($structured->derived->hyperfocalDistanceMetres !== null) {
            $data['Hyperfocal Distance'] = sprintf('%.2f m', $structured->derived->hyperfocalDistanceMetres);
        }

        // Light Value
        if ($structured->derived->ev100 !== null) {
            $data['Light Value'] = round($structured->derived->ev100, 1);
        }

        // Lens ID (combining lens make and model)
        if ($structured->lens->lensModel !== null) {
            $data['Lens ID'] = $structured->lens->lensModel;
        }

        if (!empty($data)) {
            $this->printSection('Composite', $data);
        }
    }

    /**
     * Formats duration in days/hours/minutes/seconds.
     */
    private function formatDuration(float $totalSeconds): string
    {
        $days = (int) ($totalSeconds / 86400);
        $hours = (int) (($totalSeconds % 86400) / 3600);
        $minutes = (int) (($totalSeconds % 3600) / 60);
        $seconds = (int) ($totalSeconds % 60);

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
        if ($exposureTime >= 1) {
            return number_format($exposureTime, 1);
        }

        $denominator = (int) round(1 / $exposureTime);
        return "1/{$denominator}";
    }

    /**
     * Formats GPS coordinate in degrees/minutes/seconds.
     */
    private function formatGpsCoordinate(float $decimal, string $ref): string
    {
        $degrees = (int) abs($decimal);
        $minutesFloat = (abs($decimal) - $degrees) * 60;
        $minutes = (int) $minutesFloat;
        $seconds = ($minutesFloat - $minutes) * 60;

        return sprintf(
            '%d deg %d\' %.2f" %s',
            $degrees,
            $minutes,
            $seconds,
            $ref
        );
    }
}

// Main execution
if ($argc < 2) {
    echo "Usage: php scripts/exiftool-format.php <image-file>\n";
    echo "\n";
    echo "Example:\n";
    echo "  php scripts/exiftool-format.php photo.jpg\n";
    exit(1);
}

$filePath = $argv[1];

try {
    $formatter = new ExifToolFormatter();
    $formatter->format($filePath);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
