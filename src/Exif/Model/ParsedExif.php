<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Model;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Adapter\CameraMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\DeviceMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\ExposureMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\GpsMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\ImageMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\LensMetadataAdapter;
use MagicSunday\ImageMeta\Exif\Adapter\TemporalMetadataAdapter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Text\JisTextDecoder;
use MagicSunday\ImageMeta\Exif\Text\UndefinedTextMarker;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Value\CfaPattern;
use MagicSunday\ImageMeta\Value\DeviceSettingDescription;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
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
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\SensitivityType;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use MagicSunday\ImageMeta\Value\FlashInfo;
use MagicSunday\ImageMeta\Value\Oecf;
use MagicSunday\ImageMeta\Value\SourceExposureTimes;
use MagicSunday\ImageMeta\Value\SpatialFrequencyResponse;
use MagicSunday\ImageMeta\Value\SubjectArea;

use function array_find;
use function array_map;
use function array_slice;
use function count;
use function iconv;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function ord;
use function preg_match;
use function preg_replace;
use function round;
use function rtrim;
use function spl_object_id;
use function sqrt;
use function str_pad;
use function str_replace;
use function strlen;
use function substr;
use function substr_count;
use function trim;

/**
 * Represents a parsed EXIF payload and exposes convenience accessors.
 *
 * EXIF 3.0 §4 and Annex A summarise the logical grouping of tags mirrored by
 * the accessors provided in this value object.
 */
final readonly class ParsedExif implements ExifIfd0Data, ExifIfd1Data, ExifSubIfdData, ExifGpsData, ExifInteropData
{
    /**
     * EXIF Acceleration is specified in mGal (10^-5 m/s²).
     */
    private const float ACCELERATION_MGAL_TO_MS2 = 1.0e-5;

    /**
     * EXIF 3.0 §4.6.6.8 uses denominator 0xFFFFFFFF as unknown sentinel.
     */
    private const int EXIF_UNKNOWN_DENOMINATOR = 0xFFFFFFFF;

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

    private ?string $exifVersion;

    private string $exifProfile;

    private Endian $byteOrder;

    private const int RATIONAL_BYTE_LENGTH = 8;

    private const int SHORT_BYTE_LENGTH = 2;

    /**
     * @param Ifd                   $ifd0           Root IFD of the TIFF structure.
     * @param Ifd|null              $exifIfd        Sub IFD containing EXIF-specific tags.
     * @param Ifd|null              $gpsIfd         Sub IFD containing GPS-related tags.
     * @param Ifd|null              $interopIfd     Sub IFD containing interoperability tags.
     * @param Ifd|null              $ifd1           Optional next IFD, typically thumbnails.
     * @param MakerNotesRecord|null $makerNotes     Decoded maker note metadata provided by vendor decoders.
     * @param list<Ifd>             $subsequentIfds Additional linked IFDs discovered via the next-pointer chain.
     * @param array<int, Ifd>       $subIfds        Parsed SubIFDs indexed by their file offsets.
     */
    public function __construct(
        public Ifd $ifd0,
        public ?Ifd $exifIfd,
        public ?Ifd $gpsIfd,
        public ?Ifd $interopIfd,
        public ?Ifd $ifd1,
        public ?MakerNotesRecord $makerNotes = null,
        public array $subsequentIfds = [],
        public array $subIfds = [],
        ?Endian $byteOrder = null,
    ) {
        $rawVersion        = $this->rawString($this->exifIfd, ExifTag::EXIF_VERSION);
        $this->exifVersion = ValueConverters::toExifVersion($rawVersion);
        $this->exifProfile = ExifCapabilities::fromVersion($this->exifVersion);
        $this->byteOrder   = $byteOrder ?? Endian::Little;
    }

    /**
     * Returns the decoded maker note metadata when a decoder is available.
     *
     * EXIF 3.0 §4.6.6.4.1 reserves MakerNote for manufacturer-specific data.
     */
    public function makerNotes(): ?MakerNotesRecord
    {
        return $this->makerNotes;
    }

    /**
     * Returns any additional image file directories linked from IFD0.
     *
     * @return list<Ifd>
     */
    public function subsequentIfds(): array
    {
        return $this->subsequentIfds;
    }

    /**
     * Returns the parsed SubIFDs keyed by their offset within the TIFF blob.
     *
     * @return array<int, Ifd>
     */
    public function subIfds(): array
    {
        return $this->subIfds;
    }

    /**
     * Returns ExifIFDPointer (0x8769) from IFD0.
     *
     * EXIF 3.0 §4.6.3.1.1 defines this field as the offset pointer to the Exif IFD.
     *
     * @return int|null
     */
    public function exifIfdPointer(): ?int
    {
        return $this->int($this->ifd0, ExifTag::EXIF_IFD_POINTER);
    }

    /**
     * Returns GPSInfoIFDPointer (0x8825) from IFD0.
     *
     * EXIF 3.0 §4.6.3.2.1 defines this field as the offset pointer to the GPS IFD.
     *
     * @return int|null
     */
    public function gpsIfdPointer(): ?int
    {
        return $this->int($this->ifd0, ExifTag::GPS_IFD_POINTER);
    }

    /**
     * Returns InteroperabilityIFDPointer (0xA005) from the Exif IFD.
     *
     * EXIF 3.0 §4.6.3.3.1 defines this field as the offset pointer to the Interoperability IFD.
     *
     * @return int|null
     */
    public function interoperabilityIfdPointer(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::INTEROPERABILITY_IFD_POINTER);
    }

    /**
     * Returns the camera metadata domain adapter.
     */
    public function cameraMetadata(): CameraMetadataAdapter
    {
        return new CameraMetadataAdapter($this);
    }

    /**
     * Returns the lens metadata domain adapter.
     */
    public function lensMetadata(): LensMetadataAdapter
    {
        return new LensMetadataAdapter($this);
    }

    /**
     * Returns the exposure metadata domain adapter.
     */
    public function exposureMetadata(): ExposureMetadataAdapter
    {
        return new ExposureMetadataAdapter($this);
    }

    /**
     * Returns the device metadata domain adapter.
     */
    public function deviceMetadata(): DeviceMetadataAdapter
    {
        return new DeviceMetadataAdapter($this);
    }

    /**
     * Returns the image metadata domain adapter.
     */
    public function imageMetadata(): ImageMetadataAdapter
    {
        return new ImageMetadataAdapter($this);
    }

    /**
     * Returns the temporal metadata domain adapter.
     */
    public function temporalMetadata(): TemporalMetadataAdapter
    {
        return new TemporalMetadataAdapter($this);
    }

    /**
     * Returns the GPS metadata domain adapter.
     */
    public function gpsMetadata(): GpsMetadataAdapter
    {
        return new GpsMetadataAdapter($this);
    }

    /**
     * Returns the camera manufacturer string if present.
     *
     * EXIF 3.0 §4.6.5.4.2 (Make) stores the free-form manufacturer identifier
     * as ASCII or UTF-8 including the terminating NUL.
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
     * EXIF 3.0 §4.6.5.4.3 (Model) defines the model name or number as an ASCII
     * or UTF-8 string with the NUL terminator counted in the tag length.
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
     * EXIF 3.0 §4.6.6.9.6 stores the lens model as an ASCII or UTF-8 string.
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
     * EXIF 3.0 §4.6.6.9.5 records LensMake as an ASCII or UTF-8 identifier and
     * expects it to remain stable once captured.
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
     * EXIF 3.0 §4.6.6.9.2 allows ASCII or UTF-8 text for CameraOwnerName and
     * expects Artist to be populated alongside it.
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
     * EXIF 3.0 §4.6.6.9.3 stores the camera body serial as an ASCII string.
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
     * EXIF 3.0 §4.6.6.9.7 defines LensSerialNumber as a free-form ASCII value
     * that should remain stable across edits.
     *
     * @return string|null
     */
    public function lensSerialNumber(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::LENS_SERIAL_NUMBER);
    }

    /**
     * Returns the lens specification describing focal and aperture range.
     *
     * EXIF 3.0 §4.6.6.9.4 stores four RATIONALs: minimum
     * focal length, maximum focal length, minimum F-number at the minimum focal
     * length, and minimum F-number at the maximum focal length. Unknown
     * apertures are recorded as 0/0.
     *
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public function lensSpecification(): ?array
    {
        $values = $this->rationalList($this->exifIfd, ExifTag::LENS_SPECIFICATION);

        if (!is_array($values) || count($values) !== 4) {
            return null;
        }

        return [
            $values[0],
            $values[1],
            $values[2],
            $values[3],
        ];
    }

    /**
     * Returns the EXIF orientation enumeration.
     *
     * TIFF 6.0 §8 and EXIF 3.0 §4.6.5.1.6 specify default value 1 (top-left) when not present.
     *
     * @return Orientation
     */
    public function orientation(): Orientation
    {
        $rawOrientation = $this->enumValue($this->ifd0, ExifTag::ORIENTATION);

        // Normalises numeric-string encodings emitted by some cameras.
        $orientation = Orientation::fromExifValue($rawOrientation);

        // TIFF 6.0 §8: Default is 1 (top-left) when tag is not present
        return $orientation ?? Orientation::TOP_LEFT;
    }

    /**
     * Returns the orientation as a human-readable rotation description.
     *
     * EXIF 3.0 §4.6.5.1.6 defines eight orientation states. This method
     * returns descriptions like "Rotate 180", "Rotate 90 CW", or
     * "Mirror horizontal" as commonly displayed by ExifTool.
     */
    public function orientationDescription(): string
    {
        return $this->orientation()->rotationDescription();
    }

    /**
     * Returns the image width, preferring the compressed-specific EXIF tag when applicable.
     *
     * Prefers PixelXDimension from the Exif IFD when present (EXIF 3.0
     * §4.6.6.3.1), falling back to ImageWidth from IFD0 (TIFF 6.0 §8).
     *
     * PixelXDimension is skipped only when the Compression tag is
     * explicitly set to UNCOMPRESSED. When the tag is absent (valid for
     * JPEG primary images per EXIF 3.0 §4.6.5.1.4), PixelXDimension
     * takes priority so the defaulted UNCOMPRESSED value does not
     * suppress dimension tags that are actually present.
     *
     * @return int|null
     */
    public function imageWidth(): ?int
    {
        $explicitlyUncompressed = $this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry
            && $this->compression() === Compression::UNCOMPRESSED;

        if (!$explicitlyUncompressed) {
            $pixelWidth = $this->int($this->exifIfd, ExifTag::PIXEL_X_DIMENSION);
            if ($pixelWidth !== null) {
                return $pixelWidth;
            }
        }

        return $this->int($this->ifd0, ExifTag::IMAGE_WIDTH);
    }

    /**
     * Returns the image height, preferring the compressed-specific EXIF tag when applicable.
     *
     * Prefers PixelYDimension from the Exif IFD when present (EXIF 3.0
     * §4.6.6.3.2), falling back to ImageLength from IFD0 (TIFF 6.0 §8).
     *
     * @return int|null
     */
    public function imageHeight(): ?int
    {
        $explicitlyUncompressed = $this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry
            && $this->compression() === Compression::UNCOMPRESSED;

        if (!$explicitlyUncompressed) {
            $pixelHeight = $this->int($this->exifIfd, ExifTag::PIXEL_Y_DIMENSION);
            if ($pixelHeight !== null) {
                return $pixelHeight;
            }
        }

        return $this->int($this->ifd0, ExifTag::IMAGE_LENGTH);
    }

    /**
     * Returns the colour space enumeration if present.
     *
     * EXIF 3.0 §4.6.6.2.1 (ColorSpace)
     */
    public function colorSpace(): ?ColorSpace
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::COLOR_SPACE);

        return ColorSpace::fromExifValue($value);
    }

    /**
     * Returns the image unique identifier if present.
     *
     * EXIF 3.0 §4.6.6.9.1 records a 128-bit UUID in
     * hexadecimal ASCII with a fixed count of 33 (including the terminator).
     * Version 4 UUIDs are recommended and the value should remain immutable.
     *
     * @return string|null
     */
    public function imageUniqueId(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::IMAGE_UNIQUE_ID);

        // EXIF 3.0 §4.6.6.9.1: ImageUniqueID is a 128-bit UUID encoded as
        // 32 hexadecimal ASCII characters. Reject non-conformant values.
        if (($value === null) || (preg_match('/\A[0-9a-fA-F]{32}\z/', $value) !== 1)) {
            return null;
        }

        return $value;
    }

    /**
     * Returns the normalised EXIF version string when present.
     *
     * EXIF 3.0 §4.6.6.1.1 (ExifVersion) treats a missing tag as non-conformance.
     */
    public function exifVersion(): ?string
    {
        return $this->exifVersion;
    }

    /**
     * Returns the normalised FlashPix version string when present.
     *
     * EXIF 3.0 §4.6.6.1.2 (FlashpixVersion) limits this field to four ASCII digits.
     */
    public function flashpixVersion(): ?string
    {
        $value = $this->rawString($this->exifIfd, ExifTag::FLASHPIX_VERSION);

        if ($value === null) {
            return '1.00';
        }

        return ValueConverters::toExifVersion($value);
    }

    /**
     * Returns the DNG version encoded in IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, DNGVersion): BYTE[4], required in IFD0.
     */
    public function dngVersion(): ?string
    {
        return $this->dngVersionTag($this->ifd0, DngTag::DNG_VERSION);
    }

    /**
     * Returns the backward-compatibility DNG version encoded in IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, DNGBackwardVersion): BYTE[4], required in IFD0.
     */
    public function dngBackwardVersion(): ?string
    {
        return $this->dngVersionTag($this->ifd0, DngTag::DNG_BACKWARD_VERSION);
    }

    /**
     * Returns the non-localized unique DNG camera model from IFD0 when present.
     *
     * DNG 1.7.1.0 (DNG Tags, UniqueCameraModel): ASCII, NUL-terminated.
     */
    public function uniqueCameraModel(): ?string
    {
        return $this->str($this->ifd0, DngTag::UNIQUE_CAMERA_MODEL);
    }

    /**
     * Returns the localized DNG camera model from IFD0.
     *
     * DNG 1.7.1.0 (DNG Tags, LocalizedCameraModel): ASCII or BYTE, NUL-terminated UTF-8.
     * Default: same as UniqueCameraModel when absent.
     */
    public function localizedCameraModel(): ?string
    {
        return $this->str($this->ifd0, DngTag::LOCALIZED_CAMERA_MODEL)
            ?? $this->str($this->ifd0, DngTag::UNIQUE_CAMERA_MODEL);
    }

    /**
     * Returns the derived EXIF capability profile identifier.
     */
    public function exifProfile(): string
    {
        return $this->exifProfile;
    }

    /**
     * Returns the optional image title string.
     *
     * EXIF 3.0 §4.6.6.9.8 allows ASCII or UTF-8 text for ImageTitle and
     * treats blank fields as unknown.
     */
    public function imageTitle(): ?string
    {
        $value = $this->str($this->ifd0, ExifTag::IMAGE_TITLE);

        if ($value !== null) {
            return $value;
        }

        $value = $this->str($this->exifIfd, ExifTag::IMAGE_TITLE);

        return $value ?? $this->str(
            $this->ifd0,
            ExifTag::IMAGE_DESCRIPTION
        );
    }

    /**
     * Returns the document name preferring EXIF 3.0 tags with XP fallbacks.
     */
    public function documentName(): ?string
    {
        $candidates = [
            [$this->ifd0, TiffTag::DOCUMENT_NAME],
            [$this->exifIfd, TiffTag::DOCUMENT_NAME],
            [$this->ifd0, ExifTag::IMAGE_TITLE],
            [$this->exifIfd, ExifTag::IMAGE_TITLE],
        ];

        foreach ($candidates as [$ifd, $tag]) {
            $value = $this->str($ifd, $tag);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Returns the EXIF image description when available.
     *
     * EXIF 3.0 §4.6.5.4.1 (ImageDescription) defines a free-form ASCII or UTF-8
     * description of the image content with the NUL terminator included in the stored count.
     */
    public function imageDescription(): ?string
    {
        return $this->str($this->ifd0, ExifTag::IMAGE_DESCRIPTION);
    }

    /**
     * Returns the legacy host computer string retained for pre-EXIF 3.0 metadata.
     */
    public function hostComputer(): ?string
    {
        return $this->str($this->ifd0, TiffTag::HOST_COMPUTER);
    }

    /**
     * Returns the software or firmware identifier reported by the image source.
     *
     * EXIF 3.0 §4.6.5.4.4 (Software) recommends recording the generating software
     * name and version in ASCII or UTF-8 with the terminating NUL accounted for in the count.
     */
    public function software(): ?string
    {
        return $this->str($this->ifd0, ExifTag::SOFTWARE);
    }

    /**
     * Returns the photographer name if present.
     *
     * EXIF 3.0 §4.6.6.9.9 recommends keeping the photographer attribution stable
     * and recording Artist alongside it.
     */
    public function photographer(): ?string
    {
        $value = $this->str($this->ifd0, ExifTag::PHOTOGRAPHER);

        if ($value !== null) {
            return $value;
        }

        $value = $this->str($this->exifIfd, ExifTag::PHOTOGRAPHER);

        return $value ?? $this->str(
            $this->ifd0,
            ExifTag::ARTIST
        );
    }

    /**
     * Returns the image editor attribution if present.
     *
     * EXIF 3.0 §4.6.6.9.10 captures the primary editor name and expects Artist to
     * be recorded when this tag is present.
     */
    public function imageEditor(): ?string
    {
        $value = $this->str($this->ifd0, ExifTag::IMAGE_EDITOR);

        return $value ?? $this->str(
            $this->exifIfd,
            ExifTag::IMAGE_EDITOR
        );
    }

    /**
     * Returns the tile width defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileWidth for tiled image storage.
     * For thumbnail tile width, use thumbnailTileWidth().
     */
    public function tileWidth(): ?int
    {
        return $this->int($this->ifd0, TiffTag::TILE_WIDTH);
    }

    /**
     * Returns the tile length defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileLength for tiled image storage.
     * For thumbnail tile length, use thumbnailTileLength().
     */
    public function tileLength(): ?int
    {
        return $this->int($this->ifd0, TiffTag::TILE_LENGTH);
    }

    /**
     * Returns the tile offsets defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileOffsets for tiled image storage.
     * For thumbnail tile offsets, use thumbnailTileOffsets().
     *
     * @return list<int>|null
     */
    public function tileOffsets(): ?array
    {
        return $this->numericList($this->ifd0, TiffTag::TILE_OFFSETS);
    }

    /**
     * Returns the tile byte counts defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileByteCounts for tiled image storage.
     * For thumbnail tile byte counts, use thumbnailTileByteCounts().
     *
     * @return list<int>|null
     */
    public function tileByteCounts(): ?array
    {
        return $this->numericList($this->ifd0, TiffTag::TILE_BYTE_COUNTS);
    }

    /**
     * Returns the strip offsets defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.2.1 defines StripOffsets for strip-based image storage and
     * requires the tag to be omitted for JPEG-compressed primary images.
     * For thumbnail strip offsets, use thumbnailStripOffsets().
     *
     * @return list<int>|null
     */
    public function stripOffsets(): ?array
    {
        if ($this->isJpegCompression($this->compression())) {
            return null;
        }

        return $this->numericList($this->ifd0, ExifTag::STRIP_OFFSETS);
    }

    /**
     * Returns the strip byte counts for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.5.2.3 defines StripByteCounts for strip-based image storage and
     * requires the tag to be omitted for JPEG-compressed primary images.
     * For thumbnail strip byte counts, use thumbnailStripByteCounts().
     *
     * @return list<int>|null
     */
    public function stripByteCounts(): ?array
    {
        if ($this->isJpegCompression($this->compression())) {
            return null;
        }

        return $this->numericList($this->ifd0, ExifTag::STRIP_BYTE_COUNTS);
    }

    /**
     * Returns the transfer function lookup table when available.
     *
     * EXIF 3.0 §4.6.5.3.1 defines TransferFunction as a 3×256 table of SHORT values
     * describing the tone reproduction curve.
     *
     * @return list<int>|null
     */
    public function transferFunction(): ?array
    {
        $values = $this->numericList($this->ifd0, ExifTag::TRANSFER_FUNCTION);

        if ($values === null) {
            return null;
        }

        if (count($values) !== 3 * 256) {
            return null;
        }

        return $values;
    }

    /**
     * Indicates whether a JPEG thumbnail is referenced by the EXIF structure.
     *
     * EXIF 3.0 §4.6.5.1.6 describes the JPEG thumbnail tags and requires both
     * offset and length to be populated for a valid embedded thumbnail.
     * EXIF 3.0 §4.6.5.1.4 requires Compression value 6 (JPEG) for JPEG thumbnails.
     */
    public function hasThumbnail(): bool
    {
        $compression = $this->thumbnailCompression();
        $offset      = $this->thumbnailJpegInterchangeFormat();
        $length      = $this->thumbnailJpegInterchangeFormatLength();

        if ($compression !== Compression::JPEG) {
            return false;
        }

        if ($offset === null || $length === null) {
            return false;
        }

        return $length > 0;
    }

    /**
     * Returns the JPEG thumbnail offset from the dedicated thumbnail IFD (IFD1).
     *
     * EXIF 3.0 §4.6.5.2.4 documents JPEGInterchangeFormat as the byte offset to embedded
     * JPEG thumbnails stored in IFD1 (the first IFD after IFD0).
     */
    public function thumbnailJpegInterchangeFormat(): ?int
    {
        return $this->int($this->ifd1, ExifTag::JPEG_INTERCHANGE_FORMAT);
    }

    /**
     * Returns the JPEG thumbnail byte length from the dedicated thumbnail IFD (IFD1).
     *
     * EXIF 3.0 §4.6.5.1.6 (Table 3) defines JPEGInterchangeFormatLength as the size in bytes
     * of the JPEG thumbnail stream in IFD1.
     */
    public function thumbnailJpegInterchangeFormatLength(): ?int
    {
        return $this->int($this->ifd1, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);
    }

    /**
     * Returns the compression enum describing the JPEG thumbnail stored in IFD1.
     *
     * EXIF 3.0 §4.6.5.1.4 defines Compression value 6 to designate JPEG-compressed
     * thumbnails stored in IFD1.
     */
    public function thumbnailCompression(): ?Compression
    {
        return Compression::fromExifValue($this->enumValue($this->ifd1, ExifTag::COMPRESSION));
    }

    /**
     * Returns the tile width defined for the thumbnail image data (IFD1).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileWidth for tiled image storage.
     */
    public function thumbnailTileWidth(): ?int
    {
        return $this->int($this->ifd1, TiffTag::TILE_WIDTH);
    }

    /**
     * Returns the tile length defined for the thumbnail image data (IFD1).
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileLength for tiled image storage.
     */
    public function thumbnailTileLength(): ?int
    {
        return $this->int($this->ifd1, TiffTag::TILE_LENGTH);
    }

    /**
     * Returns the tile offsets for the thumbnail image when stored using TIFF tiles.
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileOffsets for tiled image storage.
     *
     * @return list<int>|null
     */
    public function thumbnailTileOffsets(): ?array
    {
        return $this->numericList($this->ifd1, TiffTag::TILE_OFFSETS);
    }

    /**
     * Returns the tile byte counts for the thumbnail image when stored using TIFF tiles.
     *
     * EXIF 3.0 §4.6.5.1.6 and TIFF 6.0 §15 define TileByteCounts for tiled image storage.
     *
     * @return list<int>|null
     */
    public function thumbnailTileByteCounts(): ?array
    {
        return $this->numericList($this->ifd1, TiffTag::TILE_BYTE_COUNTS);
    }

    /**
     * Returns the strip offsets for the thumbnail image when stored using TIFF strips.
     *
     * EXIF 3.0 §4.6.5.2.1 defines StripOffsets for strip-based image storage and requires
     * the tag to be omitted for JPEG-compressed data.
     *
     * @return list<int>|null
     */
    public function thumbnailStripOffsets(): ?array
    {
        if ($this->isJpegCompression($this->thumbnailCompression())) {
            return null;
        }

        return $this->numericList($this->ifd1, ExifTag::STRIP_OFFSETS);
    }

    /**
     * Returns the strip byte counts for the thumbnail image when stored using TIFF strips.
     *
     * EXIF 3.0 §4.6.5.2.3 defines StripByteCounts for strip-based image storage and requires
     * the tag to be omitted for JPEG-compressed data.
     *
     * @return list<int>|null
     */
    public function thumbnailStripByteCounts(): ?array
    {
        if ($this->isJpegCompression($this->thumbnailCompression())) {
            return null;
        }

        return $this->numericList($this->ifd1, ExifTag::STRIP_BYTE_COUNTS);
    }

    /**
     * Returns the reference black and white point values as floating point numbers.
     *
     * EXIF 3.0 §4.6.5.3.5 describes defaults when the colour space is declared.
     *
     * @return list<float>|null
     */
    public function referenceBlackWhite(): ?array
    {
        $values = $this->rationalList($this->ifd0, ExifTag::REFERENCE_BLACK_WHITE);

        if ($values !== null) {
            return $this->normaliseReferenceBlackWhite($values);
        }

        return $this->defaultReferenceBlackWhite();
    }

    /**
     * Returns the copyright notice string when present.
     *
     * EXIF 3.0 §4.6.5.4.7 represents empty or blank-filled copyright fields as unknown values.
     */
    public function copyright(): ?string
    {
        return $this->str($this->ifd0, ExifTag::COPYRIGHT);
    }

    /**
     * Returns the components configuration array when present.
     *
     * EXIF 3.0 §4.6.6.3.3 describes the four-byte component order for compressed image data.
     *
     * @return list<int>|null
     */
    public function componentsConfiguration(): ?array
    {
        $value = $this->componentsInput($this->exifIfd, ExifTag::COMPONENTS_CONFIGURATION);

        return ValueConverters::componentsConfiguration($value);
    }

    /**
     * Returns the component configuration labels in human readable form.
     *
     * EXIF 3.0 §4.6.6.3.3 documents the channel identifiers for compressed data streams.
     *
     * @return list<string>|null
     */
    public function componentsConfigurationLabels(): ?array
    {
        $value = $this->componentsInput($this->exifIfd, ExifTag::COMPONENTS_CONFIGURATION);

        return ValueConverters::componentsConfigurationLabels($value);
    }

    /**
     * Returns the component configuration as a formatted string.
     */
    public function componentsConfigurationDescription(): ?string
    {
        $value = $this->componentsInput($this->exifIfd, ExifTag::COMPONENTS_CONFIGURATION);

        return ValueConverters::componentsConfigurationDescription($value);
    }

    /**
     * Returns the compressed bits per pixel ratio.
     *
     * EXIF 3.0 §4.6.6.3.4 defines this rational value for compressed imagery to indicate
     * the effective compression mode.
     */
    public function compressedBitsPerPixel(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::COMPRESSED_BITS_PER_PIXEL);
    }

    /**
     * Returns the user comment string after decoding the EXIF prefix.
     *
     * EXIF 3.0 §4.6.6.4.2 defines the multicode-compatible prefix (see §4.6.4) that annotates
     * the UserComment character code.
     */
    public function userComment(): ?string
    {
        $raw = $this->rawUserComment();

        return $raw !== null ? $this->decodeUserComment($raw) : null;
    }

    /**
     * Returns the encoding declared in the EXIF user comment prefix.
     *
     * EXIF 3.0 §4.6.4 requires UNDEFINED text fields to include an 8-byte
     * character code area. Payloads shorter than 8 bytes are non-conformant.
     */
    public function userCommentEncoding(): ?string
    {
        $raw = $this->rawUserComment();
        if ($raw === null || strlen($raw) < 8) {
            return null;
        }

        $prefix            = substr($raw, 0, 8);
        $canonicalEncoding = $this->canonicalUserCommentMarker($prefix);

        if ($canonicalEncoding === '') {
            return null;
        }

        $content    = substr($raw, 8);
        $hasContent = trim($content, "\0 ") !== '';

        return $hasContent ? $canonicalEncoding : null;
    }

    /**
     * Provides the declared user comment encoding falling back to content inference
     * when the 8-byte prefix is present but denotes UNDEFINED encoding.
     *
     * EXIF 3.0 §4.6.4 requires the 8-byte character code area to be present.
     */
    public function userCommentEncodingBestEffort(): ?string
    {
        $encoding = $this->userCommentEncoding();
        if ($encoding !== null) {
            return $encoding;
        }

        $raw = $this->rawUserComment();
        if ($raw === null || strlen($raw) < 8) {
            return null;
        }

        $prefix            = substr($raw, 0, 8);
        $canonicalEncoding = $this->canonicalUserCommentMarker($prefix);

        if ($canonicalEncoding === '') {
            return null;
        }

        $content = substr($raw, 8);

        return $this->inferUserCommentEncoding($content);
    }

    /**
     * Normalises known EXIF user comment markers to their canonical identifiers.
     */
    private function canonicalUserCommentMarker(string $prefix): string
    {
        return UndefinedTextMarker::canonicalMarkerFromPrefix($prefix);
    }

    /**
     * Returns the spectral sensitivity description.
     *
     * EXIF 3.0 §4.6.6.7.4 (SpectralSensitivity)
     */
    public function spectralSensitivity(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::SPECTRAL_SENSITIVITY);
    }

    /**
     * Returns the opto-electronic conversion function data.
     *
     * EXIF 3.0 §4.6.6.7.6 (Figure 16, Table 11) describes the relationship between
     * the camera's optical input and the image file values.
     */
    public function oecf(): ?Oecf
    {
        $payload = $this->oecfPayload();
        if ($payload === null) {
            return null;
        }

        $matrix = ValueConverters::decodeOecf($payload, $this->byteOrder);

        return Oecf::fromMatrix($matrix);
    }

    /**
     * Returns the raw opto-electronic conversion function payload.
     */
    public function oecfPayload(): ?string
    {
        return $this->rawString($this->exifIfd, ExifTag::OECF);
    }

    /**
     * Returns the declared EXIF sensitivity type as defined by EXIF 3.0 §4.6.6.7.7 Table 14.
     *
     * Signals which ISO 12232 parameter the PhotographicSensitivity tag represents.
     */
    public function sensitivityType(): ?SensitivityType
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::SENSITIVITY_TYPE);

        if ($value === null) {
            return null;
        }

        return SensitivityType::fromExifValue($value);
    }

    /**
     * Returns the standard output sensitivity (SOS) value recorded for the capture.
     *
     * EXIF 3.0 §4.6.6.7.8
     */
    public function standardOutputSensitivity(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::STANDARD_OUTPUT_SENSITIVITY);
    }

    /**
     * Returns the recommended exposure index (REI) value recorded for the capture.
     *
     * EXIF 3.0 §4.6.6.7.9
     */
    public function recommendedExposureIndex(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::RECOMMENDED_EXPOSURE_INDEX);
    }

    /**
     * Returns the ISO speed value when provided separately from photographic sensitivity.
     *
     * EXIF 3.0 §4.6.6.7.10
     */
    public function isoSpeedValue(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::ISO_SPEED);
    }

    /**
     * Returns the ISO sensitivity value if present.
     *
     * EXIF 3.0 §4.6.6.7.7 Table 14 defines how SensitivityType maps the
     * PhotographicSensitivity tag to ISO 12232 parameters and combinations.
     * When declared, the photographic sensitivity value must be prioritised for
     * the selected parameter(s) before falling back to legacy individual tags.
     *
     * @return int|null
     */
    public function iso(): ?int
    {
        $sensitivityType = $this->sensitivityType();
        if ($sensitivityType instanceof SensitivityType) {
            foreach ($this->sensitivityTagPriority($sensitivityType) as $tag) {
                $value = $this->int($this->exifIfd, $tag);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        $candidates = [
            [$this->exifIfd, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->exifIfd, ExifTag::ISO_SPEED],
            [$this->exifIfd, ExifTag::STANDARD_OUTPUT_SENSITIVITY],
            [$this->exifIfd, ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            [$this->exifIfd, ExifTag::EXPOSURE_INDEX],
            [$this->ifd0, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->ifd0, ExifTag::ISO_SPEED],
        ];

        foreach ($candidates as [$ifd, $tag]) {
            $value = $this->int($ifd, $tag);
            if ($value !== null) {
                return $value;
            }
        }

        $fallbackTags = [
            ExifTag::STANDARD_OUTPUT_SENSITIVITY,
            ExifTag::RECOMMENDED_EXPOSURE_INDEX,
            ExifTag::PHOTOGRAPHIC_SENSITIVITY,
            ExifTag::ISO_SPEED,
            ExifTag::EXPOSURE_INDEX,
        ];

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            foreach ($fallbackTags as $tag) {
                $value = $this->int($ifd, $tag);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Returns the ISO sensitivity using a broader set of fallbacks for non-standard encodings.
     */
    public function isoBestEffort(): ?int
    {
        $iso = $this->iso();
        if ($iso !== null) {
            return $iso;
        }

        $tagPriority = [
            ExifTag::STANDARD_OUTPUT_SENSITIVITY,
            ExifTag::RECOMMENDED_EXPOSURE_INDEX,
            ExifTag::ISO_SPEED,
            ExifTag::PHOTOGRAPHIC_SENSITIVITY,
            ExifTag::EXPOSURE_INDEX,
        ];

        $fallbacks = [
            [$this->exifIfd, ExifTag::STANDARD_OUTPUT_SENSITIVITY],
            [$this->exifIfd, ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            [$this->exifIfd, ExifTag::ISO_SPEED],
            [$this->exifIfd, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->ifd0, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->ifd0, ExifTag::ISO_SPEED],
            [$this->ifd1, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->ifd1, ExifTag::ISO_SPEED],
            [$this->exifIfd, ExifTag::EXPOSURE_INDEX],
            [$this->ifd0, ExifTag::EXPOSURE_INDEX],
            [$this->ifd1, ExifTag::EXPOSURE_INDEX],
        ];

        foreach ($fallbacks as [$ifd, $tag]) {
            $value = $this->coerceIntValue($this->value($ifd, $tag));
            if ($value !== null) {
                return $value;
            }
        }

        foreach ($this->subIfds as $subIfd) {
            foreach ($tagPriority as $tag) {
                $value = $this->coerceIntValue($this->value($subIfd, $tag));
                if ($value !== null) {
                    return $value;
                }
            }
        }

        if ($this->subsequentIfds !== []) {
            $additionalTags = [
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::ISO_SPEED,
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::EXPOSURE_INDEX,
            ];

            foreach ($this->subsequentIfds as $ifd) {
                if (($this->ifd1 instanceof Ifd) && ($ifd === $this->ifd1)) {
                    continue;
                }

                foreach ($additionalTags as $tag) {
                    $value = $this->coerceIntValue($this->value($ifd, $tag));
                    if ($value !== null) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Maps the EXIF sensitivity type enumeration to ISO-related tag priorities.
     *
     * @return list<int>
     */
    private function sensitivityTagPriority(SensitivityType $type): array
    {
        return match ($type) {
            SensitivityType::STANDARD_OUTPUT_SENSITIVITY => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
            ],
            SensitivityType::RECOMMENDED_EXPOSURE_INDEX => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
            ],
            SensitivityType::ISO_SPEED => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::SOS_AND_REI => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
            ],
            SensitivityType::SOS_AND_ISO => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::REI_AND_ISO => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::SOS_AND_REI_AND_ISO => [
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::EXPOSURE_INDEX,
                ExifTag::ISO_SPEED,
            ],
            SensitivityType::UNKNOWN => [],
        };
    }

    /**
     * Returns the ISO latitude yyy value when present and paired with ISOSpeed and ISOSpeedLatitudezzz.
     *
     * EXIF 3.0 §4.6.6.7.11
     */
    public function isoSpeedLatitudeYyy(): ?int
    {
        $latitudeYyy = $this->int($this->exifIfd, ExifTag::ISO_SPEED_LATITUDE_YYY);

        if ($latitudeYyy === null) {
            return null;
        }

        if ($this->isoSpeedValue() === null) {
            return null;
        }

        if ($this->isoSpeedLatitudeZzz() === null) {
            return null;
        }

        return $latitudeYyy;
    }

    /**
     * Returns the ISO latitude zzz value when present.
     *
     * EXIF 3.0 §4.6.6.7.12 (ISOSpeedLatitudezzz)
     */
    public function isoSpeedLatitudeZzz(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::ISO_SPEED_LATITUDE_ZZZ);
    }

    /**
     * Returns the exposure time in seconds if available.
     *
     * EXIF 3.0 §4.6.6.7.1 (ExposureTime)
     *
     * @return float|null
     */
    public function exposureTime(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::EXPOSURE_TIME);
    }

    /**
     * Returns the exposure time as a human-readable string like "1/50".
     *
     * EXIF 3.0 §4.6.6.7.1 (ExposureTime) stores exposure as RATIONAL seconds.
     * Formats short exposures as fractions and longer exposures as decimal seconds.
     */
    public function exposureTimeFormatted(): ?string
    {
        $seconds = $this->exposureTime();

        return ValueConverters::formatExposureTime($seconds);
    }

    /**
     * Returns the APEX shutter speed value when available.
     *
     * EXIF 3.0 §4.6.6.7.13 (ShutterSpeedValue)
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
        $raw = $this->normalisedValue($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);

        if ($raw === null) {
            return null;
        }

        return ValueConverters::apexShutterSpeedToSeconds($raw);
    }

    /**
     * Returns the APEX shutter speed as a human-readable string like "1/20".
     *
     * EXIF 3.0 §4.6.6.7.13 (ShutterSpeedValue) stores APEX shutter speed.
     * This converts the APEX value to a fraction or decimal seconds format.
     */
    public function shutterSpeedFormatted(): ?string
    {
        $raw = $this->normalisedValue($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);

        if ($raw === null) {
            return null;
        }

        return ValueConverters::formatShutterSpeedFromApex($raw);
    }

    /**
     * Returns the aperture (f-number) if available.
     *
     * EXIF 3.0 §4.6.6.7.2 (FNumber)
     *
     * @return float|null
     */
    public function fNumber(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::F_NUMBER);
    }

    /**
     * Returns the APEX aperture value when present.
     *
     * EXIF 3.0 §4.6.6.7.14 (ApertureValue)
     */
    public function apertureValue(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::APERTURE_VALUE);
    }

    /**
     * Returns the APEX aperture value as a human-readable f-number string like "f/1.9".
     *
     * EXIF 3.0 §4.6.6.7.14 (ApertureValue) stores APEX aperture.
     * This converts the APEX value to an f-number display format.
     */
    public function apertureValueFormatted(): ?string
    {
        $raw = $this->normalisedValue($this->exifIfd, ExifTag::APERTURE_VALUE);

        if ($raw === null) {
            return null;
        }

        return ValueConverters::formatApertureFromApex($raw);
    }

    /**
     * Returns the focal length in millimetres if available.
     *
     * EXIF 3.0 §4.6.6.7.23 (FocalLength)
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
     * Returns the camera exposure program enumeration if present.
     *
     * EXIF 3.0 §4.6.6.7.3 (ExposureProgram)
     */
    public function exposureProgram(): ?ExposureProgram
    {
        // EXIF 3.0 §4.6.6.7.3: default is 0 (Not defined).
        $value = $this->enumValue($this->exifIfd, ExifTag::EXPOSURE_PROGRAM) ?? 0;

        return ExposureProgram::fromExifValue($value);
    }

    /**
     * Returns the metering mode enumeration if present.
     *
     * EXIF 3.0 §4.6.6.7.19 (MeteringMode) catalogue of camera metering algorithms.
     *
     * @return MeteringMode|null
     */
    public function meteringMode(): ?MeteringMode
    {
        // EXIF 3.0 §4.6.6.7.19: default is 0 (Unknown).
        $rawMeteringMode = $this->enumValue($this->exifIfd, ExifTag::METERING_MODE) ?? 0;

        return MeteringMode::fromExifValue($rawMeteringMode);
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
     * Returns the decoded flash information value object when present.
     *
     * EXIF 3.0 §4.6.6.7.21 (Flash) defines the bit field decoded into
     * fired state, return status, mode, flash-function flag, and red-eye mode.
     */
    public function flashInfo(): ?FlashInfo
    {
        return ValueConverters::flashFromShort($this->flash());
    }

    /**
     * Returns the flash energy in beam candle power seconds when available.
     *
     * EXIF 3.0 §4.6.6.7.24 (FlashEnergy)
     *
     * @return float|null
     */
    public function flashEnergy(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FLASH_ENERGY);
    }

    /**
     * Returns the white balance enumeration if present.
     *
     * EXIF 3.0 §4.6.6.7.37 (WhiteBalance)
     */
    public function whiteBalance(): ?WhiteBalance
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::WHITE_BALANCE);

        return WhiteBalance::fromExifValue($value);
    }

    /**
     * Returns the exposure bias value in EV if present.
     *
     * EXIF 3.0 §4.6.6.7.16 (ExposureBiasValue)
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
     * EXIF 3.0 §4.6.6.7.15 (BrightnessValue)
     *
     * @return float|null
     */
    public function brightnessValue(): ?float
    {
        $value = $this->normalisedValue($this->exifIfd, ExifTag::BRIGHTNESS_VALUE);

        if ($this->isUnknownBrightness($value)) {
            return null;
        }

        return ValueConverters::rationalToFloat($value);
    }

    /**
     * Returns the APEX brightness value as a human-readable decimal string.
     *
     * EXIF 3.0 §4.6.6.7.15 (BrightnessValue) stores APEX brightness.
     * This converts the APEX value to a simple decimal format like "-2.21".
     */
    public function brightnessValueFormatted(): ?string
    {
        $value = $this->normalisedValue($this->exifIfd, ExifTag::BRIGHTNESS_VALUE);

        if ($this->isUnknownBrightness($value)) {
            return null;
        }

        return ValueConverters::formatBrightnessValue($value);
    }

    /**
     * Returns the maximum aperture value (APEX) if present.
     *
     * EXIF 3.0 §4.6.6.7.17 (MaxApertureValue) encodes a single RATIONAL representing
     * the lens's smallest F number expressed as an APEX value.
     *
     * @return float|null
     */
    public function maxApertureApex(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::MAX_APERTURE_VALUE);
    }

    /**
     * Returns the focal plane X resolution.
     *
     * EXIF 3.0 §4.6.6.7.26 defines this as the number of pixels in the image
     * width per {@see ExifTag::FOCAL_PLANE_RESOLUTION_UNIT} on the camera
     * focal plane. The value refers to the primary image rather than the
     * physical sensor grid.
     */
    public function focalPlaneXResolution(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FOCAL_PLANE_X_RESOLUTION);
    }

    /**
     * Returns the focal plane Y resolution.
     *
     * EXIF 3.0 §4.6.6.7.27 records the number of pixels in the image height per
     * {@see ExifTag::FOCAL_PLANE_RESOLUTION_UNIT} on the camera focal plane,
     * aligned with the primary image output.
     */
    public function focalPlaneYResolution(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FOCAL_PLANE_Y_RESOLUTION);
    }

    /**
     * Returns the focal plane resolution unit.
     *
     * EXIF 3.0 §4.6.6.7.28 reuses the {@see ResolutionUnit} scale for focal
     * plane resolution values.
     */
    public function focalPlaneResolutionUnit(): int
    {
        // EXIF 3.0 §4.6.6.7.28: default is 2 (inches).
        return $this->int($this->exifIfd, ExifTag::FOCAL_PLANE_RESOLUTION_UNIT) ?? 2;
    }

    /**
     * Returns the subject location coordinates when supplied.
     *
     * EXIF 3.0 §4.6.6.7.29 stores the unrotated centre pixel of the main
     * subject as (X, Y) relative to the upper-left corner. The tag always
     * contains exactly two SHORT values.
     *
     * @return list<int>|null
     */
    public function subjectLocation(): ?array
    {
        $coordinates = $this->numericList($this->exifIfd, ExifTag::SUBJECT_LOCATION);

        if ($coordinates === null || count($coordinates) !== 2) {
            return null;
        }

        return [
            0 => $coordinates[0],
            1 => $coordinates[1],
        ];
    }

    /**
     * Returns the exposure index value.
     *
     * EXIF 3.0 §4.6.6.7.30 (ExposureIndex)
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
     * Returns the decoded spatial frequency response table.
     *
     * EXIF 3.0 §4.6.3 Table 16: SFR records camera and optical system's spatial frequency
     * response characteristics.
     */
    public function spatialFrequencyResponse(): ?SpatialFrequencyResponse
    {
        $payload = $this->rawString($this->exifIfd, ExifTag::SPATIAL_FREQUENCY_RESPONSE);
        $matrix  = ValueConverters::decodeSpatialFrequencyResponse($payload, $this->byteOrder);

        return SpatialFrequencyResponse::fromMatrix($matrix);
    }

    /**
     * Returns the composite image classification when available.
     *
     * EXIF 3.0 §4.6.6.7.47 defines the CompositeImage tag with four enumerated
     * states, reserving all others.
     */
    public function compositeImage(): ?CompositeImage
    {
        $value = $this->int($this->exifIfd, ExifTag::COMPOSITE_IMAGE);

        return $value !== null ? CompositeImage::fromExifValue($value) : null;
    }

    /**
     * Returns the number of source images contributing to the composite result.
     *
     * EXIF 3.0 §4.6.6.7.48 records both the total number of captured source images
     * and how many were actually used to assemble the
     * composite. Figure 24 requires two SHORT values where both counters are at
     * least two and the used count cannot exceed the captured total.
     *
     * @return array{0:int,1:int}|null
     */
    public function sourceImageNumberOfCompositeImage(): ?array
    {
        $values = $this->numericList($this->exifIfd, ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE);

        if (($values === null) || (count($values) !== 2)) {
            return null;
        }

        [$capturedCount, $usedCount] = $values;

        if (($capturedCount < 2) || ($usedCount < 2)) {
            return null;
        }

        if ($usedCount > $capturedCount) {
            return null;
        }

        return [$capturedCount, $usedCount];
    }

    /**
     * Decodes the SourceExposureTimesOfCompositeImage payload.
     *
     * EXIF 3.0 §4.6.6.7.49 Figure 25 stores eight summary RATIONAL values
     * followed by one or more sequences of SHORT counts and RATIONAL exposure
     * times representing the contributing source images.
     */
    public function sourceExposureTimesOfCompositeImage(): ?SourceExposureTimes
    {
        $payload = $this->rawString($this->exifIfd, ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE);

        if ($payload === null || $payload === '') {
            return null;
        }

        return $this->decodeSourceExposureTimes($payload);
    }

    /**
     * Parses the binary layout defined for SourceExposureTimesOfCompositeImage.
     *
     * @param string $payload Raw tag payload stored as an UNDEFINED value.
     */
    private function decodeSourceExposureTimes(string $payload): ?SourceExposureTimes
    {
        $payloadLength = strlen($payload);
        $offset        = 0;

        $summary = [];
        for ($i = 0; $i < 8; ++$i) {
            if (($offset + self::RATIONAL_BYTE_LENGTH) > $payloadLength) {
                return null;
            }

            $summaryValue = $this->decodeRationalFromBytes(substr($payload, $offset, self::RATIONAL_BYTE_LENGTH));
            if ($summaryValue === null) {
                return null;
            }

            $summary[] = $summaryValue;
            $offset += self::RATIONAL_BYTE_LENGTH;
        }

        $sequenceCount = $this->decodeShort($payload, $offset);
        if ($sequenceCount === null) {
            return null;
        }

        $offset += self::SHORT_BYTE_LENGTH;

        $sequences = [];

        for ($i = 0; $i < $sequenceCount; ++$i) {
            $imageCount = $this->decodeShort($payload, $offset);
            if ($imageCount === null) {
                return null;
            }

            $offset += self::SHORT_BYTE_LENGTH;

            $sequence = [];
            for ($image = 0; $image < $imageCount; ++$image) {
                if (($offset + self::RATIONAL_BYTE_LENGTH) > $payloadLength) {
                    return null;
                }

                $value = $this->decodeRationalFromBytes(substr($payload, $offset, self::RATIONAL_BYTE_LENGTH));
                if ($value === null) {
                    return null;
                }

                $offset += self::RATIONAL_BYTE_LENGTH;
                $sequence[] = $value;
            }

            $sequences[] = $sequence;
        }

        if ($offset !== $payloadLength) {
            return null;
        }

        return new SourceExposureTimes(
            totalExposurePeriod: $summary[0] ?? null,
            usedExposureTimeSum: $summary[1] ?? null,
            allExposureTimeSum: $summary[2] ?? null,
            sourceImageCount: $summary[3] ?? null,
            maxUsedExposureTime: $summary[4] ?? null,
            minUsedExposureTime: $summary[5] ?? null,
            longestSourceExposureTime: $summary[6] ?? null,
            shortestSourceExposureTime: $summary[7] ?? null,
            sequences: $sequences,
        );
    }

    /**
     * Reads a SHORT value from a composite exposure payload.
     *
     * @param string $payload Raw payload bytes.
     * @param int    $offset  Offset within the payload.
     *
     * @return int|null Decoded value or null when out of range.
     */
    private function decodeShort(string $payload, int $offset): ?int
    {
        if (($offset + self::SHORT_BYTE_LENGTH) > strlen($payload)) {
            return null;
        }

        $format = $this->byteOrder === Endian::Little ? 'v' : 'n';

        return Unpack::int($format, substr($payload, $offset, self::SHORT_BYTE_LENGTH), 'EXIF composite exposure short');
    }

    /**
     * Decodes a RATIONAL value from an 8-byte payload.
     *
     * @param string $bytes Raw 8-byte rational value.
     *
     * @return float|null Decoded float value or null when invalid.
     */
    private function decodeRationalFromBytes(string $bytes): ?float
    {
        if (strlen($bytes) !== self::RATIONAL_BYTE_LENGTH) {
            return null;
        }

        // RATIONAL values are stored as numerator/denominator pairs.
        $format    = $this->byteOrder === Endian::Little ? 'V' : 'N';
        $numerator = Unpack::int($format, substr($bytes, 0, 4), 'EXIF composite exposure numerator');
        $denom     = Unpack::int($format, substr($bytes, 4, 4), 'EXIF composite exposure denominator');

        if ($denom === 0) {
            return null;
        }

        return $numerator / $denom;
    }

    /**
     * Returns the CFA pattern layout when available.
     *
     * EXIF 3.0 §4.6.6.7.34 defines the payload as two SHORT repeat units followed by m×n
     * component identifiers describing the colour filter array.
     */
    public function cfaPattern(): ?CfaPattern
    {
        $components = $this->numericList($this->exifIfd, ExifTag::CFA_PATTERN);
        if ($components === null || count($components) < 3) {
            return null;
        }

        $horizontalRepeatPixelUnit = $components[0];
        $verticalRepeatPixelUnit   = $components[1];
        $patternValues             = array_slice($components, 2);

        return CfaPattern::fromComponents($horizontalRepeatPixelUnit, $verticalRepeatPixelUnit, $patternValues);
    }

    /**
     * Returns the CFA pattern as colour enums when possible.
     *
     * @return list<CfaPatternColor>|null
     */
    public function cfaPatternColors(): ?array
    {
        $pattern = $this->cfaPattern();

        return $pattern?->colors;
    }

    /**
     * Returns the scene type classification when present.
     *
     * EXIF 3.0 §4.6.6.7.33 (SceneType)
     */
    public function sceneType(): ?SceneType
    {
        $value = $this->value($this->exifIfd, ExifTag::SCENE_TYPE);

        if (is_int($value)) {
            return SceneType::fromExifValue($value);
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            if (is_int($first)) {
                return SceneType::fromExifValue($first);
            }

            return null;
        }

        if (is_string($value) && $value !== '') {
            return SceneType::fromExifValue(ord($value[0]));
        }

        // EXIF 3.0 §4.6.6.7.33: default is 1 (directly photographed image).
        return SceneType::fromExifValue(1);
    }

    /**
     * Returns whether a custom rendering process was applied.
     *
     * EXIF 3.0 §4.6.6.7.35 (CustomRendered)
     */
    public function customRendered(): ?CustomRendered
    {
        // EXIF 3.0 §4.6.6.7.35: default is 0 (Normal process).
        $value = $this->int($this->exifIfd, ExifTag::CUSTOM_RENDERED) ?? 0;

        return CustomRendered::fromExifValue($value);
    }

    /**
     * Returns the in-camera contrast setting.
     *
     * EXIF 3.0 §4.6.6.7.42
     */
    public function contrast(): ?Contrast
    {
        // EXIF 3.0 §4.6.6.7.42: default is 0 (Normal).
        $value = $this->int($this->exifIfd, ExifTag::CONTRAST) ?? 0;

        return Contrast::fromExifValue($value);
    }

    /**
     * Returns the in-camera saturation setting.
     *
     * EXIF 3.0 §4.6.6.7.43
     */
    public function saturation(): ?Saturation
    {
        // EXIF 3.0 §4.6.6.7.43: default is 0 (Normal).
        $value = $this->int($this->exifIfd, ExifTag::SATURATION) ?? 0;

        return Saturation::fromExifValue($value);
    }

    /**
     * Returns the in-camera sharpness setting.
     *
     * EXIF 3.0 §4.6.6.7.44
     */
    public function sharpness(): ?Sharpness
    {
        // EXIF 3.0 §4.6.6.7.44: default is 0 (Normal).
        $value = $this->int($this->exifIfd, ExifTag::SHARPNESS) ?? 0;

        return Sharpness::fromExifValue($value);
    }

    /**
     * Returns the device setting description with parsed structure.
     *
     * EXIF 3.0 §4.6.6.7.45: This tag indicates information on the picture-taking
     * conditions of a particular camera model. The data is recorded in Unicode
     * using SHORT type for the number of display rows and columns and UNDEFINED
     * type for the camera settings.
     *
     * Format:
     * - 2 bytes SHORT: Display columns
     * - 2 bytes SHORT: Display rows
     * - Remaining bytes: Camera settings (Unicode UTF-16, NULL-terminated)
     */
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
        $value = $this->valueFromGpsOrExif(ExifTag::ACCELERATION);

        if (!$value instanceof ExifRationalList) {
            return null;
        }

        if ($this->containsExifUnknownDenominator($value)) {
            return null;
        }

        $vector = ValueConverters::srationalTripletToFloatVector($value);
        if ($vector === null) {
            return null;
        }

        return array_map(
            fn (float $component): float => $component * self::ACCELERATION_MGAL_TO_MS2,
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
        $value = $this->valueFromGpsOrExif(ExifTag::ACCELERATION);

        if ($this->containsExifUnknownDenominator($value)) {
            return null;
        }

        if ($value instanceof ExifRationalList) {
            $vector = ValueConverters::srationalTripletToFloatVector($value);
            if ($vector === null) {
                return null;
            }

            $scaled = array_map(
                fn (float $component): float => $component * self::ACCELERATION_MGAL_TO_MS2,
                $vector,
            );

            return sqrt(($scaled[0] ** 2) + ($scaled[1] ** 2) + ($scaled[2] ** 2));
        }

        $scalar = ValueConverters::rationalToFloat($value);
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
        return $this->str($this->exifIfd, ExifTag::CAMERA_FIRMWARE);
    }

    /**
     * Returns the raw developing software string.
     *
     * EXIF 3.0 §4.6.6.9.12 stores RAWDevelopingSoftware to document the RAW
     * processor and requires Software to be recorded too.
     */
    public function rawDevelopingSoftware(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::RAW_DEVELOPING_SOFTWARE);
    }

    /**
     * Returns the image editing software string.
     *
     * EXIF 3.0 §4.6.6.9.13 lists the primary image editing software and expects
     * the Software tag to accompany it.
     */
    public function imageEditingSoftware(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::IMAGE_EDITING_SOFTWARE);
    }

    /**
     * Returns the metadata editing software string.
     *
     * EXIF 3.0 §4.6.6.9.14 records the tool used to edit metadata without changing
     * pixels and likewise expects Software to be filled.
     */
    public function metadataEditingSoftware(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::METADATA_EDITING_SOFTWARE);
    }

    /**
     * Returns the raw DateTimeOriginal tag value.
     *
     * EXIF 3.0 §4.6.6.6.1 describes DateTimeOriginal as a 20-byte ASCII
     * timestamp (including the terminating NULL) formatted as
     * "YYYY:MM:DD HH:MM:SS".
     *
     * @return string|null
     */
    public function dateTimeOriginalRaw(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::DATETIME_ORIGINAL);

        if ($value !== null) {
            return $value;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->str($ifd, ExifTag::DATETIME_ORIGINAL);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the DateTimeOriginal tag combined with fractional seconds and offsets when available.
     */
    public function dateTimeOriginal(): ?DateTimeImmutable
    {
        $dateTime = $this->parseExifDateTime(
            $this->dateTimeOriginalRaw(),
            $this->offsetTimeOriginalRaw(),
            $this->subSecTimeOriginal(),
        );

        if ($dateTime instanceof DateTimeImmutable) {
            return $dateTime;
        }

        $digitized = $this->dateTimeDigitized();
        if ($digitized instanceof DateTimeImmutable) {
            return $digitized;
        }

        return $this->captureDateTime();
    }

    /**
     * Returns the most appropriate capture timestamp prioritising DateTimeOriginal metadata.
     */
    public function dateTimeOriginalBestEffort(): ?DateTimeImmutable
    {
        $original = $this->dateTimeOriginal();
        if ($original instanceof DateTimeImmutable) {
            return $original;
        }

        $digitized = $this->dateTimeDigitized();
        if ($digitized instanceof DateTimeImmutable) {
            return $digitized;
        }

        return $this->captureDateTime();
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
     * EXIF 3.0 §4.6.6.6.2 documents DateTimeDigitized as a 20-byte ASCII
     * timestamp (including the terminating NULL) formatted as
     * "YYYY:MM:DD HH:MM:SS".
     *
     * @return string|null
     */
    public function dateTimeDigitizedRaw(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::DATETIME_DIGITIZED);
        if ($value !== null) {
            return $value;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->str($ifd, ExifTag::DATETIME_DIGITIZED);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
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
     * Returns the normalized offset time for DateTimeOriginal.
     *
     * EXIF 3.0 §4.6.6.6.4 defines OffsetTimeOriginal as an ASCII string of
     * length 7 (including the terminating NULL) using the format "±HH:MM".
     */
    public function offsetTimeOriginal(): ?string
    {
        $offset = $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME_ORIGINAL);
        if ($offset !== null) {
            return $offset;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->normalizedOffset($ifd, ExifTag::OFFSET_TIME_ORIGINAL);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the normalized offset time for DateTimeDigitized.
     *
     * EXIF 3.0 §4.6.6.6.5 defines OffsetTimeDigitized as an ASCII string of
     * length 7 (including the terminating NULL) using the format "±HH:MM".
     */
    public function offsetTimeDigitized(): ?string
    {
        $offset = $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME_DIGITIZED);
        if ($offset !== null) {
            return $offset;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->normalizedOffset($ifd, ExifTag::OFFSET_TIME_DIGITIZED);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the normalized offset time for the IFD0 ModifyDate/DateTime tag.
     *
     * EXIF 3.0 §4.6.6.6.3 defines OffsetTime as an ASCII string of length 7
     * (including the terminating NULL) using the format "±HH:MM".
     */
    public function offsetTime(): ?string
    {
        $offset = $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME);
        if ($offset !== null) {
            return $offset;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->normalizedOffset($ifd, ExifTag::OFFSET_TIME);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the raw OffsetTimeOriginal tag value without EXIF normalization.
     */
    private function offsetTimeOriginalRaw(): ?string
    {
        $offset = $this->rawOffset($this->exifIfd, ExifTag::OFFSET_TIME_ORIGINAL);
        if ($offset !== null) {
            return $offset;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->rawOffset($ifd, ExifTag::OFFSET_TIME_ORIGINAL);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the raw OffsetTimeDigitized tag value without EXIF normalization.
     */
    private function offsetTimeDigitizedRaw(): ?string
    {
        $offset = $this->rawOffset($this->exifIfd, ExifTag::OFFSET_TIME_DIGITIZED);
        if ($offset !== null) {
            return $offset;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->rawOffset($ifd, ExifTag::OFFSET_TIME_DIGITIZED);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the raw OffsetTime tag value without EXIF normalization.
     */
    private function offsetTimeRaw(): ?string
    {
        $offset = $this->rawOffset($this->exifIfd, ExifTag::OFFSET_TIME);
        if ($offset !== null) {
            return $offset;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->rawOffset($ifd, ExifTag::OFFSET_TIME);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
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

        return is_float($value) ? $value : null;
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

        return is_float($value) ? $value : null;
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

        return is_float($value) ? $value : null;
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

        return is_float($value) ? $value : null;
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

        return is_float($value) ? $value : null;
    }

    /**
     * Returns the GPS differential correction indicator.
     */
    public function gpsDifferential(): ?int
    {
        $value = $this->gpsValue('differential');

        return is_int($value) ? $value : null;
    }

    /**
     * Returns the horizontal positioning error in metres when provided.
     */
    public function gpsHorizontalPositioningError(): ?float
    {
        $value = $this->gpsValue('h_positioning_error');

        return is_float($value) ? $value : null;
    }

    /**
     * Returns a single value from the cached GPS metadata map.
     *
     * @param string $key
     *
     * @return string|int|float|DateTimeImmutable|null
     */
    private function gpsValue(string $key): string|int|float|DateTimeImmutable|null
    {
        $gps = $this->gps();

        return $gps[$key] ?? null;
    }

    /**
     * Returns a best-effort absolute capture timestamp.
     *
     * EXIF DateTime* values without OffsetTime* remain local/offset-unknown and
     * are therefore not converted into an absolute instant here.
     *
     * @return DateTimeImmutable|null
     */
    public function captureDateTime(): ?DateTimeImmutable
    {
        $offsetOriginal  = $this->offsetTimeOriginalRaw();
        $offsetDigitized = $this->offsetTimeDigitizedRaw();
        $offset          = $this->offsetTimeRaw();

        $attempts = [
            [
                $this->dateTimeOriginalRaw(),
                $offsetOriginal,
                $this->subSecTimeOriginal(),
            ],
            [
                $this->dateTimeDigitizedRaw(),
                $offsetDigitized,
                $this->subSecTimeDigitized(),
            ],
            [
                $this->dateTimeRaw(),
                $offset,
                $this->subSecTime(),
            ],
        ];

        foreach ($attempts as [$raw, $rawOffset, $subSeconds]) {
            $dateTime = $this->parseExifDateTime($raw, $rawOffset, $subSeconds);
            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime;
            }
        }

        return $this->gpsTimestamp();
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
            $this->offsetTimeDigitizedRaw(),
            $this->subSecTimeDigitized(),
        );
    }

    /**
     * Returns the ModifyDate/DateTime tag combined with its optional offset.
     *
     * EXIF 3.0 §4.6.5.4.5 defines DateTime as "YYYY:MM:DD HH:MM:SS" with
     * blank-filled placeholders treated as unknown values.
     *
     * @return DateTimeImmutable|null
     */
    public function dateTime(): ?DateTimeImmutable
    {
        return $this->parseExifDateTime(
            $this->dateTimeRaw(),
            $this->offsetTimeRaw(),
            $this->subSecTime(),
        );
    }

    /**
     * Returns the artist tag value when present.
     *
     * EXIF 3.0 §4.6.5.4.6 requires Artist to be populated alongside
     * CameraOwnerName, Photographer, or ImageEditor. The closest available
     * attribution is returned when the primary tag is missing.
     */
    public function artist(): ?string
    {
        $artist = $this->str($this->ifd0, ExifTag::ARTIST);

        if ($artist !== null) {
            return $artist;
        }

        return array_find(
            [
                $this->str($this->exifIfd, ExifTag::CAMERA_OWNER_NAME),
                $this->str($this->ifd0, ExifTag::PHOTOGRAPHER),
                $this->str($this->exifIfd, ExifTag::PHOTOGRAPHER),
                $this->str($this->ifd0, ExifTag::IMAGE_EDITOR),
                $this->str($this->exifIfd, ExifTag::IMAGE_EDITOR),
            ],
            static fn (?string $value): bool => $value !== null,
        );
    }

    /**
     * Returns the bits per sample defined for the primary image.
     *
     * EXIF 3.0 §4.6.5.1.3 defines three SHORT values with a default of 8 8 8 for
     * RGB components. JPEG compressed data relies on the frame header precision
     * instead of this tag.
     *
     * @return int
     */
    public function bitsPerSample(): int
    {
        $bitsPerSample = $this->int($this->ifd0, ExifTag::BITS_PER_SAMPLE);

        return $bitsPerSample ?? 8;
    }

    /**
     * Returns the number of samples per pixel.
     *
     * EXIF 3.0 §4.6.5.1.7 specifies a default of 3 samples for RGB/YCbCr images.
     * TIFF 6.0 §8 lists 1 as the grayscale default; the EXIF profile overrides
     * this for the supported colour models.
     *
     * @return int
     */
    public function samplesPerPixel(): int
    {
        // EXIF 3.0 §4.6.5.1.7: Default is 3 when tag is not present (RGB/YCbCr)
        return $this->int($this->ifd0, ExifTag::SAMPLES_PER_PIXEL) ?? 3;
    }

    /**
     * Returns the rows per strip value when the image data is organized in strips.
     *
     * EXIF 3.0 §4.6.5.2.2 defines RowsPerStrip for strip-based images and
     * requires the tag to be omitted for JPEG-compressed primary images.
     */
    public function rowsPerStrip(): ?int
    {
        if ($this->isJpegCompression($this->compression())) {
            return null;
        }

        // TIFF 6.0 §8: default is 2^32−1 (entire image in one strip).
        return $this->int($this->ifd0, ExifTag::ROWS_PER_STRIP) ?? 4294967295;
    }

    /**
     * Returns the compression method enum for the primary image.
     *
     * EXIF 3.0 §4.6.5.1.4 omits the Compression tag for primary JPEG images.
     * TIFF 6.0 §8 specifies default value 1 (no compression) when not present
     * in TIFF image data.
     *
     * Returns UNCOMPRESSED when the tag is absent (TIFF default), the resolved
     * enum case when the tag value is recognised, or null when the tag is
     * present but carries an unsupported code.
     *
     * @return Compression|null
     */
    public function compression(): ?Compression
    {
        if (!$this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry) {
            return Compression::UNCOMPRESSED;
        }

        $value = $this->enumValue($this->ifd0, ExifTag::COMPRESSION);

        return Compression::fromExifValue($value);
    }

    /**
     * Returns the photometric interpretation enum.
     */
    public function photometric(): ?Photometric
    {
        $value = $this->enumValue($this->ifd0, ExifTag::PHOTOMETRIC_INTERPRETATION);

        return Photometric::fromExifValue($value);
    }

    /**
     * Returns the planar configuration enum.
     *
     * TIFF 6.0 §8 specifies default value 1 (chunky format) when not present.
     * EXIF 3.0 §4.6.5.1.10 states JPEG compressed data shall not record
     * this tag because the JPEG marker carries the equivalent information.
     * Returns null when the tag is absent in JPEG context (no Compression tag).
     *
     * @return PlanarConfiguration|null
     */
    public function planarConfiguration(): ?PlanarConfiguration
    {
        $value  = $this->enumValue($this->ifd0, ExifTag::PLANAR_CONFIGURATION);
        $config = PlanarConfiguration::fromExifValue($value);

        if ($config instanceof PlanarConfiguration) {
            return $config;
        }

        // TIFF 6.0 §8: Default is CHUNKY when tag is absent in TIFF context.
        // When Compression is absent (JPEG primary image), do not emit a
        // synthetic TIFF-layout value.
        return $this->ifd0->get(ExifTag::COMPRESSION) instanceof IfdEntry
            ? PlanarConfiguration::CHUNKY
            : null;
    }

    /**
     * Returns the resolution unit enum for the reported X/Y resolution values.
     *
     * EXIF 3.0 §4.6.5.1.11 and TIFF 6.0 §8 specify default value 2 (inches) when
     * not present.
     *
     * @return ResolutionUnit
     */
    public function resolutionUnit(): ResolutionUnit
    {
        $value = $this->enumValue($this->ifd0, ExifTag::RESOLUTION_UNIT);
        $unit  = ResolutionUnit::fromExifValue($value);

        // TIFF 6.0 §8: Default is 2 (INCHES) when tag is not present
        return $unit ?? ResolutionUnit::INCHES;
    }

    /**
     * Returns the horizontal resolution value expressed in the resolution unit.
     */
    public function xResolution(): float
    {
        // EXIF 3.0 §4.6.5.1.8: Default is 72 dpi when resolution is unknown
        return $this->rational($this->ifd0, ExifTag::X_RESOLUTION) ?? 72.0;
    }

    /**
     * Returns the vertical resolution value expressed in the resolution unit.
     */
    public function yResolution(): float
    {
        $resolution = $this->rational($this->ifd0, ExifTag::Y_RESOLUTION);

        if ($resolution !== null) {
            return $resolution;
        }

        // EXIF 3.0 §4.6.5.1.9: Default matches XResolution (72 dpi when unknown)
        return $this->xResolution();
    }

    /**
     * Returns the YCbCr positioning enum describing the chroma siting.
     *
     * EXIF 3.0 §4.6.5.1.13 defines the default as centered, but the tag
     * is only semantically applicable when the photometric interpretation
     * is YCbCr. Non-YCbCr images return null when the tag is absent.
     */
    public function ycbcrPositioning(): ?YCbCrPositioning
    {
        $rawValue = $this->value($this->ifd0, ExifTag::YCBCR_POSITIONING);

        if ($rawValue === null) {
            return $this->photometric() === Photometric::YCBCR
                ? YCbCrPositioning::CENTERED
                : null;
        }

        $value = $this->normaliseEnumScalar($rawValue);

        return YCbCrPositioning::fromExifValue($value);
    }

    /**
     * Returns the YCbCr subsampling factors.
     *
     * EXIF 3.0 §4.6.5.1.12 defines only [2,1] (YCbCr4:2:2) and [2,2]
     * (YCbCr4:2:0) as legal values. The tag shall not be recorded for JPEG
     * compressed data because the JPEG marker stream already encodes the
     * sampling factors.
     *
     * @return array{0:int,1:int}|null
     */
    public function ycbcrSubSampling(): ?array
    {
        $values = $this->numericList($this->ifd0, ExifTag::YCBCR_SUB_SAMPLING);

        if ($values !== null) {
            if (count($values) === 2) {
                return $this->validateYcbcrPair($values[0], $values[1]);
            }

            return null;
        }

        $raw = $this->rawString($this->ifd0, ExifTag::YCBCR_SUB_SAMPLING);

        if ($raw === null) {
            return null;
        }

        $pair = ValueConverters::ycbcrSubSamplingToPair($raw);

        return $pair !== null ? $this->validateYcbcrPair($pair[0], $pair[1]) : null;
    }

    /**
     * Validates a YCbCrSubSampling pair against the EXIF 3.0 §4.6.5.1.12 allowed values.
     *
     * Only [2,1] (YCbCr4:2:2) and [2,2] (YCbCr4:2:0) are defined by the spec.
     *
     * @return array{0:int,1:int}|null
     */
    private function validateYcbcrPair(int $horiz, int $vert): ?array
    {
        if ($horiz === 2 && ($vert === 1 || $vert === 2)) {
            return [$horiz, $vert];
        }

        return null;
    }

    /**
     * Returns the YCbCr conversion coefficients when provided.
     *
     * EXIF 3.0 §4.6.5.3.4 defines three rational coefficients for RGB→YCbCr
     * conversion, defaulting to Annex D values when the tag is absent.
     *
     * @return array{0:float,1:float,2:float}|null
     */
    public function ycbcrCoefficients(): ?array
    {
        $value = $this->normalisedValue($this->ifd0, ExifTag::YCBCR_COEFFICIENTS);

        if ($value instanceof ExifNumericList) {
            $coeffs = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $coeffs[] = (float) $component->toInt('YCbCrCoefficients');
                } else {
                    $coeffs[] = (float) $component;
                }
            }

            return count($coeffs) === 3 ? $coeffs : null;
        }

        if ($value instanceof ExifRationalList) {
            $coeffs = [];
            foreach ($value->values as $component) {
                $float = ValueConverters::rationalToFloat($component);
                if ($float === null) {
                    return null;
                }

                $coeffs[] = $float;
            }

            return count($coeffs) === 3 ? $coeffs : null;
        }

        return $this->defaultYCbCrCoefficients();
    }

    /**
     * Returns the normalized white point coordinates.
     *
     * EXIF 3.0 §4.6.5.3.2 (WhitePoint) encodes the chromaticity of the white
     * point as exactly two rational values (X,Y).
     *
     * @return array{0:float,1:float}|null
     */
    public function whitePoint(): ?array
    {
        $value = $this->value($this->ifd0, ExifTag::WHITE_POINT);

        return $value instanceof ExifRationalList || $value instanceof ExifNumericList
            ? ValueConverters::toWhitePoint($value)
            : null;
    }

    /**
     * Returns the primary chromaticities ordered as R,G,B.
     *
     * EXIF 3.0 §4.6.5.3.3 (PrimaryChromaticities) defines three rational pairs
     * (RedX, RedY, GreenX, GreenY, BlueX, BlueY).
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public function primaryChromaticities(): ?array
    {
        $value = $this->value($this->ifd0, ExifTag::PRIMARY_CHROMATICITIES);

        return $value instanceof ExifRationalList || $value instanceof ExifNumericList
            ? ValueConverters::toPrimaryChromaticities($value)
            : null;
    }

    /**
     * Returns the TIFF predictor value for differencing compression schemes.
     *
     * TIFF 6.0 §14 defines the Predictor tag as a mathematical operator applied before
     * compression. Valid values: 1 = No prediction (default), 2 = Horizontal differencing.
     */
    public function predictor(): int
    {
        // TIFF 6.0 §14: default is 1 (no prediction scheme).
        return $this->int($this->ifd0, TiffTag::PREDICTOR) ?? 1;
    }

    /**
     * Returns the JPEG interchange format offset for legacy thumbnails.
     *
     * EXIF 3.0 §4.6.5.2.4 notes that this tag shall not be recorded for primary
     * images encoded with JPEG compression.
     */
    public function jpegInterchangeFormat(): ?int
    {
        if ($this->isJpegCompression($this->compression())) {
            return null;
        }

        return $this->int($this->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT);
    }

    /**
     * Returns the JPEG interchange format length for legacy thumbnails.
     *
     * EXIF 3.0 §4.6.5.2.4 notes that this tag shall not be recorded for primary
     * images encoded with JPEG compression.
     */
    public function jpegInterchangeFormatLength(): ?int
    {
        if ($this->isJpegCompression($this->compression())) {
            return null;
        }

        return $this->int($this->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);
    }

    /**
     * Returns the interoperability index string when recorded.
     *
     * EXIF 3.0 §4.6.8.1.1: ASCII[4] including terminating NUL.
     */
    public function interopIndex(): ?string
    {
        $entry = $this->interopIfd?->get(ExifTag::INTEROPERABILITY_INDEX);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        if ($entry->type !== TiffConst::TYPE_ASCII || $entry->count !== 4) {
            return null;
        }

        return $this->str($this->interopIfd, ExifTag::INTEROPERABILITY_INDEX);
    }

    /**
     * Returns the gamma correction value when provided.
     *
     * EXIF 3.0 §4.6.6.2.2 (Gamma)
     */
    public function gamma(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::GAMMA);
    }

    /**
     * Returns the digital zoom ratio when encoded by the camera.
     *
     * EXIF 3.0 §4.6.6.7.38 (DigitalZoomRatio)
     * A ratio with a numerator of zero indicates that digital zoom was not used.
     */
    public function digitalZoomRatio(): ?float
    {
        $ratio = $this->rational($this->exifIfd, ExifTag::DIGITAL_ZOOM_RATIO);

        if ($ratio === 0.0) {
            return null;
        }

        return $ratio;
    }

    /**
     * Returns the exposure mode enum indicating manual or auto settings.
     *
     * EXIF 3.0 §4.6.6.7.36 (ExposureMode)
     */
    public function exposureMode(): ?ExposureMode
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::EXPOSURE_MODE);

        return ExposureMode::fromExifValue($value);
    }

    /**
     * Returns the gain control enum describing in-camera amplification.
     *
     * EXIF 3.0 §4.6.6.7.41
     */
    public function gainControl(): ?GainControl
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::GAIN_CONTROL);

        return GainControl::fromExifValue($value);
    }

    /**
     * Returns the EXIF file source enum when provided.
     *
     * EXIF 3.0 §4.6.6.7.32 (FileSource)
     */
    public function fileSource(): ?FileSource
    {
        foreach ([$this->exifIfd, $this->ifd0] as $ifd) {
            if (!$ifd instanceof Ifd) {
                continue;
            }

            $value = $this->value($ifd, ExifTag::FILE_SOURCE);

            if ($value instanceof ExifNumericList) {
                $first = $value->values[0] ?? null;

                if (is_int($first) || is_float($first)) {
                    return FileSource::fromExifValue((int) $first);
                }

                continue;
            }

            if (is_int($value) || is_float($value)) {
                return FileSource::fromExifValue((int) $value);
            }

            if (is_string($value) && $value !== '') {
                return FileSource::fromExifValue(ord($value[0]));
            }
        }

        // EXIF 3.0 §4.6.6.7.32: default is 3 (DSC).
        return FileSource::fromExifValue(3);
    }

    /**
     * Returns the EXIF sensing method enum when provided.
     *
     * EXIF 3.0 §4.6.6.7.31 (SensingMethod)
     */
    public function sensingMethod(): ?SensingMethod
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::SENSING_METHOD);

        return SensingMethod::fromExifValue($value);
    }

    /**
     * Returns the light source enum describing the scene illumination.
     *
     * EXIF 3.0 §4.6.6.7.20 (LightSource) mapping of coded illuminants and
     * default value 0 for unknown light sources.
     *
     * @return LightSource|null
     */
    public function lightSource(): ?LightSource
    {
        // EXIF 3.0 §4.6.6.7.20: default is 0 (Unknown).
        $rawLightSource = $this->enumValue($this->exifIfd, ExifTag::LIGHT_SOURCE) ?? 0;

        return LightSource::fromExifValue($rawLightSource);
    }

    /**
     * Returns the scene capture type enum when recorded.
     *
     * EXIF 3.0 §4.6.6.7.40 (SceneCaptureType)
     *
     * @return SceneCaptureType|null
     */
    public function sceneCaptureType(): ?SceneCaptureType
    {
        // EXIF 3.0 §4.6.6.7.40: default is 0 (Standard).
        $rawSceneCaptureType = $this->enumValue($this->exifIfd, ExifTag::SCENE_CAPTURE_TYPE) ?? 0;

        return SceneCaptureType::fromExifValue($rawSceneCaptureType);
    }

    /**
     * Returns the subject distance range enum when provided.
     *
     * EXIF 3.0 §4.6.6.7.46 provides the four valid SubjectDistanceRange codes;
     * other values are reserved.
     */
    public function subjectDistanceRange(): ?SubjectDistanceRange
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::SUBJECT_DISTANCE_RANGE);

        return SubjectDistanceRange::fromExifValue($value);
    }

    /**
     * Returns the ISO latitude yyy value when available.
     */
    public function isoLatitudeYyy(): ?int
    {
        return $this->isoSpeedLatitudeYyy();
    }

    /**
     * Returns the ISO latitude zzz value when available.
     */
    public function isoLatitudeZzz(): ?int
    {
        return $this->isoSpeedLatitudeZzz();
    }

    /**
     * Returns the shutter speed APEX value.
     */
    public function shutterSpeedEv(): ?float
    {
        return $this->shutterSpeedValue();
    }

    /**
     * Returns the aperture APEX value.
     */
    public function apertureEv(): ?float
    {
        return $this->apertureValue();
    }

    /**
     * Returns the subject distance in metres when provided.
     *
     * EXIF 3.0 §4.6.6.7.18 (SubjectDistance) states that a numerator of
     * 0xFFFFFFFF indicates infinity, while a numerator of 0 indicates an
     * unknown distance.
     */
    public function subjectDistance(): ?float
    {
        $value = $this->normalisedValue($this->exifIfd, ExifTag::SUBJECT_DISTANCE);

        if ($value === null) {
            return null;
        }

        $numerator = $this->subjectDistanceNumerator($value);

        if ($numerator === 0) {
            return null;
        }

        if ($numerator === 0xFFFFFFFF || $numerator === -1) {
            return INF;
        }

        return ValueConverters::rationalToFloat($value);
    }

    /**
     * Returns the EXIF subject area as a structured value object.
     *
     * EXIF 3.0 §4.6.6.7.22: SubjectArea tag 0x9214 indicates the location and area of the main
     * subject in the overall scene.
     */
    public function subjectArea(): ?SubjectArea
    {
        $value = $this->normalisedValue($this->exifIfd, ExifTag::SUBJECT_AREA);

        if ($value instanceof ExifNumericList) {
            /** @var list<int> $components */
            $components = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $components[] = $component->toInt('SubjectArea');
                } else {
                    $components[] = (int) $component;
                }
            }

            return SubjectArea::fromComponents($components);
        }

        return null;
    }

    /**
     * Returns a trimmed string value for the given IFD tag.
     *
     * @param Ifd|null $ifd IFD to inspect.
     * @param int      $tag Tag identifier.
     *
     * @return string|null Normalised string or null.
     */
    private function str(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->normalisedValue($ifd, $tag);

        if (!is_string($value)) {
            return null;
        }

        $trimmed = rtrim($value, "\0 ");

        if ($trimmed === '') {
            return null;
        }

        return trim($trimmed) === '' ? null : $trimmed;
    }

    /**
     * Returns an integer value from the given IFD if present.
     *
     * @param Ifd|null $ifd
     * @param int      $tag
     *
     * @return int|null
     */
    private function int(?Ifd $ifd, int $tag): ?int
    {
        $value = $this->normalisedValue($ifd, $tag);

        return $this->coerceIntValue($value);
    }

    /**
     * Extracts the numerator component from a subject distance value.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value Raw value.
     *
     * @return int|null Numerator value or null.
     */
    private function subjectDistanceNumerator(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): ?int {
        if ($value instanceof ExifRational) {
            return $value->numerator;
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            if ($first instanceof ExifRational) {
                return $first->numerator;
            }

            return null;
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            if (is_int($first) || is_float($first)) {
                return (int) $first;
            }

            if ($first instanceof UInt64) {
                return $first->toInt('SubjectDistance numerator');
            }

            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Returns a rational or numeric value converted to float if present in the given IFD.
     *
     * @param Ifd|null $ifd
     * @param int      $tag
     *
     * @return float|null
     */
    private function rational(?Ifd $ifd, int $tag): ?float
    {
        $value = $this->normalisedValue($ifd, $tag);

        if ($value === null) {
            return null;
        }

        return ValueConverters::rationalToFloat($value);
    }

    /**
     * Indicates whether a brightness value is marked as unknown.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value Raw value.
     *
     * @return bool True when the value is the "unknown" sentinel.
     */
    private function isUnknownBrightness(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): bool {
        if ($value instanceof ExifRational) {
            return $value->numerator === -1;
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            if ($first instanceof ExifRational) {
                return $first->numerator === -1;
            }

            return false;
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            if ($first instanceof UInt64) {
                return $first->toInt('BrightnessValue') === -1;
            }

            return $first === -1;
        }

        if (is_int($value)) {
            return $value === -1;
        }

        return false;
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

        return ValueConverters::rationalToFloat($value);
    }

    /**
     * Checks whether a rational value contains the EXIF unknown-denominator sentinel.
     *
     * EXIF 3.0 §4.6.6.8 defines 0xFFFFFFFF (or signed -1) as unknown for selected
     * shooting-situation tags.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value
     */
    private function containsExifUnknownDenominator(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): bool {
        if ($value instanceof ExifRational) {
            return $this->isExifUnknownDenominator($value->denominator);
        }

        if ($value instanceof ExifRationalList) {
            foreach ($value->values as $component) {
                if ($this->isExifUnknownDenominator($component->denominator)) {
                    return true;
                }
            }
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

        return $denominator === self::EXIF_UNKNOWN_DENOMINATOR;
    }

    /**
     * Retrieves a raw entry value preferring the GPS IFD before falling back to the EXIF IFD.
     *
     * @param int $tag
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    private function valueFromGpsOrExif(int $tag): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        $value = $this->normalisedValue($this->gpsIfd, $tag);

        return $value ?? $this->normalisedValue(
            $this->exifIfd,
            $tag
        );
    }

    /**
     * Retrieves the raw entry value for the provided tag.
     *
     * @param Ifd|null $ifd
     * @param int      $tag
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null
     */
    private function value(?Ifd $ifd, int $tag): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        return $ifd->get($tag)?->value;
    }

    /**
     * Reads and normalises a scalar tag value from an IFD.
     *
     * @param Ifd|null $ifd IFD to inspect.
     * @param int      $tag Tag identifier.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null Normalised value.
     */
    private function normalisedValue(
        ?Ifd $ifd,
        int $tag,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null {
        $value = $this->value($ifd, $tag);

        return $this->normaliseScalarValue($value);
    }

    /**
     * Normalises scalar EXIF values, converting UInt64 when possible.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw value.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null Normalised value.
     */
    private function normaliseScalarValue(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null {
        if ($value instanceof UInt64) {
            // Only expose UInt64 values that fit into the platform signed integer range.
            if (!$value->fitsSignedInt()) {
                return null;
            }

            return $value->toInt('EXIF scalar normalisation');
        }

        if ($value instanceof ExifNumericList) {
            $normalised = [];
            $changed    = false;

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    // Convert list components to integers when safe.
                    if (!$component->fitsSignedInt()) {
                        return null;
                    }

                    $normalised[] = $component->toInt('EXIF numeric list normalisation');
                    $changed      = true;

                    continue;
                }

                $normalised[] = $component;
            }

            if ($changed) {
                return new ExifNumericList($normalised);
            }

            return $value;
        }

        return $value;
    }

    /**
     * Returns a scalar value suitable for enum conversion.
     *
     * @param Ifd|null $ifd IFD to inspect.
     * @param int      $tag Tag identifier.
     *
     * @return int|string|null Normalised enum scalar.
     */
    private function enumValue(?Ifd $ifd, int $tag): int|string|null
    {
        $value = $this->value($ifd, $tag);

        return $this->normaliseEnumScalar($value);
    }

    /**
     * Normalises a mixed EXIF value to an enum-compatible scalar.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value Raw value.
     *
     * @return int|string|null Enum-compatible scalar value.
     */
    private function normaliseEnumScalar(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): int|string|null {
        if ($value instanceof ExifNumericList) {
            // Only the first entry is relevant for enum conversion.
            $first = $value->values[0] ?? null;

            return $this->normaliseEnumScalar($first);
        }

        if ($value instanceof ExifRationalList) {
            // Only the first entry is relevant for enum conversion.
            $first = $value->values[0] ?? null;

            return $this->normaliseEnumScalar($first);
        }

        if ($value instanceof ExifRational) {
            // Reduce rationals to a rounded integer for enum lookups.
            $float = ValueConverters::rationalToFloat($value);

            return $float === null ? null : $this->normaliseEnumScalar($float);
        }

        if ($value instanceof UInt64) {
            if (!$value->fitsSignedInt()) {
                return null;
            }

            return $value->toInt('EXIF enum value normalisation');
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (is_numeric($trimmed)) {
                return (int) round((float) $trimmed);
            }

            return $trimmed;
        }

        return null;
    }

    /**
     * Extracts components configuration input from IFD.
     *
     * @param Ifd|null $ifd IFD to search.
     * @param int      $tag Tag number to retrieve.
     *
     * @return array<int, int|float|string>|int|string|null Components input value or null if not found.
     */
    private function componentsInput(?Ifd $ifd, int $tag): array|int|string|null
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            /** @var list<int|float> $components */
            $components = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $components[] = $component->toInt('ComponentsConfiguration');
                } else {
                    $components[] = $component;
                }
            }

            return $components;
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            if (!$first instanceof ExifRational) {
                return null;
            }

            $float = ValueConverters::rationalToFloat($first);

            return $float === null ? null : $this->componentsInputFromScalar($float);
        }

        if ($value instanceof ExifRational) {
            $float = ValueConverters::rationalToFloat($value);

            return $float === null ? null : $this->componentsInputFromScalar($float);
        }

        if ($value instanceof UInt64) {
            return $this->componentsInputFromScalar($value->toInt('ComponentsConfiguration'));
        }

        return $this->componentsInputFromScalar($value);
    }

    /**
     * Converts a scalar components configuration value to normalized form.
     *
     * @param int|float|string|null $value Scalar value to normalize.
     *
     * @return int|string|null Normalized component value or null.
     */
    private function componentsInputFromScalar(int|float|string|null $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    /**
     * Provides the fallback IFDs consulted when primary metadata is absent.
     *
     * @param bool $includePrimaryThumbnail When true the primary thumbnail (IFD1) is considered.
     * @param bool $includeIfd0             When true the root directory (IFD0) is appended as a last resort.
     *
     * @return list<Ifd>
     */
    private function fallbackIfds(bool $includePrimaryThumbnail = true, bool $includeIfd0 = false): array
    {
        $ifds = [];
        $seen = [];

        $append = static function (?Ifd $candidate) use (&$ifds, &$seen): void {
            if (!$candidate instanceof Ifd) {
                return;
            }

            $id = spl_object_id($candidate);
            if (isset($seen[$id])) {
                return;
            }

            $seen[$id] = true;
            $ifds[]    = $candidate;
        };

        if ($includePrimaryThumbnail) {
            $append($this->ifd1);
        }

        foreach ($this->subIfds as $ifd) {
            $append($ifd);
        }

        foreach ($this->subsequentIfds as $ifd) {
            $append($ifd);
        }

        if ($includeIfd0) {
            $append($this->ifd0);
        }

        return $ifds;
    }

    /**
     * Coerces a raw EXIF scalar value into an integer when possible.
     */
    private function coerceIntValue(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?int {
        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            return $this->coerceIntValue($first);
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            return $first instanceof ExifRational ? $this->coerceIntValue($first) : null;
        }

        if ($value instanceof ExifRational) {
            $float = ValueConverters::rationalToFloat($value);

            return $float === null ? null : (int) round($float);
        }

        if ($value instanceof UInt64) {
            if (!$value->fitsSignedInt()) {
                return null;
            }

            return $value->toInt('EXIF integer coercion');
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (is_numeric($trimmed)) {
                return (int) round((float) $trimmed);
            }

            if (preg_match('/\d+/', $trimmed, $matches) === 1) {
                return (int) $matches[0];
            }

            return null;
        }

        return null;
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
     * Retrieves the raw user comment value from primary and fallback directories.
     */
    private function rawUserComment(): ?string
    {
        $raw = $this->rawString($this->exifIfd, ExifTag::USER_COMMENT);
        if ($raw !== null) {
            return $raw;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->rawString($ifd, ExifTag::USER_COMMENT);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Determines whether strip-based metadata shall be omitted for JPEG-encoded payloads.
     */
    private function isJpegCompression(?Compression $compression): bool
    {
        if (!$compression instanceof Compression) {
            return false;
        }

        return in_array(
            $compression,
            [
                Compression::JPEG,
                Compression::JPEG_NEW_STYLE,
                Compression::LOSSY_JPEG,
                Compression::JPEG_2000,
            ],
            true
        );
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
            return array_map(
                static function (int|float|UInt64 $component): int {
                    if ($component instanceof UInt64) {
                        return $component->toInt('EXIF numeric list component');
                    }

                    return (int) $component;
                },
                $value->values,
            );
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
     * Formats DNG BYTE[4] version tags into dotted notation.
     *
     * @param Ifd|null $ifd IFD that may contain the DNG version tag.
     * @param int      $tag DNG tag identifier for the requested version field.
     */
    private function dngVersionTag(?Ifd $ifd, int $tag): ?string
    {
        $components = $this->numericList($ifd, $tag);

        if (!is_array($components) || count($components) !== 4) {
            return null;
        }

        return $components[0]
            . '.'
            . $components[1]
            . '.'
            . $components[2]
            . '.'
            . $components[3];
    }

    /**
     * Normalises a reference black and white array to six components.
     *
     * EXIF 3.0 §4.6.5.3.5 requires six rational values representing the
     * black and white points for each channel.
     *
     * @param list<float> $values
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     */
    private function normaliseReferenceBlackWhite(array $values): ?array
    {
        if (count($values) !== 6) {
            return null;
        }

        return [
            0 => $values[0],
            1 => $values[1],
            2 => $values[2],
            3 => $values[3],
            4 => $values[4],
            5 => $values[5],
        ];
    }

    /**
     * Applies EXIF 3.0 §4.6.5.3.4 defaults for missing YCbCrCoefficients.
     *
     * Annex D recommends the ITU-R BT.601 coefficients when no matrix is
     * specified: [0.299, 0.587, 0.114].
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function defaultYCbCrCoefficients(): ?array
    {
        if ($this->photometric() !== Photometric::YCBCR) {
            return null;
        }

        return [0.299, 0.587, 0.114];
    }

    /**
     * Applies EXIF 3.0 §4.6.5.3.5 defaults when ReferenceBlackWhite is absent.
     *
     * Defaults are only valid when the colour space is explicitly defined and
     * the photometric interpretation is RGB or YCbCr.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     */
    private function defaultReferenceBlackWhite(): ?array
    {
        $photometric = $this->photometric();
        $colorSpace  = $this->colorSpace();

        if ((!$photometric instanceof Photometric) || (!$colorSpace instanceof ColorSpace) || ($colorSpace === ColorSpace::UNCALIBRATED)) {
            return null;
        }

        return match ($photometric) {
            Photometric::RGB   => [0.0, 255.0, 0.0, 255.0, 0.0, 255.0],
            Photometric::YCBCR => [0.0, 255.0, 128.0, 128.0, 128.0, 128.0],
            default            => null,
        };
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
            $floats = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    if (!$component->fitsSignedInt()) {
                        return null;
                    }

                    $floats[] = (float) $component->toInt('EXIF rational list component');

                    continue;
                }

                $floats[] = (float) $component;
            }

            return $floats;
        }

        if (is_int($value) || is_float($value)) {
            return [(float) $value];
        }

        return null;
    }

    /**
     * Decodes EXIF user comment strings with encoding prefixes.
     *
     * EXIF 3.0 §4.6.4 requires UNDEFINED text fields to include an 8-byte
     * character code area. Payloads shorter than 8 bytes are non-conformant
     * and are rejected. An unrecognised prefix is also rejected.
     */
    private function decodeUserComment(string $raw): ?string
    {
        if (strlen($raw) < 8) {
            return null;
        }

        $prefix            = substr($raw, 0, 8);
        $canonicalEncoding = $this->canonicalUserCommentMarker($prefix);

        if ($canonicalEncoding === '') {
            return null;
        }

        $content   = substr($raw, 8);
        $sanitized = trim($content, "\0 ");

        if ($sanitized === '') {
            return null;
        }

        return match ($canonicalEncoding) {
            'UNICODE' => $this->decodeUnicodeUserComment($content),
            'JIS'     => $this->decodeJisComment($sanitized),
            default   => $sanitized,
        };
    }

    /**
     * Decodes UNICODE-marker user comments using EXIF 3.0 UTF-8 semantics.
     *
     * Compatibility policy:
     * - EXIF 3.0 `UNICODE\0`: decode as UTF-8.
     * - Legacy fallback: when UTF-8 validation fails, accept BOM-tagged UTF-16
     *   payloads for older EXIF 2.x ecosystem files.
     */
    private function decodeUnicodeUserComment(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        if (preg_match('//u', $content) === 1) {
            $trimmed = trim($content, "\0 ");

            return $trimmed === '' ? null : $trimmed;
        }

        return $this->decodeLegacyUnicodeCommentFromBom($content);
    }

    /**
     * Decodes legacy UTF-16 user comment payloads if they contain an explicit BOM.
     */
    private function decodeLegacyUnicodeCommentFromBom(string $content): ?string
    {
        if (strlen($content) < 2) {
            return null;
        }

        $byteOrderMark = substr($content, 0, 2);
        $payload       = substr($content, 2);

        $encoding = match ($byteOrderMark) {
            "\xFF\xFE" => 'UTF-16LE',
            "\xFE\xFF" => 'UTF-16BE',
            default    => null,
        };

        if (($encoding === null) || ($payload === '') || (strlen($payload) % 2 !== 0)) {
            return null;
        }

        $converted = @iconv($encoding, 'UTF-8', $payload);
        if ($converted === false) {
            return null;
        }

        $trimmed = trim($converted, "\0 ");

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Decodes a JIS-marker user comment using ISO-2022-JP/JIS strategy.
     */
    private function decodeJisComment(string $content): ?string
    {
        return JisTextDecoder::decode($content);
    }

    /**
     * Infers the most likely user comment encoding based on the raw payload.
     */
    private function inferUserCommentEncoding(string $content): ?string
    {
        $trimmed = trim($content, "\0 ");
        if ($trimmed === '') {
            return null;
        }

        if ($this->looksLikeUtf16($content)) {
            return 'UNICODE';
        }

        if ($this->looksPrintableAscii($trimmed)) {
            return 'ASCII';
        }

        return 'UNDEFINED';
    }

    /**
     * Checks whether the payload is limited to printable ASCII characters.
     */
    private function looksPrintableAscii(string $content): bool
    {
        $length = strlen($content);
        for ($i = 0; $i < $length; ++$i) {
            $byte = ord($content[$i]);
            if ($byte < 0x20 && !in_array($byte, [0x09, 0x0A, 0x0D], true)) {
                return false;
            }

            if ($byte > 0x7E) {
                return false;
            }
        }

        return true;
    }

    /**
     * Heuristically determines whether the payload resembles UTF-16 text.
     */
    private function looksLikeUtf16(string $content): bool
    {
        $length = strlen($content);
        if ($length < 2) {
            return false;
        }

        $bom = substr($content, 0, 2);
        if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") {
            return true;
        }

        $nullCount = substr_count($content, "\x00");
        if ($nullCount < 2) {
            return false;
        }

        $sampleLength = min($length, 32);
        $sample       = substr($content, 0, $sampleLength);

        $nullsOnEven = 0;
        $nullsOnOdd  = 0;

        $sampleSize = strlen($sample);
        for ($i = 0; $i < $sampleSize; ++$i) {
            if ($sample[$i] === "\x00") {
                if (($i % 2) === 0) {
                    ++$nullsOnEven;
                } else {
                    ++$nullsOnOdd;
                }
            }
        }

        if ($nullsOnEven === 0 && $nullsOnOdd === 0) {
            return false;
        }

        if ($nullsOnEven === 0 || $nullsOnOdd === 0) {
            return true;
        }

        if ($nullCount <= 2) {
            return false;
        }

        return $nullCount >= (int) ($length / 4);
    }

    /**
     * Parses the DeviceSettingDescription tag structure.
     *
     * EXIF 3.0 §4.6.6.7.45: The format consists of:
     * - 2 bytes SHORT: Display columns
     * - 2 bytes SHORT: Display rows
     * - Remaining bytes: Camera settings in Unicode (UTF-16), NULL-terminated strings
     */
    private function parseDeviceSettingDescription(): ?DeviceSettingDescription
    {
        $raw = $this->rawString($this->exifIfd, ExifTag::DEVICE_SETTING_DESCRIPTION);

        if (
            ($raw === null)
            || (strlen($raw) < 4)
        ) {
            return null;
        }

        // EXIF 3.0 §4.6.6.7.45: columns/rows are TIFF SHORT fields —
        // decode using the EXIF/TIFF byte order context.
        $format   = $this->byteOrder === Endian::Little ? 'v2' : 'n2';
        $unpacked = unpack($format, substr($raw, 0, 4));

        if ($unpacked === false) {
            return null;
        }

        $columns = $unpacked[1] ?? null;
        $rows    = $unpacked[2] ?? null;

        if (
            !is_int($columns)
            || !is_int($rows)
        ) {
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
        $length = strlen($payload);

        // EXIF 3.0 §4.6.6.7.45: UTF-16 encoded strings require even byte
        // length for code-unit alignment.  Odd-length payloads are malformed.
        if ($length < 4 || ($length % 2) !== 0) {
            return [];
        }

        $settings = [];
        $offset   = 0;

        while (($offset + 4) <= $length) {
            $bom = substr($payload, $offset, 2);

            if ($bom !== "\xFF\xFE" && $bom !== "\xFE\xFF") {
                break;
            }

            // Scan for the null code unit at even offsets after the BOM.
            $pos       = $offset + 2;
            $termFound = false;

            while (($pos + 1) < $length) {
                if ($payload[$pos] === "\x00" && $payload[$pos + 1] === "\x00") {
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

            $decoded = $this->decodeLegacyUnicodeCommentFromBom($segment);

            if ($decoded !== null) {
                $settings[] = $decoded;
            }
        }

        return $settings;
    }

    /**
     * Normalises textual and numeric offset encodings to a canonical string representation.
     */
    private function normalizedOffset(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            $value = $value->values[0] ?? null;

            if ($value instanceof UInt64) {
                if (!$value->fitsSignedInt()) {
                    return null;
                }

                $value = $value->toInt('EXIF offset normalisation');
            }
        } elseif ($value instanceof ExifRationalList || $value instanceof ExifRational) {
            $value = ValueConverters::rationalToFloat($value);
        }

        if (is_string($value)) {
            $trimmed = rtrim(trim($value), "\0");
            if ($trimmed === '') {
                return null;
            }

            // EXIF 3.0 §4.6.6.6.3–§4.6.6.6.5: OffsetTime tags are ASCII strings
            // formatted as "±HH:MM". Reject non-conformant string encodings.
            if (preg_match('/\A[+-]\d{2}:\d{2}\z/', $trimmed) !== 1) {
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
     * Returns the raw textual offset value from an EXIF OffsetTime* tag.
     *
     * EXIF 3.0 §4.6.6.6.3–§4.6.6.6.5 defines OffsetTime* as ASCII text.
     */
    private function rawOffset(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);
        if (!is_string($value)) {
            return null;
        }

        $trimmed = rtrim(trim($value), "\0");

        return $trimmed === '' ? null : $trimmed;
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

            if ($first instanceof UInt64) {
                if (!$first->fitsSignedInt()) {
                    return null;
                }

                $first = $first->toInt('EXIF sub-second component');
            }

            if ($first === null) {
                return null;
            }

            return (string) (int) $first;
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
        $rawDateTime = rtrim($rawDateTime ?? '', " \0");

        if ($rawDateTime === '' || strlen($rawDateTime) < 19) {
            return null;
        }

        $rawDateTime = substr($rawDateTime, 0, 19);

        // EXIF 3.0 §4.6.5.4.5 / §4.6.6.6.1 / §4.6.6.6.2: strict "YYYY:MM:DD HH:MM:SS"
        if (preg_match('/\A\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}\z/', $rawDateTime) !== 1) {
            return null;
        }

        // EXIF DateTime* tags are local date/time values; without OffsetTime*
        // the absolute instant is undefined and is intentionally not inferred.
        if ($rawOffset === null || trim($rawOffset) === '') {
            return null;
        }

        $timeZone = ValueConverters::parseOffset($rawOffset);
        if (!$timeZone instanceof DateTimeZone) {
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

        $dt = DateTimeImmutable::createFromFormat($format, $normalized, $timeZone);

        if ($dt === false) {
            return null;
        }

        $lastErrors = DateTimeImmutable::getLastErrors();
        if (
            is_array($lastErrors)
            && (
                $lastErrors['warning_count'] > 0
                || $lastErrors['error_count'] > 0
            )
        ) {
            return null;
        }

        return $dt;
    }

    // ========================================================================
    // Alias methods with exact EXIF/TIFF tag names
    // ========================================================================
    // These methods provide aliases using the exact tag names from the EXIF
    // and TIFF specifications, allowing access via both the friendly names
    // and the official specification tag names.
    // ========================================================================

    /**
     * Alias for imageHeight() using exact EXIF tag name.
     * EXIF 3.0 §4.6.5.1.2 ImageLength — Tag 0x0101, type SHORT or LONG, count 1; no default; not used for JPEG compressed data.
     *
     * @return int|null Image height in pixels
     */
    public function imageLength(): ?int
    {
        return $this->imageHeight();
    }

    /**
     * Alias for imageWidth() using exact EXIF tag name.
     * EXIF 3.0 §4.6.3 Tag Support Levels, Table 9 — Tag 0xA002 PixelXDimension.
     *
     * @return int|null Image width in pixels
     */
    public function pixelXDimension(): ?int
    {
        return $this->imageWidth();
    }

    /**
     * Alias for imageHeight() using exact EXIF tag name.
     * EXIF 3.0 §4.6.3 Tag Support Levels, Table 9 — Tag 0xA003 PixelYDimension.
     *
     * @return int|null Image height in pixels
     */
    public function pixelYDimension(): ?int
    {
        return $this->imageHeight();
    }

    /**
     * Alias for iso() using exact EXIF tag name.
     * EXIF 3.0 §4.6.6.7.5 (PhotographicSensitivity).
     *
     * @return int|null ISO sensitivity value
     */
    public function photographicSensitivity(): ?int
    {
        return $this->iso();
    }

    /**
     * Alias for isoSpeedValue() using exact EXIF tag name.
     * EXIF 3.0 §4.6.3 Tag Support Levels, Table 9 — Tag 0x8833 ISOSpeed.
     *
     * @return int|null ISO speed value
     */
    public function iSOSpeed(): ?int
    {
        return $this->isoSpeedValue();
    }

    /**
     * Alias for focalLength35Mm() using exact EXIF tag name.
     * EXIF 3.0 §4.6.3 Tag Support Levels, Table 9 — Tag 0xA405 FocalLengthIn35mmFilm.
     *
     * @return int|null Focal length in 35mm equivalent
     */
    public function focalLengthIn35mmFilm(): ?int
    {
        return $this->focalLength35Mm();
    }

    // ========================================================================
    // TIFF 6.0 tag getter methods for rarely-used tags
    // ========================================================================
    // These methods provide access to rarely-used TIFF 6.0 tags that are
    // primarily for specialized printing, scanning, and halftone applications.
    // Most photography use cases will not need these tags.
    // ========================================================================

    /**
     * Returns NewSubfileType tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x00FE.
     *
     * @return int
     */
    public function newSubfileType(): int
    {
        // TIFF 6.0 §8: default is 0 (full-resolution image data).
        return $this->int($this->ifd0, TiffTag::NEW_SUBFILE_TYPE) ?? 0;
    }

    /**
     * Returns SubfileType tag value (deprecated).
     * TIFF 5.0 (deprecated in TIFF 6.0) — Tag 0x00FF.
     *
     * @return int|null
     */
    public function subfileType(): ?int
    {
        return $this->int($this->ifd0, TiffTag::SUBFILE_TYPE);
    }

    /**
     * Returns Threshholding tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0107.
     *
     * @return int
     */
    public function threshholding(): int
    {
        // TIFF 6.0 §8: default is 1 (No dithering or halftoning).
        return $this->int($this->ifd0, TiffTag::THRESHHOLDING) ?? 1;
    }

    /**
     * Returns CellWidth tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0108.
     *
     * @return int|null
     */
    public function cellWidth(): ?int
    {
        return $this->int($this->ifd0, TiffTag::CELL_WIDTH);
    }

    /**
     * Returns CellLength tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0109.
     *
     * @return int|null
     */
    public function cellLength(): ?int
    {
        return $this->int($this->ifd0, TiffTag::CELL_LENGTH);
    }

    /**
     * Returns FillOrder tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x010A.
     *
     * @return int
     */
    public function fillOrder(): int
    {
        // TIFF 6.0 §8: default is 1 (most significant bits first).
        return $this->int($this->ifd0, TiffTag::FILL_ORDER) ?? 1;
    }

    /**
     * Returns MinSampleValue tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0118.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function minSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::MIN_SAMPLE_VALUE);
    }

    /**
     * Returns MaxSampleValue tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0119.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function maxSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::MAX_SAMPLE_VALUE);
    }

    /**
     * Returns PageName tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x011D.
     *
     * @return string|null
     */
    public function pageName(): ?string
    {
        return $this->str($this->ifd0, TiffTag::PAGE_NAME);
    }

    /**
     * Returns XPosition tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x011E.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function xPosition(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::X_POSITION);
    }

    /**
     * Returns YPosition tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x011F.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function yPosition(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::Y_POSITION);
    }

    /**
     * Returns FreeOffsets tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0120.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function freeOffsets(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::FREE_OFFSETS);
    }

    /**
     * Returns FreeByteCounts tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0121.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function freeByteCounts(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::FREE_BYTE_COUNTS);
    }

    /**
     * Returns GrayResponseUnit tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0122.
     *
     * @return int
     */
    public function grayResponseUnit(): int
    {
        // TIFF 6.0 §8: default is 2 (hundredths of a unit).
        return $this->int($this->ifd0, TiffTag::GRAY_RESPONSE_UNIT) ?? 2;
    }

    /**
     * Returns GrayResponseCurve tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0123.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function grayResponseCurve(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::GRAY_RESPONSE_CURVE);
    }

    /**
     * Returns T4Options tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0124.
     *
     * @return int
     */
    public function t4Options(): int
    {
        // TIFF 6.0 §11: default is 0 (1-D encoding).
        return $this->int($this->ifd0, TiffTag::T4_OPTIONS) ?? 0;
    }

    /**
     * Returns T6Options tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0125.
     *
     * @return int
     */
    public function t6Options(): int
    {
        // TIFF 6.0 §11: default is 0 (no uncompressed mode).
        return $this->int($this->ifd0, TiffTag::T6_OPTIONS) ?? 0;
    }

    /**
     * Returns PageNumber tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0129.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function pageNumber(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::PAGE_NUMBER);
    }

    /**
     * Returns ColorMap tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0140.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function colorMap(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::COLOR_MAP);
    }

    /**
     * Returns HalftoneHints tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0141.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function halftoneHints(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::HALFTONE_HINTS);
    }

    /**
     * Returns InkSet tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x014C.
     *
     * @return int
     */
    public function inkSet(): int
    {
        // TIFF 6.0 §8: default is 1 (CMYK).
        return $this->int($this->ifd0, TiffTag::INK_SET) ?? 1;
    }

    /**
     * Returns InkNames tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x014D.
     *
     * @return string|null
     */
    public function inkNames(): ?string
    {
        return $this->str($this->ifd0, TiffTag::INK_NAMES);
    }

    /**
     * Returns NumberOfInks tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x014E.
     *
     * @return int
     */
    public function numberOfInks(): int
    {
        // TIFF 6.0 §8: default is 4 (for CMYK).
        return $this->int($this->ifd0, TiffTag::NUMBER_OF_INKS) ?? 4;
    }

    /**
     * Returns DotRange tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0150.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function dotRange(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::DOT_RANGE);
    }

    /**
     * Returns TargetPrinter tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0151.
     *
     * @return string|null
     */
    public function targetPrinter(): ?string
    {
        return $this->str($this->ifd0, TiffTag::TARGET_PRINTER);
    }

    /**
     * Returns ExtraSamples tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0152.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function extraSamples(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::EXTRA_SAMPLES);
    }

    /**
     * Returns SampleFormat tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0153.
     *
     * @return int
     */
    public function sampleFormat(): int
    {
        // TIFF 6.0 §8: default is 1 (unsigned integer data).
        return $this->int($this->ifd0, TiffTag::SAMPLE_FORMAT) ?? 1;
    }

    /**
     * Returns SMinSampleValue tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0154.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function sMinSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::S_MIN_SAMPLE_VALUE);
    }

    /**
     * Returns SMaxSampleValue tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0155.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function sMaxSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::S_MAX_SAMPLE_VALUE);
    }

    /**
     * Returns TransferRange tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0156.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function transferRange(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::TRANSFER_RANGE);
    }

    /**
     * Returns JPEGProc tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0200.
     *
     * @return int|null
     */
    public function jpegProc(): ?int
    {
        return $this->int($this->ifd0, TiffTag::JPEG_PROC);
    }

    /**
     * Returns JPEGRestartInterval tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0203.
     *
     * @return int|null
     */
    public function jpegRestartInterval(): ?int
    {
        return $this->int($this->ifd0, TiffTag::JPEG_RESTART_INTERVAL);
    }

    /**
     * Returns JPEGLosslessPredictors tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0205.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function jpegLosslessPredictors(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::JPEG_LOSSLESS_PREDICTORS);
    }

    /**
     * Returns JPEGPointTransforms tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0206.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function jpegPointTransforms(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::JPEG_POINT_TRANSFORMS);
    }

    /**
     * Returns JPEGQTables tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0207.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function jpegQTables(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::JPEG_Q_TABLES);
    }

    /**
     * Returns JPEGDCTables tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0208.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function jpegDCTables(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::JPEG_DC_TABLES);
    }

    /**
     * Returns JPEGACTables tag value.
     * TIFF 6.0 §8 Baseline Field Reference — Tag 0x0209.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    public function jpegACTables(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->normalisedValue($this->ifd0, TiffTag::JPEG_AC_TABLES);
    }
}
