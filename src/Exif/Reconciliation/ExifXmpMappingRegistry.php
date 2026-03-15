<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reconciliation;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Model\Xmp\XmpNamespace;

/**
 * Central registry of EXIF↔XMP property mappings per CIPA DC-X010-2017.
 *
 * Tables 3-16 map EXIF/TIFF tag IDs to XMP namespace + property + value type.
 * GPS and Interoperability IFDs use separate tag ID spaces, resolved via
 * dedicated lookup methods.
 */
final class ExifXmpMappingRegistry
{
    /** @var array<int, ExifXmpMapping> Primary/Exif IFD mappings keyed by tag ID. */
    private array $primary;

    /** @var array<int, ExifXmpMapping> GPS IFD mappings keyed by tag ID. */
    private array $gps;

    /** @var array<int, ExifXmpMapping> Interoperability IFD mappings keyed by tag ID. */
    private array $interop;

    /**
     * @param list<ExifXmpMapping> $primaryMappings Primary/Exif IFD mappings.
     * @param list<ExifXmpMapping> $gpsMappings     GPS IFD mappings.
     * @param list<ExifXmpMapping> $interopMappings Interoperability IFD mappings.
     */
    public function __construct(array $primaryMappings, array $gpsMappings = [], array $interopMappings = [])
    {
        $this->primary = $this->indexByTag($primaryMappings);
        $this->gps     = $this->indexByTag($gpsMappings);
        $this->interop = $this->indexByTag($interopMappings);
    }

    /**
     * Creates a registry pre-populated with all CIPA DC-X010-2017 mapping tables.
     */
    public static function createDefault(): self
    {
        return new self(
            self::primaryMappings(),
            self::gpsMappings(),
            self::interopMappings(),
        );
    }

    /**
     * Finds the XMP mapping for a primary or Exif IFD tag.
     */
    public function findByExifTag(int $tag): ?ExifXmpMapping
    {
        return $this->primary[$tag] ?? null;
    }

    /**
     * Finds the XMP mapping for a GPS IFD tag.
     */
    public function findGpsTag(int $tag): ?ExifXmpMapping
    {
        return $this->gps[$tag] ?? null;
    }

    /**
     * Finds the XMP mapping for an Interoperability IFD tag.
     */
    public function findInteropTag(int $tag): ?ExifXmpMapping
    {
        return $this->interop[$tag] ?? null;
    }

    /**
     * @param list<ExifXmpMapping> $mappings
     *
     * @return array<int, ExifXmpMapping>
     */
    private function indexByTag(array $mappings): array
    {
        $indexed = [];

        foreach ($mappings as $mapping) {
            $indexed[$mapping->exifTag] = $mapping;
        }

        return $indexed;
    }

    // ========================================================================
    // Tables 3-5: TIFF properties
    // ========================================================================

    /**
     * @return list<ExifXmpMapping>
     */
    private static function primaryMappings(): array
    {
        $t = XmpNamespace::TIFF;
        $e = XmpNamespace::EXIF;
        $x = XmpNamespace::EXIFEX;
        $d = XmpNamespace::DC;
        $a = XmpNamespace::XAP;

        return [
            // Table 3: TIFF Image Data Structure
            new ExifXmpMapping(ExifTag::IMAGE_WIDTH, $t, 'ImageWidth', ExifXmpValueType::Integer),
            new ExifXmpMapping(ExifTag::IMAGE_LENGTH, $t, 'ImageLength', ExifXmpValueType::Integer),
            new ExifXmpMapping(ExifTag::BITS_PER_SAMPLE, $t, 'BitsPerSample', ExifXmpValueType::OrderedArrayOfInteger),
            new ExifXmpMapping(ExifTag::COMPRESSION, $t, 'Compression', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::PHOTOMETRIC_INTERPRETATION, $t, 'PhotometricInterpretation', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::ORIENTATION, $t, 'Orientation', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::SAMPLES_PER_PIXEL, $t, 'SamplesPerPixel', ExifXmpValueType::Integer),
            new ExifXmpMapping(ExifTag::PLANAR_CONFIGURATION, $t, 'PlanarConfiguration', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::YCBCR_SUB_SAMPLING, $t, 'YCbCrSubSampling', ExifXmpValueType::ClosedChoiceOfOrderedArray),
            new ExifXmpMapping(ExifTag::YCBCR_POSITIONING, $t, 'YCbCrPositioning', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::X_RESOLUTION, $t, 'XResolution', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::Y_RESOLUTION, $t, 'YResolution', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::RESOLUTION_UNIT, $t, 'ResolutionUnit', ExifXmpValueType::ClosedChoiceOfInteger),

            // Table 4: TIFF Image Data Characteristics
            new ExifXmpMapping(ExifTag::TRANSFER_FUNCTION, $t, 'TransferFunction', ExifXmpValueType::OrderedArrayOfInteger),
            new ExifXmpMapping(ExifTag::WHITE_POINT, $t, 'WhitePoint', ExifXmpValueType::OrderedArrayOfRational),
            new ExifXmpMapping(ExifTag::PRIMARY_CHROMATICITIES, $t, 'PrimaryChromaticities', ExifXmpValueType::OrderedArrayOfRational),
            new ExifXmpMapping(ExifTag::YCBCR_COEFFICIENTS, $t, 'YCbCrCoefficients', ExifXmpValueType::OrderedArrayOfRational),
            new ExifXmpMapping(ExifTag::REFERENCE_BLACK_WHITE, $t, 'ReferenceBlackWhite', ExifXmpValueType::OrderedArrayOfRational),

            // Table 5: Other TIFF Properties
            new ExifXmpMapping(ExifTag::DATETIME, $a, 'ModifyDate', ExifXmpValueType::Date),
            new ExifXmpMapping(ExifTag::IMAGE_DESCRIPTION, $d, 'description', ExifXmpValueType::LanguageAlternative),
            new ExifXmpMapping(ExifTag::MAKE, $t, 'Make', ExifXmpValueType::ProperName),
            new ExifXmpMapping(ExifTag::MODEL, $t, 'Model', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::SOFTWARE, $a, 'CreatorTool', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::ARTIST, $d, 'creator', ExifXmpValueType::OrderedArrayOfProperName),
            new ExifXmpMapping(ExifTag::COPYRIGHT, $d, 'rights', ExifXmpValueType::LanguageAlternative),

            // Table 7: Exif Version Related
            new ExifXmpMapping(ExifTag::EXIF_VERSION, $e, 'ExifVersion', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::FLASHPIX_VERSION, $e, 'FlashpixVersion', ExifXmpValueType::Text),

            // Table 8: Exif Image Data Characteristics
            new ExifXmpMapping(ExifTag::COLOR_SPACE, $e, 'ColorSpace', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::GAMMA, $x, 'Gamma', ExifXmpValueType::Rational),

            // Table 9: Exif Image Configuration
            new ExifXmpMapping(ExifTag::COMPONENTS_CONFIGURATION, $e, 'ComponentsConfiguration', ExifXmpValueType::ClosedChoiceOfOrderedArray),
            new ExifXmpMapping(ExifTag::COMPRESSED_BITS_PER_PIXEL, $e, 'CompressedBitsPerPixel', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::PIXEL_X_DIMENSION, $e, 'PixelXDimension', ExifXmpValueType::Integer),
            new ExifXmpMapping(ExifTag::PIXEL_Y_DIMENSION, $e, 'PixelYDimension', ExifXmpValueType::Integer),

            // Table 10: User Information
            new ExifXmpMapping(ExifTag::USER_COMMENT, $e, 'UserComment', ExifXmpValueType::LanguageAlternative),

            // Table 11: File Information
            new ExifXmpMapping(ExifTag::RELATED_SOUND_FILE, $e, 'RelatedSoundFile', ExifXmpValueType::Text),

            // Table 12: Date and Time
            new ExifXmpMapping(ExifTag::DATETIME_ORIGINAL, $e, 'DateTimeOriginal', ExifXmpValueType::Date),
            new ExifXmpMapping(ExifTag::DATETIME_DIGITIZED, $a, 'CreateDate', ExifXmpValueType::Date),

            // Table 13: Picture-Taking Conditions
            new ExifXmpMapping(ExifTag::EXPOSURE_TIME, $e, 'ExposureTime', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::F_NUMBER, $e, 'FNumber', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::EXPOSURE_PROGRAM, $e, 'ExposureProgram', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::SPECTRAL_SENSITIVITY, $e, 'SpectralSensitivity', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::PHOTOGRAPHIC_SENSITIVITY, $x, 'PhotographicSensitivity', ExifXmpValueType::Integer),
            new ExifXmpMapping(ExifTag::SENSITIVITY_TYPE, $x, 'SensitivityType', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::STANDARD_OUTPUT_SENSITIVITY, $x, 'StandardOutputSensitivity', ExifXmpValueType::Integer),
            new ExifXmpMapping(ExifTag::RECOMMENDED_EXPOSURE_INDEX, $x, 'RecommendedExposureIndex', ExifXmpValueType::Integer),
            new ExifXmpMapping(ExifTag::ISO_SPEED, $x, 'ISOSpeed', ExifXmpValueType::Integer),
            new ExifXmpMapping(ExifTag::ISO_SPEED_LATITUDE_YYY, $x, 'ISOSpeedLatitudeyyy', ExifXmpValueType::Integer),
            new ExifXmpMapping(ExifTag::ISO_SPEED_LATITUDE_ZZZ, $x, 'ISOSpeedLatitudezzz', ExifXmpValueType::Integer),
            new ExifXmpMapping(ExifTag::SHUTTER_SPEED_VALUE, $e, 'ShutterSpeedValue', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::APERTURE_VALUE, $e, 'ApertureValue', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::BRIGHTNESS_VALUE, $e, 'BrightnessValue', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::EXPOSURE_BIAS_VALUE, $e, 'ExposureBiasValue', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::MAX_APERTURE_VALUE, $e, 'MaxApertureValue', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::SUBJECT_DISTANCE, $e, 'SubjectDistance', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::METERING_MODE, $e, 'MeteringMode', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::LIGHT_SOURCE, $e, 'LightSource', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::FLASH, $e, 'Flash', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::FOCAL_LENGTH, $e, 'FocalLength', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::SUBJECT_AREA, $e, 'SubjectArea', ExifXmpValueType::OrderedArrayOfInteger),
            new ExifXmpMapping(ExifTag::FLASH_ENERGY, $e, 'FlashEnergy', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::FOCAL_PLANE_X_RESOLUTION, $e, 'FocalPlaneXResolution', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::FOCAL_PLANE_Y_RESOLUTION, $e, 'FocalPlaneYResolution', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::FOCAL_PLANE_RESOLUTION_UNIT, $e, 'FocalPlaneResolutionUnit', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::SUBJECT_LOCATION, $e, 'SubjectLocation', ExifXmpValueType::OrderedArrayOfInteger),
            new ExifXmpMapping(ExifTag::EXPOSURE_INDEX, $e, 'ExposureIndex', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::SENSING_METHOD, $e, 'SensingMethod', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::FILE_SOURCE, $e, 'FileSource', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::SCENE_TYPE, $e, 'SceneType', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::CFA_PATTERN, $e, 'CFAPattern', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::CUSTOM_RENDERED, $e, 'CustomRendered', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::EXPOSURE_MODE, $e, 'ExposureMode', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::WHITE_BALANCE, $e, 'WhiteBalance', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::DIGITAL_ZOOM_RATIO, $e, 'DigitalZoomRatio', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::FOCAL_LENGTH_IN_35MM_FILM, $e, 'FocalLengthIn35mmFilm', ExifXmpValueType::Integer),
            new ExifXmpMapping(ExifTag::SCENE_CAPTURE_TYPE, $e, 'SceneCaptureType', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::GAIN_CONTROL, $e, 'GainControl', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::CONTRAST, $e, 'Contrast', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::SATURATION, $e, 'Saturation', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::SHARPNESS, $e, 'Sharpness', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::SUBJECT_DISTANCE_RANGE, $e, 'SubjectDistanceRange', ExifXmpValueType::ClosedChoiceOfInteger),

            // Table 14: Other Exif Properties
            new ExifXmpMapping(ExifTag::IMAGE_UNIQUE_ID, $e, 'ImageUniqueID', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::CAMERA_OWNER_NAME, $x, 'CameraOwnerName', ExifXmpValueType::ProperName),
            new ExifXmpMapping(ExifTag::BODY_SERIAL_NUMBER, $x, 'BodySerialNumber', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::LENS_SPECIFICATION, $x, 'LensSpecification', ExifXmpValueType::OrderedArrayOfRational),
            new ExifXmpMapping(ExifTag::LENS_MAKE, $x, 'LensMake', ExifXmpValueType::ProperName),
            new ExifXmpMapping(ExifTag::LENS_MODEL, $x, 'LensModel', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::LENS_SERIAL_NUMBER, $x, 'LensSerialNumber', ExifXmpValueType::Text),
        ];
    }

    // ========================================================================
    // Table 15: GPS Information
    // ========================================================================

    /**
     * @return list<ExifXmpMapping>
     */
    private static function gpsMappings(): array
    {
        $e = XmpNamespace::EXIF;

        return [
            new ExifXmpMapping(ExifTag::GPS_VERSION_ID, $e, 'GPSVersionID', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::GPS_LATITUDE, $e, 'GPSLatitude', ExifXmpValueType::GpsCoordinate),
            new ExifXmpMapping(ExifTag::GPS_LONGITUDE, $e, 'GPSLongitude', ExifXmpValueType::GpsCoordinate),
            new ExifXmpMapping(ExifTag::GPS_ALTITUDE_REF, $e, 'GPSAltitudeRef', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::GPS_ALTITUDE, $e, 'GPSAltitude', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::GPS_TIME_STAMP, $e, 'GPSTimeStamp', ExifXmpValueType::Date),
            new ExifXmpMapping(ExifTag::GPS_SATELLITES, $e, 'GPSSatellites', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::GPS_STATUS, $e, 'GPSStatus', ExifXmpValueType::ClosedChoiceOfText),
            new ExifXmpMapping(ExifTag::GPS_MEASURE_MODE, $e, 'GPSMeasureMode', ExifXmpValueType::ClosedChoiceOfText),
            new ExifXmpMapping(ExifTag::GPS_DOP, $e, 'GPSDOP', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::GPS_SPEED_REF, $e, 'GPSSpeedRef', ExifXmpValueType::ClosedChoiceOfText),
            new ExifXmpMapping(ExifTag::GPS_SPEED, $e, 'GPSSpeed', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::GPS_TRACK_REF, $e, 'GPSTrackRef', ExifXmpValueType::ClosedChoiceOfText),
            new ExifXmpMapping(ExifTag::GPS_TRACK, $e, 'GPSTrack', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::GPS_IMG_DIRECTION_REF, $e, 'GPSImgDirectionRef', ExifXmpValueType::ClosedChoiceOfText),
            new ExifXmpMapping(ExifTag::GPS_IMG_DIRECTION, $e, 'GPSImgDirection', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::GPS_MAP_DATUM, $e, 'GPSMapDatum', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::GPS_DEST_LATITUDE, $e, 'GPSDestLatitude', ExifXmpValueType::GpsCoordinate),
            new ExifXmpMapping(ExifTag::GPS_DEST_LONGITUDE, $e, 'GPSDestLongitude', ExifXmpValueType::GpsCoordinate),
            new ExifXmpMapping(ExifTag::GPS_DEST_BEARING_REF, $e, 'GPSDestBearingRef', ExifXmpValueType::ClosedChoiceOfText),
            new ExifXmpMapping(ExifTag::GPS_DEST_BEARING, $e, 'GPSDestBearing', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::GPS_DEST_DISTANCE_REF, $e, 'GPSDestDistanceRef', ExifXmpValueType::ClosedChoiceOfText),
            new ExifXmpMapping(ExifTag::GPS_DEST_DISTANCE, $e, 'GPSDestDistance', ExifXmpValueType::Rational),
            new ExifXmpMapping(ExifTag::GPS_PROCESSING_METHOD, $e, 'GPSProcessingMethod', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::GPS_AREA_INFORMATION, $e, 'GPSAreaInformation', ExifXmpValueType::Text),
            new ExifXmpMapping(ExifTag::GPS_DATE_STAMP, $e, 'GPSDateStamp', ExifXmpValueType::Date),
            new ExifXmpMapping(ExifTag::GPS_DIFFERENTIAL, $e, 'GPSDifferential', ExifXmpValueType::ClosedChoiceOfInteger),
            new ExifXmpMapping(ExifTag::GPS_H_POSITIONING_ERROR, $e, 'GPSHPositioningError', ExifXmpValueType::Rational),
        ];
    }

    // ========================================================================
    // Table 16: Interoperability
    // ========================================================================

    /**
     * @return list<ExifXmpMapping>
     */
    private static function interopMappings(): array
    {
        return [
            new ExifXmpMapping(ExifTag::INTEROPERABILITY_INDEX, XmpNamespace::EXIFEX, 'InteroperabilityIndex', ExifXmpValueType::ClosedChoiceOfText),
        ];
    }
}
