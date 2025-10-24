<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Core\ValueConverters as CoreValueConverters;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters as ExifValueConverters;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Compression;
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
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use MagicSunday\ImageMeta\Value\FlashInfo;

use function array_map;
use function array_values;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function ord;
use function trim;

/**
 * Provides high level accessors for common EXIF tags without exposing raw identifiers to consumers.
 */
final readonly class ExifTagResolver
{
    public function __construct(private ?ExifDocument $document)
    {
    }

    /**
     * Returns the camera manufacturer when available.
     */
    public function cameraMake(): ?string
    {
        return $this->document?->cameraMake();
    }

    /**
     * Returns the camera model when available.
     */
    public function cameraModel(): ?string
    {
        return $this->document?->cameraModel();
    }

    /**
     * Returns the artist tag value when present.
     */
    public function artist(): ?string
    {
        return $this->stringValue($this->document?->ifd0, ExifTag::ARTIST);
    }

    /**
     * Returns the camera owner name when present.
     */
    public function ownerName(): ?string
    {
        return $this->document?->ownerName();
    }

    /**
     * Returns the body serial number when present.
     */
    public function bodySerialNumber(): ?string
    {
        return $this->document?->bodySerialNumber();
    }

    /**
     * Returns the software tag value.
     */
    public function software(): ?string
    {
        return $this->stringValue($this->document?->ifd0, ExifTag::SOFTWARE);
    }

    /**
     * Returns the dedicated camera firmware tag when available.
     */
    public function cameraFirmware(): ?string
    {
        return $this->document?->cameraFirmware();
    }

    /**
     * Returns the raw developing software string.
     */
    public function rawDevelopingSoftware(): ?string
    {
        return $this->document?->rawDevelopingSoftware();
    }

    /**
     * Returns the image editing software string.
     */
    public function imageEditingSoftware(): ?string
    {
        return $this->document?->imageEditingSoftware();
    }

    /**
     * Returns the metadata editing software string.
     */
    public function metadataEditingSoftware(): ?string
    {
        return $this->document?->metadataEditingSoftware();
    }

    /**
     * Returns the lens model description when available.
     */
    public function lensModel(): ?string
    {
        return $this->document?->lensModel();
    }

    /**
     * Returns the lens manufacturer name.
     */
    public function lensMake(): ?string
    {
        return $this->stringValue($this->document?->exifIfd, ExifTag::LENS_MAKE);
    }

    /**
     * Returns the lens serial number when present.
     */
    public function lensSerialNumber(): ?string
    {
        return $this->document?->lensSerialNumber();
    }

    /**
     * Returns the lens specification array describing focal and aperture range.
     *
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public function lensInfo(): ?array
    {
        $entry = $this->getEntry($this->document?->exifIfd, ExifTag::LENS_INFO);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $values = $this->normalizeRationalList($entry->value);
        if (count($values) !== 4) {
            return null;
        }

        return $values;
    }

    /**
     * Returns the maximum aperture as f-number converted from APEX.
     */
    public function maxApertureFNumber(): ?float
    {
        $apex = $this->document?->maxApertureApex();
        if ($apex === null) {
            return null;
        }

        return CoreValueConverters::apexToFNumber($apex);
    }

    /**
     * Returns the image width using EXIF fallbacks.
     */
    public function imageWidth(): ?int
    {
        return $this->document?->imageWidth();
    }

    /**
     * Returns the image height using EXIF fallbacks.
     */
    public function imageHeight(): ?int
    {
        return $this->document?->imageHeight();
    }

    /**
     * Returns the image orientation as an enum.
     */
    public function orientation(): ?Orientation
    {
        return Orientation::fromExifValue($this->document?->orientation());
    }

    /**
     * Returns bits per sample.
     */
    public function bitsPerSample(): ?int
    {
        $value = $this->numericValue($this->document?->ifd0, ExifTag::BITS_PER_SAMPLE);

        return $value !== null ? (int) $value : null;
    }

    /**
     * Returns the EXIF color space enumeration when available.
     */
    public function colorSpace(): ?ColorSpace
    {
        return ColorSpace::fromExifValue($this->document?->colorSpace());
    }

    /**
     * Returns the image unique identifier if present.
     */
    public function imageUniqueId(): ?string
    {
        return $this->document?->imageUniqueId();
    }

    /**
     * Returns the image title value.
     */
    public function imageTitle(): ?string
    {
        return $this->document?->imageTitle();
    }

    /**
     * Returns the photographer string when available.
     */
    public function photographer(): ?string
    {
        return $this->document?->photographer();
    }

    /**
     * Returns the image editor string when available.
     */
    public function imageEditor(): ?string
    {
        return $this->document?->imageEditor();
    }

    /**
     * Returns the components configuration array.
     *
     * @return list<int>|null
     */
    public function componentsConfiguration(): ?array
    {
        $values = $this->document?->componentsConfiguration();

        return $values !== null ? array_values($values) : null;
    }

    /**
     * Returns the compressed bits per pixel ratio.
     */
    public function compressedBitsPerPixel(): ?float
    {
        return $this->document?->compressedBitsPerPixel();
    }

    /**
     * Returns the decoded user comment string.
     */
    public function userComment(): ?string
    {
        return $this->document?->userComment();
    }

    /**
     * Returns the spectral sensitivity description.
     */
    public function spectralSensitivity(): ?string
    {
        return $this->document?->spectralSensitivity();
    }

    /**
     * Returns the opto-electronic conversion function payload.
     */
    public function oecf(): ?string
    {
        return $this->document?->oecf();
    }

    /**
     * Returns the spatial frequency response payload.
     */
    public function spatialFrequencyResponse(): ?string
    {
        return $this->document?->spatialFrequencyResponse();
    }

    /**
     * Returns the focal length in millimetres.
     */
    public function focalLength(): ?float
    {
        return $this->document?->focalLengthMm();
    }

    /**
     * Returns the 35mm equivalent focal length.
     */
    public function focalLength35mm(): ?int
    {
        return $this->document?->focalLength35Mm();
    }

    /**
     * Returns the focal plane X resolution.
     */
    public function focalPlaneXResolution(): ?float
    {
        return $this->document?->focalPlaneXResolution();
    }

    /**
     * Returns the focal plane Y resolution.
     */
    public function focalPlaneYResolution(): ?float
    {
        return $this->document?->focalPlaneYResolution();
    }

    /**
     * Returns the focal plane resolution unit.
     */
    public function focalPlaneResolutionUnit(): ?ResolutionUnit
    {
        $value = $this->document?->focalPlaneResolutionUnit();

        return $value !== null ? ResolutionUnit::fromExifValue($value) : null;
    }

    /**
     * Returns the CFA pattern description.
     *
     * @return list<int>|null
     */
    public function cfaPattern(): ?array
    {
        $pattern = $this->document?->cfaPattern();

        return $pattern !== null ? array_values($pattern) : null;
    }

    /**
     * Returns the image description field.
     */
    public function imageDescription(): ?string
    {
        return $this->stringValue($this->document?->ifd0, ExifTag::IMAGE_DESCRIPTION);
    }

    /**
     * Returns the related sound file reference.
     */
    public function relatedSoundFile(): ?string
    {
        return $this->document?->relatedSoundFile();
    }

    /**
     * Returns the number of samples per pixel.
     */
    public function samplesPerPixel(): ?int
    {
        $value = $this->numericValue($this->document?->ifd0, ExifTag::SAMPLES_PER_PIXEL);

        return $value !== null ? (int) $value : null;
    }

    /**
     * Returns rows per strip if present.
     */
    public function rowsPerStrip(): ?int
    {
        $value = $this->numericValue($this->document?->ifd0, ExifTag::ROWS_PER_STRIP);

        return $value !== null ? (int) $value : null;
    }

    /**
     * Returns the strip offsets defined in the TIFF IFD.
     *
     * @return list<int>|null
     */
    public function stripOffsets(): ?array
    {
        return $this->integerList($this->document?->ifd0, ExifTag::STRIP_OFFSETS);
    }

    /**
     * Returns the strip byte counts for each TIFF strip.
     *
     * @return list<int>|null
     */
    public function stripByteCounts(): ?array
    {
        return $this->integerList($this->document?->ifd0, ExifTag::STRIP_BYTE_COUNTS);
    }

    /**
     * Returns the transfer function lookup table when present.
     *
     * @return list<int>|null
     */
    public function transferFunction(): ?array
    {
        return $this->integerList($this->document?->ifd0, ExifTag::TRANSFER_FUNCTION);
    }

    /**
     * Returns the compression enum.
     */
    public function compression(): ?Compression
    {
        $value = $this->numericValue($this->document?->ifd0, ExifTag::COMPRESSION);

        return $value !== null ? Compression::fromExifValue($value) : null;
    }

    /**
     * Returns the photometric interpretation enum.
     */
    public function photometric(): ?Photometric
    {
        $value = $this->numericValue($this->document?->ifd0, ExifTag::PHOTOMETRIC_INTERPRETATION);

        return $value !== null ? Photometric::fromExifValue($value) : null;
    }

    /**
     * Returns the planar configuration enum.
     */
    public function planarConfiguration(): ?PlanarConfiguration
    {
        $value = $this->numericValue($this->document?->ifd0, ExifTag::PLANAR_CONFIGURATION);

        return $value !== null ? PlanarConfiguration::fromExifValue($value) : null;
    }

    /**
     * Returns the JPEG interchange format offset when present.
     */
    public function jpegInterchangeFormat(): ?int
    {
        return $this->numericValue($this->document?->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT);
    }

    /**
     * Returns the byte length of the embedded JPEG interchange format stream.
     */
    public function jpegInterchangeFormatLength(): ?int
    {
        return $this->numericValue($this->document?->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);
    }

    /**
     * Returns the resolution unit enum.
     */
    public function resolutionUnit(): ?ResolutionUnit
    {
        $value = $this->numericValue($this->document?->ifd0, ExifTag::RESOLUTION_UNIT);

        return $value !== null ? ResolutionUnit::fromExifValue($value) : null;
    }

    /**
     * Returns the horizontal resolution value.
     */
    public function xResolution(): ?float
    {
        return $this->rationalValue($this->document?->ifd0, ExifTag::X_RESOLUTION);
    }

    /**
     * Returns the vertical resolution value.
     */
    public function yResolution(): ?float
    {
        return $this->rationalValue($this->document?->ifd0, ExifTag::Y_RESOLUTION);
    }

    /**
     * Returns the YCbCr positioning enum.
     */
    public function ycbcrPositioning(): ?YCbCrPositioning
    {
        $value = $this->numericValue($this->document?->ifd0, ExifTag::YCBCR_POSITIONING);

        return $value !== null ? YCbCrPositioning::fromExifValue($value) : null;
    }

    /**
     * Returns the YCbCr subsampling factors.
     *
     * @return array{0:int,1:int}|null
     */
    public function ycbcrSubSampling(): ?array
    {
        $entry = $this->getEntry($this->document?->ifd0, ExifTag::YCBCR_SUB_SAMPLING);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        if (is_string($entry->value)) {
            return CoreValueConverters::ycbcrSubSamplingToPair($entry->value);
        }

        $values = $this->normalizeNumericList($entry->value);
        if (count($values) !== 2) {
            return null;
        }

        return [(int) $values[0], (int) $values[1]];
    }

    /**
     * Returns the YCbCr coefficients array.
     *
     * @return array{0:float,1:float,2:float}|null
     */
    public function ycbcrCoefficients(): ?array
    {
        $values = $this->normalizeRationalList($this->getValue($this->document?->ifd0, ExifTag::YCBCR_COEFFICIENTS));
        if (count($values) !== 3) {
            return null;
        }

        return $values;
    }

    /**
     * Returns the normalized white point coordinates.
     *
     * @return array{0:float,1:float}|null
     */
    public function whitePoint(): ?array
    {
        $value = $this->getValue($this->document?->ifd0, ExifTag::WHITE_POINT);

        return CoreValueConverters::toWhitePoint($value);
    }

    /**
     * Returns the primary chromaticities array.
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public function primaryChromaticities(): ?array
    {
        $value = $this->getValue($this->document?->ifd0, ExifTag::PRIMARY_CHROMATICITIES);

        return CoreValueConverters::toPrimaryChromaticities($value);
    }

    /**
     * Returns the reference black and white point values.
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public function referenceBlackWhite(): ?array
    {
        $values = $this->normalizeRationalList($this->getValue($this->document?->ifd0, ExifTag::REFERENCE_BLACK_WHITE));
        if (count($values) !== 6) {
            return null;
        }

        return $values;
    }

    /**
     * Returns the copyright notice string when present.
     */
    public function copyright(): ?string
    {
        return $this->stringValue($this->document?->ifd0, ExifTag::COPYRIGHT);
    }

    /**
     * Returns the interoperability index string.
     */
    public function interopIndex(): ?string
    {
        return $this->stringValue($this->document?->interopIfd, ExifTag::INTEROPERABILITY_INDEX);
    }

    /**
     * Returns the normalized EXIF version string.
     */
    public function exifVersion(): ?string
    {
        $value = $this->stringValue($this->document?->exifIfd, ExifTag::EXIF_VERSION);

        return CoreValueConverters::toExifVersion($value);
    }

    /**
     * Returns the FlashPix version string if present.
     */
    public function flashpixVersion(): ?string
    {
        $value = $this->stringValue($this->document?->exifIfd, ExifTag::FLASHPIX_VERSION);

        return $value !== null ? trim($value, "\0") : null;
    }

    /**
     * Returns the EXIF sensitivity type enumeration describing the recorded ISO metrics.
     */
    public function sensitivityType(): ?int
    {
        return $this->numericValue($this->document?->exifIfd, ExifTag::SENSITIVITY_TYPE);
    }

    /**
     * Returns the ISO sensitivity with fallbacks defined by EXIF 3.0.
     */
    public function iso(): ?int
    {
        $iso = $this->document?->iso();
        if ($iso !== null) {
            return $iso;
        }

        $sensitivityType = $this->sensitivityType();
        if ($sensitivityType !== null) {
            foreach ($this->sensitivityTagPriority($sensitivityType) as $tag) {
                $value = $this->numericValue($this->document?->exifIfd, $tag);
                if ($value !== null) {
                    return (int) $value;
                }
            }
        }

        foreach ([
            ExifTag::STANDARD_OUTPUT_SENSITIVITY,
            ExifTag::RECOMMENDED_EXPOSURE_INDEX,
        ] as $tag) {
            $value = $this->numericValue($this->document?->exifIfd, $tag);
            if ($value !== null) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Returns the ISO latitude yyy value.
     */
    public function isoLatitudeYyy(): ?int
    {
        return $this->document?->isoSpeedLatitudeYyy();
    }

    /**
     * Returns the ISO latitude zzz value.
     */
    public function isoLatitudeZzz(): ?int
    {
        return $this->document?->isoSpeedLatitudeZzz();
    }

    /**
     * Returns the exposure time in seconds.
     */
    public function exposureTime(): ?float
    {
        return $this->document?->exposureTime();
    }

    /**
     * Returns the APEX shutter speed value.
     */
    public function shutterSpeedEv(): ?float
    {
        return $this->document?->shutterSpeedValue();
    }

    /**
     * Returns the aperture f-number.
     */
    public function fNumber(): ?float
    {
        return $this->document?->fNumber();
    }

    /**
     * Returns the APEX aperture value.
     */
    public function apertureEv(): ?float
    {
        return $this->document?->apertureValue();
    }

    /**
     * Returns the exposure bias in EV.
     */
    public function exposureBias(): ?float
    {
        return $this->document?->exposureBias();
    }

    /**
     * Returns the exposure program enumeration when available.
     */
    public function exposureProgram(): ?ExposureProgram
    {
        $value = $this->document?->exposureProgram();

        return $value !== null ? ExposureProgram::tryFrom($value) : null;
    }

    /**
     * Returns the metering mode enumeration when available.
     */
    public function meteringMode(): ?MeteringMode
    {
        $value = $this->document?->meteringMode();

        return $value !== null ? MeteringMode::tryFrom($value) : null;
    }

    /**
     * Returns the white balance enumeration when available.
     */
    public function whiteBalance(): ?WhiteBalance
    {
        return WhiteBalance::fromExifValue($this->document?->whiteBalance());
    }

    /**
     * Returns the flash metadata as a value object when available.
     */
    public function flash(): ?FlashInfo
    {
        $flashValue = $this->document?->flash();
        if ($flashValue === null) {
            return null;
        }

        return CoreValueConverters::flashFromShort($flashValue);
    }

    /**
     * Returns the flash energy value when recorded.
     */
    public function flashEnergy(): ?float
    {
        return $this->document?->flashEnergy();
    }

    /**
     * Returns the brightness value in EV.
     */
    public function brightnessValue(): ?float
    {
        return $this->document?->brightnessValue();
    }

    /**
     * Returns the exposure mode enum.
     */
    public function exposureMode(): ?ExposureMode
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::EXPOSURE_MODE);

        return $value !== null ? ExposureMode::fromExifValue($value) : null;
    }

    /**
     * Returns the gain control enum.
     */
    public function gainControl(): ?GainControl
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::GAIN_CONTROL);

        return $value !== null ? GainControl::fromExifValue($value) : null;
    }

    /**
     * Returns the custom rendered value as reported by the camera.
     */
    public function customRendered(): ?int
    {
        return $this->document?->customRendered();
    }

    /**
     * Returns the contrast processing setting.
     */
    public function contrast(): ?int
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::CONTRAST);

        return $value !== null ? (int) $value : null;
    }

    /**
     * Returns the saturation processing setting.
     */
    public function saturation(): ?int
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::SATURATION);

        return $value !== null ? (int) $value : null;
    }

    /**
     * Returns the sharpness processing setting.
     */
    public function sharpness(): ?int
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::SHARPNESS);

        return $value !== null ? (int) $value : null;
    }

    /**
     * Returns the device setting description payload.
     */
    public function deviceSettingDescription(): ?string
    {
        return $this->document?->deviceSettingDescription();
    }

    /**
     * Returns the digital zoom ratio.
     */
    public function digitalZoomRatio(): ?float
    {
        return $this->rationalValue($this->document?->exifIfd, ExifTag::DIGITAL_ZOOM_RATIO);
    }

    /**
     * Returns the capture datetime derived from DateTimeOriginal and offset tags.
     */
    public function captureDateTime(): ?DateTimeImmutable
    {
        return $this->document?->captureDateTime();
    }

    /**
     * Returns the digitised datetime when available.
     */
    public function digitizedDateTime(): ?DateTimeImmutable
    {
        return $this->document?->dateTimeDigitized();
    }

    /**
     * Returns the image datetime when available.
     */
    public function fileDateTime(): ?DateTimeImmutable
    {
        return $this->document?->dateTime();
    }

    /**
     * Returns the raw offset from DateTimeOriginal.
     */
    public function originalOffset(): ?string
    {
        return $this->document?->offsetTimeOriginalRaw();
    }

    /**
     * Returns the GPS coordinates as an array of floats.
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
        return $this->document instanceof ExifDocument
            ? $this->document->gps()
            : ExifValueConverters::emptyGpsResult();
    }

    /**
     * Returns the latitude reference indicating north or south hemisphere.
     */
    public function gpsLatitudeRef(): ?string
    {
        $value = $this->gpsField('lat_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the longitude reference indicating east or west hemisphere.
     */
    public function gpsLongitudeRef(): ?string
    {
        $value = $this->gpsField('lon_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the altitude reference (0 above, 1 below sea level).
     */
    public function gpsAltitudeRef(): ?int
    {
        $value = $this->gpsField('alt_ref');

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        return null;
    }

    /**
     * Returns the GPS date stamp in ISO 8601 calendar format.
     */
    public function gpsDate(): ?string
    {
        $value = $this->gpsField('date');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the GPS time stamp in HH:MM:SS(.fff) format.
     */
    public function gpsTime(): ?string
    {
        $value = $this->gpsField('time');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the combined GPS timestamp as a UTC DateTime instance.
     */
    public function gpsTimestamp(): ?DateTimeImmutable
    {
        $value = $this->gpsField('timestamp');

        return $value instanceof DateTimeImmutable ? $value : null;
    }

    /**
     * Returns the GPS version identifier string.
     */
    public function gpsVersion(): ?string
    {
        $value = $this->gpsField('version');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the reported GPS satellites description.
     */
    public function gpsSatellites(): ?string
    {
        $value = $this->gpsField('satellites');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the GPS receiver status.
     */
    public function gpsStatus(): ?string
    {
        $value = $this->gpsField('status');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the GPS measurement mode description.
     */
    public function gpsMeasureMode(): ?string
    {
        $value = $this->gpsField('measure_mode');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the GPS dilution of precision value.
     */
    public function gpsDop(): ?float
    {
        $value = $this->gpsField('dop');

        return is_float($value) ? $value : (is_int($value) ? (float) $value : null);
    }

    /**
     * Returns the GPS speed reference character.
     */
    public function gpsSpeedRef(): ?string
    {
        $value = $this->gpsField('speed_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the GPS ground speed converted to metres per second.
     */
    public function gpsSpeed(): ?float
    {
        $value = $this->gpsField('speed_ms');

        return is_float($value) ? $value : (is_int($value) ? (float) $value : null);
    }

    /**
     * Returns the current track reference (true or magnetic).
     */
    public function gpsTrackRef(): ?string
    {
        $value = $this->gpsField('track_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the movement track in degrees.
     */
    public function gpsTrack(): ?float
    {
        $value = $this->gpsField('track');

        return is_float($value) ? $value : (is_int($value) ? (float) $value : null);
    }

    /**
     * Returns the image direction reference (true or magnetic).
     */
    public function gpsImgDirectionRef(): ?string
    {
        $value = $this->gpsField('img_direction_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the camera image direction in degrees.
     */
    public function gpsImgDirection(): ?float
    {
        $value = $this->gpsField('img_direction');

        return is_float($value) ? $value : (is_int($value) ? (float) $value : null);
    }

    /**
     * Returns the map datum string when present.
     */
    public function gpsMapDatum(): ?string
    {
        $value = $this->gpsField('map_datum');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the destination latitude reference (N/S).
     */
    public function gpsDestinationLatitudeRef(): ?string
    {
        $value = $this->gpsField('dest_lat_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the destination latitude in decimal degrees.
     */
    public function gpsDestinationLatitude(): ?float
    {
        $value = $this->gpsField('dest_lat');

        return is_float($value) ? $value : (is_int($value) ? (float) $value : null);
    }

    /**
     * Returns the destination longitude reference (E/W).
     */
    public function gpsDestinationLongitudeRef(): ?string
    {
        $value = $this->gpsField('dest_lon_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the destination longitude in decimal degrees.
     */
    public function gpsDestinationLongitude(): ?float
    {
        $value = $this->gpsField('dest_lon');

        return is_float($value) ? $value : (is_int($value) ? (float) $value : null);
    }

    /**
     * Returns the destination bearing reference (true or magnetic).
     */
    public function gpsDestinationBearingRef(): ?string
    {
        $value = $this->gpsField('dest_bearing_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the destination bearing in degrees.
     */
    public function gpsDestinationBearing(): ?float
    {
        $value = $this->gpsField('dest_bearing');

        return is_float($value) ? $value : (is_int($value) ? (float) $value : null);
    }

    /**
     * Returns the destination distance reference (kilometres, miles or nautical miles).
     */
    public function gpsDestinationDistanceRef(): ?string
    {
        $value = $this->gpsField('dest_distance_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the destination distance converted to metres.
     */
    public function gpsDestinationDistance(): ?float
    {
        $value = $this->gpsField('dest_distance_m');

        return is_float($value) ? $value : (is_int($value) ? (float) $value : null);
    }

    /**
     * Returns the GPS processing method description.
     */
    public function gpsProcessingMethod(): ?string
    {
        $value = $this->gpsField('processing_method');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns additional GPS area information if available.
     */
    public function gpsAreaInformation(): ?string
    {
        $value = $this->gpsField('area_information');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the GPS differential correction indicator.
     */
    public function gpsDifferential(): ?int
    {
        $value = $this->gpsField('differential');

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        return null;
    }

    /**
     * Returns the reported horizontal positioning error in metres.
     */
    public function gpsHorizontalPositioningError(): ?float
    {
        $value = $this->gpsField('h_positioning_error');

        return is_float($value) ? $value : (is_int($value) ? (float) $value : null);
    }

    /**
     * Returns a single field from the cached GPS metadata map.
     *
     * @return mixed
     */
    private function gpsField(string $key): mixed
    {
        $gps = $this->gps();

        return $gps[$key] ?? null;
    }

    /**
     * Returns the recorded temperature in Celsius.
     */
    public function temperatureCelsius(): ?float
    {
        return $this->document?->temperatureCelsius();
    }

    /**
     * Returns the relative humidity percentage.
     */
    public function humidityPercent(): ?float
    {
        return $this->document?->humidityPercent();
    }

    /**
     * Returns the ambient pressure in hPa.
     */
    public function pressureHPa(): ?float
    {
        return $this->document?->pressureHPa();
    }

    /**
     * Returns the water depth in metres when provided.
     */
    public function waterDepthMeters(): ?float
    {
        return $this->document?->waterDepthMeters();
    }

    /**
     * Returns the camera acceleration in m/s².
     */
    public function accelerationMs2(): ?float
    {
        return $this->document?->accelerationMs2();
    }

    /**
     * Returns the camera elevation angle in degrees.
     */
    public function cameraElevationAngleDeg(): ?float
    {
        return $this->document?->cameraElevationAngleDeg();
    }

    /**
     * Returns the subject distance in metres when available.
     */
    public function subjectDistance(): ?float
    {
        return $this->getRational($this->document?->exifIfd, ExifTag::SUBJECT_DISTANCE);
    }

    /**
     * Returns the exposure index when provided.
     */
    public function exposureIndex(): ?float
    {
        return $this->document?->exposureIndex();
    }

    /**
     * Returns the EXIF subject area values.
     *
     * @return array<int, int>|null
     */
    public function subjectArea(): ?array
    {
        $entry = $this->getEntry($this->document?->exifIfd, ExifTag::SUBJECT_AREA);
        $value = $entry?->value;

        if ($value instanceof ExifNumericList) {
            return array_map(static fn ($v): int => (int) $v, $value->values);
        }

        if (is_array($value)) {
            return array_map(static fn ($v): int => (int) $v, $value);
        }

        return null;
    }

    /**
     * Returns the subject location coordinates when provided.
     *
     * @return list<int>|null
     */
    public function subjectLocation(): ?array
    {
        $values = $this->document?->subjectLocation();

        return $values !== null ? array_values($values) : null;
    }

    /**
     * Returns the light source enum value.
     */
    public function lightSource(): ?LightSource
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::LIGHT_SOURCE);

        return $value !== null ? LightSource::tryFrom((int) $value) : null;
    }

    /**
     * Returns the scene capture type enum.
     */
    public function sceneCaptureType(): ?SceneCaptureType
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::SCENE_CAPTURE_TYPE);

        return $value !== null ? SceneCaptureType::tryFrom((int) $value) : null;
    }

    /**
     * Returns the raw scene type classification.
     */
    public function sceneType(): ?int
    {
        return $this->document?->sceneType();
    }

    /**
     * Returns the subject distance range enum.
     */
    public function subjectDistanceRange(): ?SubjectDistanceRange
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::SUBJECT_DISTANCE_RANGE);

        return $value !== null ? SubjectDistanceRange::fromExifValue($value) : null;
    }

    /**
     * Returns the file source enum.
     */
    public function fileSource(): ?FileSource
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::FILE_SOURCE);

        return $value !== null ? FileSource::fromExifValue($value) : null;
    }

    /**
     * Returns the sensing method enum.
     */
    public function sensingMethod(): ?SensingMethod
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::SENSING_METHOD);

        return $value !== null ? SensingMethod::fromExifValue($value) : null;
    }

    /**
     * Returns the composite image type enum.
     */
    public function compositeImage(): ?CompositeImage
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::COMPOSITE_IMAGE);

        return $value !== null ? CompositeImage::fromExifValue($value) : null;
    }

    /**
     * Returns the composite image counts.
     *
     * @return array{0:int,1:int}|null
     */
    public function compositeImageCount(): ?array
    {
        $values = $this->normalizeNumericList($this->getValue($this->document?->exifIfd, ExifTag::COMPOSITE_IMAGE_COUNT));
        if (count($values) !== 2) {
            return null;
        }

        return [(int) $values[0], (int) $values[1]];
    }

    /**
     * Returns the exposure times for composite image sources.
     *
     * @return list<float>|null
     */
    public function compositeExposureTimes(): ?array
    {
        $values = $this->normalizeRationalList($this->getValue($this->document?->exifIfd, ExifTag::COMPOSITE_IMAGE_EXPOSURE_TIMES));
        if ($values === []) {
            return null;
        }

        return array_values($values);
    }

    /**
     * Returns the gamma value.
     */
    public function gamma(): ?float
    {
        return $this->rationalValue($this->document?->exifIfd, ExifTag::GAMMA);
    }

    /**
     * Maps the EXIF sensitivity type enumeration to ISO-related tag priorities.
     *
     * @return list<int>
     */
    private function sensitivityTagPriority(int $type): array
    {
        return match ($type) {
            1       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY],
            2       => [ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            3       => [ExifTag::ISO_SPEED],
            4       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY, ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            5       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY, ExifTag::ISO_SPEED],
            6       => [ExifTag::RECOMMENDED_EXPOSURE_INDEX, ExifTag::ISO_SPEED],
            7       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY, ExifTag::RECOMMENDED_EXPOSURE_INDEX, ExifTag::ISO_SPEED],
            default => [],
        };
    }

    /**
     * Helper returning an IFD entry by tag from a given directory.
     */
    private function getEntry(?Ifd $ifd, int $tag): ?IfdEntry
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        return $ifd->get($tag);
    }

    /**
     * Returns the raw entry value for a tag.
     */
    private function getValue(?Ifd $ifd, int $tag): mixed
    {
        return $this->getEntry($ifd, $tag)?->value;
    }

    /**
     * Returns a string value from the given IFD if present.
     */
    private function stringValue(?Ifd $ifd, int $tag): ?string
    {
        $entry = $this->getEntry($ifd, $tag);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $value = $entry->value;
        if (!is_string($value)) {
            return null;
        }

        return trim($value, "\0");
    }

    /**
     * Returns a numeric value (first entry) from the given IFD.
     */
    private function numericValue(?Ifd $ifd, int $tag): ?int
    {
        $entry = $this->getEntry($ifd, $tag);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $value = $entry->value;
        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;
            if (is_int($first) || is_float($first)) {
                return (int) $first;
            }

            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && $value !== '') {
            return ord($value[0]);
        }

        return null;
    }

    /**
     * Returns a float converted from a rational value.
     */
    private function rationalValue(?Ifd $ifd, int $tag): ?float
    {
        $entry = $this->getEntry($ifd, $tag);

        return $entry instanceof IfdEntry ? CoreValueConverters::rationalToFloat($entry->value) : null;
    }

    /**
     * Returns an array of floats converted from rational values.
     *
     * @return list<float>
     */
    private function normalizeRationalList(mixed $value): array
    {
        if ($value instanceof ExifRationalList) {
            return array_map(static fn (ExifRational $rational): float => CoreValueConverters::rationalToFloat($rational), $value->values);
        }

        if ($value instanceof ExifRational) {
            return [CoreValueConverters::rationalToFloat($value) ?? 0.0];
        }

        if (is_array($value)) {
            $values = [];
            foreach ($value as $component) {
                $float = CoreValueConverters::rationalToFloat($component);
                if ($float === null) {
                    return [];
                }

                $values[] = $float;
            }

            return $values;
        }

        return [];
    }

    /**
     * Normalises integer based lists from EXIF entries.
     *
     * @return list<int>|null
     */
    private function integerList(?Ifd $ifd, int $tag): ?array
    {
        $values = $this->normalizeNumericList($this->getValue($ifd, $tag));
        if ($values === []) {
            return null;
        }

        return array_map(static fn (int|float $value): int => (int) $value, $values);
    }

    /**
     * Normalises numeric lists from EXIF entries.
     *
     * @return list<int|float>
     */
    private function normalizeNumericList(mixed $value): array
    {
        if ($value instanceof ExifNumericList) {
            return array_values($value->values);
        }

        if (is_array($value)) {
            return array_values($value);
        }

        if (is_int($value) || is_float($value)) {
            return [$value];
        }

        return [];
    }

    /**
     * Helper returning rational values by tag.
     */
    private function getRational(?Ifd $ifd, int $tag): ?float
    {
        $entry = $this->getEntry($ifd, $tag);

        return $entry instanceof IfdEntry ? ExifValueConverters::rationalToFloat($entry->value) : null;
    }
}
