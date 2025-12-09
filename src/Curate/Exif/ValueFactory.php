<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif;

use MagicSunday\ImageMeta\Contracts\ValueFactoryInterface;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\CameraFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\DeviceFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\ExposureFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\GpsFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\ImageFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\LensFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\MotionFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\MultiPictureFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\RegionsFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\SceneFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\SensorFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\TemporalFactory;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\Icc\IccDecoder;
use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\AudioClips;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\Regions\RegionType;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\Thumbnail;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;

use function count;

/**
 * Builds the structured metadata aggregate by orchestrating value-object creation from
 * ParsedExif, QuickTimeMeta and MakerNotes sources.
 */
final readonly class ValueFactory implements ValueFactoryInterface
{
    /**
     * Constructs the ValueFactory with specialized sub-factories.
     *
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
     */
    public function __construct(
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
    ) {
    }

    /**
     * Produces normalised value objects derived from the supplied metadata container.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return array{
     *     audio: Audio,
     *     author: Author,
     *     camera: Camera,
     *     capture: Capture,
     *     colorProfile: ColorProfile,
     *     composite: CompositeImageInfo,
     *     container: Container,
     *     derived: Derived,
     *     device: Device,
     *     embeddedAudio: AudioClips,
     *     exposure: Exposure,
     *     file: File,
     *     flashPix: FlashPix,
     *     focus: Focus,
     *     gps: Gps,
     *     image: Image,
     *     integrity: Integrity,
     *     interop: Interop,
     *     keywords: Keywords,
     *     lens: Lens,
     *     motion: Motion,
     *     multiPicture: MultiPicture,
     *     processing: ProcessingSettings,
     *     regions: Regions,
     *     related: RelatedAssets,
     *     rights: Rights,
     *     scene: Scene,
     *     sensor: Sensor,
     *     standards: Standards,
     *     temporal: Temporal,
     *     thumbnail: Thumbnail,
     *     tiff: TiffData,
     *     video: Video,
     *     whiteBalance: WhiteBalanceDetails,
     *     xmp: Xmp,
     *     makerNotesApple: AppleMakerNotes|null,
     * }
     */
    public function createComponents(Metadata $metadata): array
    {
        $xmpDocument = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();

        // Use sub-factories for modular metadata creation
        $gps          = $this->gpsFactory->create($metadata);
        $camera       = $this->cameraFactory->create($metadata);
        $lens         = $this->lensFactory->create($metadata);
        $exposure     = $this->exposureFactory->create($metadata);
        $sensor       = $this->sensorFactory->create($metadata);
        $device       = $this->deviceFactory->create($metadata);
        $image        = $this->imageFactory->create($metadata);
        $motion       = $this->motionFactory->create($metadata);
        $temporal     = $this->temporalFactory->create($metadata);
        $regions      = $this->regionsFactory->create($metadata);
        $multiPicture = $this->multiPictureFactory->create($metadata);
        $scene        = $this->sceneFactory->create($metadata, $this->countFaceRegions($regions));

        $exifDocument    = $metadata->exifDoc;
        $quickTimeMeta   = $metadata->quickTime;
        $quickTimeLookup = new QuickTimeLookup($quickTimeMeta);
        $appleMakerNotes = $metadata->makerNotes?->apple;

        $interop = new Interop(
            index: $exifDocument?->interopIndex(),
        );

        $bitsPerSample    = $exifDocument?->bitsPerSample() ?? $metadata->jpegBitsPerSample;
        $ycbcrSubSampling = $exifDocument?->ycbcrSubSampling() ?? $metadata->jpegYCbCrSubSampling;

        $referenceBlackWhite = $exifDocument?->referenceBlackWhite();
        if ($referenceBlackWhite !== null) {
            if (count($referenceBlackWhite) === 6) {
                /** @var array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $referenceBlackWhite */
                $referenceBlackWhite = [
                    0 => $referenceBlackWhite[0],
                    1 => $referenceBlackWhite[1],
                    2 => $referenceBlackWhite[2],
                    3 => $referenceBlackWhite[3],
                    4 => $referenceBlackWhite[4],
                    5 => $referenceBlackWhite[5],
                ];
            } else {
                $referenceBlackWhite = null;
            }
        }

        $tiff = new TiffData(
            samplesPerPixel: $exifDocument?->samplesPerPixel(),
            bitsPerSample: $bitsPerSample,
            rowsPerStrip: $exifDocument?->rowsPerStrip(),
            tileWidth: $exifDocument?->tileWidth(),
            tileLength: $exifDocument?->tileLength(),
            compression: $exifDocument?->compression(),
            photometric: $exifDocument?->photometric(),
            planar: $exifDocument?->planarConfiguration(),
            resolutionUnit: $exifDocument?->resolutionUnit(),
            xResolution: $exifDocument?->xResolution(),
            yResolution: $exifDocument?->yResolution(),
            ycbcrPos: $exifDocument?->ycbcrPositioning(),
            ycbcrSubSampling: $ycbcrSubSampling,
            ycbcrCoefficients: $exifDocument?->ycbcrCoefficients(),
            whitePoint: $exifDocument?->whitePoint(),
            primaryChromaticities: $exifDocument?->primaryChromaticities(),
            stripOffsets: $exifDocument?->stripOffsets(),
            stripByteCounts: $exifDocument?->stripByteCounts(),
            tileOffsets: $exifDocument?->tileOffsets(),
            tileByteCounts: $exifDocument?->tileByteCounts(),
            transferFunction: $exifDocument?->transferFunction(),
            jpegInterchangeFormat: $exifDocument?->jpegInterchangeFormat(),
            jpegInterchangeFormatLength: $exifDocument?->jpegInterchangeFormatLength(),
            referenceBlackWhite: $referenceBlackWhite,
            copyright: $exifDocument?->copyright(),
        );

        $composite = new CompositeImageInfo(
            type: $exifDocument?->compositeImage(),
            counts: $exifDocument?->sourceImageNumberOfCompositeImage(),
            exposureTimesTotal: $exifDocument?->sourceExposureTimesOfCompositeImage(),
        );

        $exifVersion = $exifDocument?->exifVersion();
        $profile     = $exifDocument?->exifProfile() ?? 'unknown';

        $standards = new Standards(
            exifVersion: $exifVersion,
            profile: $profile,
            flashpixVersion: $exifDocument?->flashpixVersion(),
        );

        $flashPix = new FlashPix($metadata->flashPixStreams);

        $capture = new Capture(
            dateTime: $exifDocument?->captureDateTime(),
            temperatureC: $exifDocument?->temperatureCelsius(),
            humidityPercent: $exifDocument?->humidityPercent(),
            pressureHPa: $exifDocument?->pressureHPa(),
            waterDepthM: $exifDocument?->waterDepthMeters(),
            accelerationMs2: $exifDocument?->accelerationMs2(),
            cameraElevationAngleDeg: $exifDocument?->cameraElevationAngleDeg(),
        );

        $apple = $appleMakerNotes ?? $this->emptyAppleMakerNotes();
        $xmp   = new Xmp($xmpDocument);

        $file = new File(
            $metadata->mimeType,
            $metadata->fileSize,
            $metadata->extension,
            $metadata->digestSha1,
            $metadata->digestMd5,
        );

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

        $thumbnailCompression  = $exifDocument?->thumbnailCompression();
        $thumbnailStripOffsets = $exifDocument?->thumbnailStripOffsets();
        $thumbnailStripCounts  = $exifDocument?->thumbnailStripByteCounts();
        $thumbnailTileOffsets  = $exifDocument?->thumbnailTileOffsets();
        $thumbnailTileCounts   = $exifDocument?->thumbnailTileByteCounts();
        $thumbnailTileWidth    = $exifDocument?->thumbnailTileWidth();
        $thumbnailTileLength   = $exifDocument?->thumbnailTileLength();

        $thumbnail = new Thumbnail(
            hasThumbnail: $exifDocument?->hasThumbnail() ?? false,
            thumbnailOffset: $exifDocument?->thumbnailJpegInterchangeFormat(),
            thumbnailLength: $exifDocument?->thumbnailJpegInterchangeFormatLength(),
            thumbnailCompression: $thumbnailCompression,
            thumbnailTileWidth: $thumbnailTileWidth,
            thumbnailTileLength: $thumbnailTileLength,
            thumbnailTileOffsets: $thumbnailTileOffsets,
            thumbnailTileByteCounts: $thumbnailTileCounts,
            thumbnailStripOffsets: $thumbnailStripOffsets,
            thumbnailStripByteCounts: $thumbnailStripCounts,
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

        $audio = new Audio(
            channels: $quickTimeLookup->int(QuickTimeMeta::AUDIO_CHANNELS_KEY),
            sampleRate: $quickTimeLookup->int(QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY),
            codec: $quickTimeLookup->string(QuickTimeMeta::AUDIO_FORMAT_KEY,
                QuickTimeMeta::AUDIO_CODEC_KEY,
            ),
            bitDepth: $quickTimeLookup->int(QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY),
        );

        $embeddedAudio = AudioClips::fromJpegAudioStreams($metadata->jpegAudioStreams);

        $iccData = null;
        if ($metadata->iccProfile !== null || $metadata->iccSegments !== []) {
            $iccData = (new IccDecoder())->decode($metadata->iccProfile, $metadata->iccSegments);
        }

        $colorProfile = new ColorProfile(
            profileName: $iccData['description'] ?? null,
            profileVersion: $iccData['version'] ?? null,
            pcs: $iccData['pcs'] ?? null,
            renderingIntent: $iccData['renderingIntent'] ?? null,
            gamma: $exifDocument?->gamma(),
            profileId: $iccData['profileId'] ?? null,
        );

        $processing = new ProcessingSettings(
            sharpness: $exifDocument?->sharpness(),
            contrast: $exifDocument?->contrast(),
            saturation: $exifDocument?->saturation(),
            pictureStyle: null,
            clarity: null,
            customRendered: $exifDocument?->customRendered()?->value,
            deviceSettingDescription: $exifDocument?->deviceSettingDescription(),
        );

        $whiteBalanceKelvin = $apple->colorTemperature;
        if ($whiteBalanceKelvin === null) {
            $whiteBalanceKelvin = $quickTimeLookup->int('ColorTemperature');
        }

        $whiteBalanceDetails = new WhiteBalanceDetails(
            mode: $exposure->whiteBalance,
            kelvin: $whiteBalanceKelvin,
            rgGain: null,
            bgGain: null,
        );

        $subjectArea = $exifDocument?->subjectArea();

        $focus = new Focus(
            subjectDistanceM: $exifDocument?->subjectDistance(),
            subjectArea: $subjectArea,
            afMode: null,
        );

        $flatKeywords         = $xmpDocument?->stringList('http://purl.org/dc/elements/1.1/', 'subject') ?? [];
        $hierarchicalKeywords = $xmpDocument?->stringList('http://ns.adobe.com/lightroom/1.0/', 'hierarchicalSubject') ?? [];

        $keywords = new Keywords(
            flat: $flatKeywords,
            hierarchical: $hierarchicalKeywords !== [] ? $hierarchicalKeywords : null,
        );

        $rights = new Rights(
            copyright: $exifDocument?->copyright(),
            usageTerms: $xmpDocument?->string('http://ns.adobe.com/xap/1.0/rights/', 'UsageTerms'),
            licenseUrl: $xmpDocument?->string('http://ns.adobe.com/xap/1.0/rights/', 'WebStatement'),
            creditLine: $xmpDocument?->string('http://ns.adobe.com/photoshop/1.0/', 'Credit'),
        );

        $author = new Author(
            artist: $exifDocument?->artist(),
            ownerName: $exifDocument?->ownerName(),
            creator: $this->firstListValue($xmpDocument?->stringList('http://purl.org/dc/elements/1.1/', 'creator') ?? []),
            creatorEmail: $xmpDocument?->string('http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/', 'CreatorContactInfo/Iptc4xmpCore:CiEmailWork'),
            photographer: $exifDocument?->photographer(),
            imageEditor: $exifDocument?->imageEditor(),
        );

        $cropFactor          = ValueConverters::calcCropFactor($lens->focalLengthIn35mm, $lens->focalLengthMm);
        $circleOfConfusionMm = $cropFactor !== null
            ? ValueConverters::calcCircleOfConfusionMm($cropFactor)
            : null;

        $derived = new Derived(
            ev100: ValueConverters::calcEv100(
                $exposure->exposureTimeSec,
                $exposure->fNumber,
                $exposure->iso,
            ),
            hyperfocalDistanceMetres: ValueConverters::calcHyperfocalM(
                $lens->focalLengthMm,
                $exposure->fNumber,
                $circleOfConfusionMm,
            ),
            circleOfConfusionMm: $circleOfConfusionMm,
            fieldOfViewDiagonalDeg: ValueConverters::calcFovDeg($lens->focalLengthIn35mm, $cropFactor, $lens->focalLengthMm),
            fieldOfViewHorizontalDeg: ValueConverters::calcHorizontalFovDeg($lens->focalLengthIn35mm, $cropFactor, $lens->focalLengthMm),
            fieldOfViewVerticalDeg: ValueConverters::calcVerticalFovDeg($lens->focalLengthIn35mm, $cropFactor, $lens->focalLengthMm),
            equivalent35mm: $lens->focalLengthIn35mm,
            cropFactor: $cropFactor,
        );

        $panoramaFlag = $xmpDocument?->bool('http://ns.google.com/photos/1.0/panorama/', 'UsePanoramaViewer');
        $related      = new RelatedAssets(
            livePhotoPairId: $metadata->quickTime?->contentIdentifier(),
            burstId: $quickTimeLookup->string('BurstUUID'),
            isPrimaryInBurst: $quickTimeLookup->bool('BurstSelected') ?? false,
            panoramaId: $panoramaFlag === true ? 'panorama' : null,
            depthDataId: $quickTimeLookup->string('DepthData'),
            relatedSoundFile: $exifDocument?->relatedSoundFile(),
        );

        $hasHistory = $xmpDocument?->has('http://ns.adobe.com/xap/1.0/mm/', 'History') ?? false;

        $integrity = new Integrity(
            originalFileName: $xmpDocument?->string('http://ns.adobe.com/tiff/1.0/', 'OriginalFileName'),
            originalDigest: null,
            edited: $hasHistory ? true : null,
            historyLastSoftware: null,
        );

        return [
            'audio'           => $audio,
            'author'          => $author,
            'camera'          => $camera,
            'capture'         => $capture,
            'colorProfile'    => $colorProfile,
            'composite'       => $composite,
            'container'       => $container,
            'derived'         => $derived,
            'device'          => $device,
            'embeddedAudio'   => $embeddedAudio,
            'exposure'        => $exposure,
            'file'            => $file,
            'flashPix'        => $flashPix,
            'focus'           => $focus,
            'gps'             => $gps,
            'image'           => $image,
            'integrity'       => $integrity,
            'interop'         => $interop,
            'keywords'        => $keywords,
            'lens'            => $lens,
            'motion'          => $motion,
            'multiPicture'    => $multiPicture,
            'processing'      => $processing,
            'regions'         => $regions,
            'related'         => $related,
            'rights'          => $rights,
            'scene'           => $scene,
            'sensor'          => $sensor,
            'standards'       => $standards,
            'temporal'        => $temporal,
            'thumbnail'       => $thumbnail,
            'tiff'            => $tiff,
            'video'           => $video,
            'whiteBalance'    => $whiteBalanceDetails,
            'xmp'             => $xmp,
            'makerNotesApple' => $apple,
        ];
    }

    /**
     * Counts the number of face regions detected in the supplied region aggregate.
     *
     * @param Regions $regions Region aggregate containing detected regions.
     *
     * @return int|null Number of face regions or null when no face region exists.
     */
    private function countFaceRegions(Regions $regions): ?int
    {
        $count = 0;

        foreach ($regions->items as $region) {
            if ($region->type === RegionType::FACE) {
                ++$count;
            }
        }

        return $count > 0 ? $count : null;
    }

    private function emptyAppleMakerNotes(): AppleMakerNotes
    {
        /** @var AppleMakerNotes|null $empty */
        static $empty = null;

        if ($empty === null) {
            $empty = new AppleMakerNotes(
                contentIdentifier: null,
                cameraType: null,
                hdrHeadroom: null,
                hdrGain: null,
                snr: null,
                aeStable: null,
                aeTarget: null,
                aeAverage: null,
                afStable: null,
                afPerformance: null,
                signalToNoiseRatioType: null,
                luminanceNoiseAmplitude: null,
                focusPosition: null,
                livePhotoIndex: null,
                colorTemperature: null,
                semanticStylePreset: null,
                semanticStyleWarmth: null,
                semanticStyleTone: null,
                flags: [],
                accelerationVector: null,
            );
        }

        return $empty;
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
     * Normalises EXIF fractional second strings.
     *
     * @param string|null $value Raw fractional second string as stored in EXIF tags.
     *
     * @return string|null Cleaned fractional second string or null when empty.
     */
}
