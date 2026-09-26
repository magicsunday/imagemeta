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
use MagicSunday\ImageMeta\Core\ParseError;
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

    private ?IfdValueReader $cachedReader = null;

    private ?FallbackIfdSet $cachedFallbackIfdSet = null;

    private ?GpsExifReader $cachedGpsReader = null;

    private ?TemporalExifReader $cachedTemporalReader = null;

    private ?ExposureParameterReader $cachedExposureParameterReader = null;

    private ?IsoSensitivityReader $cachedIsoSensitivityReader = null;

    private ?SceneModeReader $cachedSceneModeReader = null;

    private ?FocalReader $cachedFocalReader = null;

    private ?SensorDataReader $cachedSensorDataReader = null;

    private ?CameraLensExifReader $cachedCameraLensReader = null;

    private ?ImageStructureExifReader $cachedImageStructureReader = null;

    private ?ColorSpaceExifReader $cachedColorSpaceReader = null;

    private ?DngMetadataExifReader $cachedDngMetadataReader = null;

    private ?UserCommentExifReader $cachedUserCommentReader = null;

    private ?DescriptionExifReader $cachedDescriptionReader = null;

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

    // ── Camera / lens domain ──────────────────────────────────

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function cameraMake(): ?string
    {
        return $this->cameraLensReader()->cameraMake();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function cameraModel(): ?string
    {
        return $this->cameraLensReader()->cameraModel();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function lensModel(): ?string
    {
        return $this->cameraLensReader()->lensModel();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function lensMake(): ?string
    {
        return $this->cameraLensReader()->lensMake();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function ownerName(): ?string
    {
        return $this->cameraLensReader()->ownerName();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function bodySerialNumber(): ?string
    {
        return $this->cameraLensReader()->bodySerialNumber();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function lensSerialNumber(): ?string
    {
        return $this->cameraLensReader()->lensSerialNumber();
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function lensSpecification(): ?array
    {
        return $this->cameraLensReader()->lensSpecification();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function uniqueCameraModel(): ?string
    {
        return $this->cameraLensReader()->uniqueCameraModel();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function localizedCameraModel(): ?string
    {
        return $this->cameraLensReader()->localizedCameraModel();
    }

    // ── Image structure domain ─────────────────────────────────

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function orientation(): Orientation
    {
        return $this->imageStructureReader()->orientation();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function orientationDescription(): string
    {
        return $this->imageStructureReader()->orientationDescription();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function imageWidth(): ?int
    {
        return $this->imageStructureReader()->imageWidth();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function imageHeight(): ?int
    {
        return $this->imageStructureReader()->imageHeight();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function imageLength(): ?int
    {
        return $this->imageStructureReader()->imageLength();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function pixelXDimension(): ?int
    {
        return $this->imageStructureReader()->pixelXDimension();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function pixelYDimension(): ?int
    {
        return $this->imageStructureReader()->pixelYDimension();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function compression(): ?Compression
    {
        return $this->imageStructureReader()->compression();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function compressedBitsPerPixel(): ?float
    {
        return $this->imageStructureReader()->compressedBitsPerPixel();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function resolutionUnit(): ResolutionUnit
    {
        return $this->imageStructureReader()->resolutionUnit();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function xResolution(): ?float
    {
        return $this->imageStructureReader()->xResolution();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function yResolution(): ?float
    {
        return $this->imageStructureReader()->yResolution();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function rowsPerStrip(): ?int
    {
        return $this->imageStructureReader()->rowsPerStrip();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function stripOffsets(): ?array
    {
        return $this->imageStructureReader()->stripOffsets();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function stripByteCounts(): ?array
    {
        return $this->imageStructureReader()->stripByteCounts();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function jpegInterchangeFormat(): ?int
    {
        return $this->imageStructureReader()->jpegInterchangeFormat();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function jpegInterchangeFormatLength(): ?int
    {
        return $this->imageStructureReader()->jpegInterchangeFormatLength();
    }

    // ── Colour space domain ────────────────────────────────────

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function colorSpace(): ?ColorSpace
    {
        return $this->colorSpaceReader()->colorSpace();
    }

    public function exifProfile(): string
    {
        return $this->colorSpaceReader()->exifProfile();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function photometric(): ?Photometric
    {
        return $this->colorSpaceReader()->photometric();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function planarConfiguration(): ?PlanarConfiguration
    {
        return $this->colorSpaceReader()->planarConfiguration();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function samplesPerPixel(): int
    {
        return $this->colorSpaceReader()->samplesPerPixel();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function bitsPerSample(): ?int
    {
        return $this->colorSpaceReader()->bitsPerSample();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function bitsPerSampleList(): ?array
    {
        return $this->colorSpaceReader()->bitsPerSampleList();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function ycbcrPositioning(): ?YCbCrPositioning
    {
        return $this->colorSpaceReader()->ycbcrPositioning();
    }

    /**
     * @return array{0:int,1:int}|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function ycbcrSubSampling(): ?array
    {
        return $this->colorSpaceReader()->ycbcrSubSampling();
    }

    /**
     * @return array{0:float,1:float,2:float}|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function ycbcrCoefficients(): ?array
    {
        return $this->colorSpaceReader()->ycbcrCoefficients();
    }

    /**
     * @return list<float>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function referenceBlackWhite(): ?array
    {
        return $this->colorSpaceReader()->referenceBlackWhite();
    }

    /**
     * @return array{0:float,1:float}|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function whitePoint(): ?array
    {
        return $this->colorSpaceReader()->whitePoint();
    }

    /**
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function primaryChromaticities(): ?array
    {
        return $this->colorSpaceReader()->primaryChromaticities();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function componentsConfiguration(): ?array
    {
        return $this->colorSpaceReader()->componentsConfiguration();
    }

    /**
     * @return list<string>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function componentsConfigurationLabels(): ?array
    {
        return $this->colorSpaceReader()->componentsConfigurationLabels();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function componentsConfigurationDescription(): ?string
    {
        return $this->colorSpaceReader()->componentsConfigurationDescription();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gamma(): ?float
    {
        return $this->colorSpaceReader()->gamma();
    }

    // ── DNG metadata domain ────────────────────────────────────

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function dngVersion(): ?string
    {
        return $this->dngMetadataReader()->dngVersion();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function dngBackwardVersion(): ?string
    {
        return $this->dngMetadataReader()->dngBackwardVersion();
    }

    // ── Description domain ─────────────────────────────────────

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function imageTitle(): ?string
    {
        return $this->descriptionReader()->imageTitle();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function documentName(): ?string
    {
        return $this->descriptionReader()->documentName();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function imageDescription(): ?string
    {
        return $this->descriptionReader()->imageDescription();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function hostComputer(): ?string
    {
        return $this->reader()->str($this->ifd0, TiffTag::HOST_COMPUTER);
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function software(): ?string
    {
        return $this->descriptionReader()->software();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function photographer(): ?string
    {
        return $this->descriptionReader()->photographer();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function imageEditor(): ?string
    {
        return $this->descriptionReader()->imageEditor();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function copyright(): ?string
    {
        return $this->descriptionReader()->copyright();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function artist(): ?string
    {
        return $this->descriptionReader()->artist();
    }

    public function learningOptOutIn(): ?LearningOptOutIn
    {
        return $this->descriptionReader()->learningOptOutIn();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
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

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function hasThumbnail(): bool
    {
        return $this->thumbnailReader()->hasThumbnail();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function thumbnailJpegInterchangeFormat(): ?int
    {
        return $this->thumbnailReader()->thumbnailJpegInterchangeFormat();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function thumbnailJpegInterchangeFormatLength(): ?int
    {
        return $this->thumbnailReader()->thumbnailJpegInterchangeFormatLength();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function thumbnailCompression(): ?Compression
    {
        return $this->thumbnailReader()->thumbnailCompression();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function thumbnailTileWidth(): ?int
    {
        return $this->thumbnailReader()->thumbnailTileWidth();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function thumbnailTileLength(): ?int
    {
        return $this->thumbnailReader()->thumbnailTileLength();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function thumbnailTileOffsets(): ?array
    {
        return $this->thumbnailReader()->thumbnailTileOffsets();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function thumbnailTileByteCounts(): ?array
    {
        return $this->thumbnailReader()->thumbnailTileByteCounts();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function thumbnailStripOffsets(): ?array
    {
        return $this->thumbnailReader()->thumbnailStripOffsets();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function thumbnailStripByteCounts(): ?array
    {
        return $this->thumbnailReader()->thumbnailStripByteCounts();
    }

    // ── Exposure domain ─────────────────────────────────────────

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
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

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function sensitivityType(): ?SensitivityType
    {
        return $this->isoSensitivityReader()->sensitivityType();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function standardOutputSensitivity(): ?int
    {
        return $this->isoSensitivityReader()->standardOutputSensitivity();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function recommendedExposureIndex(): ?int
    {
        return $this->isoSensitivityReader()->recommendedExposureIndex();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function isoSpeedValue(): ?int
    {
        return $this->isoSensitivityReader()->isoSpeedValue();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function iso(): ?int
    {
        return $this->isoSensitivityReader()->iso();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function isoBestEffort(): ?int
    {
        return $this->isoSensitivityReader()->isoBestEffort();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function isoSpeedLatitudeYyy(): ?int
    {
        return $this->isoSensitivityReader()->isoSpeedLatitudeYyy();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function isoSpeedLatitudeZzz(): ?int
    {
        return $this->isoSensitivityReader()->isoSpeedLatitudeZzz();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function exposureTime(): ?float
    {
        return $this->exposureParameterReader()->exposureTime();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function exposureTimeFormatted(): ?string
    {
        return $this->exposureParameterReader()->exposureTimeFormatted();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function shutterSpeedValue(): ?float
    {
        return $this->exposureParameterReader()->shutterSpeedValue();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function shutterSpeedSeconds(): ?float
    {
        return $this->exposureParameterReader()->shutterSpeedSeconds();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function shutterSpeedFormatted(): ?string
    {
        return $this->exposureParameterReader()->shutterSpeedFormatted();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function fNumber(): ?float
    {
        return $this->exposureParameterReader()->fNumber();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function apertureValue(): ?float
    {
        return $this->exposureParameterReader()->apertureValue();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function apertureValueFormatted(): ?string
    {
        return $this->exposureParameterReader()->apertureValueFormatted();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function focalLengthMm(): ?float
    {
        return $this->focalReader()->focalLengthMm();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function focalLength35Mm(): ?int
    {
        return $this->focalReader()->focalLength35Mm();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function exposureProgram(): ?ExposureProgram
    {
        return $this->exposureParameterReader()->exposureProgram();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function meteringMode(): ?MeteringMode
    {
        return $this->sceneModeReader()->meteringMode();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function flash(): ?int
    {
        return $this->sceneModeReader()->flash();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function flashInfo(): ?FlashInfo
    {
        return $this->sceneModeReader()->flashInfo();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function flashEnergy(): ?float
    {
        return $this->sceneModeReader()->flashEnergy();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function whiteBalance(): ?WhiteBalance
    {
        return $this->sceneModeReader()->whiteBalance();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function exposureBias(): ?float
    {
        return $this->exposureParameterReader()->exposureBias();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function brightnessValue(): ?float
    {
        return $this->exposureParameterReader()->brightnessValue();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function brightnessValueFormatted(): ?string
    {
        return $this->exposureParameterReader()->brightnessValueFormatted();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function maxApertureApex(): ?float
    {
        return $this->exposureParameterReader()->maxApertureApex();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function focalPlaneXResolution(): ?float
    {
        return $this->focalReader()->focalPlaneXResolution();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function focalPlaneYResolution(): ?float
    {
        return $this->focalReader()->focalPlaneYResolution();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function focalPlaneResolutionUnit(): int
    {
        return $this->focalReader()->focalPlaneResolutionUnit();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function subjectLocation(): ?array
    {
        return $this->sceneModeReader()->subjectLocation();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function exposureIndex(): ?float
    {
        return $this->exposureParameterReader()->exposureIndex();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function relatedSoundFile(): ?string
    {
        return $this->reader()->str($this->exifIfd, ExifTag::RELATED_SOUND_FILE);
    }

    public function spatialFrequencyResponse(): ?SpatialFrequencyResponse
    {
        return $this->sensorDataReader()->spatialFrequencyResponse();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function compositeImage(): ?CompositeImage
    {
        return $this->sensorDataReader()->compositeImage();
    }

    /**
     * @return array{0:int,1:int}|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function sourceImageNumberOfCompositeImage(): ?array
    {
        return $this->sensorDataReader()->sourceImageNumberOfCompositeImage();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function sourceExposureTimesOfCompositeImage(): ?SourceExposureTimes
    {
        return $this->sensorDataReader()->sourceExposureTimesOfCompositeImage();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function cfaPattern(): ?CfaPattern
    {
        return $this->focalReader()->cfaPattern();
    }

    /**
     * @return list<CfaPatternColor>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function cfaPatternColors(): ?array
    {
        return $this->focalReader()->cfaPatternColors();
    }

    public function sceneType(): ?SceneType
    {
        return $this->sceneModeReader()->sceneType();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function customRendered(): ?CustomRendered
    {
        return $this->sceneModeReader()->customRendered();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function contrast(): ?Contrast
    {
        return $this->sceneModeReader()->contrast();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function saturation(): ?Saturation
    {
        return $this->sceneModeReader()->saturation();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function sharpness(): ?Sharpness
    {
        return $this->sceneModeReader()->sharpness();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function sensingMethod(): ?SensingMethod
    {
        return SensingMethod::fromExifValue($this->reader()->enumValue($this->exifIfd, ExifTag::SENSING_METHOD));
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function lightSource(): ?LightSource
    {
        return $this->sceneModeReader()->lightSource();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function sceneCaptureType(): ?SceneCaptureType
    {
        return $this->sceneModeReader()->sceneCaptureType();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function subjectDistanceRange(): ?SubjectDistanceRange
    {
        return $this->sceneModeReader()->subjectDistanceRange();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function developmentCharacteristic(): ?DevelopmentCharacteristic
    {
        return $this->sceneModeReader()->developmentCharacteristic();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function developmentDefault(): ?DevelopmentDefault
    {
        return $this->sceneModeReader()->developmentDefault();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function developmentTypeDescription(): ?string
    {
        return $this->sceneModeReader()->developmentTypeDescription();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function distortionCorrection(): ?CorrectionApplied
    {
        return $this->sceneModeReader()->distortionCorrection();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function chromaticAberrationCorrection(): ?CorrectionApplied
    {
        return $this->sceneModeReader()->chromaticAberrationCorrection();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function shadingCorrection(): ?CorrectionApplied
    {
        return $this->sceneModeReader()->shadingCorrection();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function noiseReduction(): ?NoiseReduction
    {
        return $this->sceneModeReader()->noiseReduction();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function subjectDistance(): ?float
    {
        return $this->sceneModeReader()->subjectDistance();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function subjectArea(): ?SubjectArea
    {
        return $this->sceneModeReader()->subjectArea();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function digitalZoomRatio(): ?float
    {
        return $this->exposureParameterReader()->digitalZoomRatio();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function exposureMode(): ?ExposureMode
    {
        return $this->exposureParameterReader()->exposureMode();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gainControl(): ?GainControl
    {
        return $this->sceneModeReader()->gainControl();
    }

    public function fileSource(): ?FileSource
    {
        return $this->focalReader()->fileSource();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function interopIndex(): ?string
    {
        return $this->focalReader()->interopIndex();
    }

    // ── Device domain ───────────────────────────────────────────

    public function deviceSettingDescription(): ?DeviceSettingDescription
    {
        return $this->deviceReader()->deviceSettingDescription();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function temperatureCelsius(): ?float
    {
        return $this->deviceReader()->temperatureCelsius();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function humidityPercent(): ?float
    {
        return $this->deviceReader()->humidityPercent();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function pressureHPa(): ?float
    {
        return $this->deviceReader()->pressureHPa();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function waterDepthMeters(): ?float
    {
        return $this->deviceReader()->waterDepthMeters();
    }

    /**
     * @return array{0:float,1:float,2:float}|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function accelerationVector(): ?array
    {
        return $this->deviceReader()->accelerationVector();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function accelerationMs2(): ?float
    {
        return $this->deviceReader()->accelerationMs2();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function cameraElevationAngleDeg(): ?float
    {
        return $this->deviceReader()->cameraElevationAngleDeg();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function cameraFirmware(): ?string
    {
        return $this->deviceReader()->cameraFirmware();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function rawDevelopingSoftware(): ?string
    {
        return $this->deviceReader()->rawDevelopingSoftware();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function imageEditingSoftware(): ?string
    {
        return $this->deviceReader()->imageEditingSoftware();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function metadataEditingSoftware(): ?string
    {
        return $this->deviceReader()->metadataEditingSoftware();
    }

    // ── Temporal domain ─────────────────────────────────────────

    public function dateTimeOriginalRaw(): ?string
    {
        return $this->temporalReader()->dateTimeOriginalRaw();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function dateTimeOriginal(): ?DateTimeImmutable
    {
        return $this->temporalReader()->dateTimeOriginal();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function dateTimeOriginalBestEffort(): ?DateTimeImmutable
    {
        return $this->temporalReader()->dateTimeOriginalBestEffort();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function subSecTimeOriginal(): ?string
    {
        return $this->temporalReader()->subSecTimeOriginal();
    }

    public function dateTimeDigitizedRaw(): ?string
    {
        return $this->temporalReader()->dateTimeDigitizedRaw();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function subSecTimeDigitized(): ?string
    {
        return $this->temporalReader()->subSecTimeDigitized();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function dateTimeRaw(): ?string
    {
        return $this->temporalReader()->dateTimeRaw();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
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

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function captureDateTime(): ?DateTimeImmutable
    {
        return $this->temporalReader()->captureDateTime();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function dateTimeDigitized(): ?DateTimeImmutable
    {
        return $this->temporalReader()->dateTimeDigitized();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function dateTime(): ?DateTimeImmutable
    {
        return $this->temporalReader()->dateTime();
    }

    // ── GPS domain ──────────────────────────────────────────────

    /**
     * @return GpsFieldMap
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gps(): array
    {
        return $this->gpsReader()->gps();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsDateStamp(): ?string
    {
        return $this->gpsReader()->gpsDateStamp();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsTimeStampString(): ?string
    {
        return $this->gpsReader()->gpsTimeStampString();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsTimestamp(): ?DateTimeImmutable
    {
        return $this->gpsReader()->gpsTimestamp();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsSpeedRef(): ?string
    {
        return $this->gpsReader()->gpsSpeedRef();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsSpeedMetresPerSecond(): ?float
    {
        return $this->gpsReader()->gpsSpeedMetresPerSecond();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsTrackRef(): ?string
    {
        return $this->gpsReader()->gpsTrackRef();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsTrack(): ?float
    {
        return $this->gpsReader()->gpsTrack();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsImgDirectionRef(): ?string
    {
        return $this->gpsReader()->gpsImgDirectionRef();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsImgDirection(): ?float
    {
        return $this->gpsReader()->gpsImgDirection();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsDestinationBearingRef(): ?string
    {
        return $this->gpsReader()->gpsDestinationBearingRef();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsDestinationBearing(): ?float
    {
        return $this->gpsReader()->gpsDestinationBearing();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsDestinationDistanceRef(): ?string
    {
        return $this->gpsReader()->gpsDestinationDistanceRef();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsDestinationDistanceMetres(): ?float
    {
        return $this->gpsReader()->gpsDestinationDistanceMetres();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsDifferential(): ?int
    {
        return $this->gpsReader()->gpsDifferential();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function gpsHorizontalPositioningError(): ?float
    {
        return $this->gpsReader()->gpsHorizontalPositioningError();
    }

    // ── TIFF Baseline domain ────────────────────────────────────

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function tileWidth(): ?int
    {
        return $this->tiffBaselineReader()->tileWidth();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function tileLength(): ?int
    {
        return $this->tiffBaselineReader()->tileLength();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function tileOffsets(): ?array
    {
        return $this->tiffBaselineReader()->tileOffsets();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function tileByteCounts(): ?array
    {
        return $this->tiffBaselineReader()->tileByteCounts();
    }

    /**
     * @return list<int>|null
     *
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function transferFunction(): ?array
    {
        return $this->tiffBaselineReader()->transferFunction();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function predictor(): int
    {
        return $this->tiffBaselineReader()->predictor();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function newSubfileType(): int
    {
        return $this->tiffBaselineReader()->newSubfileType();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function subfileType(): ?int
    {
        return $this->tiffBaselineReader()->subfileType();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function threshholding(): int
    {
        return $this->tiffBaselineReader()->threshholding();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function cellWidth(): ?int
    {
        return $this->tiffBaselineReader()->cellWidth();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function cellLength(): ?int
    {
        return $this->tiffBaselineReader()->cellLength();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function fillOrder(): int
    {
        return $this->tiffBaselineReader()->fillOrder();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function minSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList
    {
        return $this->tiffBaselineReader()->minSampleValue();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function maxSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList
    {
        return $this->tiffBaselineReader()->maxSampleValue();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function pageName(): ?string
    {
        return $this->tiffBaselineReader()->pageName();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function xPosition(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->xPosition();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function yPosition(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->yPosition();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function freeOffsets(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->freeOffsets();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function freeByteCounts(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->freeByteCounts();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function grayResponseUnit(): int
    {
        return $this->tiffBaselineReader()->grayResponseUnit();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function grayResponseCurve(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->grayResponseCurve();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function t4Options(): int
    {
        return $this->tiffBaselineReader()->t4Options();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function t6Options(): int
    {
        return $this->tiffBaselineReader()->t6Options();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function pageNumber(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->pageNumber();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function colorMap(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->colorMap();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function halftoneHints(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->halftoneHints();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function inkSet(): int
    {
        return $this->tiffBaselineReader()->inkSet();
    }

    public function inkNames(): ?string
    {
        return $this->tiffBaselineReader()->inkNames();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function numberOfInks(): int
    {
        return $this->tiffBaselineReader()->numberOfInks();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function dotRange(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->dotRange();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function targetPrinter(): ?string
    {
        return $this->tiffBaselineReader()->targetPrinter();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function extraSamples(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->extraSamples();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function sampleFormat(): int
    {
        return $this->tiffBaselineReader()->sampleFormat();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function sMinSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->sMinSampleValue();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function sMaxSampleValue(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->sMaxSampleValue();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function transferRange(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->transferRange();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function jpegProc(): ?int
    {
        return $this->tiffBaselineReader()->jpegProc();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function jpegRestartInterval(): ?int
    {
        return $this->tiffBaselineReader()->jpegRestartInterval();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function jpegLosslessPredictors(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->jpegLosslessPredictors();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function jpegPointTransforms(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->jpegPointTransforms();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function jpegQTables(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->jpegQTables();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
    public function jpegDCTables(): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        return $this->tiffBaselineReader()->jpegDCTables();
    }

    /**
     * @throws ParseError If the input is malformed or inconsistent.
     */
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
