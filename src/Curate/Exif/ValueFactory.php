<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MagicSunday\ImageMeta\Contracts\ValueFactoryInterface;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\CameraFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\DeviceFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\ExposureFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\GpsFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\ImageFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\LensFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\MotionFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\SceneFactory;
use MagicSunday\ImageMeta\Curate\Exif\SubFactory\SensorFactory;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Icc\IccDecoder;
use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\AudioClips;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\ExifFlash;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\MultiPictureEntry;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\Regions\Region;
use MagicSunday\ImageMeta\Value\Regions\RegionType;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\Thumbnail;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;

use function abs;
use function array_any;
use function array_map;
use function array_shift;
use function array_values;
use function ceil;
use function count;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function ksort;
use function log10;
use function max;
use function preg_match;
use function preg_replace;
use function preg_split;
use function round;
use function sprintf;
use function str_contains;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function strtoupper;
use function substr;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Builds the structured metadata aggregate by orchestrating value-object creation from
 * ParsedExif, QuickTimeMeta and MakerNotes sources.
 */
final class ValueFactory implements ValueFactoryInterface
{
    /**
     * Constructs the ValueFactory with specialized sub-factories.
     *
     * @param CameraFactory   $cameraFactory   Factory for camera metadata.
     * @param LensFactory     $lensFactory     Factory for lens metadata.
     * @param ExposureFactory $exposureFactory Factory for exposure metadata.
     * @param SensorFactory   $sensorFactory   Factory for sensor metadata.
     * @param DeviceFactory   $deviceFactory   Factory for device metadata.
     * @param ImageFactory    $imageFactory    Factory for image metadata.
     * @param SceneFactory    $sceneFactory    Factory for scene metadata.
     * @param MotionFactory   $motionFactory   Factory for motion metadata.
     * @param GpsFactory      $gpsFactory      Factory for GPS metadata.
     */
    public function __construct(
        private readonly CameraFactory $cameraFactory = new CameraFactory(),
        private readonly LensFactory $lensFactory = new LensFactory(),
        private readonly ExposureFactory $exposureFactory = new ExposureFactory(),
        private readonly SensorFactory $sensorFactory = new SensorFactory(),
        private readonly DeviceFactory $deviceFactory = new DeviceFactory(),
        private readonly ImageFactory $imageFactory = new ImageFactory(),
        private readonly SceneFactory $sceneFactory = new SceneFactory(),
        private readonly MotionFactory $motionFactory = new MotionFactory(),
        private readonly GpsFactory $gpsFactory = new GpsFactory(),
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
        
        $regions      = $this->createRegions($xmpDocument);
        $scene        = $this->sceneFactory->create($metadata, $this->countFaceRegions($regions));
        $multiPicture = $this->createMultiPicture($metadata);
        $exifDocument    = $metadata->exifDoc;
        $quickTimeMeta   = $metadata->quickTime;
        $quickTimeLookup = new QuickTimeLookup($quickTimeMeta);
        $appleMakerNotes = $metadata->makerNotes?->apple;

        $interop = new Interop(
            index: $exifDocument?->interopIndex(),
        );

        $bitsPerSample    = $exifDocument?->bitsPerSample() ?? $metadata->jpegBitsPerSample;
        $ycbcrSubSampling = $exifDocument?->ycbcrSubSampling();
        if ($ycbcrSubSampling === null) {
            $ycbcrSubSampling = $metadata->jpegYCbCrSubSampling;
        }

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
        $profile     = $exifDocument?->exifProfile() ?? '2.2';

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
            mode: $whiteBalance,
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

        $temporal = $this->buildTemporal($exifDocument, $quickTimeMeta, $xmpDocument);

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
     * Builds the temporal value object derived from EXIF, QuickTime and XMP data.
     *
     * Fractional seconds are mirrored into the generic field to keep display values consistent
     * whenever only the original or digitized timestamp carries sub-second precision.
     *
     * @param ParsedExif|null    $exifDocument EXIF document exposing timestamps and offsets.
     * @param QuickTimeMeta|null $quickTime    QuickTime metadata used for time fallbacks.
     * @param XmpDocument|null   $xmpDocument  XMP document providing timestamp fields.
     *
     * @return Temporal Normalised temporal metadata aggregate.
     */
    private function buildTemporal(?ParsedExif $exifDocument, ?QuickTimeMeta $quickTime, ?XmpDocument $xmpDocument): Temporal
    {
        $exifCreate = $exifDocument?->dateTimeDigitized();
        $exifModify = $exifDocument?->dateTime();

        $xmpCreate       = $this->parseFlexibleDate($xmpDocument?->string('http://ns.adobe.com/xap/1.0/', 'CreateDate'));
        $xmpModify       = $this->parseFlexibleDate($xmpDocument?->string('http://ns.adobe.com/xap/1.0/', 'ModifyDate'));
        $xmpDateCreated  = $this->parseFlexibleDate($xmpDocument?->string('http://ns.adobe.com/photoshop/1.0/', 'DateCreated'));
        $lookup          = new QuickTimeLookup($quickTime);
        $quickTimeCreate = $this->parseFlexibleDate($lookup->string('CreationDate'));
        $quickTimeModify = $this->parseFlexibleDate($lookup->string('ModifyDate'));

        $create = $exifCreate ?? $xmpCreate ?? $quickTimeCreate ?? $xmpDateCreated;
        $modify = $exifModify ?? $xmpModify ?? $quickTimeModify;

        [$original, $tz, $subOriginalRaw] = $this->originalTimestampComponents($exifDocument);

        $originalWithTz = $original;
        if ($original instanceof DateTimeImmutable && $tz instanceof DateTimeZone) {
            $originalWithTz = $original->setTimezone($tz);
        }

        $offsetTime          = $exifDocument?->offsetTime();
        $offsetTimeOriginal  = $exifDocument?->offsetTimeOriginal();
        $offsetTimeDigitized = $exifDocument?->offsetTimeDigitized();

        $subSecTime          = $this->sanitizeSubSeconds($exifDocument?->subSecTime());
        $subSecTimeDigitized = $this->sanitizeSubSeconds($exifDocument?->subSecTimeDigitized());
        $subSecOriginal      = $this->sanitizeSubSeconds($subOriginalRaw);

        if ($subSecTime === null) {
            $subSecTime = $subSecOriginal ?? $subSecTimeDigitized;
        }

        $tzSource = null;

        if (($tz instanceof DateTimeZone)
            && ($offsetTimeOriginal !== null)
            && (ValueConverters::parseOffset($offsetTimeOriginal) instanceof DateTimeZone)
        ) {
            $tzSource = 'OffsetTimeOriginal';
        }

        return new Temporal(
            create: $create,
            modify: $modify,
            original: $originalWithTz,
            tz: $tz,
            tzSource: $tzSource,
            offsetTime: $offsetTime,
            offsetTimeOriginal: $offsetTimeOriginal,
            offsetTimeDigitized: $offsetTimeDigitized,
            subSecTime: $subSecTime,
            subSecTimeOriginal: $subSecOriginal,
            subSecTimeDigitized: $subSecTimeDigitized,
        );
    }

    /**
     * Extracts the original capture timestamp components from the EXIF document.
     *
     * @return array{0:?DateTimeImmutable,1:?DateTimeZone,2:?string}
     */
    private function originalTimestampComponents(?ParsedExif $document): array
    {
        if (!$document instanceof ParsedExif) {
            return [null, null, null];
        }

        $original = $document->dateTimeOriginalBestEffort();
        $offset   = $document->offsetTimeOriginal();

        if ($offset === null && $this->dateTimeStringEmpty($document->dateTimeOriginalRaw())) {
            $offset = $document->offsetTimeDigitized();
        }

        if (
            $offset === null
            && $this->dateTimeStringEmpty($document->dateTimeOriginalRaw())
            && $this->dateTimeStringEmpty($document->dateTimeDigitizedRaw())
        ) {
            $offset = $document->offsetTime();
        }

        $offsetString = $this->normaliseOffsetValue($offset);
        $timezone     = ValueConverters::parseOffset($offsetString);

        if ($timezone instanceof DateTimeZone && $original instanceof DateTimeImmutable) {
            $original = $original->setTimezone($timezone);
        }

        $subSeconds = $document->subSecTimeOriginal();

        return [
            $original,
            $timezone instanceof DateTimeZone ? $timezone : null,
            $subSeconds,
        ];
    }

    /**
     * Determines whether an EXIF date/time string is empty after trimming whitespace.
     */
    private function dateTimeStringEmpty(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    /**
     * Normalises textual or numeric offsets to a canonical ±HH:MM representation.
     */
    private function normaliseOffsetValue(int|string|null $offset): ?string
    {
        if ($offset === null) {
            return null;
        }

        if (is_string($offset)) {
            $trimmed = trim($offset);

            return $trimmed === '' ? null : $trimmed;
        }

        $absOffset = abs($offset);
        $hours     = $absOffset;
        $minutes   = 0;

        if ($absOffset > 14) {
            $hours   = intdiv($absOffset, 60);
            $minutes = $absOffset % 60;
        }

        if ($hours > 14 || ($hours === 14 && $minutes !== 0)) {
            return null;
        }

        $sign = $offset < 0 ? '-' : '+';

        return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
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
        return new AppleMakerNotes(
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
    private function sanitizeSubSeconds(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $digits = preg_replace('/\\D+/', '', $value);
        if ($digits === null || $digits === '') {
            return null;
        }

        $digits = substr($digits, 0, 3);

        return str_pad($digits, 3, '0');
    }

    /**
     * Attempts to parse various ISO 8601 date representations.
     *
     * @param string|null $value Timestamp string in ISO 8601 format.
     *
     * @return DateTimeImmutable|null Parsed timestamp or null when parsing fails.
     */
    private function parseFlexibleDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private function createRegions(?XmpDocument $document): Regions
    {
        return $this->resolveRegions($document);
    }

    private const string NS_MWG_REGIONS = 'http://www.metadataworkinggroup.com/schemas/regions/';

    private const string NS_ST_AREA = 'http://ns.adobe.com/xmp/sType/Area#';

    private const string NS_ST_DIMENSIONS = 'http://ns.adobe.com/xmp/sType/Dimensions#';

    private const string NS_APPLE_FACEINFO = 'http://ns.apple.com/faceinfo/1.0/';

    private const float MATCH_THRESHOLD = 0.12;

    /**
     * Builds a regions aggregate from the supplied XMP document.
     */
    private function resolveRegions(?XmpDocument $document): Regions
    {
        if (!$document instanceof XmpDocument) {
            return new Regions([]);
        }

        $dimensions   = $this->appliedDimensions($document);
        $mwgRegions   = $this->extractMwgRegions($document, $dimensions);
        $appleData    = $this->extractAppleFaceRegions($document, $dimensions, $mwgRegions);
        $supplement   = $appleData['supplemental'];
        $mwgRegions   = $this->applyAppleSupplementalMetadata($mwgRegions, $supplement);
        $appleRegions = $appleData['regions'];

        foreach ($appleRegions as $appleRegion) {
            $matchIndex = $this->findMatchingRegionIndex($mwgRegions, $appleRegion);
            if ($matchIndex !== null) {
                $mwgRegions[$matchIndex] = $this->mergeRegion($mwgRegions[$matchIndex], $appleRegion);
            } else {
                $mwgRegions[] = $appleRegion;
            }
        }

        $mwgRegions = $this->applyAppleSupplementalMetadata($mwgRegions, $supplement);

        /** @var list<Region> $normalisedRegions */
        $normalisedRegions = array_values($mwgRegions);

        return new Regions($normalisedRegions);
    }

    /**
     * Extracts MWG-RS region entries.
     *
     * @param array{w: float, h: float}|null $dimensions
     *
     * @return list<Region>
     */
    private function extractMwgRegions(XmpDocument $document, ?array $dimensions): array
    {
        $types        = $this->stringValues($document, self::NS_MWG_REGIONS, 'Type');
        $names        = $this->stringValues($document, self::NS_MWG_REGIONS, 'Name');
        $displayNames = $this->stringValues($document, self::NS_MWG_REGIONS, 'PersonDisplayName');
        $confidences  = $this->floatValues($document, self::NS_MWG_REGIONS, 'Confidence');
        $rotations    = $this->floatValues($document, self::NS_MWG_REGIONS, 'Rotation');
        $centersX     = $this->floatValues($document, self::NS_ST_AREA, 'x');
        $centersY     = $this->floatValues($document, self::NS_ST_AREA, 'y');
        $widths       = $this->floatValues($document, self::NS_ST_AREA, 'w');
        $heights      = $this->floatValues($document, self::NS_ST_AREA, 'h');
        $regionCount  = max(count($centersX), count($centersY), count($widths), count($heights));
        $resolved     = [];

        for ($index = 0; $index < $regionCount; ++$index) {
            $centerX = $centersX[$index] ?? null;
            $centerY = $centersY[$index] ?? null;
            $width   = $widths[$index] ?? null;
            $height  = $heights[$index] ?? null;
            if ($centerX === null) {
                continue;
            }

            if ($centerY === null) {
                continue;
            }

            if ($width === null) {
                continue;
            }

            if ($height === null) {
                continue;
            }

            $normalised = $this->normalisedBox($centerX, $centerY, $width, $height, $dimensions);
            if ($normalised === null) {
                continue;
            }

            $typeLabel = $types[$index] ?? null;
            $type      = $typeLabel !== null ? RegionType::fromLabel($typeLabel) : null;

            $person = $displayNames[$index] ?? $names[$index] ?? null;
            if ($person !== null && $person === '') {
                $person = null;
            }

            $resolved[] = new Region(
                $type,
                $normalised['x'],
                $normalised['y'],
                $normalised['w'],
                $normalised['h'],
                $person,
                $confidences[$index] ?? null,
                $rotations[$index] ?? null,
            );
        }

        return $resolved;
    }

    /**
     * Extracts Apple FaceInfo face entries along with supplemental metadata.
     *
     * @param array{w: float, h: float}|null $dimensions
     * @param list<Region>                   $mwgRegions
     *
     * @return array{regions: list<Region>, supplemental: array<int, Region>}
     */
    private function extractAppleFaceRegions(XmpDocument $document, ?array $dimensions, array $mwgRegions): array
    {
        $entries = $this->appleFaceEntries($document, $dimensions);

        return [
            'regions'      => $this->regionsFromAppleEntries($entries),
            'supplemental' => $this->supplementalRegionsFromAppleEntries($entries, $mwgRegions),
        ];
    }

    /**
     * Extracts Apple face region entries from XMP document.
     *
     * @param XmpDocument                    $document   XMP document to extract from.
     * @param array{w: float, h: float}|null $dimensions Image dimensions for normalization.
     *
     * @return list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}> List of Apple face entries.
     */
    private function appleFaceEntries(XmpDocument $document, ?array $dimensions): array
    {
        $centersX         = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'CenterX');
        $centersY         = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'CenterY');
        $widths           = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'Width');
        $heights          = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'Height');
        $confidenceLevels = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'ConfidenceLevel');
        $confidences      = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'Confidence');
        $angleInfoRolls   = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'AngleInfoRoll');
        $rolls            = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'Roll');
        $yaws             = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'Yaw');

        $confidenceScale = $this->confidenceScale($confidenceLevels, $confidences);

        $names = $this->stringValues($document, self::NS_APPLE_FACEINFO, 'Name');
        if ($names === []) {
            $names = $this->stringValues($document, self::NS_APPLE_FACEINFO, 'FullName');
        }

        $faceIds = $this->stringValues($document, self::NS_APPLE_FACEINFO, 'FaceID');
        if ($faceIds === []) {
            $faceIds = $this->stringValues($document, self::NS_APPLE_FACEINFO, 'FaceUUID');
        }

        $count = 0;
        foreach ([$centersX, $centersY, $widths, $heights, $confidenceLevels, $confidences, $angleInfoRolls, $rolls, $yaws, $names, $faceIds] as $values) {
            $valueCount = count($values);
            if ($valueCount > $count) {
                $count = $valueCount;
            }
        }

        if ($count === 0) {
            return [];
        }

        $entries = [];

        for ($index = 0; $index < $count; ++$index) {
            $centerX = $centersX[$index] ?? null;
            $centerY = $centersY[$index] ?? null;
            $width   = $widths[$index] ?? null;
            $height  = $heights[$index] ?? null;

            $geometry = null;
            if ($centerX !== null && $centerY !== null && $width !== null && $height !== null) {
                $geometry = $this->normalisedBox($centerX, $centerY, $width, $height, $dimensions);
            }

            $confidence = $this->normalisedConfidence($confidenceLevels[$index] ?? null, $confidenceScale);
            if ($confidence === null) {
                $confidence = $this->normalisedConfidence($confidences[$index] ?? null, $confidenceScale);
            }

            $rotation = $angleInfoRolls[$index] ?? $rolls[$index] ?? $yaws[$index] ?? null;

            $entries[] = [
                'geometry'   => $geometry,
                'person'     => $this->stringAt($names, $index),
                'confidence' => $confidence,
                'rotation'   => $rotation,
                'faceId'     => $this->stringAt($faceIds, $index),
            ];
        }

        return $entries;
    }

    /**
     * Converts Apple face entries to Region value objects.
     *
     * @param list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}> $entries Apple face entries.
     *
     * @return list<Region> List of Region value objects.
     */
    private function regionsFromAppleEntries(array $entries): array
    {
        $regions = [];

        foreach ($entries as $entry) {
            $geometry = $entry['geometry'];
            if ($geometry !== null) {
                $regions[] = new Region(
                    RegionType::FACE,
                    $geometry['x'],
                    $geometry['y'],
                    $geometry['w'],
                    $geometry['h'],
                    $entry['person'],
                    $entry['confidence'],
                    $entry['rotation'],
                    $entry['faceId'],
                );
            }
        }

        return $regions;
    }

    /**
     * Applies Apple supplemental metadata to existing regions.
     *
     * @param array<int, Region> $regions      Existing regions.
     * @param array<int, Region> $supplemental Supplemental regions to merge.
     *
     * @return array<int, Region> Updated regions with supplemental data applied.
     */
    private function applyAppleSupplementalMetadata(array $regions, array $supplemental): array
    {
        if ($supplemental === []) {
            return $regions;
        }

        foreach ($supplemental as $index => $supplement) {
            $baseRegion = $regions[$index] ?? null;
            if (!$baseRegion instanceof Region) {
                continue;
            }

            $regions[$index] = $this->mergeRegion($baseRegion, $supplement);
        }

        return $regions;
    }

    /**
     * Creates supplemental regions from Apple entries matched to MWG regions.
     *
     * @param list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}> $entries    Apple face entries.
     * @param list<Region>                                                                                                                                                      $mwgRegions MWG region list for matching.
     *
     * @return array<int, Region> Supplemental regions indexed by MWG region position.
     */
    private function supplementalRegionsFromAppleEntries(array $entries, array $mwgRegions): array
    {
        if ($entries === [] || $mwgRegions === []) {
            return [];
        }

        // Collect indices of MWG face regions eligible for supplemental Apple metadata.
        $faceIndices = [];
        foreach ($mwgRegions as $index => $region) {
            if ($region->type === RegionType::FACE) {
                $faceIndices[] = $index;
            }
        }

        if ($faceIndices === []) {
            return [];
        }

        // Track face indices still lacking a matched Apple entry.
        $unmatchedIndices = $faceIndices;
        $supplemental     = [];

        foreach ($entries as $entry) {
            // Align geometry-bearing Apple entries with MWG faces based on their shared shape.
            $matchIndex = $this->matchAppleEntryToMwgRegion($mwgRegions, $entry);
            if ($matchIndex === null) {
                continue;
            }

            $unmatchedIndices = $this->removeMatchedIndex($unmatchedIndices, $matchIndex);

            if (!$this->hasSupplementalMetadata($entry)) {
                continue;
            }

            $baseRegion                = $mwgRegions[$matchIndex];
            $supplemental[$matchIndex] = $this->createSupplementalRegion($baseRegion, $entry);
        }

        // Assign remaining supplemental details to faces even when Apple omitted geometry.
        foreach ($entries as $entry) {
            if ($entry['geometry'] !== null) {
                continue;
            }

            if (!$this->hasSupplementalMetadata($entry)) {
                continue;
            }

            $nextIndex = array_shift($unmatchedIndices);
            if ($nextIndex === null) {
                break;
            }

            $baseRegion               = $mwgRegions[$nextIndex];
            $supplemental[$nextIndex] = $this->createSupplementalRegion($baseRegion, $entry);
        }

        if ($supplemental === []) {
            return [];
        }

        ksort($supplemental);

        return $supplemental;
    }

    /**
     * Matches an Apple face entry to an MWG region by geometry.
     *
     * @param list<Region>                                                                                                                                                $mwgRegions MWG region list.
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry      Apple face entry to match.
     *
     * @return int|null Index of matching MWG region or null if no match.
     */
    private function matchAppleEntryToMwgRegion(array $mwgRegions, array $entry): ?int
    {
        $geometry = $entry['geometry'];
        if ($geometry === null) {
            return null;
        }

        $candidate = new Region(
            RegionType::FACE,
            $geometry['x'],
            $geometry['y'],
            $geometry['w'],
            $geometry['h'],
            $entry['person'],
            $entry['confidence'],
            $entry['rotation'],
            $entry['faceId'],
        );

        return $this->findMatchingRegionIndex($mwgRegions, $candidate);
    }

    /**
     * Removes a matched index from a list of indices.
     *
     * @param list<int> $indices List of indices.
     * @param int       $match   Index to remove.
     *
     * @return list<int> Updated list without the matched index.
     */
    private function removeMatchedIndex(array $indices, int $match): array
    {
        foreach ($indices as $position => $index) {
            if ($index === $match) {
                unset($indices[$position]);
                break;
            }
        }

        return array_values($indices);
    }

    /**
     * Creates a supplemental region with Apple-specific metadata.
     *
     * @param Region                                                                                                                                                      $baseRegion Base region to supplement.
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry      Apple face entry data.
     *
     * @return Region Enhanced region with supplemental metadata.
     */
    private function createSupplementalRegion(Region $baseRegion, array $entry): Region
    {
        return new Region(
            $baseRegion->type ?? RegionType::FACE,
            $baseRegion->x,
            $baseRegion->y,
            $baseRegion->w,
            $baseRegion->h,
            $entry['person'],
            $entry['confidence'],
            $entry['rotation'],
            $entry['faceId'],
        );
    }

    /**
     * Checks if an Apple face entry contains supplemental metadata.
     *
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry Apple face entry.
     *
     * @return bool True if entry has supplemental metadata.
     */
    private function hasSupplementalMetadata(array $entry): bool
    {
        return $entry['person'] !== null
            || $entry['confidence'] !== null
            || $entry['rotation'] !== null
            || $entry['faceId'] !== null;
    }

    /**
     * Attempts to match an Apple face region with an MWG region by spatial overlap.
     *
     * @param array<int, Region> $regions
     */
    private function findMatchingRegionIndex(array $regions, Region $candidate): ?int
    {
        if ($candidate->type !== RegionType::FACE) {
            return null;
        }

        $bestIndex             = null;
        $bestScore             = null;
        [$targetCx, $targetCy] = $this->regionCenter($candidate);

        foreach ($regions as $index => $region) {
            if ($region->type !== RegionType::FACE) {
                continue;
            }

            [$cx, $cy] = $this->regionCenter($region);
            $distance  = abs($cx - $targetCx) + abs($cy - $targetCy);
            if ($distance > self::MATCH_THRESHOLD) {
                continue;
            }

            $sizeDiff = abs($region->w - $candidate->w) + abs($region->h - $candidate->h);
            $score    = $distance + $sizeDiff;
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    /**
     * Merges overlapping region metadata, preferring existing geometry while enriching attributes.
     *
     * @param Region $base       Primary region resolved from MWG metadata.
     * @param Region $supplement Supplementary region derived from Apple metadata.
     *
     * @return Region Combined region carrying the most complete metadata set.
     */
    private function mergeRegion(Region $base, Region $supplement): Region
    {
        $person     = $base->personName ?? $supplement->personName;
        $confidence = $base->confidence;
        if ($confidence === null) {
            $confidence = $supplement->confidence;
        } elseif ($supplement->confidence !== null) {
            $confidence = max($confidence, $supplement->confidence);
        }

        $rotation = $base->rotationDeg ?? $supplement->rotationDeg;
        $faceId   = $base->faceId ?? $supplement->faceId;
        $type     = $base->type ?? $supplement->type;

        return new Region(
            $type,
            $base->x,
            $base->y,
            $base->w,
            $base->h,
            $person,
            $confidence,
            $rotation,
            $faceId,
        );
    }

    /**
     * Calculates the center point of a region.
     *
     * @param Region $region Region to calculate center for.
     *
     * @return array{0: float, 1: float} Center coordinates [x, y].
     */
    private function regionCenter(Region $region): array
    {
        return [
            $region->x + ($region->w / 2.0),
            $region->y + ($region->h / 2.0),
        ];
    }

    /**
     * Retrieves a string value at a specific index from a list.
     *
     * @param list<string> $values List of string values.
     * @param int          $index  Index to retrieve.
     *
     * @return string|null String value at index or null if not found.
     */
    private function stringAt(array $values, int $index): ?string
    {
        $value = $values[$index] ?? null;
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Normalises Apple-specific confidence values to the unit interval.
     */
    private function normalisedConfidence(?float $confidence, float $scale): ?float
    {
        if ($confidence === null) {
            return null;
        }

        if ($scale <= 1.0 || abs($confidence) <= 1.0) {
            return $confidence;
        }

        $normalised = $confidence / $scale;

        if ($normalised > 1.0) {
            return 1.0;
        }

        if ($normalised < -1.0) {
            return -1.0;
        }

        return $normalised;
    }

    /**
     * Calculates a normalized confidence scale from confidence levels.
     *
     * @param list<float|null> $confidenceLevels Raw confidence level values.
     * @param list<float|null> $confidences      Confidence percentage values.
     *
     * @return float Normalized confidence scale value.
     */
    private function confidenceScale(array $confidenceLevels, array $confidences): float
    {
        $maxConfidence = 0.0;

        foreach ([$confidenceLevels, $confidences] as $values) {
            foreach ($values as $value) {
                if ($value === null) {
                    continue;
                }

                $absolute = abs($value);
                if ($absolute > $maxConfidence) {
                    $maxConfidence = $absolute;
                }
            }
        }

        if ($maxConfidence <= 1.0) {
            return 1.0;
        }

        $scale = 10.0 ** ceil(log10($maxConfidence));

        if ($scale <= 0.0) {
            return 1.0;
        }

        return $scale;
    }

    /**
     * Creates a normalized bounding box from center and dimensions.
     *
     * @param float                          $centerX    Center X coordinate.
     * @param float                          $centerY    Center Y coordinate.
     * @param float                          $width      Box width.
     * @param float                          $height     Box height.
     * @param array{w: float, h: float}|null $dimensions Image dimensions for normalization.
     *
     * @return array{x: float, y: float, w: float, h: float}|null Normalized bounding box or null if invalid.
     */
    private function normalisedBox(float $centerX, float $centerY, float $width, float $height, ?array $dimensions): ?array
    {
        if ($width <= 0.0 || $height <= 0.0) {
            return null;
        }

        $scaledCenterX = $centerX;
        $scaledCenterY = $centerY;
        $scaledWidth   = $width;
        $scaledHeight  = $height;

        if ($dimensions !== null) {
            if ($scaledCenterX > 1.0 || $scaledWidth > 1.0) {
                $scaledCenterX /= $dimensions['w'];
                $scaledWidth /= $dimensions['w'];
            }

            if ($scaledCenterY > 1.0 || $scaledHeight > 1.0) {
                $scaledCenterY /= $dimensions['h'];
                $scaledHeight /= $dimensions['h'];
            }
        }

        if (($scaledCenterX > 1.0 || $scaledCenterY > 1.0 || $scaledWidth > 1.0 || $scaledHeight > 1.0) && ($scaledCenterX <= 100.0 && $scaledCenterY <= 100.0 && $scaledWidth <= 100.0 && $scaledHeight <= 100.0)) {
            $scaledCenterX /= 100.0;
            $scaledCenterY /= 100.0;
            $scaledWidth /= 100.0;
            $scaledHeight /= 100.0;
        }

        $halfWidth  = $scaledWidth / 2.0;
        $halfHeight = $scaledHeight / 2.0;

        return [
            'x' => $this->clamp($scaledCenterX - $halfWidth),
            'y' => $this->clamp($scaledCenterY - $halfHeight),
            'w' => $this->clamp($scaledWidth),
            'h' => $this->clamp($scaledHeight),
        ];
    }

    /**
     * Constrains a normalised coordinate to the unit interval.
     *
     * @param float $value Coordinate or dimension value to clamp.
     *
     * @return float Value restricted to the range [0.0, 1.0].
     */
    private function clamp(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    /**
     * Extracts a list of string values from XMP document.
     *
     * @param XmpDocument $document  XMP document to extract from.
     * @param string      $namespace XML namespace URI.
     * @param string      $localName Local element name.
     *
     * @return list<string> List of string values.
     */
    private function stringValues(XmpDocument $document, string $namespace, string $localName): array
    {
        $raw = $document->get($namespace, $localName);

        if (is_array($raw)) {
            if ($raw === []) {
                return [];
            }

            return array_values(array_map(trim(...), $raw));
        }

        if (!is_string($raw)) {
            return [];
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? [] : [$trimmed];
    }

    /**
     * Extracts a list of float values from XMP document.
     *
     * @param XmpDocument $document  XMP document to extract from.
     * @param string      $namespace XML namespace URI.
     * @param string      $localName Local element name.
     *
     * @return list<float|null> List of float values with nulls for invalid entries.
     */
    private function floatValues(XmpDocument $document, string $namespace, string $localName): array
    {
        $raw = $document->get($namespace, $localName);

        if (is_array($raw)) {
            if ($raw === []) {
                return [];
            }
        } elseif (is_string($raw)) {
            $raw = [$raw];
        } else {
            return [];
        }

        return array_values(array_map(XmpDocument::parseNumericValue(...), $raw));
    }

    /**
     * Extracts applied image dimensions from XMP document.
     *
     * @param XmpDocument $document XMP document to extract from.
     *
     * @return array{w: float, h: float}|null Image dimensions or null if not found.
     */
    private function appliedDimensions(XmpDocument $document): ?array
    {
        $widths  = $this->floatValues($document, self::NS_ST_DIMENSIONS, 'w');
        $heights = $this->floatValues($document, self::NS_ST_DIMENSIONS, 'h');

        $width  = $widths[0] ?? null;
        $height = $heights[0] ?? null;

        if ($width === null || $width <= 0.0 || $height === null || $height <= 0.0) {
            return null;
        }

        return ['w' => $width, 'h' => $height];
    }

    private function createMultiPicture(Metadata $metadata): MultiPicture
    {
        return $this->resolveMultiPicture($metadata->mpfDocument);
    }

    private function resolveMultiPicture(?MpfDocument $document): MultiPicture
    {
        if (!$document instanceof MpfDocument) {
            return new MultiPicture(null, 0, [], null, null, null, null, null);
        }

        $entries = [];
        foreach ($document->entries as $entry) {
            $entries[] = new MultiPictureEntry(
                attributes: $entry->attributes,
                imageSize: $entry->imageSize,
                dataOffset: $entry->dataOffset,
                dependentImage1: $entry->dependentImage1,
                dependentImage2: $entry->dependentImage2,
            );
        }

        $attributes = $document->attributes;

        return new MultiPicture(
            version: $document->version,
            imageCount: $document->imageCount,
            entries: $entries,
            totalFrames: $attributes?->totalFrames,
            individualImageNumber: $attributes?->individualImageNumber,
            imageUidList: $attributes?->imageUidList,
            panoramaAngle: $attributes?->panoramaAngle,
            panoramaAxis: $attributes?->panoramaAxis,
        );
    }
}
