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
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Reader\DeviceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ExposureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\GpsExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageExifReader;
use MagicSunday\ImageMeta\Exif\Reader\TemporalExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ThumbnailExifReader;
use MagicSunday\ImageMeta\Exif\Reader\TiffBaselineExifReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
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

/**
 * Represents a parsed EXIF payload and exposes convenience accessors.
 *
 * EXIF 3.0 §4 and Annex A summarise the logical grouping of tags mirrored by
 * the accessors provided in this value object. Domain-specific logic has been
 * extracted into dedicated reader classes; this class delegates all calls.
 *
 * @phpstan-import-type GpsFieldMap from GpsConverter
 */
final class ParsedExif implements ExifIfd0Data, ExifIfd1Data, ExifSubIfdData, ExifGpsData, ExifInteropData
{
    private readonly ?string $exifVersion;

    private readonly string $exifProfile;

    private readonly Endian $byteOrder;

    private ?IfdValueReader $cachedReader = null;

    private ?FallbackIfdSet $cachedFallbackIfdSet = null;

    private ?GpsExifReader $cachedGpsReader = null;

    private ?TemporalExifReader $cachedTemporalReader = null;

    private ?ExposureExifReader $cachedExposureReader = null;

    private ?ImageExifReader $cachedImageReader = null;

    private ?DeviceExifReader $cachedDeviceReader = null;

    private ?ThumbnailExifReader $cachedThumbnailReader = null;

    private ?TiffBaselineExifReader $cachedTiffBaselineReader = null;

    /**
     * @param Ifd                   $ifd0           Root IFD of the TIFF structure.
     * @param Ifd|null              $exifIfd        Sub IFD containing EXIF-specific tags.
     * @param Ifd|null              $gpsIfd         Sub IFD containing GPS-related tags.
     * @param Ifd|null              $interopIfd     Sub IFD containing interoperability tags.
     * @param Ifd|null              $ifd1           Optional next IFD, typically thumbnails.
     * @param MakerNotesRecord|null $makerNotes     Decoded maker note metadata provided by vendor decoders.
     * @param list<Ifd>             $subsequentIfds Additional linked IFDs discovered via the next-pointer chain.
     * @param array<int, Ifd>       $subIfds        Parsed SubIFDs indexed by their file offsets.
     * @param ValueConverters       $converters     Value converter facade for EXIF type normalization.
     */
    public function __construct(
        public readonly Ifd $ifd0,
        public readonly ?Ifd $exifIfd,
        public readonly ?Ifd $gpsIfd,
        public readonly ?Ifd $interopIfd,
        public readonly ?Ifd $ifd1,
        public readonly ?MakerNotesRecord $makerNotes = null,
        public readonly array $subsequentIfds = [],
        public readonly array $subIfds = [],
        ?Endian $byteOrder = null,
        private readonly ValueConverters $converters = new ValueConverters(),
    ) {
        $rawVersion        = $this->reader()->rawString($this->exifIfd, ExifTag::EXIF_VERSION);
        $this->exifVersion = $this->converters->toExifVersion($rawVersion);
        $this->exifProfile = ExifCapabilities::fromVersion($this->exifVersion);
        $this->byteOrder   = $byteOrder ?? Endian::Little;
    }

    // ── Core access ─────────────────────────────────────────────

    public function makerNotes(): ?MakerNotesRecord
    {
        return $this->makerNotes;
    }

    /**
     * @return list<Ifd>
     */
    public function subsequentIfds(): array
    {
        return $this->subsequentIfds;
    }

    /**
     * @return array<int, Ifd>
     */
    public function subIfds(): array
    {
        return $this->subIfds;
    }

    public function exifIfdPointer(): ?int
    {
        return $this->reader()->int($this->ifd0, ExifTag::EXIF_IFD_POINTER);
    }

    public function gpsIfdPointer(): ?int
    {
        return $this->reader()->int($this->ifd0, ExifTag::GPS_IFD_POINTER);
    }

    public function interoperabilityIfdPointer(): ?int
    {
        return $this->reader()->int($this->exifIfd, ExifTag::INTEROPERABILITY_IFD_POINTER);
    }

    // ── Image domain ────────────────────────────────────────────

    public function cameraMake(): ?string
    {
        return $this->imageReader()->cameraMake();
    }

    public function cameraModel(): ?string
    {
        return $this->imageReader()->cameraModel();
    }

    public function lensModel(): ?string
    {
        return $this->imageReader()->lensModel();
    }

    public function lensMake(): ?string
    {
        return $this->imageReader()->lensMake();
    }

    public function ownerName(): ?string
    {
        return $this->imageReader()->ownerName();
    }

    public function bodySerialNumber(): ?string
    {
        return $this->imageReader()->bodySerialNumber();
    }

    public function lensSerialNumber(): ?string
    {
        return $this->imageReader()->lensSerialNumber();
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public function lensSpecification(): ?array
    {
        return $this->imageReader()->lensSpecification();
    }

    public function orientation(): Orientation
    {
        return $this->imageReader()->orientation();
    }

    public function orientationDescription(): string
    {
        return $this->imageReader()->orientationDescription();
    }

    public function imageWidth(): ?int
    {
        return $this->imageReader()->imageWidth();
    }

    public function imageHeight(): ?int
    {
        return $this->imageReader()->imageHeight();
    }

    public function imageLength(): ?int
    {
        return $this->imageReader()->imageLength();
    }

    public function pixelXDimension(): ?int
    {
        return $this->imageReader()->pixelXDimension();
    }

    public function pixelYDimension(): ?int
    {
        return $this->imageReader()->pixelYDimension();
    }

    public function colorSpace(): ?ColorSpace
    {
        return $this->imageReader()->colorSpace();
    }

    public function imageUniqueId(): ?string
    {
        return $this->imageReader()->imageUniqueId();
    }

    public function exifVersion(): ?string
    {
        return $this->imageReader()->exifVersion();
    }

    public function flashpixVersion(): ?string
    {
        return $this->imageReader()->flashpixVersion();
    }

    public function dngVersion(): ?string
    {
        return $this->imageReader()->dngVersion();
    }

    public function dngBackwardVersion(): ?string
    {
        return $this->imageReader()->dngBackwardVersion();
    }

    public function uniqueCameraModel(): ?string
    {
        return $this->imageReader()->uniqueCameraModel();
    }

    public function localizedCameraModel(): ?string
    {
        return $this->imageReader()->localizedCameraModel();
    }

    public function exifProfile(): string
    {
        return $this->imageReader()->exifProfile();
    }

    public function imageTitle(): ?string
    {
        return $this->imageReader()->imageTitle();
    }

    public function documentName(): ?string
    {
        return $this->imageReader()->documentName();
    }

    public function imageDescription(): ?string
    {
        return $this->imageReader()->imageDescription();
    }

    public function hostComputer(): ?string
    {
        return $this->reader()->str($this->ifd0, TiffTag::HOST_COMPUTER);
    }

    public function software(): ?string
    {
        return $this->imageReader()->software();
    }

    public function photographer(): ?string
    {
        return $this->imageReader()->photographer();
    }

    public function imageEditor(): ?string
    {
        return $this->imageReader()->imageEditor();
    }

    /**
     * @return list<int>|null
     */
    public function stripOffsets(): ?array
    {
        return $this->imageReader()->stripOffsets();
    }

    /**
     * @return list<int>|null
     */
    public function stripByteCounts(): ?array
    {
        return $this->imageReader()->stripByteCounts();
    }

    /**
     * @return list<float>|null
     */
    public function referenceBlackWhite(): ?array
    {
        return $this->imageReader()->referenceBlackWhite();
    }

    public function copyright(): ?string
    {
        return $this->imageReader()->copyright();
    }

    /**
     * @return list<int>|null
     */
    public function componentsConfiguration(): ?array
    {
        return $this->imageReader()->componentsConfiguration();
    }

    /**
     * @return list<string>|null
     */
    public function componentsConfigurationLabels(): ?array
    {
        return $this->imageReader()->componentsConfigurationLabels();
    }

    public function componentsConfigurationDescription(): ?string
    {
        return $this->imageReader()->componentsConfigurationDescription();
    }

    public function compressedBitsPerPixel(): ?float
    {
        return $this->imageReader()->compressedBitsPerPixel();
    }

    public function userComment(): ?string
    {
        return $this->imageReader()->userComment();
    }

    public function userCommentEncoding(): ?string
    {
        return $this->imageReader()->userCommentEncoding();
    }

    public function userCommentEncodingBestEffort(): ?string
    {
        return $this->imageReader()->userCommentEncodingBestEffort();
    }

    public function bitsPerSample(): ?int
    {
        return $this->imageReader()->bitsPerSample();
    }

    /**
     * @return list<int>|null
     */
    public function bitsPerSampleList(): ?array
    {
        return $this->imageReader()->bitsPerSampleList();
    }

    public function samplesPerPixel(): int
    {
        return $this->imageReader()->samplesPerPixel();
    }

    public function rowsPerStrip(): ?int
    {
        return $this->imageReader()->rowsPerStrip();
    }

    public function compression(): ?Compression
    {
        return $this->imageReader()->compression();
    }

    public function photometric(): ?Photometric
    {
        return $this->imageReader()->photometric();
    }

    public function planarConfiguration(): ?PlanarConfiguration
    {
        return $this->imageReader()->planarConfiguration();
    }

    public function resolutionUnit(): ResolutionUnit
    {
        return $this->imageReader()->resolutionUnit();
    }

    public function xResolution(): ?float
    {
        return $this->imageReader()->xResolution();
    }

    public function yResolution(): ?float
    {
        return $this->imageReader()->yResolution();
    }

    public function ycbcrPositioning(): ?YCbCrPositioning
    {
        return $this->imageReader()->ycbcrPositioning();
    }

    /**
     * @return array{0:int,1:int}|null
     */
    public function ycbcrSubSampling(): ?array
    {
        return $this->imageReader()->ycbcrSubSampling();
    }

    /**
     * @return array{0:float,1:float,2:float}|null
     */
    public function ycbcrCoefficients(): ?array
    {
        return $this->imageReader()->ycbcrCoefficients();
    }

    /**
     * @return array{0:float,1:float}|null
     */
    public function whitePoint(): ?array
    {
        return $this->imageReader()->whitePoint();
    }

    /**
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public function primaryChromaticities(): ?array
    {
        return $this->imageReader()->primaryChromaticities();
    }

    public function jpegInterchangeFormat(): ?int
    {
        return $this->imageReader()->jpegInterchangeFormat();
    }

    public function jpegInterchangeFormatLength(): ?int
    {
        return $this->imageReader()->jpegInterchangeFormatLength();
    }

    public function artist(): ?string
    {
        return $this->imageReader()->artist();
    }

    public function gamma(): ?float
    {
        return $this->imageReader()->gamma();
    }

    // ── Thumbnail domain ────────────────────────────────────────

    public function hasThumbnail(): bool
    {
        return $this->thumbnailReader()->hasThumbnail();
    }

    public function thumbnailJpegInterchangeFormat(): ?int
    {
        return $this->thumbnailReader()->thumbnailJpegInterchangeFormat();
    }

    public function thumbnailJpegInterchangeFormatLength(): ?int
    {
        return $this->thumbnailReader()->thumbnailJpegInterchangeFormatLength();
    }

    public function thumbnailCompression(): ?Compression
    {
        return $this->thumbnailReader()->thumbnailCompression();
    }

    public function thumbnailTileWidth(): ?int
    {
        return $this->thumbnailReader()->thumbnailTileWidth();
    }

    public function thumbnailTileLength(): ?int
    {
        return $this->thumbnailReader()->thumbnailTileLength();
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailTileOffsets(): ?array
    {
        return $this->thumbnailReader()->thumbnailTileOffsets();
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailTileByteCounts(): ?array
    {
        return $this->thumbnailReader()->thumbnailTileByteCounts();
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailStripOffsets(): ?array
    {
        return $this->thumbnailReader()->thumbnailStripOffsets();
    }

    /**
     * @return list<int>|null
     */
    public function thumbnailStripByteCounts(): ?array
    {
        return $this->thumbnailReader()->thumbnailStripByteCounts();
    }

    // ── Exposure domain ─────────────────────────────────────────

    public function spectralSensitivity(): ?string
    {
        return $this->exposureReader()->spectralSensitivity();
    }

    public function oecf(): ?Oecf
    {
        return $this->exposureReader()->oecf();
    }

    public function oecfPayload(): ?string
    {
        return $this->exposureReader()->oecfPayload();
    }

    public function sensitivityType(): ?SensitivityType
    {
        return $this->exposureReader()->sensitivityType();
    }

    public function standardOutputSensitivity(): ?int
    {
        return $this->exposureReader()->standardOutputSensitivity();
    }

    public function recommendedExposureIndex(): ?int
    {
        return $this->exposureReader()->recommendedExposureIndex();
    }

    public function isoSpeedValue(): ?int
    {
        return $this->exposureReader()->isoSpeedValue();
    }

    public function iso(): ?int
    {
        return $this->exposureReader()->iso();
    }

    public function isoBestEffort(): ?int
    {
        return $this->exposureReader()->isoBestEffort();
    }

    public function isoSpeedLatitudeYyy(): ?int
    {
        return $this->exposureReader()->isoSpeedLatitudeYyy();
    }

    public function isoSpeedLatitudeZzz(): ?int
    {
        return $this->exposureReader()->isoSpeedLatitudeZzz();
    }

    public function exposureTime(): ?float
    {
        return $this->exposureReader()->exposureTime();
    }

    public function exposureTimeFormatted(): ?string
    {
        return $this->exposureReader()->exposureTimeFormatted();
    }

    public function shutterSpeedValue(): ?float
    {
        return $this->exposureReader()->shutterSpeedValue();
    }

    public function shutterSpeedSeconds(): ?float
    {
        return $this->exposureReader()->shutterSpeedSeconds();
    }

    public function shutterSpeedFormatted(): ?string
    {
        return $this->exposureReader()->shutterSpeedFormatted();
    }

    public function fNumber(): ?float
    {
        return $this->exposureReader()->fNumber();
    }

    public function apertureValue(): ?float
    {
        return $this->exposureReader()->apertureValue();
    }

    public function apertureValueFormatted(): ?string
    {
        return $this->exposureReader()->apertureValueFormatted();
    }

    public function focalLengthMm(): ?float
    {
        return $this->exposureReader()->focalLengthMm();
    }

    public function focalLength35Mm(): ?int
    {
        return $this->exposureReader()->focalLength35Mm();
    }

    public function exposureProgram(): ?ExposureProgram
    {
        return $this->exposureReader()->exposureProgram();
    }

    public function meteringMode(): ?MeteringMode
    {
        return $this->exposureReader()->meteringMode();
    }

    public function flash(): ?int
    {
        return $this->exposureReader()->flash();
    }

    public function flashInfo(): ?FlashInfo
    {
        return $this->exposureReader()->flashInfo();
    }

    public function flashEnergy(): ?float
    {
        return $this->exposureReader()->flashEnergy();
    }

    public function whiteBalance(): ?WhiteBalance
    {
        return $this->exposureReader()->whiteBalance();
    }

    public function exposureBias(): ?float
    {
        return $this->exposureReader()->exposureBias();
    }

    public function brightnessValue(): ?float
    {
        return $this->exposureReader()->brightnessValue();
    }

    public function brightnessValueFormatted(): ?string
    {
        return $this->exposureReader()->brightnessValueFormatted();
    }

    public function maxApertureApex(): ?float
    {
        return $this->exposureReader()->maxApertureApex();
    }

    public function focalPlaneXResolution(): ?float
    {
        return $this->exposureReader()->focalPlaneXResolution();
    }

    public function focalPlaneYResolution(): ?float
    {
        return $this->exposureReader()->focalPlaneYResolution();
    }

    public function focalPlaneResolutionUnit(): int
    {
        return $this->exposureReader()->focalPlaneResolutionUnit();
    }

    /**
     * @return list<int>|null
     */
    public function subjectLocation(): ?array
    {
        return $this->exposureReader()->subjectLocation();
    }

    public function exposureIndex(): ?float
    {
        return $this->exposureReader()->exposureIndex();
    }

    public function relatedSoundFile(): ?string
    {
        return $this->reader()->str($this->exifIfd, ExifTag::RELATED_SOUND_FILE);
    }

    public function spatialFrequencyResponse(): ?SpatialFrequencyResponse
    {
        return $this->exposureReader()->spatialFrequencyResponse();
    }

    public function compositeImage(): ?CompositeImage
    {
        return $this->exposureReader()->compositeImage();
    }

    /**
     * @return array{0:int,1:int}|null
     */
    public function sourceImageNumberOfCompositeImage(): ?array
    {
        return $this->exposureReader()->sourceImageNumberOfCompositeImage();
    }

    public function sourceExposureTimesOfCompositeImage(): ?SourceExposureTimes
    {
        return $this->exposureReader()->sourceExposureTimesOfCompositeImage();
    }

    public function cfaPattern(): ?CfaPattern
    {
        return $this->exposureReader()->cfaPattern();
    }

    /**
     * @return list<CfaPatternColor>|null
     */
    public function cfaPatternColors(): ?array
    {
        return $this->exposureReader()->cfaPatternColors();
    }

    public function sceneType(): ?SceneType
    {
        return $this->exposureReader()->sceneType();
    }

    public function customRendered(): ?CustomRendered
    {
        return $this->exposureReader()->customRendered();
    }

    public function contrast(): ?Contrast
    {
        return $this->exposureReader()->contrast();
    }

    public function saturation(): ?Saturation
    {
        return $this->exposureReader()->saturation();
    }

    public function sharpness(): ?Sharpness
    {
        return $this->exposureReader()->sharpness();
    }

    public function sensingMethod(): ?SensingMethod
    {
        return SensingMethod::fromExifValue($this->reader()->enumValue($this->exifIfd, ExifTag::SENSING_METHOD));
    }

    public function lightSource(): ?LightSource
    {
        return $this->exposureReader()->lightSource();
    }

    public function sceneCaptureType(): ?SceneCaptureType
    {
        return $this->exposureReader()->sceneCaptureType();
    }

    public function subjectDistanceRange(): ?SubjectDistanceRange
    {
        return $this->exposureReader()->subjectDistanceRange();
    }

    public function subjectDistance(): ?float
    {
        return $this->exposureReader()->subjectDistance();
    }

    public function subjectArea(): ?SubjectArea
    {
        return $this->exposureReader()->subjectArea();
    }

    public function digitalZoomRatio(): ?float
    {
        return $this->exposureReader()->digitalZoomRatio();
    }

    public function exposureMode(): ?ExposureMode
    {
        return $this->exposureReader()->exposureMode();
    }

    public function gainControl(): ?GainControl
    {
        return $this->exposureReader()->gainControl();
    }

    public function fileSource(): ?FileSource
    {
        return $this->exposureReader()->fileSource();
    }

    public function interopIndex(): ?string
    {
        return $this->exposureReader()->interopIndex();
    }

    // ── Exposure aliases ────────────────────────────────────────

    public function isoLatitudeYyy(): ?int
    {
        return $this->exposureReader()->isoLatitudeYyy();
    }

    public function isoLatitudeZzz(): ?int
    {
        return $this->exposureReader()->isoLatitudeZzz();
    }

    public function shutterSpeedEv(): ?float
    {
        return $this->exposureReader()->shutterSpeedEv();
    }

    public function apertureEv(): ?float
    {
        return $this->exposureReader()->apertureEv();
    }

    public function photographicSensitivity(): ?int
    {
        return $this->exposureReader()->photographicSensitivity();
    }

    public function iSOSpeed(): ?int
    {
        return $this->exposureReader()->iSOSpeed();
    }

    public function focalLengthIn35mmFilm(): ?int
    {
        return $this->exposureReader()->focalLengthIn35mmFilm();
    }

    // ── Device domain ───────────────────────────────────────────

    public function deviceSettingDescription(): ?DeviceSettingDescription
    {
        return $this->deviceReader()->deviceSettingDescription();
    }

    public function temperatureCelsius(): ?float
    {
        return $this->deviceReader()->temperatureCelsius();
    }

    public function humidityPercent(): ?float
    {
        return $this->deviceReader()->humidityPercent();
    }

    public function pressureHPa(): ?float
    {
        return $this->deviceReader()->pressureHPa();
    }

    public function waterDepthMeters(): ?float
    {
        return $this->deviceReader()->waterDepthMeters();
    }

    /**
     * @return array{0:float,1:float,2:float}|null
     */
    public function accelerationVector(): ?array
    {
        return $this->deviceReader()->accelerationVector();
    }

    public function accelerationMs2(): ?float
    {
        return $this->deviceReader()->accelerationMs2();
    }

    public function cameraElevationAngleDeg(): ?float
    {
        return $this->deviceReader()->cameraElevationAngleDeg();
    }

    public function cameraFirmware(): ?string
    {
        return $this->deviceReader()->cameraFirmware();
    }

    public function rawDevelopingSoftware(): ?string
    {
        return $this->deviceReader()->rawDevelopingSoftware();
    }

    public function imageEditingSoftware(): ?string
    {
        return $this->deviceReader()->imageEditingSoftware();
    }

    public function metadataEditingSoftware(): ?string
    {
        return $this->deviceReader()->metadataEditingSoftware();
    }

    // ── Temporal domain ─────────────────────────────────────────

    public function dateTimeOriginalRaw(): ?string
    {
        return $this->temporalReader()->dateTimeOriginalRaw();
    }

    public function dateTimeOriginal(): ?DateTimeImmutable
    {
        return $this->temporalReader()->dateTimeOriginal();
    }

    public function dateTimeOriginalBestEffort(): ?DateTimeImmutable
    {
        return $this->temporalReader()->dateTimeOriginalBestEffort();
    }

    public function subSecTimeOriginal(): ?string
    {
        return $this->temporalReader()->subSecTimeOriginal();
    }

    public function dateTimeDigitizedRaw(): ?string
    {
        return $this->temporalReader()->dateTimeDigitizedRaw();
    }

    public function subSecTimeDigitized(): ?string
    {
        return $this->temporalReader()->subSecTimeDigitized();
    }

    public function dateTimeRaw(): ?string
    {
        return $this->temporalReader()->dateTimeRaw();
    }

    public function subSecTime(): ?string
    {
        return $this->temporalReader()->subSecTime();
    }

    public function offsetTimeOriginal(): ?string
    {
        return $this->temporalReader()->offsetTimeOriginal();
    }

    public function offsetTimeDigitized(): ?string
    {
        return $this->temporalReader()->offsetTimeDigitized();
    }

    public function offsetTime(): ?string
    {
        return $this->temporalReader()->offsetTime();
    }

    public function captureDateTime(): ?DateTimeImmutable
    {
        return $this->temporalReader()->captureDateTime();
    }

    public function dateTimeDigitized(): ?DateTimeImmutable
    {
        return $this->temporalReader()->dateTimeDigitized();
    }

    public function dateTime(): ?DateTimeImmutable
    {
        return $this->temporalReader()->dateTime();
    }

    // ── GPS domain ──────────────────────────────────────────────

    /**
     * @return GpsFieldMap
     */
    public function gps(): array
    {
        return $this->gpsReader()->gps();
    }

    public function gpsDateStamp(): ?string
    {
        return $this->gpsReader()->gpsDateStamp();
    }

    public function gpsTimeStampString(): ?string
    {
        return $this->gpsReader()->gpsTimeStampString();
    }

    public function gpsTimestamp(): ?DateTimeImmutable
    {
        return $this->gpsReader()->gpsTimestamp();
    }

    public function gpsSpeedRef(): ?string
    {
        return $this->gpsReader()->gpsSpeedRef();
    }

    public function gpsSpeedMetresPerSecond(): ?float
    {
        return $this->gpsReader()->gpsSpeedMetresPerSecond();
    }

    public function gpsTrackRef(): ?string
    {
        return $this->gpsReader()->gpsTrackRef();
    }

    public function gpsTrack(): ?float
    {
        return $this->gpsReader()->gpsTrack();
    }

    public function gpsImgDirectionRef(): ?string
    {
        return $this->gpsReader()->gpsImgDirectionRef();
    }

    public function gpsImgDirection(): ?float
    {
        return $this->gpsReader()->gpsImgDirection();
    }

    public function gpsDestinationBearingRef(): ?string
    {
        return $this->gpsReader()->gpsDestinationBearingRef();
    }

    public function gpsDestinationBearing(): ?float
    {
        return $this->gpsReader()->gpsDestinationBearing();
    }

    public function gpsDestinationDistanceRef(): ?string
    {
        return $this->gpsReader()->gpsDestinationDistanceRef();
    }

    public function gpsDestinationDistanceMetres(): ?float
    {
        return $this->gpsReader()->gpsDestinationDistanceMetres();
    }

    public function gpsDifferential(): ?int
    {
        return $this->gpsReader()->gpsDifferential();
    }

    public function gpsHorizontalPositioningError(): ?float
    {
        return $this->gpsReader()->gpsHorizontalPositioningError();
    }

    // ── TIFF Baseline domain ────────────────────────────────────

    public function tileWidth(): ?int
    {
        return $this->tiffBaselineReader()->tileWidth();
    }

    public function tileLength(): ?int
    {
        return $this->tiffBaselineReader()->tileLength();
    }

    /**
     * @return list<int>|null
     */
    public function tileOffsets(): ?array
    {
        return $this->tiffBaselineReader()->tileOffsets();
    }

    /**
     * @return list<int>|null
     */
    public function tileByteCounts(): ?array
    {
        return $this->tiffBaselineReader()->tileByteCounts();
    }

    /**
     * @return list<int>|null
     */
    public function transferFunction(): ?array
    {
        return $this->tiffBaselineReader()->transferFunction();
    }

    public function predictor(): int
    {
        return $this->tiffBaselineReader()->predictor();
    }

    public function newSubfileType(): int
    {
        return $this->tiffBaselineReader()->newSubfileType();
    }

    public function subfileType(): ?int
    {
        return $this->tiffBaselineReader()->subfileType();
    }

    public function threshholding(): int
    {
        return $this->tiffBaselineReader()->threshholding();
    }

    public function cellWidth(): ?int
    {
        return $this->tiffBaselineReader()->cellWidth();
    }

    public function cellLength(): ?int
    {
        return $this->tiffBaselineReader()->cellLength();
    }

    public function fillOrder(): int
    {
        return $this->tiffBaselineReader()->fillOrder();
    }

    public function minSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList
    {
        return $this->tiffBaselineReader()->minSampleValue();
    }

    public function maxSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList
    {
        return $this->tiffBaselineReader()->maxSampleValue();
    }

    public function pageName(): ?string
    {
        return $this->tiffBaselineReader()->pageName();
    }

    public function xPosition(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->xPosition();
    }

    public function yPosition(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->yPosition();
    }

    public function freeOffsets(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->freeOffsets();
    }

    public function freeByteCounts(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->freeByteCounts();
    }

    public function grayResponseUnit(): int
    {
        return $this->tiffBaselineReader()->grayResponseUnit();
    }

    public function grayResponseCurve(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->grayResponseCurve();
    }

    public function t4Options(): int
    {
        return $this->tiffBaselineReader()->t4Options();
    }

    public function t6Options(): int
    {
        return $this->tiffBaselineReader()->t6Options();
    }

    public function pageNumber(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->pageNumber();
    }

    public function colorMap(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->colorMap();
    }

    public function halftoneHints(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->halftoneHints();
    }

    public function inkSet(): int
    {
        return $this->tiffBaselineReader()->inkSet();
    }

    public function inkNames(): ?string
    {
        return $this->tiffBaselineReader()->inkNames();
    }

    public function numberOfInks(): int
    {
        return $this->tiffBaselineReader()->numberOfInks();
    }

    public function dotRange(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->dotRange();
    }

    public function targetPrinter(): ?string
    {
        return $this->tiffBaselineReader()->targetPrinter();
    }

    public function extraSamples(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->extraSamples();
    }

    public function sampleFormat(): int
    {
        return $this->tiffBaselineReader()->sampleFormat();
    }

    public function sMinSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->sMinSampleValue();
    }

    public function sMaxSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->sMaxSampleValue();
    }

    public function transferRange(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->transferRange();
    }

    public function jpegProc(): ?int
    {
        return $this->tiffBaselineReader()->jpegProc();
    }

    public function jpegRestartInterval(): ?int
    {
        return $this->tiffBaselineReader()->jpegRestartInterval();
    }

    public function jpegLosslessPredictors(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->jpegLosslessPredictors();
    }

    public function jpegPointTransforms(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->jpegPointTransforms();
    }

    public function jpegQTables(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->jpegQTables();
    }

    public function jpegDCTables(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->jpegDCTables();
    }

    public function jpegACTables(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->jpegACTables();
    }

    // ── Lazy reader factories ───────────────────────────────────

    private function reader(): IfdValueReader
    {
        return $this->cachedReader ??= new IfdValueReader($this->converters);
    }

    private function fallbackIfdSet(): FallbackIfdSet
    {
        return $this->cachedFallbackIfdSet ??= new FallbackIfdSet(
            $this->ifd1,
            $this->subIfds,
            $this->subsequentIfds,
            $this->ifd0,
        );
    }

    private function gpsReader(): GpsExifReader
    {
        return $this->cachedGpsReader ??= new GpsExifReader(
            $this->converters,
            $this->gpsIfd,
        );
    }

    private function temporalReader(): TemporalExifReader
    {
        return $this->cachedTemporalReader ??= new TemporalExifReader(
            $this->reader(),
            $this->converters,
            $this->exifIfd,
            $this->ifd0,
            $this->fallbackIfdSet(),
            $this->gpsReader(),
        );
    }

    private function exposureReader(): ExposureExifReader
    {
        return $this->cachedExposureReader ??= new ExposureExifReader(
            $this->reader(),
            $this->converters,
            $this->ifd0,
            $this->exifIfd,
            $this->interopIfd,
            $this->fallbackIfdSet(),
            $this->byteOrder,
        );
    }

    private function imageReader(): ImageExifReader
    {
        return $this->cachedImageReader ??= new ImageExifReader(
            $this->reader(),
            $this->converters,
            $this->ifd0,
            $this->exifIfd,
            $this->exifProfile,
            $this->fallbackIfdSet(),
        );
    }

    private function deviceReader(): DeviceExifReader
    {
        return $this->cachedDeviceReader ??= new DeviceExifReader(
            $this->reader(),
            $this->converters,
            $this->gpsIfd,
            $this->exifIfd,
            $this->byteOrder,
        );
    }

    private function thumbnailReader(): ThumbnailExifReader
    {
        return $this->cachedThumbnailReader ??= new ThumbnailExifReader(
            $this->reader(),
            $this->ifd1,
        );
    }

    private function tiffBaselineReader(): TiffBaselineExifReader
    {
        return $this->cachedTiffBaselineReader ??= new TiffBaselineExifReader(
            $this->reader(),
            $this->ifd0,
        );
    }
}
