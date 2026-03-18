<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Exif\ExifConst;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\DeviceSettingDescription;

use function array_any;
use function array_map;
use function in_array;
use function is_int;
use function sqrt;
use function strlen;
use function substr;
use function unpack;

/**
 * Reads environmental sensor data and device settings from EXIF metadata.
 *
 * EXIF 3.0 §4.6.6.8 defines the shooting-situation tags decoded by this reader.
 */
final readonly class DeviceExifReader
{
    /**
     * EXIF Acceleration is specified in mGal (10^-5 m/s²).
     */
    private const float ACCELERATION_MGAL_TO_MS2      = 1.0e-5;

    /**
     * EXIF tags that define unknown rational denominators in EXIF 3.0 §4.6.6.8.
     *
     * @var list<int>
     */
    private const array EXIF_UNKNOWN_DENOMINATOR_TAGS = [
        ExifTag::TEMPERATURE,
        ExifTag::HUMIDITY,
        ExifTag::PRESSURE,
        ExifTag::WATER_DEPTH,
        ExifTag::ACCELERATION,
        ExifTag::CAMERA_ELEVATION_ANGLE,
    ];

    /**
     * @param IfdValueReader  $reader     Value reader for IFD tag extraction.
     * @param ValueConverters $converters Value converter facade.
     * @param Ifd|null        $gpsIfd     Sub IFD containing GPS-related tags.
     * @param Ifd|null        $exifIfd    Sub IFD containing EXIF-specific tags.
     * @param Endian          $byteOrder  TIFF byte order.
     */
    public function __construct(
        private IfdValueReader $reader,
        private ValueConverters $converters,
        private ?Ifd $gpsIfd,
        private ?Ifd $exifIfd,
        private Endian $byteOrder,
    ) {
    }

    public function deviceSettingDescription(): ?DeviceSettingDescription
    {
        return $this->parseDeviceSettingDescription();
    }

    /**
     * Returns the recorded temperature in Celsius.
     *
     * EXIF 3.0 §4.6.6.8.2 (Temperature, 0x9400) stores an SRATIONAL in °C with
     * a denominator of 0xFFFFFFFF indicating an unknown value.
     */
    public function temperatureCelsius(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::TEMPERATURE);
    }

    /**
     * Returns the relative humidity in percent.
     *
     * EXIF 3.0 §4.6.6.8.3 (Humidity, 0x9401) stores a RATIONAL in % with
     * denominator 0xFFFFFFFF meaning the humidity is unknown.
     */
    public function humidityPercent(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::HUMIDITY);
    }

    /**
     * Returns the ambient pressure in hPa.
     *
     * EXIF 3.0 §4.6.6.8.4 (Pressure, 0x9402) stores a RATIONAL in hPa and
     * uses 0xFFFFFFFF as denominator to express unknown values.
     */
    public function pressureHPa(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::PRESSURE);
    }

    /**
     * Returns the recorded water depth in metres.
     *
     * EXIF 3.0 §4.6.6.8.5 WaterDepth (0x9403) records the depth of the camera below the
     * water surface, stored as SRATIONAL in metres with 0xFFFFFFFF indicating unknown.
     *
     * @return float|null Water depth in metres, or null if not present.
     */
    public function waterDepthMeters(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::WATER_DEPTH);
    }

    /**
     * Returns the camera acceleration vector in metres per second squared.
     *
     * EXIF 3.0 §4.6.6.8.6 Acceleration (0x9404) records the 3D acceleration vector as an
     * SRATIONAL triplet (X, Y, Z components) in mGal (10^-5 m/s²). A denominator of
     * 0xFFFFFFFF marks an unknown component.
     *
     * @return array{0:float,1:float,2:float}|null Three-component acceleration vector, or null if not present.
     */
    public function accelerationVector(): ?array
    {
        $value  = $this->valueFromGpsOrExif(ExifTag::ACCELERATION);

        if (!$value instanceof ExifRationalList) {
            return null;
        }

        if ($this->containsExifUnknownDenominator($value)) {
            return null;
        }

        $vector = $this->converters->srationalTripletToFloatVector($value);

        if ($vector === null) {
            return null;
        }

        return array_map(
            static fn (float $component): float => $component * self::ACCELERATION_MGAL_TO_MS2,
            $vector,
        );
    }

    /**
     * Returns the camera acceleration in metres per second squared.
     *
     * EXIF 3.0 §4.6.6.8.6 Acceleration (0x9404) as scalar magnitude. Computes the
     * Euclidean norm of the acceleration vector: sqrt(x² + y² + z²). Components with a
     * denominator of 0xFFFFFFFF are treated as unknown and produce null.
     *
     * @return float|null Acceleration magnitude in m/s², or null if not present.
     */
    public function accelerationMs2(): ?float
    {
        $value  = $this->valueFromGpsOrExif(ExifTag::ACCELERATION);

        if ($this->containsExifUnknownDenominator($value)) {
            return null;
        }

        if ($value instanceof ExifRationalList) {
            $vector = $this->converters->srationalTripletToFloatVector($value);

            if ($vector === null) {
                return null;
            }

            $scaled = array_map(
                static fn (float $component): float => $component * self::ACCELERATION_MGAL_TO_MS2,
                $vector,
            );

            return sqrt(($scaled[0] ** 2) + ($scaled[1] ** 2) + ($scaled[2] ** 2));
        }

        $scalar = $this->converters->rationalToFloat($value);

        if ($scalar === null) {
            return null;
        }

        return $scalar * self::ACCELERATION_MGAL_TO_MS2;
    }

    /**
     * Returns the camera elevation angle in degrees.
     *
     * EXIF 3.0 §4.6.6.8.7 CameraElevationAngle (0x9405) records the camera's elevation
     * angle relative to the horizon as SRATIONAL in degrees, using denominator 0xFFFFFFFF
     * to denote unknown.
     * Positive values indicate upward tilt, negative values indicate downward tilt.
     *
     * @return float|null Elevation angle in degrees, or null if not present.
     */
    public function cameraElevationAngleDeg(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::CAMERA_ELEVATION_ANGLE);
    }

    /**
     * Returns the camera firmware string when present.
     *
     * EXIF 3.0 §4.6.6.9.11 captures the camera firmware name/version in ASCII or UTF-8
     * and expects the Software tag to be present alongside it.
     */
    public function cameraFirmware(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::CAMERA_FIRMWARE);
    }

    /**
     * Returns the raw developing software string.
     *
     * EXIF 3.0 §4.6.6.9.12 stores RAWDevelopingSoftware to document the RAW
     * processor and requires Software to be recorded too.
     */
    public function rawDevelopingSoftware(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::RAW_DEVELOPING_SOFTWARE);
    }

    /**
     * Returns the image editing software string.
     *
     * EXIF 3.0 §4.6.6.9.13 lists the primary image editing software and expects
     * the Software tag to accompany it.
     */
    public function imageEditingSoftware(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::IMAGE_EDITING_SOFTWARE);
    }

    /**
     * Returns the metadata editing software string.
     *
     * EXIF 3.0 §4.6.6.9.14 records the tool used to edit metadata without changing
     * pixels and likewise expects Software to be filled.
     */
    public function metadataEditingSoftware(): ?string
    {
        return $this->reader->str($this->exifIfd, ExifTag::METADATA_EDITING_SOFTWARE);
    }

    /**
     * Returns a rational or numeric entry converted to float, preferring GPS data when available.
     */
    private function rationalFromGpsOrExif(int $tag): ?float
    {
        $value = $this->valueFromGpsOrExif($tag);

        if ($value === null) {
            return null;
        }

        if (in_array($tag, self::EXIF_UNKNOWN_DENOMINATOR_TAGS, true) && $this->containsExifUnknownDenominator($value)) {
            return null;
        }

        return $this->converters->rationalToFloat($value);
    }

    /**
     * Retrieves a raw entry value preferring the GPS IFD before falling back to the EXIF IFD.
     */
    private function valueFromGpsOrExif(int $tag): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        $value = $this->reader->normalizedValue($this->gpsIfd, $tag);

        return $value ?? $this->reader->normalizedValue(
            $this->exifIfd,
            $tag
        );
    }

    /**
     * Checks whether a rational value contains the EXIF unknown-denominator sentinel.
     *
     * EXIF 3.0 §4.6.6.8 defines 0xFFFFFFFF (or signed -1) as unknown for selected
     * shooting-situation tags.
     */
    private function containsExifUnknownDenominator(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): bool {
        if ($value instanceof ExifRational) {
            return $this->isExifUnknownDenominator($value->denominator);
        }

        if ($value instanceof ExifRationalList) {
            return array_any(
                $value->values,
                fn (ExifRational $component): bool => $this->isExifUnknownDenominator($component->denominator),
            );
        }

        return false;
    }

    /**
     * Returns whether a denominator encodes the EXIF unknown sentinel.
     */
    private function isExifUnknownDenominator(int $denominator): bool
    {
        if ($denominator === -1) {
            return true;
        }

        return $denominator === ExifConst::EXIF_UNKNOWN_DENOMINATOR;
    }

    private function parseDeviceSettingDescription(): ?DeviceSettingDescription
    {
        $raw           = $this->reader->rawString($this->exifIfd, ExifTag::DEVICE_SETTING_DESCRIPTION);

        if (($raw === null) || (strlen($raw) < 4)) {
            return null;
        }

        // EXIF 3.0 §4.6.6.7.45: columns/rows are TIFF SHORT fields —
        // decode using the EXIF/TIFF byte order context.
        $format        = $this->byteOrder === Endian::Little ? 'v2' : 'n2';
        $unpacked      = unpack($format, substr($raw, 0, 4));

        if ($unpacked === false) {
            return null;
        }

        $columns       = $unpacked[1] ?? null;
        $rows          = $unpacked[2] ?? null;

        if (!is_int($columns) || !is_int($rows)) {
            return null;
        }

        // Extract camera settings (skip the 4-byte header)
        $settingsBytes = substr($raw, 4);
        $settings      = $this->parseDeviceSettingStrings($settingsBytes);

        return new DeviceSettingDescription(
            columns: $columns,
            rows: $rows,
            settings: $settings,
        );
    }

    /**
     * Parses UTF-16 encoded camera settings entries following the display grid dimensions.
     *
     * EXIF 3.0 §4.6.6.7.45: each setting is a UTF-16 string recorded
     * **including Signature** (BOM) and NULL-terminated.  Only BOM-framed
     * segments are accepted; heuristic decoding is not applied.
     *
     * Null terminators are scanned at code-unit-aligned positions (every
     * 2 bytes after the BOM) to avoid false matches inside UTF-16 data.
     *
     * @return list<string>
     */
    private function parseDeviceSettingStrings(string $payload): array
    {
        $length   = strlen($payload);

        // EXIF 3.0 §4.6.6.7.45: UTF-16 encoded strings require even byte
        // length for code-unit alignment.  Odd-length payloads are malformed.
        if ($length < 4 || ($length % 2) !== 0) {
            return [];
        }

        $settings = [];
        $offset   = 0;

        while (($offset + 4) <= $length) {
            $bom       = substr($payload, $offset, 2);

            if (($bom !== "\xFF\xFE") && ($bom !== "\xFE\xFF")) {
                break;
            }

            // Scan for the null code unit at even offsets after the BOM.
            $pos       = $offset + 2;
            $termFound = false;

            while (($pos + 1) < $length) {
                if (($payload[$pos] === "\x00") && ($payload[$pos + 1] === "\x00")) {
                    $termFound = true;

                    break;
                }

                $pos += 2;
            }

            if ($termFound) {
                $segment = substr($payload, $offset, $pos - $offset);
                $offset  = $pos + 2;
            } else {
                $segment = substr($payload, $offset);
                $offset  = $length;
            }

            $decoded   = $this->reader->decodeLegacyUnicodeFromBom($segment);

            if ($decoded !== null) {
                $settings[] = $decoded;
            }
        }

        return $settings;
    }
}
