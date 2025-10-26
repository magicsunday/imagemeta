<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use BackedEnum;
use DateTimeImmutable;
use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\Core\ValueConverters as CoreValueConverters;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters as ExifValueConverters;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
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
use MagicSunday\ImageMeta\Value\Enum\SceneType;
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
use function is_numeric;
use function is_string;
use function ord;
use function strtolower;
use function trim;

/**
 * Provides high level accessors for common EXIF tags without exposing raw identifiers to consumers.
 */
final readonly class ExifTagResolver
{
    /**
     * Wraps an optional EXIF document for high level tag lookups.
     *
     * @param ExifDocument|null $document Parsed EXIF document instance.
     */
    public function __construct(private ?ExifDocument $document)
    {
    }

    /**
     * Returns an integer representation for the requested EXIF tag name.
     */
    public function int(string $tag): ?int
    {
        $key = strtolower($tag);

        return match ($key) {
            'iso'                       => $this->document?->iso(),
            'isospeed'                  => $this->numericValue($this->document?->exifIfd, ExifTag::ISO_SPEED),
            'standardoutputsensitivity' => $this->numericValue($this->document?->exifIfd, ExifTag::STANDARD_OUTPUT_SENSITIVITY),
            'recommendedexposureindex'  => $this->numericValue($this->document?->exifIfd, ExifTag::RECOMMENDED_EXPOSURE_INDEX),
            'exifimagewidth'            => $this->numericValue($this->document?->exifIfd, ExifTag::PIXEL_X_DIMENSION),
            'imagewidth'                => $this->numericValue($this->document?->ifd0, ExifTag::IMAGE_WIDTH),
            'exifimageheight'           => $this->numericValue($this->document?->exifIfd, ExifTag::PIXEL_Y_DIMENSION),
            'imagelength'               => $this->numericValue($this->document?->ifd0, ExifTag::IMAGE_HEIGHT),
            'flash'                     => $this->numericValue($this->document?->exifIfd, ExifTag::FLASH),
            'contrast'                  => $this->numericValue($this->document?->exifIfd, ExifTag::CONTRAST),
            'saturation'                => $this->numericValue($this->document?->exifIfd, ExifTag::SATURATION),
            'sharpness'                 => $this->numericValue($this->document?->exifIfd, ExifTag::SHARPNESS),
            'isolatitudeyyy'            => $this->numericValue($this->document?->exifIfd, ExifTag::ISO_SPEED_LATITUDE_YYY),
            'isolatitudezzz'            => $this->numericValue($this->document?->exifIfd, ExifTag::ISO_SPEED_LATITUDE_ZZZ),
            'colorspace'                => $this->numericValue($this->document?->exifIfd, ExifTag::COLOR_SPACE),
            'exposuremode'              => $this->numericValue($this->document?->exifIfd, ExifTag::EXPOSURE_MODE),
            'gaincontrol'               => $this->numericValue($this->document?->exifIfd, ExifTag::GAIN_CONTROL),
            'meteringmode'              => $this->numericValue($this->document?->exifIfd, ExifTag::METERING_MODE),
            'whitebalance'              => $this->numericValue($this->document?->exifIfd, ExifTag::WHITE_BALANCE),
            'exposureprogram'           => $this->numericValue($this->document?->exifIfd, ExifTag::EXPOSURE_PROGRAM),
            default                     => null,
        };
    }

    /**
     * Returns a list of integers for the requested EXIF tag name.
     *
     * @return list<int>|null
     */
    public function ints(string $tag): ?array
    {
        return match (strtolower($tag)) {
            'timezoneoffset' => $this->timeZoneOffsetMinutes(),
            default          => null,
        };
    }

    /**
     * Returns a string value for the requested EXIF tag name.
     */
    public function string(string $tag): ?string
    {
        return match (strtolower($tag)) {
            'offsettime'          => $this->offsetTime(),
            'offsettimeoriginal'  => $this->offsetTimeOriginal(),
            'offsettimedigitized' => $this->offsetTimeDigitized(),
            'subsectime'          => $this->subSecTime(),
            'subsectimeoriginal'  => $this->subSecTimeOriginal(),
            'subsectimedigitized' => $this->subSecTimeDigitized(),
            'interopindex'        => $this->interopIndex(),
            'interopversion'      => $this->interopVersion(),
            default               => null,
        };
    }

    /**
     * Returns the interoperability version string.
     */
    public function interopVersion(): ?string
    {
        if ($this->document instanceof ExifDocument) {
            return $this->document->interopVersion();
        }

        return null;
    }

    /**
     * Returns a date value for the requested EXIF tag name.
     */
    public function date(string $tag): ?DateTimeImmutable
    {
        return match (strtolower($tag)) {
            'datetimeoriginal'  => $this->captureDateTime(),
            'datetimedigitized' => $this->digitizedDateTime(),
            'datetime'          => $this->fileDateTime(),
            default             => null,
        };
    }

    /**
     * Returns a rational-compatible value for the requested EXIF tag name.
     *
     * @return array<int, int|float|string>|int|float|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function rational(string $tag): array|int|float|ExifRational|ExifRationalList|ExifNumericList|null
    {
        $key = strtolower($tag);

        $entry = match ($key) {
            'exposuretime'         => $this->getEntry($this->document?->exifIfd, ExifTag::EXPOSURE_TIME),
            'fnumber'              => $this->getEntry($this->document?->exifIfd, ExifTag::F_NUMBER),
            'exposurecompensation' => $this->getEntry($this->document?->exifIfd, ExifTag::EXPOSURE_BIAS_VALUE),
            'brightnessvalue'      => $this->getEntry($this->document?->exifIfd, ExifTag::BRIGHTNESS_VALUE),
            'digitalzoomratio'     => $this->getEntry($this->document?->exifIfd, ExifTag::DIGITAL_ZOOM_RATIO),
            'shutterspeedvalue'    => $this->getEntry($this->document?->exifIfd, ExifTag::SHUTTER_SPEED_VALUE),
            'aperturevalue'        => $this->getEntry($this->document?->exifIfd, ExifTag::APERTURE_VALUE),
            'exposureindex'        => $this->getEntry($this->document?->exifIfd, ExifTag::EXPOSURE_INDEX),
            'flashenergy'          => $this->getEntry($this->document?->exifIfd, ExifTag::FLASH_ENERGY),
            'maxaperturevalue'     => $this->getEntry($this->document?->exifIfd, ExifTag::MAX_APERTURE_VALUE),
            default                => null,
        };

        if (!$entry instanceof IfdEntry) {
            return null;
        }

        return $this->sanitizeRationalInput($entry->value);
    }

    /**
     * Returns a backed enum mapped from the requested EXIF tag value.
     */
    /**
     * @template T of BackedEnum
     *
     * @param class-string<T> $enumClass
     *
     * @return T|null
     */
    public function enum(string $tag, string $enumClass): ?BackedEnum
    {
        $value = $this->int($tag);

        return CoreValueConverters::toEnumOrNull($enumClass, $value);
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
     * Returns the camera firmware version string when recorded via legacy tags.
     */
    public function cameraFirmwareVersion(): ?string
    {
        return $this->document?->cameraFirmwareVersion();
    }

    /**
     * Returns the raw developing software string.
     */
    public function rawDevelopingSoftware(): ?string
    {
        return $this->document?->rawDevelopingSoftware();
    }

    /**
     * Returns the raw developing software version string when recorded via legacy tags.
     */
    public function rawDevelopingSoftwareVersion(): ?string
    {
        return $this->document?->rawDevelopingSoftwareVersion();
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
     * Returns the metadata editing software version string when recorded via legacy tags.
     */
    public function metadataEditingSoftwareVersion(): ?string
    {
        return $this->document?->metadataEditingSoftwareVersion();
    }

    /**
     * Returns the sequential image number captured by the camera.
     */
    public function imageNumber(): ?int
    {
        return $this->document?->imageNumber();
    }

    /**
     * Returns the security classification string if provided.
     */
    public function securityClassification(): ?string
    {
        return $this->document?->securityClassification();
    }

    /**
     * Returns the recorded image history text.
     */
    public function imageHistory(): ?string
    {
        return $this->document?->imageHistory();
    }

    /**
     * Returns the TIFF/EP standard identifier bytes.
     *
     * @return list<int>|null
     */
    public function tiffEpStandardId(): ?array
    {
        return $this->document?->tiffEpStandardId();
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
    public function lensSpecification(): ?array
    {
        $entry = $this->getEntry($this->document?->exifIfd, ExifTag::LENS_SPECIFICATION);
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

        return $value;
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
        return $this->document?->componentsConfiguration();
    }

    /**
     * Returns human readable component configuration labels.
     *
     * @return list<string>|null
     */
    public function componentsConfigurationLabels(): ?array
    {
        return $this->document?->componentsConfigurationLabels();
    }

    /**
     * Returns the component configuration description string.
     */
    public function componentsConfigurationDescription(): ?string
    {
        return $this->document?->componentsConfigurationDescription();
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
     * Returns the interlace flag from the EXIF IFD.
     */
    public function interlace(): ?int
    {
        return $this->document?->interlace();
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
        return $this->document?->cfaPattern();
    }

    /**
     * Returns the CFA pattern as colour enums.
     *
     * @return list<CfaPatternColor>|null
     */
    public function cfaPatternColors(): ?array
    {
        return $this->document?->cfaPatternColors();
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

        return $value;
    }

    /**
     * Returns rows per strip if present.
     */
    public function rowsPerStrip(): ?int
    {
        $value = $this->numericValue($this->document?->ifd0, ExifTag::ROWS_PER_STRIP);

        return $value;
    }

    /**
     * Returns the strip offsets defined in the TIFF IFD.
     *
     * @return list<int>|null
     */
    public function stripOffsets(): ?array
    {
        return $this->document?->stripOffsets();
    }

    /**
     * Returns the strip byte counts for each TIFF strip.
     *
     * @return list<int>|null
     */
    public function stripByteCounts(): ?array
    {
        return $this->document?->stripByteCounts();
    }

    /**
     * Returns the transfer function lookup table when present.
     *
     * @return list<int>|null
     */
    public function transferFunction(): ?array
    {
        return $this->document?->transferFunction();
    }

    /**
     * Returns the JPEG thumbnail offset when present.
     */
    public function jpegThumbnailOffset(): ?int
    {
        return $this->document?->jpegThumbnailOffset();
    }

    /**
     * Returns the JPEG thumbnail length when present.
     */
    public function jpegThumbnailLength(): ?int
    {
        return $this->document?->jpegThumbnailLength();
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

        if ($value instanceof ExifRationalList || $value instanceof ExifNumericList) {
            return CoreValueConverters::toWhitePoint($value);
        }

        return null;
    }

    /**
     * Returns the primary chromaticities array.
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public function primaryChromaticities(): ?array
    {
        $value = $this->getValue($this->document?->ifd0, ExifTag::PRIMARY_CHROMATICITIES);

        if ($value instanceof ExifRationalList || $value instanceof ExifNumericList) {
            return CoreValueConverters::toPrimaryChromaticities($value);
        }

        return null;
    }

    /**
     * Returns the reference black and white point values.
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public function referenceBlackWhite(): ?array
    {
        $values = $this->document?->referenceBlackWhite();
        if ($values === null || count($values) !== 6) {
            return null;
        }

        return $values;
    }

    /**
     * Returns the copyright notice string when present.
     */
    public function copyright(): ?string
    {
        return $this->document?->copyright();
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
        return $this->document?->exifVersion();
    }

    /**
     * Returns the derived EXIF capability profile identifier.
     */
    public function exifProfile(): string
    {
        if ($this->document instanceof ExifDocument) {
            return $this->document->exifProfile();
        }

        return ExifCapabilities::fromVersion(null);
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
                    return $value;
                }
            }
        }

        foreach ([
            ExifTag::STANDARD_OUTPUT_SENSITIVITY,
            ExifTag::RECOMMENDED_EXPOSURE_INDEX,
        ] as $tag) {
            $value = $this->numericValue($this->document?->exifIfd, $tag);
            if ($value !== null) {
                return $value;
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
    public function customRendered(): ?CustomRendered
    {
        return $this->document?->customRendered();
    }

    /**
     * Returns the contrast processing setting.
     */
    public function contrast(): ?int
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::CONTRAST);

        return $value;
    }

    /**
     * Returns the saturation processing setting.
     */
    public function saturation(): ?int
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::SATURATION);

        return $value;
    }

    /**
     * Returns the sharpness processing setting.
     */
    public function sharpness(): ?int
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::SHARPNESS);

        return $value;
    }

    /**
     * Returns the noise reduction strength encoded by the camera.
     */
    public function noiseReduction(): ?float
    {
        return $this->rationalValue($this->document?->exifIfd, ExifTag::NOISE);
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
     * Returns the ModifyDate/DateTime timestamp when available.
     */
    public function fileDateTime(): ?DateTimeImmutable
    {
        return $this->document?->dateTime();
    }

    /**
     * Returns the sanitized OffsetTime tag value.
     */
    public function offsetTime(): ?string
    {
        return $this->document?->offsetTime();
    }

    /**
     * Returns the sanitized OffsetTimeOriginal tag value.
     */
    public function offsetTimeOriginal(): ?string
    {
        return $this->document?->offsetTimeOriginal();
    }

    /**
     * Returns the sanitized OffsetTimeDigitized tag value.
     */
    public function offsetTimeDigitized(): ?string
    {
        return $this->document?->offsetTimeDigitized();
    }

    /**
     * Returns the fractional seconds associated with the ModifyDate/DateTime tag.
     */
    public function subSecTime(): ?string
    {
        return $this->document?->subSecTime();
    }

    /**
     * Returns the fractional seconds associated with DateTimeOriginal.
     */
    public function subSecTimeOriginal(): ?string
    {
        return $this->document?->subSecTimeOriginal();
    }

    /**
     * Returns the fractional seconds associated with DateTimeDigitized.
     */
    public function subSecTimeDigitized(): ?string
    {
        return $this->document?->subSecTimeDigitized();
    }

    /**
     * Returns the EXIF time zone offsets expressed in minutes.
     *
     * @return list<int>|null
     */
    public function timeZoneOffsetMinutes(): ?array
    {
        return $this->document?->timeZoneOffsetMinutes();
    }

    /**
     * Returns the raw offset from DateTimeOriginal.
     */
    public function originalOffset(): ?string
    {
        return $this->offsetTimeOriginal();
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

        return is_int($value) ? $value : null;
    }

    /**
     * Returns the GPS date stamp in ISO 8601 calendar format.
     */
    public function gpsDate(): ?string
    {
        return $this->document?->gpsDateStamp();
    }

    /**
     * Returns the GPS time stamp in HH:MM:SS(.fff) format.
     */
    public function gpsTime(): ?string
    {
        return $this->document?->gpsTimeStampString();
    }

    /**
     * Returns the combined GPS timestamp as a UTC DateTime instance.
     */
    public function gpsTimestamp(): ?DateTimeImmutable
    {
        return $this->document?->gpsTimestamp();
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

        return is_float($value) ? $value : null;
    }

    /**
     * Returns the GPS speed reference character.
     */
    public function gpsSpeedRef(): ?string
    {
        return $this->document?->gpsSpeedRef();
    }

    /**
     * Returns the GPS ground speed converted to metres per second.
     */
    public function gpsSpeed(): ?float
    {
        return $this->document?->gpsSpeedMetresPerSecond();
    }

    /**
     * Returns the current track reference (true or magnetic).
     */
    public function gpsTrackRef(): ?string
    {
        return $this->document?->gpsTrackRef();
    }

    /**
     * Returns the movement track in degrees.
     */
    public function gpsTrack(): ?float
    {
        return $this->document?->gpsTrack();
    }

    /**
     * Returns the image direction reference (true or magnetic).
     */
    public function gpsImgDirectionRef(): ?string
    {
        return $this->document?->gpsImgDirectionRef();
    }

    /**
     * Returns the camera image direction in degrees.
     */
    public function gpsImgDirection(): ?float
    {
        return $this->document?->gpsImgDirection();
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

        return is_float($value) ? $value : null;
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

        return is_float($value) ? $value : null;
    }

    /**
     * Returns the destination bearing reference (true or magnetic).
     */
    public function gpsDestinationBearingRef(): ?string
    {
        return $this->document?->gpsDestinationBearingRef();
    }

    /**
     * Returns the destination bearing in degrees.
     */
    public function gpsDestinationBearing(): ?float
    {
        return $this->document?->gpsDestinationBearing();
    }

    /**
     * Returns the destination distance reference (kilometres, miles or nautical miles).
     */
    public function gpsDestinationDistanceRef(): ?string
    {
        return $this->document?->gpsDestinationDistanceRef();
    }

    /**
     * Returns the destination distance converted to metres.
     */
    public function gpsDestinationDistance(): ?float
    {
        return $this->document?->gpsDestinationDistanceMetres();
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
        return $this->document?->gpsDifferential();
    }

    /**
     * Returns the reported horizontal positioning error in metres.
     */
    public function gpsHorizontalPositioningError(): ?float
    {
        return $this->document?->gpsHorizontalPositioningError();
    }

    /**
     * Returns a single field from the cached GPS metadata map.
     *
     * @return string|int|float|DateTimeImmutable|null
     */
    private function gpsField(string $key): string|int|float|DateTimeImmutable|null
    {
        $gps = $this->gps();

        return $gps[$key] ?? null;
    }

    /**
     * Returns the recorded self timer delay in seconds.
     */
    public function selfTimerModeSeconds(): ?int
    {
        return $this->document?->selfTimerModeSeconds();
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
            return array_map(static fn (int|float $v): int => (int) $v, $value->values);
        }

        if (is_int($value) || is_float($value)) {
            return [(int) $value];
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
        return $this->document?->subjectLocation();
    }

    /**
     * Returns the light source enum value.
     */
    public function lightSource(): ?LightSource
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::LIGHT_SOURCE);

        return $value !== null ? LightSource::tryFrom($value) : null;
    }

    /**
     * Returns the scene capture type enum.
     */
    public function sceneCaptureType(): ?SceneCaptureType
    {
        $value = $this->numericValue($this->document?->exifIfd, ExifTag::SCENE_CAPTURE_TYPE);

        return $value !== null ? SceneCaptureType::tryFrom($value) : null;
    }

    /**
     * Returns the raw scene type classification.
     */
    public function sceneType(): ?SceneType
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
     * Returns the composite image source counts.
     *
     * @return array{0:int,1:int}|null
     */
    public function sourceImageNumberOfCompositeImage(): ?array
    {
        $values = $this->normalizeNumericList(
            $this->getValue($this->document?->exifIfd, ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE),
        );
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
    public function sourceExposureTimesOfCompositeImage(): ?array
    {
        $values = $this->normalizeRationalList(
            $this->getValue($this->document?->exifIfd, ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE),
        );

        return $values !== [] ? $values : null;
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
    private function getValue(?Ifd $ifd, int $tag): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
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
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $value = $this->sanitizeRationalInput($entry->value);
        if ($value === null) {
            return null;
        }

        return CoreValueConverters::rationalToFloat($value);
    }

    /**
     * Returns an array of floats converted from rational values.
     *
     * @param array|int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value
     *
     * @phpstan-param array<int|string, int|float|string|array<int|string, int|float|string|null>>|int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value
     *
     * @return list<float>
     */
    private function normalizeRationalList(
        array|int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): array {
        if ($value instanceof ExifRationalList) {
            $floats = [];
            foreach ($value->values as $rational) {
                $float = CoreValueConverters::rationalToFloat($rational);
                if ($float === null) {
                    return [];
                }

                $floats[] = $float;
            }

            return $floats;
        }

        if ($value instanceof ExifRational) {
            $float = CoreValueConverters::rationalToFloat($value);

            return $float !== null ? [$float] : [];
        }

        if (is_array($value)) {
            $values = [];
            /** @var list<array<int|string, int|float|string|null>|int|float|string> $components */
            $components = array_values($value);

            foreach ($components as $component) {
                $sanitised = $this->sanitizeRationalArrayComponent($component);
                if ($sanitised === null) {
                    return [];
                }

                $float = CoreValueConverters::rationalToFloat($sanitised);
                if ($float === null) {
                    return [];
                }

                $values[] = $float;
            }

            return $values;
        }

        $sanitised = $this->sanitizeRationalInput($value);
        if ($sanitised === null) {
            return [];
        }

        $float = CoreValueConverters::rationalToFloat($sanitised);

        return $float !== null ? [$float] : [];
    }

    /**
     * Normalises numeric lists from EXIF entries.
     *
     * @param array|int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value
     *
     * @phpstan-param array<int|string, int|float|string|array<int|string, int|float|string|null>>|int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value
     *
     * @return list<int|float>
     */
    private function normalizeNumericList(array|int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value): array
    {
        if ($value instanceof ExifNumericList) {
            return $value->values;
        }

        if (is_array($value)) {
            /** @var array<int|string, int|float|string|array<int|string, int|float|string|null>> $numericComponents */
            $numericComponents = $value;

            $result = [];
            foreach ($numericComponents as $component) {
                if (is_int($component) || is_float($component)) {
                    $result[] = $component;
                }
            }

            return $result;
        }

        if (is_int($value) || is_float($value)) {
            return [$value];
        }

        return [];
    }

    /**
     * Normalises raw rational values into formats accepted by the shared converter.
     *
     * @param array|int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value
     *
     * @phpstan-param array<int|string, int|float|string|array<int|string, int|float|string|null>>|int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value
     *
     * @return array<int, int|float|string>|int|float|ExifRational|ExifRationalList|ExifNumericList|null
     */
    private function sanitizeRationalInput(
        array|int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): array|int|float|ExifRational|ExifRationalList|ExifNumericList|null {
        if ($value instanceof ExifRationalList || $value instanceof ExifRational || $value instanceof ExifNumericList) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            if ($value === '' || !is_numeric($value)) {
                return null;
            }

            return (float) $value;
        }

        if (!is_array($value)) {
            return null;
        }

        return $this->sanitizeRationalPair(array_values($value));
    }

    /**
     * Normalises list elements into rational-compatible representations.
     *
     * @param array|int|float|string|null $component
     *
     * @phpstan-param array<int|string, array<int|string, int|float|string|null>|int|float|string|null>|int|float|string|null $component
     *
     * @return array<int, int|float|string>|int|float|null
     */
    private function sanitizeRationalArrayComponent(
        array|int|float|string|null $component,
    ): array|int|float|null {
        if (is_int($component) || is_float($component)) {
            return $component;
        }

        if (is_string($component)) {
            if ($component === '' || !is_numeric($component)) {
                return null;
            }

            return (float) $component;
        }

        if (!is_array($component)) {
            return null;
        }

        return $this->sanitizeRationalPair(array_values($component));
    }

    /**
     * Sanitises associative or nested arrays into rational numerator/denominator pairs.
     *
     * @param array<int|string, int|float|string|array<int|string, int|float|string|null>> $value
     *
     * @phpstan-param array<int|string, int|float|string|array<int|string, int|float|string|null>> $value
     *
     * @return array<int, int|float|string>|null
     */
    private function sanitizeRationalPair(array $value): ?array
    {
        $components = array_values($value);
        if ($components === []) {
            return null;
        }

        if (isset($components[0]) && is_array($components[0])) {
            return $this->sanitizeRationalPair(array_values($components[0]));
        }

        $pair = [];
        foreach ($components as $component) {
            if (is_int($component) || is_float($component)) {
                $pair[] = $component;
            } elseif (is_string($component)) {
                if ($component === '' || !is_numeric($component)) {
                    return null;
                }

                $pair[] = $component;
            } else {
                return null;
            }

            if (count($pair) === 2) {
                break;
            }
        }

        if (count($pair) < 2) {
            return null;
        }

        return [$pair[0], $pair[1]];
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
