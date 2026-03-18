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
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DeviceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ExposureParameterReader;
use MagicSunday\ImageMeta\Exif\Reader\FocalReader;
use MagicSunday\ImageMeta\Exif\Reader\GpsExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\IsoSensitivityReader;
use MagicSunday\ImageMeta\Exif\Reader\SceneModeReader;
use MagicSunday\ImageMeta\Exif\Reader\SensorDataReader;
use MagicSunday\ImageMeta\Exif\Reader\TemporalExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ThumbnailExifReader;
use MagicSunday\ImageMeta\Exif\Reader\TiffBaselineExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
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
use MagicSunday\ImageMeta\Value\Enum\CorrectionApplied;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\DevelopmentCharacteristic;
use MagicSunday\ImageMeta\Value\Enum\DevelopmentDefault;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
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
use MagicSunday\ImageMeta\Value\Enum\SensitivityType;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use MagicSunday\ImageMeta\Value\FlashInfo;
use MagicSunday\ImageMeta\Value\LearningOptOutIn;
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
final class ParsedExif
{
    private readonly string $exifProfile;

    private readonly Endian $byteOrder;

    private ?IfdValueReader $cachedReader                           = null;

    private ?FallbackIfdSet $cachedFallbackIfdSet                   = null;

    private ?GpsExifReader $cachedGpsReader                         = null;

    private ?TemporalExifReader $cachedTemporalReader               = null;

    private ?ExposureParameterReader $cachedExposureParameterReader = null;

    private ?IsoSensitivityReader $cachedIsoSensitivityReader       = null;

    private ?SceneModeReader $cachedSceneModeReader                 = null;

    private ?FocalReader $cachedFocalReader                         = null;

    private ?SensorDataReader $cachedSensorDataReader               = null;

    private ?CameraLensExifReader $cachedCameraLensReader           = null;

    private ?ImageStructureExifReader $cachedImageStructureReader   = null;

    private ?ColorSpaceExifReader $cachedColorSpaceReader           = null;

    private ?DngMetadataExifReader $cachedDngMetadataReader         = null;

    private ?UserCommentExifReader $cachedUserCommentReader         = null;

    private ?DescriptionExifReader $cachedDescriptionReader         = null;

    private ?DeviceExifReader $cachedDeviceReader                   = null;

    private ?ThumbnailExifReader $cachedThumbnailReader             = null;

    private ?TiffBaselineExifReader $cachedTiffBaselineReader       = null;

    /**
     * @param Ifd                   $ifd0           Root IFD of the TIFF structure.
     * @param Ifd|null              $exifIfd        Sub IFD containing EXIF-specific tags.
     * @param Ifd|null              $gpsIfd         Sub IFD containing GPS-related tags.
     * @param Ifd|null              $interopIfd     Sub IFD containing interoperability tags.
     * @param Ifd|null              $ifd1           Optional next IFD, typically thumbnails.
     * @param MakerNotesRecord|null $makerNotes     Decoded maker note metadata provided by vendor decoders.
     * @param list<Ifd>             $subsequentIfds Additional linked IFDs discovered via the next-pointer chain.
     * @param array<int, Ifd>       $subIfds        Parsed SubIFDs indexed by their file offsets.
     * @param string|null           $xmpPacketRaw   Raw UTF-8 XMP/RDF XML from TIFF tag 700 (0x02BC).
     * @param string|null           $iccProfileRaw  Raw ICC profile binary from TIFF tag 34675 (0x8773).
     * @param string|null           $iptcNaaRaw     Raw IPTC-IIM binary from TIFF tag 33723 (0x83BB).
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
        public readonly ?string $xmpPacketRaw = null,
        public readonly ?string $iccProfileRaw = null,
        public readonly ?string $iptcNaaRaw = null,
        ?Endian $byteOrder = null,
        private readonly ValueConverters $converters = new ValueConverters(),
    ) {
        $rawVersion        = $this->reader()->rawString($this->exifIfd, ExifTag::EXIF_VERSION);
        $exifVersion       = $this->converters->toExifVersion($rawVersion);
        $this->exifProfile = ExifCapabilities::fromVersion($exifVersion);
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

    // ── Camera / lens domain ──────────────────────────────────

    public function cameraMake(): ?string
    {
        return $this->cameraLensReader()->cameraMake();
    }

    public function cameraModel(): ?string
    {
        return $this->cameraLensReader()->cameraModel();
    }

    public function lensModel(): ?string
    {
        return $this->cameraLensReader()->lensModel();
    }

    public function lensMake(): ?string
    {
        return $this->cameraLensReader()->lensMake();
    }

    public function ownerName(): ?string
    {
        return $this->cameraLensReader()->ownerName();
    }

    public function bodySerialNumber(): ?string
    {
        return $this->cameraLensReader()->bodySerialNumber();
    }

    public function lensSerialNumber(): ?string
    {
        return $this->cameraLensReader()->lensSerialNumber();
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public function lensSpecification(): ?array
    {
        return $this->cameraLensReader()->lensSpecification();
    }

    public function uniqueCameraModel(): ?string
    {
        return $this->cameraLensReader()->uniqueCameraModel();
    }

    public function localizedCameraModel(): ?string
    {
        return $this->cameraLensReader()->localizedCameraModel();
    }

    // ── Image structure domain ─────────────────────────────────

    public function orientation(): Orientation
    {
        return $this->imageStructureReader()->orientation();
    }

    public function orientationDescription(): string
    {
        return $this->imageStructureReader()->orientationDescription();
    }

    public function imageWidth(): ?int
    {
        return $this->imageStructureReader()->imageWidth();
    }

    public function imageHeight(): ?int
    {
        return $this->imageStructureReader()->imageHeight();
    }

    public function imageLength(): ?int
    {
        return $this->imageStructureReader()->imageLength();
    }

    public function pixelXDimension(): ?int
    {
        return $this->imageStructureReader()->pixelXDimension();
    }

    public function pixelYDimension(): ?int
    {
        return $this->imageStructureReader()->pixelYDimension();
    }

    public function compression(): ?Compression
    {
        return $this->imageStructureReader()->compression();
    }

    public function compressedBitsPerPixel(): ?float
    {
        return $this->imageStructureReader()->compressedBitsPerPixel();
    }

    public function resolutionUnit(): ResolutionUnit
    {
        return $this->imageStructureReader()->resolutionUnit();
    }

    public function xResolution(): ?float
    {
        return $this->imageStructureReader()->xResolution();
    }

    public function yResolution(): ?float
    {
        return $this->imageStructureReader()->yResolution();
    }

    public function rowsPerStrip(): ?int
    {
        return $this->imageStructureReader()->rowsPerStrip();
    }

    /**
     * @return list<int>|null
     */
    public function stripOffsets(): ?array
    {
        return $this->imageStructureReader()->stripOffsets();
    }

    /**
     * @return list<int>|null
     */
    public function stripByteCounts(): ?array
    {
        return $this->imageStructureReader()->stripByteCounts();
    }

    public function jpegInterchangeFormat(): ?int
    {
        return $this->imageStructureReader()->jpegInterchangeFormat();
    }

    public function jpegInterchangeFormatLength(): ?int
    {
        return $this->imageStructureReader()->jpegInterchangeFormatLength();
    }

    // ── Colour space domain ────────────────────────────────────

    public function colorSpace(): ?ColorSpace
    {
        return $this->colorSpaceReader()->colorSpace();
    }

    public function exifProfile(): string
    {
        return $this->colorSpaceReader()->exifProfile();
    }

    public function photometric(): ?Photometric
    {
        return $this->colorSpaceReader()->photometric();
    }

    public function planarConfiguration(): ?PlanarConfiguration
    {
        return $this->colorSpaceReader()->planarConfiguration();
    }

    public function samplesPerPixel(): int
    {
        return $this->colorSpaceReader()->samplesPerPixel();
    }

    public function bitsPerSample(): ?int
    {
        return $this->colorSpaceReader()->bitsPerSample();
    }

    /**
     * @return list<int>|null
     */
    public function bitsPerSampleList(): ?array
    {
        return $this->colorSpaceReader()->bitsPerSampleList();
    }

    public function ycbcrPositioning(): ?YCbCrPositioning
    {
        return $this->colorSpaceReader()->ycbcrPositioning();
    }

    /**
     * @return array{0:int,1:int}|null
     */
    public function ycbcrSubSampling(): ?array
    {
        return $this->colorSpaceReader()->ycbcrSubSampling();
    }

    /**
     * @return array{0:float,1:float,2:float}|null
     */
    public function ycbcrCoefficients(): ?array
    {
        return $this->colorSpaceReader()->ycbcrCoefficients();
    }

    /**
     * @return list<float>|null
     */
    public function referenceBlackWhite(): ?array
    {
        return $this->colorSpaceReader()->referenceBlackWhite();
    }

    /**
     * @return array{0:float,1:float}|null
     */
    public function whitePoint(): ?array
    {
        return $this->colorSpaceReader()->whitePoint();
    }

    /**
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public function primaryChromaticities(): ?array
    {
        return $this->colorSpaceReader()->primaryChromaticities();
    }

    /**
     * @return list<int>|null
     */
    public function componentsConfiguration(): ?array
    {
        return $this->colorSpaceReader()->componentsConfiguration();
    }

    /**
     * @return list<string>|null
     */
    public function componentsConfigurationLabels(): ?array
    {
        return $this->colorSpaceReader()->componentsConfigurationLabels();
    }

    public function componentsConfigurationDescription(): ?string
    {
        return $this->colorSpaceReader()->componentsConfigurationDescription();
    }

    public function gamma(): ?float
    {
        return $this->colorSpaceReader()->gamma();
    }

    // ── DNG metadata domain ────────────────────────────────────

    public function dngVersion(): ?string
    {
        return $this->dngMetadataReader()->dngVersion();
    }

    public function dngBackwardVersion(): ?string
    {
        return $this->dngMetadataReader()->dngBackwardVersion();
    }

    // ── Description domain ─────────────────────────────────────

    public function imageTitle(): ?string
    {
        return $this->descriptionReader()->imageTitle();
    }

    public function documentName(): ?string
    {
        return $this->descriptionReader()->documentName();
    }

    public function imageDescription(): ?string
    {
        return $this->descriptionReader()->imageDescription();
    }

    public function hostComputer(): ?string
    {
        return $this->reader()->str($this->ifd0, TiffTag::HOST_COMPUTER);
    }

    public function software(): ?string
    {
        return $this->descriptionReader()->software();
    }

    public function photographer(): ?string
    {
        return $this->descriptionReader()->photographer();
    }

    public function imageEditor(): ?string
    {
        return $this->descriptionReader()->imageEditor();
    }

    public function copyright(): ?string
    {
        return $this->descriptionReader()->copyright();
    }

    public function artist(): ?string
    {
        return $this->descriptionReader()->artist();
    }

    public function learningOptOutIn(): ?LearningOptOutIn
    {
        return $this->descriptionReader()->learningOptOutIn();
    }

    public function imageUniqueId(): ?string
    {
        return $this->descriptionReader()->imageUniqueId();
    }

    public function exifVersion(): ?string
    {
        return $this->descriptionReader()->exifVersion();
    }

    public function flashpixVersion(): ?string
    {
        return $this->descriptionReader()->flashpixVersion();
    }

    // ── Windows XP tags ───────────────────────────────────────

    public function xpTitle(): ?string
    {
        return $this->descriptionReader()->xpTitle();
    }

    public function xpComment(): ?string
    {
        return $this->descriptionReader()->xpComment();
    }

    public function xpAuthor(): ?string
    {
        return $this->descriptionReader()->xpAuthor();
    }

    public function xpKeywords(): ?string
    {
        return $this->descriptionReader()->xpKeywords();
    }

    public function xpSubject(): ?string
    {
        return $this->descriptionReader()->xpSubject();
    }

    // ── User comment domain ────────────────────────────────────

    public function userComment(): ?string
    {
        return $this->userCommentReader()->userComment();
    }

    public function userCommentEncoding(): ?string
    {
        return $this->userCommentReader()->userCommentEncoding();
    }

    public function userCommentEncodingBestEffort(): ?string
    {
        return $this->userCommentReader()->userCommentEncodingBestEffort();
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
        return $this->isoSensitivityReader()->spectralSensitivity();
    }

    public function oecf(): ?Oecf
    {
        return $this->sensorDataReader()->oecf();
    }

    public function oecfPayload(): ?string
    {
        return $this->sensorDataReader()->oecfPayload();
    }

    public function sensitivityType(): ?SensitivityType
    {
        return $this->isoSensitivityReader()->sensitivityType();
    }

    public function standardOutputSensitivity(): ?int
    {
        return $this->isoSensitivityReader()->standardOutputSensitivity();
    }

    public function recommendedExposureIndex(): ?int
    {
        return $this->isoSensitivityReader()->recommendedExposureIndex();
    }

    public function isoSpeedValue(): ?int
    {
        return $this->isoSensitivityReader()->isoSpeedValue();
    }

    public function iso(): ?int
    {
        return $this->isoSensitivityReader()->iso();
    }

    public function isoBestEffort(): ?int
    {
        return $this->isoSensitivityReader()->isoBestEffort();
    }

    public function isoSpeedLatitudeYyy(): ?int
    {
        return $this->isoSensitivityReader()->isoSpeedLatitudeYyy();
    }

    public function isoSpeedLatitudeZzz(): ?int
    {
        return $this->isoSensitivityReader()->isoSpeedLatitudeZzz();
    }

    public function exposureTime(): ?float
    {
        return $this->exposureParameterReader()->exposureTime();
    }

    public function exposureTimeFormatted(): ?string
    {
        return $this->exposureParameterReader()->exposureTimeFormatted();
    }

    public function shutterSpeedValue(): ?float
    {
        return $this->exposureParameterReader()->shutterSpeedValue();
    }

    public function shutterSpeedSeconds(): ?float
    {
        return $this->exposureParameterReader()->shutterSpeedSeconds();
    }

    public function shutterSpeedFormatted(): ?string
    {
        return $this->exposureParameterReader()->shutterSpeedFormatted();
    }

    public function fNumber(): ?float
    {
        return $this->exposureParameterReader()->fNumber();
    }

    public function apertureValue(): ?float
    {
        return $this->exposureParameterReader()->apertureValue();
    }

    public function apertureValueFormatted(): ?string
    {
        return $this->exposureParameterReader()->apertureValueFormatted();
    }

    public function focalLengthMm(): ?float
    {
        return $this->focalReader()->focalLengthMm();
    }

    public function focalLength35Mm(): ?int
    {
        return $this->focalReader()->focalLength35Mm();
    }

    public function exposureProgram(): ?ExposureProgram
    {
        return $this->exposureParameterReader()->exposureProgram();
    }

    public function meteringMode(): ?MeteringMode
    {
        return $this->sceneModeReader()->meteringMode();
    }

    public function flash(): ?int
    {
        return $this->sceneModeReader()->flash();
    }

    public function flashInfo(): ?FlashInfo
    {
        return $this->sceneModeReader()->flashInfo();
    }

    public function flashEnergy(): ?float
    {
        return $this->sceneModeReader()->flashEnergy();
    }

    public function whiteBalance(): ?WhiteBalance
    {
        return $this->sceneModeReader()->whiteBalance();
    }

    public function exposureBias(): ?float
    {
        return $this->exposureParameterReader()->exposureBias();
    }

    public function brightnessValue(): ?float
    {
        return $this->exposureParameterReader()->brightnessValue();
    }

    public function brightnessValueFormatted(): ?string
    {
        return $this->exposureParameterReader()->brightnessValueFormatted();
    }

    public function maxApertureApex(): ?float
    {
        return $this->exposureParameterReader()->maxApertureApex();
    }

    public function focalPlaneXResolution(): ?float
    {
        return $this->focalReader()->focalPlaneXResolution();
    }

    public function focalPlaneYResolution(): ?float
    {
        return $this->focalReader()->focalPlaneYResolution();
    }

    public function focalPlaneResolutionUnit(): int
    {
        return $this->focalReader()->focalPlaneResolutionUnit();
    }

    /**
     * @return list<int>|null
     */
    public function subjectLocation(): ?array
    {
        return $this->sceneModeReader()->subjectLocation();
    }

    public function exposureIndex(): ?float
    {
        return $this->exposureParameterReader()->exposureIndex();
    }

    public function relatedSoundFile(): ?string
    {
        return $this->reader()->str($this->exifIfd, ExifTag::RELATED_SOUND_FILE);
    }

    public function spatialFrequencyResponse(): ?SpatialFrequencyResponse
    {
        return $this->sensorDataReader()->spatialFrequencyResponse();
    }

    public function compositeImage(): ?CompositeImage
    {
        return $this->sensorDataReader()->compositeImage();
    }

    /**
     * @return array{0:int,1:int}|null
     */
    public function sourceImageNumberOfCompositeImage(): ?array
    {
        return $this->sensorDataReader()->sourceImageNumberOfCompositeImage();
    }

    public function sourceExposureTimesOfCompositeImage(): ?SourceExposureTimes
    {
        return $this->sensorDataReader()->sourceExposureTimesOfCompositeImage();
    }

    public function cfaPattern(): ?CfaPattern
    {
        return $this->focalReader()->cfaPattern();
    }

    /**
     * @return list<CfaPatternColor>|null
     */
    public function cfaPatternColors(): ?array
    {
        return $this->focalReader()->cfaPatternColors();
    }

    public function sceneType(): ?SceneType
    {
        return $this->sceneModeReader()->sceneType();
    }

    public function customRendered(): ?CustomRendered
    {
        return $this->sceneModeReader()->customRendered();
    }

    public function contrast(): ?Contrast
    {
        return $this->sceneModeReader()->contrast();
    }

    public function saturation(): ?Saturation
    {
        return $this->sceneModeReader()->saturation();
    }

    public function sharpness(): ?Sharpness
    {
        return $this->sceneModeReader()->sharpness();
    }

    public function sensingMethod(): ?SensingMethod
    {
        return SensingMethod::fromExifValue($this->reader()->enumValue($this->exifIfd, ExifTag::SENSING_METHOD));
    }

    public function lightSource(): ?LightSource
    {
        return $this->sceneModeReader()->lightSource();
    }

    public function sceneCaptureType(): ?SceneCaptureType
    {
        return $this->sceneModeReader()->sceneCaptureType();
    }

    public function subjectDistanceRange(): ?SubjectDistanceRange
    {
        return $this->sceneModeReader()->subjectDistanceRange();
    }

    public function developmentCharacteristic(): ?DevelopmentCharacteristic
    {
        return $this->sceneModeReader()->developmentCharacteristic();
    }

    public function developmentDefault(): ?DevelopmentDefault
    {
        return $this->sceneModeReader()->developmentDefault();
    }

    public function developmentTypeDescription(): ?string
    {
        return $this->sceneModeReader()->developmentTypeDescription();
    }

    public function distortionCorrection(): ?CorrectionApplied
    {
        return $this->sceneModeReader()->distortionCorrection();
    }

    public function chromaticAberrationCorrection(): ?CorrectionApplied
    {
        return $this->sceneModeReader()->chromaticAberrationCorrection();
    }

    public function shadingCorrection(): ?CorrectionApplied
    {
        return $this->sceneModeReader()->shadingCorrection();
    }

    public function noiseReduction(): ?NoiseReduction
    {
        return $this->sceneModeReader()->noiseReduction();
    }

    public function subjectDistance(): ?float
    {
        return $this->sceneModeReader()->subjectDistance();
    }

    public function subjectArea(): ?SubjectArea
    {
        return $this->sceneModeReader()->subjectArea();
    }

    public function digitalZoomRatio(): ?float
    {
        return $this->exposureParameterReader()->digitalZoomRatio();
    }

    public function exposureMode(): ?ExposureMode
    {
        return $this->exposureParameterReader()->exposureMode();
    }

    public function gainControl(): ?GainControl
    {
        return $this->sceneModeReader()->gainControl();
    }

    public function fileSource(): ?FileSource
    {
        return $this->focalReader()->fileSource();
    }

    public function interopIndex(): ?string
    {
        return $this->focalReader()->interopIndex();
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

    private function exposureParameterReader(): ExposureParameterReader
    {
        return $this->cachedExposureParameterReader ??= new ExposureParameterReader(
            $this->reader(),
            $this->converters,
            $this->exifIfd,
        );
    }

    private function isoSensitivityReader(): IsoSensitivityReader
    {
        return $this->cachedIsoSensitivityReader ??= new IsoSensitivityReader(
            $this->reader(),
            $this->ifd0,
            $this->exifIfd,
            $this->fallbackIfdSet(),
        );
    }

    private function sceneModeReader(): SceneModeReader
    {
        return $this->cachedSceneModeReader ??= new SceneModeReader(
            $this->reader(),
            $this->converters,
            $this->exifIfd,
        );
    }

    private function focalReader(): FocalReader
    {
        return $this->cachedFocalReader ??= new FocalReader(
            $this->reader(),
            $this->exifIfd,
            $this->ifd0,
            $this->interopIfd,
        );
    }

    private function sensorDataReader(): SensorDataReader
    {
        return $this->cachedSensorDataReader ??= new SensorDataReader(
            $this->reader(),
            $this->converters,
            $this->exifIfd,
            $this->byteOrder,
        );
    }

    private function cameraLensReader(): CameraLensExifReader
    {
        return $this->cachedCameraLensReader ??= new CameraLensExifReader(
            $this->reader(),
            $this->ifd0,
            $this->exifIfd,
        );
    }

    private function imageStructureReader(): ImageStructureExifReader
    {
        return $this->cachedImageStructureReader ??= new ImageStructureExifReader(
            $this->reader(),
            $this->ifd0,
            $this->exifIfd,
        );
    }

    private function colorSpaceReader(): ColorSpaceExifReader
    {
        return $this->cachedColorSpaceReader ??= new ColorSpaceExifReader(
            $this->reader(),
            $this->converters,
            $this->ifd0,
            $this->exifIfd,
            $this->exifProfile,
        );
    }

    private function dngMetadataReader(): DngMetadataExifReader
    {
        return $this->cachedDngMetadataReader ??= new DngMetadataExifReader(
            $this->reader(),
            $this->ifd0,
        );
    }

    private function userCommentReader(): UserCommentExifReader
    {
        return $this->cachedUserCommentReader ??= new UserCommentExifReader(
            $this->reader(),
            $this->exifIfd,
            $this->fallbackIfdSet(),
        );
    }

    private function descriptionReader(): DescriptionExifReader
    {
        return $this->cachedDescriptionReader ??= new DescriptionExifReader(
            $this->reader(),
            $this->converters,
            $this->ifd0,
            $this->exifIfd,
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
