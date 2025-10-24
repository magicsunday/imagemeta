<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\SceneType;

use function array_key_exists;
use function array_map;
use function iconv;
use function is_float;
use function is_int;
use function is_string;
use function ord;
use function preg_replace;
use function round;
use function rtrim;
use function str_pad;
use function str_replace;
use function strlen;
use function substr;
use function trim;

/**
 * Represents a parsed EXIF payload and exposes convenience accessors.
 */
final readonly class ExifDocument
{
    /**
     * @param Ifd                     $ifd0       Root IFD of the TIFF structure.
     * @param Ifd|null                $exifIfd    Sub IFD containing EXIF-specific tags.
     * @param Ifd|null                $gpsIfd     Sub IFD containing GPS-related tags.
     * @param Ifd|null                $interopIfd Sub IFD containing interoperability tags.
     * @param Ifd|null                $ifd1       Optional next IFD, typically thumbnails.
     * @param MakerNotesMetadata|null $makerNotes Decoded maker note metadata provided by vendor decoders.
     */
    public function __construct(
        public Ifd $ifd0,
        public ?Ifd $exifIfd,
        public ?Ifd $gpsIfd,
        public ?Ifd $interopIfd,
        public ?Ifd $ifd1,
        public ?MakerNotesMetadata $makerNotes = null,
    ) {
    }

    /**
     * Returns the decoded maker note metadata when a decoder is available.
     */
    public function makerNotes(): ?MakerNotesMetadata
    {
        return $this->makerNotes;
    }

    /**
     * Returns the camera manufacturer string if present.
     *
     * @return string|null
     */
    public function cameraMake(): ?string
    {
        return $this->str($this->ifd0, ExifTag::MAKE);
    }

    /**
     * Returns the camera model string if present.
     *
     * @return string|null
     */
    public function cameraModel(): ?string
    {
        return $this->str($this->ifd0, ExifTag::MODEL);
    }

    /**
     * Returns the lens model string if present.
     *
     * @return string|null
     */
    public function lensModel(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::LENS_MODEL);
    }

    /**
     * Returns the lens manufacturer string if present.
     *
     * @return string|null
     */
    public function lensMake(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::LENS_MAKE);
    }

    /**
     * Returns the camera owner name if present.
     *
     * @return string|null
     */
    public function ownerName(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::CAMERA_OWNER_NAME);
    }

    /**
     * Returns the camera body serial number if present.
     *
     * @return string|null
     */
    public function bodySerialNumber(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::BODY_SERIAL_NUMBER);
    }

    /**
     * Returns the lens serial number if present.
     *
     * @return string|null
     */
    public function lensSerialNumber(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::LENS_SERIAL_NUMBER);
    }

    /**
     * Returns the EXIF orientation value if present.
     *
     * @return int|null
     */
    public function orientation(): ?int
    {
        return $this->int($this->ifd0, ExifTag::ORIENTATION);
    }

    /**
     * Returns the image width, preferring the EXIF-specific tag and falling back to IFD0.
     *
     * @return int|null
     */
    public function imageWidth(): ?int
    {
        $width = $this->int($this->exifIfd, ExifTag::PIXEL_X_DIMENSION);

        return $width ?? $this->int($this->ifd0, ExifTag::IMAGE_WIDTH);
    }

    /**
     * Returns the image height, preferring the EXIF-specific tag and falling back to IFD0.
     *
     * @return int|null
     */
    public function imageHeight(): ?int
    {
        $height = $this->int($this->exifIfd, ExifTag::PIXEL_Y_DIMENSION);

        return $height ?? $this->int($this->ifd0, ExifTag::IMAGE_HEIGHT);
    }

    /**
     * Returns the colour space identifier if present.
     *
     * @return int|null
     */
    public function colorSpace(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::COLOR_SPACE);
    }

    /**
     * Returns the image unique identifier if present.
     *
     * @return string|null
     */
    public function imageUniqueId(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::IMAGE_UNIQUE_ID);
    }

    /**
     * Returns the optional image title string.
     */
    public function imageTitle(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::IMAGE_TITLE);

        if ($value !== null) {
            return $value;
        }

        $value = $this->str($this->ifd0, ExifTag::IMAGE_TITLE_LEGACY);

        if ($value !== null) {
            return $value;
        }

        return $this->str($this->ifd0, ExifTag::IMAGE_DESCRIPTION);
    }

    /**
     * Returns the photographer name if present.
     */
    public function photographer(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::PHOTOGRAPHER);

        if ($value !== null) {
            return $value;
        }

        $value = $this->str($this->ifd0, ExifTag::PHOTOGRAPHER_LEGACY);

        if ($value !== null) {
            return $value;
        }

        return $this->str($this->ifd0, ExifTag::ARTIST);
    }

    /**
     * Returns the image editor attribution if present.
     */
    public function imageEditor(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::IMAGE_EDITOR);

        return $value ?? $this->str($this->ifd0, ExifTag::IMAGE_EDITOR_LEGACY);
    }

    /**
     * Returns the strip offsets defined for the primary or thumbnail image data.
     *
     * @return list<int>|null
     */
    public function stripOffsets(): ?array
    {
        $offsets = $this->numericList($this->ifd0, ExifTag::STRIP_OFFSETS);

        return $offsets ?? $this->numericList($this->ifd1, ExifTag::STRIP_OFFSETS);
    }

    /**
     * Returns the strip byte counts for the primary or thumbnail image data.
     *
     * @return list<int>|null
     */
    public function stripByteCounts(): ?array
    {
        $counts = $this->numericList($this->ifd0, ExifTag::STRIP_BYTE_COUNTS);

        return $counts ?? $this->numericList($this->ifd1, ExifTag::STRIP_BYTE_COUNTS);
    }

    /**
     * Returns the transfer function lookup table when available.
     *
     * @return list<int>|null
     */
    public function transferFunction(): ?array
    {
        return $this->numericList($this->ifd0, ExifTag::TRANSFER_FUNCTION);
    }

    /**
     * Returns the JPEG thumbnail offset, preferring the dedicated thumbnail IFD when present.
     */
    public function jpegThumbnailOffset(): ?int
    {
        $offset = $this->int($this->ifd1, ExifTag::JPEG_INTERCHANGE_FORMAT);

        return $offset ?? $this->int($this->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT);
    }

    /**
     * Returns the JPEG thumbnail byte length, preferring the dedicated thumbnail IFD when present.
     */
    public function jpegThumbnailLength(): ?int
    {
        $length = $this->int($this->ifd1, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);

        return $length ?? $this->int($this->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);
    }

    /**
     * Returns the reference black and white point values as floating point numbers.
     *
     * @return list<float>|null
     */
    public function referenceBlackWhite(): ?array
    {
        return $this->rationalList($this->ifd0, ExifTag::REFERENCE_BLACK_WHITE);
    }

    /**
     * Returns the copyright notice string when present.
     */
    public function copyright(): ?string
    {
        return $this->str($this->ifd0, ExifTag::COPYRIGHT);
    }

    /**
     * Returns the sequential image number when provided by the camera.
     */
    public function imageNumber(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::IMAGE_NUMBER);
    }

    /**
     * Returns the security classification label recorded in the metadata.
     */
    public function securityClassification(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::SECURITY_CLASSIFICATION);
    }

    /**
     * Returns the free-form image history string when present.
     */
    public function imageHistory(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::IMAGE_HISTORY);
    }

    /**
     * Returns the components configuration array when present.
     *
     * @return list<int>|null
     */
    public function componentsConfiguration(): ?array
    {
        return $this->numericList($this->exifIfd, ExifTag::COMPONENTS_CONFIGURATION);
    }

    /**
     * Returns the component configuration labels in human readable form.
     *
     * @return list<string>|null
     */
    public function componentsConfigurationLabels(): ?array
    {
        $components = $this->componentsConfiguration();

        return $components !== null ? ValueConverters::componentsConfigurationLabels($components) : null;
    }

    /**
     * Returns the component configuration as a formatted string.
     */
    public function componentsConfigurationDescription(): ?string
    {
        $components = $this->componentsConfiguration();

        return $components !== null ? ValueConverters::componentsConfigurationDescription($components) : null;
    }

    /**
     * Returns the TIFF/EP standard identifier as a list of bytes.
     *
     * @return list<int>|null
     */
    public function tiffEpStandardId(): ?array
    {
        $values = $this->numericList($this->exifIfd, ExifTag::TIFF_EP_STANDARD_ID);

        return $values;
    }

    /**
     * Returns the compressed bits per pixel ratio.
     */
    public function compressedBitsPerPixel(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::COMPRESSED_BITS_PER_PIXEL);
    }

    /**
     * Returns the user comment string after decoding the EXIF prefix.
     */
    public function userComment(): ?string
    {
        $raw = $this->rawString($this->exifIfd, ExifTag::USER_COMMENT);

        return $raw !== null ? $this->decodeUserComment($raw) : null;
    }

    /**
     * Returns the interlace flag when recorded.
     */
    public function interlace(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::INTERLACE);
    }

    /**
     * Returns the spectral sensitivity description.
     */
    public function spectralSensitivity(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::SPECTRAL_SENSITIVITY);
    }

    /**
     * Returns the opto-electronic conversion function data as a string.
     */
    public function oecf(): ?string
    {
        return $this->binaryString($this->exifIfd, ExifTag::OECF);
    }

    /**
     * Returns the ISO sensitivity value if present.
     *
     * @return int|null
     */
    public function iso(): ?int
    {
        $iso = $this->int($this->exifIfd, ExifTag::ISO_SPEED);
        if ($iso !== null) {
            return $iso;
        }

        $iso = $this->int($this->exifIfd, ExifTag::STANDARD_OUTPUT_SENSITIVITY);
        if ($iso !== null) {
            return $iso;
        }

        $iso = $this->int($this->exifIfd, ExifTag::RECOMMENDED_EXPOSURE_INDEX);
        if ($iso !== null) {
            return $iso;
        }

        $iso = $this->int($this->exifIfd, ExifTag::PHOTOGRAPHIC_SENSITIVITY);
        if ($iso !== null) {
            return $iso;
        }

        return $this->int($this->ifd0, ExifTag::PHOTOGRAPHIC_SENSITIVITY);
    }

    /**
     * Returns the ISO latitude yyy value when present.
     */
    public function isoSpeedLatitudeYyy(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::ISO_SPEED_LATITUDE_YYY);
    }

    /**
     * Returns the ISO latitude zzz value when present.
     */
    public function isoSpeedLatitudeZzz(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::ISO_SPEED_LATITUDE_ZZZ);
    }

    /**
     * Returns the exposure time in seconds if available.
     *
     * @return float|null
     */
    public function exposureTime(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::EXPOSURE_TIME);
    }

    /**
     * Returns the APEX shutter speed value when available.
     */
    public function shutterSpeedValue(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);
    }

    /**
     * Returns the shutter speed in seconds derived from the APEX value.
     */
    public function shutterSpeedSeconds(): ?float
    {
        $raw = $this->value($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);

        if (
            $raw === null
            || (
                !is_int($raw)
                && !is_float($raw)
                && !is_string($raw)
                && !$raw instanceof ExifRational
                && !$raw instanceof ExifRationalList
                && !$raw instanceof ExifNumericList
            )
        ) {
            return null;
        }

        return ValueConverters::apexShutterSpeedToSeconds($raw);
    }

    /**
     * Returns the aperture (f-number) if available.
     *
     * @return float|null
     */
    public function fNumber(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::F_NUMBER);
    }

    /**
     * Returns the APEX aperture value when present.
     */
    public function apertureValue(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::APERTURE_VALUE);
    }

    /**
     * Returns the focal length in millimetres if available.
     *
     * @return float|null
     */
    public function focalLengthMm(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FOCAL_LENGTH);
    }

    /**
     * Returns the focal length in 35mm equivalent if available.
     *
     * @return int|null
     */
    public function focalLength35Mm(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::FOCAL_LENGTH_IN_35MM_FILM);
    }

    /**
     * Returns the camera exposure program code if present.
     *
     * @return int|null
     */
    public function exposureProgram(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::EXPOSURE_PROGRAM);
    }

    /**
     * Returns the metering mode code if present.
     *
     * @return int|null
     */
    public function meteringMode(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::METERING_MODE);
    }

    /**
     * Returns the flash status flags if present.
     *
     * @return int|null
     */
    public function flash(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::FLASH);
    }

    /**
     * Returns the white balance mode if present.
     *
     * @return int|null
     */
    public function whiteBalance(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::WHITE_BALANCE);
    }

    /**
     * Returns the exposure bias value in EV if present.
     *
     * @return float|null
     */
    public function exposureBias(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::EXPOSURE_BIAS_VALUE);
    }

    /**
     * Returns the scene brightness value (APEX) if present.
     *
     * @return float|null
     */
    public function brightnessValue(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::BRIGHTNESS_VALUE);
    }

    /**
     * Returns the maximum aperture value (APEX) if present.
     *
     * @return float|null
     */
    public function maxApertureApex(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::MAX_APERTURE_VALUE);
    }

    /**
     * Returns the flash energy when provided.
     */
    public function flashEnergy(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FLASH_ENERGY);
    }

    /**
     * Returns the focal plane X resolution.
     */
    public function focalPlaneXResolution(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FOCAL_PLANE_X_RESOLUTION);
    }

    /**
     * Returns the focal plane Y resolution.
     */
    public function focalPlaneYResolution(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FOCAL_PLANE_Y_RESOLUTION);
    }

    /**
     * Returns the focal plane resolution unit.
     */
    public function focalPlaneResolutionUnit(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::FOCAL_PLANE_RESOLUTION_UNIT);
    }

    /**
     * Returns the subject location coordinates when supplied.
     *
     * @return list<int>|null
     */
    public function subjectLocation(): ?array
    {
        return $this->numericList($this->exifIfd, ExifTag::SUBJECT_LOCATION);
    }

    /**
     * Returns the exposure index value.
     */
    public function exposureIndex(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::EXPOSURE_INDEX);
    }

    /**
     * Returns the related sound file reference.
     */
    public function relatedSoundFile(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::RELATED_SOUND_FILE);
    }

    /**
     * Returns the spatial frequency response payload.
     */
    public function spatialFrequencyResponse(): ?string
    {
        return $this->binaryString($this->exifIfd, ExifTag::SPATIAL_FREQUENCY_RESPONSE);
    }

    /**
     * Returns the CFA pattern definition as a list of component identifiers.
     *
     * @return list<int>|null
     */
    public function cfaPattern(): ?array
    {
        return $this->numericList($this->exifIfd, ExifTag::CFA_PATTERN);
    }

    /**
     * Returns the CFA pattern as colour enums when possible.
     *
     * @return list<CfaPatternColor>|null
     */
    public function cfaPatternColors(): ?array
    {
        $pattern = $this->cfaPattern();

        return $pattern !== null ? ValueConverters::cfaPatternToColors($pattern) : null;
    }

    /**
     * Returns the scene type classification when present.
     */
    public function sceneType(): ?SceneType
    {
        $value = $this->value($this->exifIfd, ExifTag::SCENE_TYPE);

        if (is_int($value)) {
            return SceneType::fromExifValue($value);
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            return is_int($first) ? SceneType::fromExifValue($first) : null;
        }

        if (is_string($value) && $value !== '') {
            return SceneType::fromExifValue(ord($value[0]));
        }

        return null;
    }

    /**
     * Returns whether a custom rendering process was applied.
     */
    public function customRendered(): ?CustomRendered
    {
        $value = $this->int($this->exifIfd, ExifTag::CUSTOM_RENDERED);

        return CustomRendered::fromExifValue($value);
    }

    /**
     * Returns the device setting description payload.
     */
    public function deviceSettingDescription(): ?string
    {
        return $this->binaryString($this->exifIfd, ExifTag::DEVICE_SETTING_DESCRIPTION);
    }

    /**
     * Returns the self timer mode in seconds when available.
     */
    public function selfTimerModeSeconds(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::SELF_TIMER_MODE);
    }

    /**
     * Returns the recorded temperature in Celsius.
     */
    public function temperatureCelsius(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::TEMPERATURE);
    }

    /**
     * Returns the relative humidity in percent.
     */
    public function humidityPercent(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::HUMIDITY);
    }

    /**
     * Returns the ambient pressure in hPa.
     */
    public function pressureHPa(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::PRESSURE);
    }

    /**
     * Returns the recorded water depth in metres.
     */
    public function waterDepthMeters(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::WATER_DEPTH);
    }

    /**
     * Returns the camera acceleration in metres per second squared.
     */
    public function accelerationMs2(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::ACCELERATION);
    }

    /**
     * Returns the camera elevation angle in degrees.
     */
    public function cameraElevationAngleDeg(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::CAMERA_ELEVATION_ANGLE);
    }

    /**
     * Returns the camera firmware string when present.
     */
    public function cameraFirmware(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::CAMERA_FIRMWARE);

        return $value ?? $this->str($this->exifIfd, ExifTag::CAMERA_FIRMWARE_LEGACY);
    }

    /**
     * Returns the camera firmware version string when legacy tags are provided.
     *
     * EXIF 3.0 no longer defines a dedicated firmware version identifier, so
     * this method only returns data from the legacy tags preserved for
     * compatibility.
     */
    public function cameraFirmwareVersion(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::CAMERA_FIRMWARE_VERSION_LEGACY);
    }

    /**
     * Returns the raw developing software string.
     */
    public function rawDevelopingSoftware(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::RAW_DEVELOPING_SOFTWARE);

        return $value ?? $this->str($this->exifIfd, ExifTag::RAW_DEVELOPING_SOFTWARE_LEGACY);
    }

    /**
     * Returns the raw developing software version string when legacy tags are provided.
     *
     * EXIF 3.0 reassigned the identifier to CAMERA_FIRMWARE, so only legacy
     * metadata produces a value.
     */
    public function rawDevelopingSoftwareVersion(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY);
    }

    /**
     * Returns the image editing software string.
     */
    public function imageEditingSoftware(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::IMAGE_EDITING_SOFTWARE);

        return $value ?? $this->str($this->exifIfd, ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY);
    }

    /**
     * Returns the metadata editing software string.
     */
    public function metadataEditingSoftware(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::METADATA_EDITING_SOFTWARE);

        return $value ?? $this->str($this->exifIfd, ExifTag::METADATA_EDITING_SOFTWARE_LEGACY);
    }

    /**
     * Returns the metadata editing software version string when legacy tags are provided.
     *
     * EXIF 3.0 reassigned the identifier to METADATA_EDITING_SOFTWARE, so only
     * legacy metadata produces a value.
     */
    public function metadataEditingSoftwareVersion(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::METADATA_EDITING_SOFTWARE_VERSION_LEGACY);
    }

    /**
     * Returns the raw DateTimeOriginal tag value.
     *
     * @return string|null
     */
    public function dateTimeOriginalRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::DATETIME_ORIGINAL);
    }

    /**
     * Returns the fractional seconds associated with DateTimeOriginal.
     */
    public function subSecTimeOriginal(): ?string
    {
        return $this->sanitizedSubSec($this->exifIfd, ExifTag::SUB_SEC_TIME_ORIGINAL);
    }

    /**
     * Returns the raw DateTimeDigitized tag value.
     *
     * @return string|null
     */
    public function dateTimeDigitizedRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::DATETIME_DIGITIZED);
    }

    /**
     * Returns the fractional seconds for DateTimeDigitized.
     */
    public function subSecTimeDigitized(): ?string
    {
        return $this->sanitizedSubSec($this->exifIfd, ExifTag::SUB_SEC_TIME_DIGITIZED);
    }

    /**
     * Returns the raw ModifyDate (legacy DateTime) tag value from IFD0.
     *
     * @return string|null
     */
    public function dateTimeRaw(): ?string
    {
        return $this->str($this->ifd0, ExifTag::DATETIME);
    }

    /**
     * Returns the fractional seconds for the ModifyDate/DateTime tag.
     */
    public function subSecTime(): ?string
    {
        return $this->sanitizedSubSec($this->exifIfd, ExifTag::SUB_SEC_TIME);
    }

    /**
     * Returns the EXIF time zone offset values expressed in minutes from UTC.
     *
     * @return list<int>|null
     */
    public function timeZoneOffsetMinutes(): ?array
    {
        $values = $this->numericList($this->exifIfd, ExifTag::TIME_ZONE_OFFSET);

        if ($values === null || $values === []) {
            return null;
        }

        $minutes = [];

        foreach ($values as $value) {
            $converted = ValueConverters::offsetToMinutes($value);

            if ($converted === null) {
                return null;
            }

            $minutes[] = $converted;
        }

        return $minutes;
    }

    /**
     * Returns the normalized offset time for DateTimeOriginal.
     */
    public function offsetTimeOriginal(): ?string
    {
        return $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME_ORIGINAL);
    }

    /**
     * Returns the normalized offset time for DateTimeDigitized.
     */
    public function offsetTimeDigitized(): ?string
    {
        return $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME_DIGITIZED);
    }

    /**
     * Returns the normalized offset time for the IFD0 ModifyDate/DateTime tag.
     */
    public function offsetTime(): ?string
    {
        return $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME);
    }

    /**
     * Returns the raw offset time for DateTimeOriginal.
     *
     * @return string|null
     */
    public function offsetTimeOriginalRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::OFFSET_TIME_ORIGINAL);
    }

    /**
     * Returns the raw offset time for DateTimeDigitized.
     *
     * @return string|null
     */
    public function offsetTimeDigitizedRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::OFFSET_TIME_DIGITIZED);
    }

    /**
     * Returns the raw offset time for the ModifyDate/DateTime tag.
     *
     * @return string|null
     */
    public function offsetTimeRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::OFFSET_TIME);
    }

    /**
     * Returns the parsed GPS metadata extracted from the GPS IFD.
     *
     * @return array{
     *     lat_ref:?string,
     *     lat:?float,
     *     lon_ref:?string,
     *     lon:?float,
     *     alt_ref:?int,
     *     alt:?float,
     *     version:?string,
     *     satellites:?string,
     *     status:?string,
     *     measure_mode:?string,
     *     dop:?float,
     *     speed_ref:?string,
     *     speed_ms:?float,
     *     track_ref:?string,
     *     track:?float,
     *     img_direction_ref:?string,
     *     img_direction:?float,
     *     map_datum:?string,
     *     dest_lat_ref:?string,
     *     dest_lat:?float,
     *     dest_lon_ref:?string,
     *     dest_lon:?float,
     *     dest_bearing_ref:?string,
     *     dest_bearing:?float,
     *     dest_distance_ref:?string,
     *     dest_distance_m:?float,
     *     processing_method:?string,
     *     area_information:?string,
     *     date:?string,
     *     time:?string,
     *     timestamp:?DateTimeImmutable,
     *     differential:?int,
     *     h_positioning_error:?float
     * }
     */
    public function gps(): array
    {
        if (!$this->gpsIfd instanceof Ifd) {
            return ValueConverters::emptyGpsResult();
        }

        return ValueConverters::gpsFromIfd($this->gpsIfd);
    }

    /**
     * Returns the recorded GPS date stamp in ISO calendar format.
     */
    public function gpsDateStamp(): ?string
    {
        $value = $this->gpsValue('date');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the recorded GPS time stamp in HH:MM:SS(.sss) format.
     */
    public function gpsTimeStampString(): ?string
    {
        $value = $this->gpsValue('time');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the combined GPS timestamp in UTC when both date and time are available.
     */
    public function gpsTimestamp(): ?DateTimeImmutable
    {
        $value = $this->gpsValue('timestamp');

        return $value instanceof DateTimeImmutable ? $value : null;
    }

    /**
     * Returns the GPSSpeedRef value indicating the source units (K, M, N).
     */
    public function gpsSpeedRef(): ?string
    {
        $value = $this->gpsValue('speed_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the GPS speed converted to metres per second.
     */
    public function gpsSpeedMetresPerSecond(): ?float
    {
        $value = $this->gpsValue('speed_ms');

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Returns the GPSTrackRef value (T for true, M for magnetic).
     */
    public function gpsTrackRef(): ?string
    {
        $value = $this->gpsValue('track_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the normalised course over ground in degrees within [0, 360).
     */
    public function gpsTrack(): ?float
    {
        $value = $this->gpsValue('track');

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Returns the GPSImgDirectionRef value.
     */
    public function gpsImgDirectionRef(): ?string
    {
        $value = $this->gpsValue('img_direction_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the normalised image direction in degrees within [0, 360).
     */
    public function gpsImgDirection(): ?float
    {
        $value = $this->gpsValue('img_direction');

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Returns the GPSDestBearingRef value (true or magnetic).
     */
    public function gpsDestinationBearingRef(): ?string
    {
        $value = $this->gpsValue('dest_bearing_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the normalised destination bearing in degrees within [0, 360).
     */
    public function gpsDestinationBearing(): ?float
    {
        $value = $this->gpsValue('dest_bearing');

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Returns the GPSDestDistanceRef value (kilometres, miles or nautical miles).
     */
    public function gpsDestinationDistanceRef(): ?string
    {
        $value = $this->gpsValue('dest_distance_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the destination distance converted to metres.
     */
    public function gpsDestinationDistanceMetres(): ?float
    {
        $value = $this->gpsValue('dest_distance_m');

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Returns the GPS differential correction indicator.
     */
    public function gpsDifferential(): ?int
    {
        $value = $this->gpsValue('differential');

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        return null;
    }

    /**
     * Returns the horizontal positioning error in metres when provided.
     */
    public function gpsHorizontalPositioningError(): ?float
    {
        $value = $this->gpsValue('h_positioning_error');

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Returns a single value from the cached GPS metadata map.
     */
    private function gpsValue(string $key): mixed
    {
        $gps = $this->gps();

        return $gps[$key] ?? null;
    }

    /**
     * Returns a best-effort capture timestamp. Defaults to UTC when no offset tag is provided.
     *
     * @return DateTimeImmutable|null
     */
    public function captureDateTime(): ?DateTimeImmutable
    {
        $offsetOriginal  = $this->offsetTimeOriginal();
        $offsetDigitized = $this->offsetTimeDigitized();
        $offset          = $this->offsetTime();

        $fallbackOriginal  = null;
        $fallbackDigitized = null;
        $fallbackModify    = null;

        if ($offsetOriginal === null && $offsetDigitized === null && $offset === null) {
            $primaryOffset   = $this->derivedOffsetFromTimeZoneOffset(0);
            $secondaryOffset = $this->derivedOffsetFromTimeZoneOffset(1);

            $fallbackOriginal  = $primaryOffset;
            $fallbackDigitized = $secondaryOffset ?? $primaryOffset;
            $fallbackModify    = $primaryOffset;
        }

        $attempts = [
            [$this->dateTimeOriginalRaw(), $offsetOriginal ?? $fallbackOriginal, $this->subSecTimeOriginal()],
            [$this->dateTimeDigitizedRaw(), $offsetDigitized ?? $fallbackDigitized, $this->subSecTimeDigitized()],
            [$this->dateTimeRaw(), $offset ?? $fallbackModify, $this->subSecTime()],
        ];

        foreach ($attempts as [$raw, $rawOffset, $subSeconds]) {
            $dateTime = $this->parseExifDateTime($raw, $rawOffset, $subSeconds);
            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime;
            }
        }

        return null;
    }

    /**
     * Returns the digitised timestamp combining the raw value and offset tags.
     *
     * @return DateTimeImmutable|null
     */
    public function dateTimeDigitized(): ?DateTimeImmutable
    {
        return $this->parseExifDateTime(
            $this->dateTimeDigitizedRaw(),
            $this->offsetTimeDigitized(),
            $this->subSecTimeDigitized(),
        );
    }

    /**
     * Returns the ModifyDate/DateTime tag combined with its optional offset.
     *
     * @return DateTimeImmutable|null
     */
    public function dateTime(): ?DateTimeImmutable
    {
        return $this->parseExifDateTime(
            $this->dateTimeRaw(),
            $this->offsetTime(),
            $this->subSecTime(),
        );
    }

    /**
     * Returns a string value from the given IFD if present.
     *
     * @return string|null
     */
    private function str(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);

        if (!is_string($value)) {
            return null;
        }

        $trimmed = rtrim($value, "\0");

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Returns an integer value from the given IFD if present.
     *
     * @return int|null
     */
    private function int(?Ifd $ifd, int $tag): ?int
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;
            if (is_int($first)) {
                return $first;
            }

            if (is_float($first)) {
                return (int) $first;
            }

            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Returns a rational or numeric value converted to float if present in the given IFD.
     *
     * @return float|null
     */
    private function rational(?Ifd $ifd, int $tag): ?float
    {
        $value = $this->value($ifd, $tag);

        if (
            $value !== null
            && !is_int($value)
            && !is_float($value)
            && !is_string($value)
            && !$value instanceof ExifRational
            && !$value instanceof ExifRationalList
            && !$value instanceof ExifNumericList
        ) {
            return null;
        }

        return ValueConverters::rationalToFloat($value);
    }

    /**
     * Retrieves the raw entry value for the provided tag.
     */
    private function value(?Ifd $ifd, int $tag): mixed
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        $entry = $ifd->get($tag);

        return $entry?->value;
    }

    /**
     * Returns the raw string value without trimming trailing null bytes.
     */
    private function rawString(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);

        return is_string($value) ? $value : null;
    }

    /**
     * Converts a numeric list or undefined string into a list of integers.
     *
     * @return list<int>|null
     */
    private function numericList(?Ifd $ifd, int $tag): ?array
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            return array_map(static fn (int|float $v): int => (int) $v, $value->values);
        }

        if (is_int($value)) {
            return [$value];
        }

        if (is_string($value) && $value !== '') {
            $length = strlen($value);
            $bytes  = [];
            for ($i = 0; $i < $length; ++$i) {
                $bytes[] = ord($value[$i]);
            }

            return $bytes;
        }

        return null;
    }

    /**
     * Converts rational or numeric list values into floating point lists.
     *
     * @return list<float>|null
     */
    private function rationalList(?Ifd $ifd, int $tag): ?array
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifRationalList) {
            $result = [];
            foreach ($value->values as $item) {
                $float = ValueConverters::rationalToFloat($item);
                if ($float === null) {
                    return null;
                }

                $result[] = $float;
            }

            return $result;
        }

        if ($value instanceof ExifRational) {
            $float = ValueConverters::rationalToFloat($value);

            return $float !== null ? [$float] : null;
        }

        if ($value instanceof ExifNumericList) {
            return array_map(static fn (int|float $v): float => (float) $v, $value->values);
        }

        if (is_int($value) || is_float($value)) {
            return [(float) $value];
        }

        return null;
    }

    /**
     * Returns a trimmed binary string value, or null when empty.
     */
    private function binaryString(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->rawString($ifd, $tag);
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value, "\0");

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Decodes EXIF user comment strings with encoding prefixes.
     */
    private function decodeUserComment(string $raw): ?string
    {
        if (strlen($raw) <= 8) {
            return null;
        }

        $prefix   = substr($raw, 0, 8);
        $encoding = strtoupper(trim($prefix, "\0 "));
        $content  = substr($raw, 8);
        $content  = trim($content, "\0");

        return match ($encoding) {
            'ASCII', 'UTF8', '' => $content !== '' ? $content : null,
            'UNICODE' => $this->decodeUnicodeComment($content),
            default   => $content !== '' ? $content : null,
        };
    }

    /**
     * Decodes a UTF-16 encoded user comment.
     */
    private function decodeUnicodeComment(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        $converted = @iconv('UTF-16LE', 'UTF-8', $content);
        if ($converted === false) {
            $converted = @iconv('UTF-16BE', 'UTF-8', $content);
        }

        if ($converted !== false) {
            $converted = trim($converted, "\0");

            return $converted === '' ? null : $converted;
        }

        $stripped = preg_replace('/\x00/u', '', $content);
        if ($stripped === null) {
            return null;
        }

        $stripped = trim($stripped, "\0");

        return $stripped === '' ? null : $stripped;
    }

    /**
     * Derives a canonical offset string from the legacy TimeZoneOffset tag when available.
     */
    private function derivedOffsetFromTimeZoneOffset(int $component = 0): ?string
    {
        $values = $this->numericList($this->exifIfd, ExifTag::TIME_ZONE_OFFSET);

        if ($values === null || !array_key_exists($component, $values)) {
            return null;
        }

        return ValueConverters::parseOffsetString($values[$component]);
    }

    /**
     * Normalises textual and numeric offset encodings to a canonical string representation.
     */
    private function normalizedOffset(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            $value = $value->values[0] ?? null;
        } elseif ($value instanceof ExifRationalList || $value instanceof ExifRational) {
            $value = ValueConverters::rationalToFloat($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            $value = $trimmed;
        }

        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        return ValueConverters::parseOffsetString($value);
    }

    /**
     * Returns sanitized sub-second components limited to microsecond precision.
     */
    private function sanitizedSubSec(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);

        if (is_string($value)) {
            $digits = preg_replace('/[^0-9]/', '', $value);

            return ($digits !== null && $digits !== '') ? $digits : null;
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            return $first !== null ? (string) (int) $first : null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Normalises EXIF timestamp strings and optional offsets into immutable datetime instances.
     *
     * @param string|null $rawDateTime Raw EXIF datetime formatted as "YYYY:MM:DD HH:MM:SS".
     * @param string|null $rawOffset   Optional timezone offset such as "+01:00".
     */
    private function parseExifDateTime(?string $rawDateTime, ?string $rawOffset, ?string $subSeconds): ?DateTimeImmutable
    {
        if ($rawDateTime === null || $rawDateTime === '' || strlen($rawDateTime) < 19) {
            return null;
        }

        try {
            $tz = ($rawOffset !== null && $rawOffset !== '')
                ? new DateTimeZone($rawOffset)
                : new DateTimeZone('UTC');
        } catch (Exception) {
            return null;
        }

        $normalized = str_replace(':', '-', substr($rawDateTime, 0, 10)) . substr($rawDateTime, 10);
        $format     = 'Y-m-d H:i:s';

        if ($subSeconds !== null && $subSeconds !== '') {
            $digits = preg_replace('/[^0-9]/', '', $subSeconds);
            if ($digits !== null && $digits !== '') {
                $digits = substr($digits, 0, 6);
                $digits = str_pad($digits, 6, '0');
                $normalized .= '.' . $digits;
                $format .= '.u';
            }
        }

        $dt = DateTimeImmutable::createFromFormat($format, $normalized, $tz);

        return $dt !== false ? $dt : null;
    }
}
