<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Factory;

use MagicSunday\ImageMeta\Contract\IccParserInterface;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\ComponentKey;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpNamespace;
use MagicSunday\ImageMeta\Model\Xmp\XmpStructuredValue;
use MagicSunday\ImageMeta\Value\Audio as ValueAudio;
use MagicSunday\ImageMeta\Value\AudioClips;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\ColorProfile as ValueColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\CreatorContact;
use MagicSunday\ImageMeta\Value\DepthMap;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Enum\RegionType;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File as ValueFile;
use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\HdrGainMap;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop as ValueInterop;
use MagicSunday\ImageMeta\Value\Iptc as ValueIptc;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\ProcessingSettings as ValueProcessingSettings;
use MagicSunday\ImageMeta\Value\Region;
use MagicSunday\ImageMeta\Value\RegionCollection;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards as ValueStandards;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\Thumbnail;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp as ValueXmp;

use function array_filter;
use function count;

/**
 * Builds the structured metadata aggregate by orchestrating value-object creation from
 * ParsedExif, QuickTimeMeta and MakerNotes sources.
 *
 * @phpstan-type MediaComponents array{audio: ValueAudio, container: Container, embeddedAudio: AudioClips, flashPix: FlashPix, video: Video}
 * @phpstan-type XmpComponents array{depthMap: DepthMap, hdrGainMap: HdrGainMap, keywords: Keywords, related: RelatedAssets}
 * @phpstan-type CoreComponents array{author: Author, camera: Camera, capture: Capture, colorProfile: ValueColorProfile, composite: CompositeImageInfo, derived: Derived, device: Device, exposure: Exposure, file: ValueFile, focus: Focus, gps: Gps, image: Image, integrity: Integrity, interop: ValueInterop, iptc: ValueIptc, lens: Lens, motion: Motion, multiPicture: MultiPicture, processing: ValueProcessingSettings, regions: RegionCollection, rights: Rights, scene: Scene, sensor: Sensor, standards: ValueStandards, temporal: Temporal, thumbnail: Thumbnail, tiff: TiffData, whiteBalance: WhiteBalanceDetails, xmp: ValueXmp, makerNotesApple: AppleMakerNotes|null}
 * @phpstan-type ValueComponents array{audio: ValueAudio, author: Author, camera: Camera, capture: Capture, colorProfile: ValueColorProfile, composite: CompositeImageInfo, container: Container, derived: Derived, depthMap: DepthMap, device: Device, embeddedAudio: AudioClips, exposure: Exposure, file: ValueFile, flashPix: FlashPix, focus: Focus, gps: Gps, hdrGainMap: HdrGainMap, image: Image, integrity: Integrity, interop: ValueInterop, iptc: ValueIptc, keywords: Keywords, lens: Lens, motion: Motion, multiPicture: MultiPicture, processing: ValueProcessingSettings, regions: RegionCollection, related: RelatedAssets, rights: Rights, scene: Scene, sensor: Sensor, standards: ValueStandards, temporal: Temporal, thumbnail: Thumbnail, tiff: TiffData, video: Video, whiteBalance: WhiteBalanceDetails, xmp: ValueXmp, makerNotesApple: AppleMakerNotes|null}
 */
final readonly class ValueFactory
{
    /**
     * Constructs the ValueFactory with specialized sub-factories.
     *
     * @param IccParserInterface  $iccParser           Parser used for ICC profile decoding.
     * @param CameraFactory       $cameraFactory       Factory for camera metadata.
     * @param LensFactory         $lensFactory         Factory for lens metadata.
     * @param ExposureFactory     $exposureFactory     Factory for exposure metadata.
     * @param SensorFactory       $sensorFactory       Factory for sensor metadata.
     * @param DeviceFactory       $deviceFactory       Factory for device metadata.
     * @param ImageFactory        $imageFactory        Factory for image metadata.
     * @param SceneFactory        $sceneFactory        Factory for scene metadata.
     * @param MotionFactory       $motionFactory       Factory for motion metadata.
     * @param GpsFactory          $gpsFactory          Factory for GPS metadata.
     * @param TemporalFactory     $temporalFactory     Factory for temporal metadata.
     * @param RegionsFactory      $regionsFactory      Factory for regions metadata.
     * @param MultiPictureFactory $multiPictureFactory Factory for multi-picture metadata.
     * @param ValueConverters     $converters          Value converter facade for EXIF type normalization.
     */
    public function __construct(
        private IccParserInterface $iccParser,
        private CameraFactory $cameraFactory = new CameraFactory(),
        private LensFactory $lensFactory = new LensFactory(),
        private ExposureFactory $exposureFactory = new ExposureFactory(),
        private SensorFactory $sensorFactory = new SensorFactory(),
        private DeviceFactory $deviceFactory = new DeviceFactory(),
        private ImageFactory $imageFactory = new ImageFactory(),
        private SceneFactory $sceneFactory = new SceneFactory(),
        private MotionFactory $motionFactory = new MotionFactory(),
        private GpsFactory $gpsFactory = new GpsFactory(),
        private TemporalFactory $temporalFactory = new TemporalFactory(),
        private RegionsFactory $regionsFactory = new RegionsFactory(),
        private MultiPictureFactory $multiPictureFactory = new MultiPictureFactory(),
        private ValueConverters $converters = new ValueConverters(),
    ) {
    }

    /**
     * Produces normalized value objects derived from the supplied metadata container.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return ValueComponents
     */
    public function createComponents(Metadata $metadata): array
    {
        $xmpDocument     = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
        $iptcDocument    = $metadata->iptcDoc ?? $metadata->selectiveIptcDocument();
        $exifDocument    = $metadata->exifDoc;
        $quickTimeLookup = new QuickTimeLookup($metadata->quickTime);
        $appleMakerNotes = $metadata->makerNotes?->apple;
        $apple           = $appleMakerNotes ?? AppleMakerNotes::empty();

        // Sub-factory delegation
        $gps          = $this->gpsFactory->create($metadata);
        $camera       = $this->cameraFactory->create($metadata);
        $lens         = $this->lensFactory->create($metadata);
        $exposure     = $this->exposureFactory->create($metadata);
        $sensor       = $this->sensorFactory->create($metadata);
        $device       = $this->deviceFactory->create($metadata);
        $image        = $this->imageFactory->create($metadata, $xmpDocument);
        $motion       = $this->motionFactory->create($metadata);
        $temporal     = $this->temporalFactory->create($metadata);
        $regions      = $this->regionsFactory->create($metadata);
        $multiPicture = $this->multiPictureFactory->create($metadata);
        $scene        = $this->sceneFactory->create($metadata, $this->countFaceRegions($regions));

        // Cohesive private methods for complex value-object creation
        $tiff         = (new TiffDataFactory())->create($metadata);
        $thumbnail    = $this->createThumbnail($exifDocument);
        $colorProfile = $this->createColorProfile($metadata, $exifDocument);
        $author       = $this->createAuthor($exifDocument, $xmpDocument);
        $rights       = $this->createRights($exifDocument, $xmpDocument);
        $derived      = $this->createDerived($lens, $exposure);
        $integrity    = $this->createIntegrity($xmpDocument);

        $interop = new ValueInterop(
            index: $exifDocument?->interopIndex(),
        );

        $composite = new CompositeImageInfo(
            type: $exifDocument?->compositeImage(),
            counts: $exifDocument?->sourceImageNumberOfCompositeImage(),
            sourceExposureTimes: $exifDocument?->sourceExposureTimesOfCompositeImage(),
        );

        $standards = new ValueStandards(
            exifVersion: $exifDocument?->exifVersion(),
            profile: $exifDocument?->exifProfile() ?? 'unknown',
            flashpixVersion: $exifDocument?->flashpixVersion(),
        );

        $capture = new Capture(
            dateTime: $exifDocument?->captureDateTime(),
            temperatureC: $exifDocument?->temperatureCelsius(),
            humidityPercent: $exifDocument?->humidityPercent(),
            pressureHPa: $exifDocument?->pressureHPa(),
            waterDepthM: $exifDocument?->waterDepthMeters(),
            accelerationMs2: $exifDocument?->accelerationMs2(),
            cameraElevationAngleDeg: $exifDocument?->cameraElevationAngleDeg(),
        );

        $file = new ValueFile(
            $metadata->mimeType,
            $metadata->fileSize,
            $metadata->extension,
            $metadata->digestSha1,
            $metadata->digestMd5,
        );

        $media = $this->createContainerMedia($quickTimeLookup, $metadata);

        $processing = new ValueProcessingSettings(
            sharpness: $exifDocument?->sharpness(),
            contrast: $exifDocument?->contrast(),
            saturation: $exifDocument?->saturation(),
            pictureStyle: null,
            clarity: null,
            customRendered: $exifDocument?->customRendered(),
            deviceSettingDescription: $exifDocument?->deviceSettingDescription(),
            distortionCorrection: $exifDocument?->distortionCorrection(),
            chromaticAberrationCorrection: $exifDocument?->chromaticAberrationCorrection(),
            shadingCorrection: $exifDocument?->shadingCorrection(),
            noiseReduction: $exifDocument?->noiseReduction(),
            developmentCharacteristic: $exifDocument?->developmentCharacteristic(),
            developmentDefault: $exifDocument?->developmentDefault(),
            developmentTypeDescription: $exifDocument?->developmentTypeDescription(),
        );

        $whiteBalanceDetails = new WhiteBalanceDetails(
            mode: $exposure->adjustments?->whiteBalance,
            kelvin: $apple->camera->colorTemperature ?? $quickTimeLookup->int('ColorTemperature'),
            rgGain: null,
            bgGain: null,
        );

        $focus = new Focus(
            subjectDistanceM: $exifDocument?->subjectDistance(),
            subjectArea: $exifDocument?->subjectArea(),
            afMode: null,
        );

        $xmpValues = $this->createXmpValues($xmpDocument, $exifDocument, $quickTimeLookup, $metadata);

        /** @var CoreComponents $coreComponents */
        $coreComponents = [
            ComponentKey::Author->value          => $author,
            ComponentKey::Camera->value          => $camera,
            ComponentKey::Capture->value         => $capture,
            ComponentKey::ColorProfile->value    => $colorProfile,
            ComponentKey::Composite->value       => $composite,
            ComponentKey::Derived->value         => $derived,
            ComponentKey::Device->value          => $device,
            ComponentKey::Exposure->value        => $exposure,
            ComponentKey::File->value            => $file,
            ComponentKey::Focus->value           => $focus,
            ComponentKey::Gps->value             => $gps,
            ComponentKey::Image->value           => $image,
            ComponentKey::Integrity->value       => $integrity,
            ComponentKey::Interop->value         => $interop,
            ComponentKey::Iptc->value            => new ValueIptc($iptcDocument),
            ComponentKey::Lens->value            => $lens,
            ComponentKey::Motion->value          => $motion,
            ComponentKey::MultiPicture->value    => $multiPicture,
            ComponentKey::Processing->value      => $processing,
            ComponentKey::Regions->value         => $regions,
            ComponentKey::Rights->value          => $rights,
            ComponentKey::Scene->value           => $scene,
            ComponentKey::Sensor->value          => $sensor,
            ComponentKey::Standards->value       => $standards,
            ComponentKey::Temporal->value        => $temporal,
            ComponentKey::Thumbnail->value       => $thumbnail,
            ComponentKey::Tiff->value            => $tiff,
            ComponentKey::WhiteBalance->value    => $whiteBalanceDetails,
            ComponentKey::Xmp->value             => new ValueXmp($xmpDocument),
            ComponentKey::MakerNotesApple->value => $apple,
        ];

        /** @var ValueComponents $components */
        $components = [...$coreComponents, ...$media, ...$xmpValues];

        return $components;
    }

    /**
     * Creates container, video, audio and embedded media value objects from QuickTime metadata.
     *
     * @param QuickTimeLookup $quickTimeLookup QuickTime metadata lookup.
     * @param Metadata        $metadata        Source metadata container.
     *
     * @return array{container: Container, video: Video, audio: ValueAudio, embeddedAudio: AudioClips, flashPix: FlashPix}
     */
    private function createContainerMedia(QuickTimeLookup $quickTimeLookup, Metadata $metadata): array
    {
        $container = new Container(
            format: $quickTimeLookup->string(QuickTimeMeta::MAJOR_BRAND_KEY),
            encoder: $quickTimeLookup->string('com.apple.quicktime.encoder',
                'Encoder',
            ),
            bitrate: $quickTimeLookup->int('AvgBitrate', 'Bitrate'),
            videoCodec: $quickTimeLookup->string(QuickTimeMeta::COMPRESSOR_NAME_KEY,
                QuickTimeMeta::VIDEO_CODEC_KEY,
                QuickTimeMeta::HANDLER_DESCRIPTION_KEY,
            ),
            audioCodec: $quickTimeLookup->string(QuickTimeMeta::AUDIO_FORMAT_KEY,
                QuickTimeMeta::AUDIO_CODEC_KEY,
            ),
        );

        $video = new Video(
            durationSec: $quickTimeLookup->float('com.apple.quicktime.duration'),
            frameRate: $quickTimeLookup->float('com.apple.quicktime.videoFrameRate'),
            width: $quickTimeLookup->int(QuickTimeMeta::VIDEO_WIDTH_KEY),
            height: $quickTimeLookup->int(QuickTimeMeta::VIDEO_HEIGHT_KEY),
            codec: $quickTimeLookup->string(QuickTimeMeta::COMPRESSOR_NAME_KEY,
                QuickTimeMeta::VIDEO_CODEC_KEY,
            ),
            hdr: $quickTimeLookup->bool('com.apple.quicktime.hdrFormat') ?? false,
            transferFunction: $quickTimeLookup->string('com.apple.quicktime.transferFunction'),
            colorPrimaries: $quickTimeLookup->string('com.apple.quicktime.colorPrimaries'),
        );

        $audio = new ValueAudio(
            channels: $quickTimeLookup->int(QuickTimeMeta::AUDIO_CHANNELS_KEY),
            sampleRate: $quickTimeLookup->int(QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY),
            codec: $quickTimeLookup->string(QuickTimeMeta::AUDIO_FORMAT_KEY,
                QuickTimeMeta::AUDIO_CODEC_KEY,
            ),
            bitDepth: $quickTimeLookup->int(QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY),
        );

        $embeddedAudio = AudioClips::fromJpegAudioStreams($metadata->jpegAudioStreams);
        $flashPix      = new FlashPix($metadata->flashPixStreams);

        return [
            ComponentKey::Container->value     => $container,
            ComponentKey::Video->value         => $video,
            ComponentKey::Audio->value         => $audio,
            ComponentKey::EmbeddedAudio->value => $embeddedAudio,
            ComponentKey::FlashPix->value      => $flashPix,
        ];
    }

    /**
     * Creates XMP-derived keyword, relationship and depth metadata.
     *
     * @param XmpDocument|null $xmpDocument     Parsed XMP document.
     * @param ParsedExif|null  $exifDocument    Parsed EXIF document.
     * @param QuickTimeLookup  $quickTimeLookup QuickTime metadata lookup.
     * @param Metadata         $metadata        Source metadata container.
     *
     * @return array{keywords: Keywords, related: RelatedAssets, depthMap: DepthMap, hdrGainMap: HdrGainMap}
     */
    private function createXmpValues(
        ?XmpDocument $xmpDocument,
        ?ParsedExif $exifDocument,
        QuickTimeLookup $quickTimeLookup,
        Metadata $metadata,
    ): array {
        $flatKeywords         = $xmpDocument?->stringList(XmpNamespace::DC->value, 'subject') ?? [];
        $hierarchicalKeywords = $xmpDocument?->stringList(XmpNamespace::LIGHTROOM->value, 'hierarchicalSubject') ?? [];

        $keywords = new Keywords(
            flat: $flatKeywords,
            hierarchical: $hierarchicalKeywords !== [] ? $hierarchicalKeywords : null,
        );

        $panoramaFlag = $xmpDocument?->bool(XmpNamespace::GOOGLE_PANORAMA->value, 'UsePanoramaViewer');
        $related      = new RelatedAssets(
            livePhotoPairId: $metadata->quickTime?->contentIdentifier(),
            burstId: $quickTimeLookup->string('BurstUUID'),
            isPrimaryInBurst: $quickTimeLookup->bool('BurstSelected') ?? false,
            panoramaId: $panoramaFlag === true ? 'panorama' : null,
            depthDataId: $quickTimeLookup->string('DepthData'),
            relatedSoundFile: $exifDocument?->relatedSoundFile(),
        );

        $depthMap = new DepthMap(
            data: $xmpDocument?->string(XmpNamespace::GOOGLE_DEPTH_MAP->value, 'Data'),
            mime: $xmpDocument?->string(XmpNamespace::GOOGLE_DEPTH_MAP->value, 'Mime'),
            near: $xmpDocument?->float(XmpNamespace::GOOGLE_DEPTH_MAP->value, 'Near'),
            far: $xmpDocument?->float(XmpNamespace::GOOGLE_DEPTH_MAP->value, 'Far'),
        );

        $hdrGainMap = new HdrGainMap(
            version: $xmpDocument?->string(XmpNamespace::HDR_GAINMAP->value, 'Version'),
            baseRenditionIsHdr: $xmpDocument?->bool(XmpNamespace::HDR_GAINMAP->value, 'BaseRenditionIsHDR'),
            hdrCapacityMin: $xmpDocument?->float(XmpNamespace::HDR_GAINMAP->value, 'HDRCapacityMin'),
            hdrCapacityMax: $xmpDocument?->float(XmpNamespace::HDR_GAINMAP->value, 'HDRCapacityMax'),
            gainMapMin: $xmpDocument?->float(XmpNamespace::HDR_GAINMAP->value, 'GainMapMin'),
            gainMapMax: $xmpDocument?->float(XmpNamespace::HDR_GAINMAP->value, 'GainMapMax'),
            gamma: $xmpDocument?->float(XmpNamespace::HDR_GAINMAP->value, 'Gamma'),
            offsetSdr: $xmpDocument?->float(XmpNamespace::HDR_GAINMAP->value, 'OffsetSDR'),
            offsetHdr: $xmpDocument?->float(XmpNamespace::HDR_GAINMAP->value, 'OffsetHDR'),
            auxiliaryImageType: $xmpDocument?->string(XmpNamespace::APPLE_PIXELDATA->value, 'AuxiliaryImageType'),
        );

        return [
            ComponentKey::Keywords->value   => $keywords,
            ComponentKey::Related->value    => $related,
            ComponentKey::DepthMap->value   => $depthMap,
            ComponentKey::HdrGainMap->value => $hdrGainMap,
        ];
    }

    /**
     * Creates thumbnail metadata from EXIF IFD1 entries.
     *
     * @param ParsedExif|null $exifDocument Parsed EXIF document.
     */
    private function createThumbnail(?ParsedExif $exifDocument): Thumbnail
    {
        return new Thumbnail(
            hasThumbnail: $exifDocument?->hasThumbnail() ?? false,
            thumbnailOffset: $exifDocument?->thumbnailJpegInterchangeFormat(),
            thumbnailLength: $exifDocument?->thumbnailJpegInterchangeFormatLength(),
            thumbnailCompression: $exifDocument?->thumbnailCompression(),
            thumbnailTileWidth: $exifDocument?->thumbnailTileWidth(),
            thumbnailTileLength: $exifDocument?->thumbnailTileLength(),
            thumbnailTileOffsets: $exifDocument?->thumbnailTileOffsets(),
            thumbnailTileByteCounts: $exifDocument?->thumbnailTileByteCounts(),
            thumbnailStripOffsets: $exifDocument?->thumbnailStripOffsets(),
            thumbnailStripByteCounts: $exifDocument?->thumbnailStripByteCounts(),
        );
    }

    /**
     * Creates color profile metadata from ICC data and EXIF gamma.
     *
     * @param Metadata        $metadata     Source metadata container.
     * @param ParsedExif|null $exifDocument Parsed EXIF document.
     */
    private function createColorProfile(Metadata $metadata, ?ParsedExif $exifDocument): ValueColorProfile
    {
        $iccData = null;
        if ($metadata->iccProfile !== null || $metadata->iccSegments !== []) {
            try {
                $iccData = $this->iccParser->decode($metadata->iccProfile, $metadata->iccSegments);
            } catch (ParseError) {
                // Malformed ICC profiles (non-standard padding, tag table layout,
                // etc.) are common in the wild.  Degrade gracefully so EXIF/XMP
                // metadata extraction is not blocked.
            }
        }

        return new ValueColorProfile(
            profileName: $iccData?->description,
            profileVersion: $iccData?->version,
            pcs: $iccData?->pcs,
            renderingIntent: $iccData?->renderingIntent,
            gamma: $exifDocument?->gamma(),
            profileId: $iccData?->profileId,
        );
    }

    /**
     * Creates author and creator contact metadata from EXIF and XMP sources.
     *
     * @param ParsedExif|null  $exifDocument Parsed EXIF document.
     * @param XmpDocument|null $xmpDocument  Parsed XMP document.
     */
    private function createAuthor(?ParsedExif $exifDocument, ?XmpDocument $xmpDocument): Author
    {
        $iptcNamespace      = XmpNamespace::IPTC_CORE->value;
        $creatorContactInfo = $xmpDocument?->structured($iptcNamespace, 'CreatorContactInfo');

        $contact = new CreatorContact(
            email: $this->resolveCreatorContactValue($xmpDocument, $creatorContactInfo, $iptcNamespace, 'CiEmailWork'),
            phone: $this->resolveCreatorContactValue($xmpDocument, $creatorContactInfo, $iptcNamespace, 'CiTelWork'),
            address: $this->resolveCreatorContactValue($xmpDocument, $creatorContactInfo, $iptcNamespace, 'CiAdrExtadr'),
            city: $this->resolveCreatorContactValue($xmpDocument, $creatorContactInfo, $iptcNamespace, 'CiAdrCity'),
            region: $this->resolveCreatorContactValue($xmpDocument, $creatorContactInfo, $iptcNamespace, 'CiAdrRegion'),
            postalCode: $this->resolveCreatorContactValue($xmpDocument, $creatorContactInfo, $iptcNamespace, 'CiAdrPcode'),
            country: $this->resolveCreatorContactValue($xmpDocument, $creatorContactInfo, $iptcNamespace, 'CiAdrCtry'),
            url: $this->resolveCreatorContactValue($xmpDocument, $creatorContactInfo, $iptcNamespace, 'CiUrlWork'),
        );

        return new Author(
            artist: $exifDocument?->artist(),
            ownerName: $exifDocument?->ownerName(),
            creator: $this->firstListValue($xmpDocument?->stringList(XmpNamespace::DC->value, 'creator') ?? []),
            contact: $contact,
            photographer: $exifDocument?->photographer(),
            imageEditor: $exifDocument?->imageEditor(),
        );
    }

    /**
     * Creates copyright and usage rights metadata from EXIF and XMP sources.
     *
     * @param ParsedExif|null  $exifDocument Parsed EXIF document.
     * @param XmpDocument|null $xmpDocument  Parsed XMP document.
     */
    private function createRights(?ParsedExif $exifDocument, ?XmpDocument $xmpDocument): Rights
    {
        return new Rights(
            copyright: $exifDocument?->copyright(),
            usageTerms: $xmpDocument?->string(XmpNamespace::XAP_RIGHTS->value, 'UsageTerms'),
            licenseUrl: $xmpDocument?->string(XmpNamespace::XAP_RIGHTS->value, 'WebStatement'),
            creditLine: $xmpDocument?->string(XmpNamespace::PHOTOSHOP->value, 'Credit'),
            learningOptOutIn: $exifDocument?->learningOptOutIn(),
        );
    }

    /**
     * Creates derived photography values from lens and exposure data.
     *
     * @param Lens     $lens     Lens metadata.
     * @param Exposure $exposure Exposure metadata.
     */
    private function createDerived(Lens $lens, Exposure $exposure): Derived
    {
        $cropFactor          = $this->converters->calcCropFactor($lens->focalLengthIn35mm, $lens->focalLengthMm);
        $circleOfConfusionMm = $cropFactor !== null
            ? $this->converters->calcCircleOfConfusionMm($cropFactor)
            : null;

        return new Derived(
            ev100: $this->converters->calcEv100(
                $exposure->settings?->exposureTimeSec,
                $exposure->settings?->fNumber,
                $exposure->settings?->iso,
            ),
            hyperfocalDistanceMetres: $this->converters->calcHyperfocalM(
                $lens->focalLengthMm,
                $exposure->settings?->fNumber,
                $circleOfConfusionMm,
            ),
            circleOfConfusionMm: $circleOfConfusionMm,
            fieldOfViewDiagonalDeg: $this->converters->calcFovDeg($lens->focalLengthIn35mm, $cropFactor, $lens->focalLengthMm),
            fieldOfViewHorizontalDeg: $this->converters->calcHorizontalFovDeg($lens->focalLengthIn35mm, $cropFactor, $lens->focalLengthMm),
            fieldOfViewVerticalDeg: $this->converters->calcVerticalFovDeg($lens->focalLengthIn35mm, $cropFactor, $lens->focalLengthMm),
            equivalent35mm: $lens->focalLengthIn35mm,
            cropFactor: $cropFactor,
        );
    }

    /**
     * Creates file integrity metadata from XMP edit-history indicators.
     *
     * @param XmpDocument|null $xmpDocument Parsed XMP document.
     */
    private function createIntegrity(?XmpDocument $xmpDocument): Integrity
    {
        $hasHistory = $xmpDocument?->has(XmpNamespace::XAP_MM->value, 'History') ?? false;

        return new Integrity(
            originalFileName: $xmpDocument?->string(XmpNamespace::TIFF->value, 'OriginalFileName'),
            originalDigest: null,
            edited: $hasHistory ? true : null,
            historyLastSoftware: null,
        );
    }

    /**
     * Counts the number of face regions detected in the supplied region aggregate.
     *
     * @param RegionCollection $regions Region aggregate containing detected regions.
     *
     * @return int|null Number of face regions or null when no face region exists.
     */
    private function countFaceRegions(RegionCollection $regions): ?int
    {
        $count = count(array_filter(
            $regions->items,
            static fn (Region $region): bool => $region->type === RegionType::Face,
        ));

        return $count > 0 ? $count : null;
    }

    /**
     * Returns the first string from the list or null.
     *
     * @param list<string> $values List of candidate string values in priority order.
     *
     * @return string|null First entry in the list or null when the list is empty.
     */
    private function firstListValue(array $values): ?string
    {
        return $values[0] ?? null;
    }

    /**
     * Resolves IPTC creator contact fields from either flattened legacy fields or CreatorContactInfo.
     *
     * @param XmpDocument|null        $document       Parsed XMP document.
     * @param XmpStructuredValue|null $creatorContact Structured CreatorContactInfo object.
     * @param string                  $namespace      IPTC namespace URI.
     * @param string                  $localName      Contact field local name.
     *
     * @return string|null Resolved contact value or null when unavailable.
     */
    private function resolveCreatorContactValue(
        ?XmpDocument $document,
        ?XmpStructuredValue $creatorContact,
        string $namespace,
        string $localName,
    ): ?string {
        $directValue = $document?->string($namespace, $localName);
        if ($directValue !== null) {
            return $directValue;
        }

        return $creatorContact?->string($namespace, $localName);
    }
}
