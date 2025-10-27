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
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Icc\IccDecoder;
use MagicSunday\ImageMeta\Value\AudioClips;
use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\ColorProfileGainMap;
use MagicSunday\ImageMeta\Value\ColorProfileHueSatMap;
use MagicSunday\ImageMeta\Value\ColorProfileLookTable;
use MagicSunday\ImageMeta\Value\ColorProfileToneCurve;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\DngProfileGainTableTag;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\FlashInfo;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\Preview;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\Regions\RegionType;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Uav;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\Xmp;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;

use function abs;
use function array_key_exists;
use function count;
use function intdiv;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function preg_replace;
use function sprintf;
use function str_pad;
use function substr;
use function str_starts_with;
use function trim;
use function strtoupper;

/**
 * Builds the structured metadata aggregate by orchestrating specialised resolvers.
 */
final class ValueFactory
{
    /**
     * Produces normalised value objects derived from the supplied metadata container.
     *
     * @param Metadata     $metadata     Metadata container with decoded EXIF, XMP and QuickTime data.
     * @param Gps          $gps           Pre-resolved GPS aggregate derived from EXIF and XMP sources.
     * @param Regions      $regions       Annotated region collection supplied by callers.
     * @param MultiPicture $multiPicture  Multi-picture aggregate resolved from MPF documents.
     * @param XmpDocument|null $xmpDocument Optional pre-parsed XMP document reused by the caller.
     *
     * @return array{
     *     interop: Interop,
     *     tiff: TiffData,
     *     composite: CompositeImageInfo,
     *     standards: Standards,
     *     flashPix: FlashPix,
     *     multiPicture: MultiPicture,
     *     camera: Camera,
     *     lens: Lens,
     *     image: Image,
     *     exposure: Exposure,
     *     capture: Capture,
     *     gps: Gps,
     *     device: Device,
     *     apple: AppleMakerNotes,
     *     xmp: Xmp,
     *     file: File,
     *     container: Container,
     *     preview: Preview,
     *     video: Video,
     *     audio: Audio,
     *     embeddedAudio: AudioClips,
     *     colorProfile: ColorProfile,
     *     processing: ProcessingSettings,
     *     whiteBalanceDetails: WhiteBalanceDetails,
     *     focus: Focus,
     *     motion: Motion,
     *     scene: Scene,
     *     regions: Regions,
     *     keywords: Keywords,
     *     rights: Rights,
     *     author: Author,
     *     temporal: Temporal,
     *     derived: Derived,
     *     related: RelatedAssets,
     *     sensor: Sensor,
     *     uav: Uav,
     *     integrity: Integrity,
     * }
     */
    public function createComponents(
        Metadata $metadata,
        Gps $gps,
        Regions $regions,
        MultiPicture $multiPicture,
        ?XmpDocument $xmpDocument = null,
    ): array {
        $exifDocument    = $metadata->exifDoc;
        $xmpDocument   ??= $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
        $quickTimeMeta   = $metadata->quickTime;
        $appleMakerNotes = $metadata->makerNotes?->apple();

        $interop = new Interop(
            index: $exifDocument?->interopIndex(),
            version: $exifDocument?->interopVersion(),
            relatedImageFileFormat: $exifDocument?->relatedImageFileFormat(),
            relatedImageWidth: $exifDocument?->relatedImageWidth(),
            relatedImageLength: $exifDocument?->relatedImageLength(),
        );

        $bitsPerSample    = $exifDocument?->bitsPerSample() ?? $metadata->jpegBitsPerSample;
        $ycbcrSubSampling = $exifDocument?->ycbcrSubSampling();
        if ($ycbcrSubSampling === null) {
            $ycbcrSubSampling = $metadata->jpegYCbCrSubSampling;
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
            referenceBlackWhite: $exifDocument?->referenceBlackWhite(),
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
            tiffEpStandardId: $exifDocument?->tiffEpStandardId(),
            tiffEpStandardString: $exifDocument?->tiffEpStandardIdString(),
        );

        $flashPix = new FlashPix($metadata->flashPixStreams);

        $camera = $this->buildCamera($exifDocument);
        $lens   = $this->buildLens($exifDocument);
        $image  = $this->buildImage($metadata, $exifDocument);

        $exposureProgram = null;
        $meteringMode    = null;
        $whiteBalance    = null;

        $programCode = $exifDocument?->exposureProgram();
        if ($programCode !== null) {
            $exposureProgram = ExposureProgram::tryFrom($programCode);
        }

        $meteringCode = $exifDocument?->meteringMode();
        if ($meteringCode !== null) {
            $meteringMode = MeteringMode::tryFrom($meteringCode);
        }

        $whiteBalanceCode = $exifDocument?->whiteBalance();
        if ($whiteBalanceCode !== null) {
            $whiteBalance = WhiteBalance::tryFrom($whiteBalanceCode);
        }

        $flashInfo = FlashInfo::fromExifValue($exifDocument?->flash());

        $exposure = new Exposure(
            iso: $exifDocument?->iso(),
            exposureTimeSec: $exifDocument?->exposureTime(),
            fNumber: $exifDocument?->fNumber(),
            exposureBiasEv: $exifDocument?->exposureBias(),
            program: $exposureProgram,
            meteringMode: $meteringMode,
            flash: $flashInfo,
            whiteBalance: $whiteBalance,
            brightnessEv: $exifDocument?->brightnessValue(),
            exposureMode: $exifDocument?->exposureMode(),
            gainControl: $exifDocument?->gainControl(),
            contrast: $exifDocument?->contrast(),
            saturation: $exifDocument?->saturation(),
            sharpness: $exifDocument?->sharpness(),
            digitalZoomRatio: $exifDocument?->digitalZoomRatio(),
            shutterSpeedEv: $exifDocument?->shutterSpeedEv(),
            apertureEv: $exifDocument?->apertureEv(),
            isoLatitudeYyy: $exifDocument?->isoLatitudeYyy(),
            isoLatitudeZzz: $exifDocument?->isoLatitudeZzz(),
            exposureIndex: $exifDocument?->exposureIndex(),
            flashEnergy: $exifDocument?->flashEnergy(),
        );

        $capture = new Capture(
            dateTime: $exifDocument?->captureDateTime(),
            temperatureC: $exifDocument?->temperatureCelsius(),
            humidityPercent: $exifDocument?->humidityPercent(),
            pressureHPa: $exifDocument?->pressureHPa(),
            batteryLevelPercent: $exifDocument?->batteryLevelPercent(),
            waterDepthM: $exifDocument?->waterDepthMeters(),
            accelerationMs2: $exifDocument?->accelerationMs2(),
            cameraElevationAngleDeg: $exifDocument?->cameraElevationAngleDeg(),
            selfTimerModeSeconds: $exifDocument?->selfTimerModeSeconds(),
        );

        $device = $this->buildDevice($exifDocument, $quickTimeMeta, $xmpDocument);

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
            format: $this->quickTimeString($quickTimeMeta, QuickTimeMeta::MAJOR_BRAND_KEY),
            encoder: $this->quickTimeString(
                $quickTimeMeta,
                'com.apple.quicktime.encoder',
                'Encoder',
            ),
            bitrate: $this->quickTimeInt($quickTimeMeta, 'AvgBitrate', 'Bitrate'),
            videoCodec: $this->quickTimeString(
                $quickTimeMeta,
                QuickTimeMeta::COMPRESSOR_NAME_KEY,
                QuickTimeMeta::VIDEO_CODEC_KEY,
                QuickTimeMeta::HANDLER_DESCRIPTION_KEY,
            ),
            audioCodec: $this->quickTimeString(
                $quickTimeMeta,
                QuickTimeMeta::AUDIO_FORMAT_KEY,
                QuickTimeMeta::AUDIO_CODEC_KEY,
            ),
        );

        $preview = new Preview(
            $exifDocument?->hasThumbnail(),
            $exifDocument?->hasPreviewImage(),
            $exifDocument?->previewImageWidth(),
            $exifDocument?->previewImageHeight(),
        );

        $video = new Video(
            durationSec: $this->quickTimeFloat($quickTimeMeta, 'com.apple.quicktime.duration'),
            frameRate: $this->quickTimeFloat($quickTimeMeta, 'com.apple.quicktime.videoFrameRate'),
            width: $this->quickTimeInt($quickTimeMeta, QuickTimeMeta::VIDEO_WIDTH_KEY),
            height: $this->quickTimeInt($quickTimeMeta, QuickTimeMeta::VIDEO_HEIGHT_KEY),
            codec: $this->quickTimeString(
                $quickTimeMeta,
                QuickTimeMeta::COMPRESSOR_NAME_KEY,
                QuickTimeMeta::VIDEO_CODEC_KEY,
            ),
            hdr: $this->quickTimeBool($quickTimeMeta, 'com.apple.quicktime.hdrFormat'),
            transferFunction: $this->quickTimeString($quickTimeMeta, 'com.apple.quicktime.transferFunction'),
            colorPrimaries: $this->quickTimeString($quickTimeMeta, 'com.apple.quicktime.colorPrimaries'),
        );

        $audio = new Audio(
            channels: $this->quickTimeInt($quickTimeMeta, QuickTimeMeta::AUDIO_CHANNELS_KEY),
            sampleRate: $this->quickTimeInt($quickTimeMeta, QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY),
            codec: $this->quickTimeString(
                $quickTimeMeta,
                QuickTimeMeta::AUDIO_FORMAT_KEY,
                QuickTimeMeta::AUDIO_CODEC_KEY,
            ),
            bitDepth: $this->quickTimeInt($quickTimeMeta, QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY),
        );

        $embeddedAudio = AudioClips::fromJpegAudioStreams($metadata->jpegAudioStreams);

        $iccData = null;
        if ($metadata->iccProfile !== null || $metadata->iccSegments !== []) {
            $iccData = (new IccDecoder())->decode($metadata->iccProfile, $metadata->iccSegments);
        }

        $hueSatMap = null;
        $hueSatMapData = $exifDocument?->profileHueSatMap();
        if (is_array($hueSatMapData)) {
            $dimensions = $hueSatMapData['dimensions'] ?? null;
            $hueSatMap = new ColorProfileHueSatMap(
                $dimensions[0] ?? null,
                $dimensions[1] ?? null,
                $dimensions[2] ?? null,
                $hueSatMapData['map1'] ?? null,
                $hueSatMapData['map2'] ?? null,
                $hueSatMapData['map3'] ?? null,
            );
        }

        $lookTable = null;
        $lookTableData = $exifDocument?->profileLookTable();
        if (is_array($lookTableData)) {
            $dimensions = $lookTableData['dimensions'] ?? null;
            $entries    = null;
            $data       = $lookTableData['data'] ?? null;
            if (is_array($data)) {
                $entries = $this->chunkTripletEntries($data);
                if ($entries === []) {
                    $entries = null;
                }
            }

            $lookTable = new ColorProfileLookTable(
                $dimensions[0] ?? null,
                $dimensions[1] ?? null,
                $dimensions[2] ?? null,
                $entries,
            );
        }

        $toneCurve = null;
        $toneCurveData = $exifDocument?->profileToneCurve();
        if (is_array($toneCurveData) && $toneCurveData !== []) {
            $points = $this->chunkPairEntries($toneCurveData);
            if ($points !== []) {
                $toneCurve = new ColorProfileToneCurve($points);
            }
        }

        $gainMap = null;
        $gainMapData = $exifDocument?->profileGainTableMap();
        if (is_array($gainMapData) && $gainMapData !== []) {
            $gainMap = new ColorProfileGainMap(DngProfileGainTableTag::GAIN_TABLE_MAP, $gainMapData);
        }

        $colorProfile = new ColorProfile(
            profileName: $iccData['description'] ?? null,
            profileVersion: $iccData['version'] ?? null,
            pcs: $iccData['pcs'] ?? null,
            renderingIntent: $iccData['renderingIntent'] ?? null,
            gamma: $exifDocument?->gamma(),
            profileId: $iccData['profileId'] ?? null,
            cameraCalibrationSignature: $exifDocument?->cameraCalibrationSignature(),
            profileCalibrationSignature: $exifDocument?->profileCalibrationSignature(),
            hueSatMap: $hueSatMap,
            lookTable: $lookTable,
            toneCurve: $toneCurve,
            gainMap: $gainMap,
        );

        $processing = new ProcessingSettings(
            sharpness: $exifDocument?->sharpness(),
            contrast: $exifDocument?->contrast(),
            saturation: $exifDocument?->saturation(),
            pictureStyle: null,
            noiseReduction: $exifDocument?->noiseReduction(),
            clarity: null,
            customRendered: $exifDocument?->customRendered()?->value,
            deviceSettingDescription: $exifDocument?->deviceSettingDescription(),
            processingSoftware: $exifDocument?->processingSoftware(),
        );

        $whiteBalanceKelvin = $apple->colorTemperature;
        if ($whiteBalanceKelvin === null) {
            $whiteBalanceKelvin = $this->quickTimeInt($quickTimeMeta, 'ColorTemperature');
        }

        $whiteBalanceDetails = new WhiteBalanceDetails(
            mode: $whiteBalance,
            kelvin: $whiteBalanceKelvin,
            rgGain: null,
            bgGain: null,
        );

        $rect      = null;
        $focusRect = $exifDocument?->subjectArea();
        if ($focusRect !== null) {
            $rect = ValueConverters::subjectAreaToRect($focusRect);
        }

        if ($rect === null) {
            $location = $exifDocument?->subjectLocation();
            $rect     = ($location !== null && count($location) >= 2)
                ? ['x' => $location[0], 'y' => $location[1], 'w' => null, 'h' => null]
                : ['x' => null, 'y' => null, 'w' => null, 'h' => null];
        }

        $focus = new Focus(
            subjectDistanceM: $exifDocument?->subjectDistance(),
            subjectAreaX: $rect['x'],
            subjectAreaY: $rect['y'],
            subjectAreaW: $rect['w'],
            subjectAreaH: $rect['h'],
            afMode: null,
        );

        $motion = $this->buildMotion($exifDocument, $apple);

        $scene = $this->buildScene(
            $exifDocument,
            $quickTimeMeta,
            $apple,
            $this->countFaceRegions($regions),
        );

        $flatKeywords         = $xmpDocument?->stringList('http://purl.org/dc/elements/1.1/', 'subject') ?? [];
        $hierarchicalKeywords = $xmpDocument?->stringList('http://ns.adobe.com/lightroom/1.0/', 'hierarchicalSubject') ?? [];

        if ($flatKeywords === []) {
            $xpKeywords = $exifDocument?->xpKeywords();
            if ($xpKeywords !== null) {
                $flatKeywords = $xpKeywords;
            }
        }

        $keywords = new Keywords(
            flat: $flatKeywords,
            hierarchical: $hierarchicalKeywords !== [] ? $hierarchicalKeywords : null,
        );

        $rights = new Rights(
            copyright: $exifDocument?->copyright(),
            usageTerms: $xmpDocument?->string('http://ns.adobe.com/xap/1.0/rights/', 'UsageTerms'),
            licenseUrl: $xmpDocument?->string('http://ns.adobe.com/xap/1.0/rights/', 'WebStatement'),
            creditLine: $xmpDocument?->string('http://ns.adobe.com/photoshop/1.0/', 'Credit'),
            securityClassification: $exifDocument?->securityClassification(),
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
        $circleOfConfusionMm = ValueConverters::calcCircleOfConfusionMm($cropFactor);

        $derived = new Derived(
            ev100: ValueConverters::calcEv100(
                $exposure->exposureTimeSec,
                $exposure->fNumber,
                $exposure->iso,
            ),
            hyperfocalM: ValueConverters::calcHyperfocalM(
                $lens->focalLengthMm,
                $exposure->fNumber,
                $circleOfConfusionMm,
            ),
            fovDiagonalDeg: ValueConverters::calcFovDeg($lens->focalLengthIn35mm, $cropFactor, $lens->focalLengthMm),
            fovHorizontalDeg: ValueConverters::calcHorizontalFovDeg($lens->focalLengthIn35mm, $cropFactor, $lens->focalLengthMm),
            fovVerticalDeg: ValueConverters::calcVerticalFovDeg($lens->focalLengthIn35mm, $cropFactor, $lens->focalLengthMm),
            focalLength35mm: $lens->focalLengthIn35mm,
            cropFactor: $cropFactor,
        );

        $panoramaFlag = $xmpDocument?->bool('http://ns.google.com/photos/1.0/panorama/', 'UsePanoramaViewer');
        $related      = new RelatedAssets(
            livePhotoPairId: $metadata->quickTime?->contentIdentifier(),
            burstId: $this->quickTimeString($quickTimeMeta, 'BurstUUID'),
            isPrimaryInBurst: $this->quickTimeBool($quickTimeMeta, 'BurstSelected'),
            panoramaId: $panoramaFlag === true ? 'panorama' : null,
            depthDataId: $this->quickTimeString($quickTimeMeta, 'DepthData'),
            relatedSoundFile: $exifDocument?->relatedSoundFile(),
        );

        $focalPlaneUnit = null;
        $focalPlaneUnitCode = $exifDocument?->focalPlaneResolutionUnit();
        if ($focalPlaneUnitCode !== null) {
            $focalPlaneUnit = ResolutionUnit::tryFrom($focalPlaneUnitCode);
        }

        $sensor = new Sensor(
            pixelPitchUm: null,
            cfaWidth: $exifDocument?->cfaRepeatPatternWidth(),
            cfaHeight: $exifDocument?->cfaRepeatPatternHeight(),
            sensorType: null,
            ibis: null,
            cfaPattern: $exifDocument?->cfaPattern(),
            spectralSensitivity: $exifDocument?->spectralSensitivity(),
            oecf: $exifDocument?->oecf(),
            spatialFrequencyResponse: $exifDocument?->spatialFrequencyResponse(),
            focalPlaneXResolution: $exifDocument?->focalPlaneXResolution(),
            focalPlaneYResolution: $exifDocument?->focalPlaneYResolution(),
            focalPlaneResolutionUnit: $focalPlaneUnit,
        );

        $uav = $this->buildUav($exifDocument, $quickTimeMeta);

        $hasHistory      = $xmpDocument?->has('http://ns.adobe.com/xap/1.0/mm/', 'History') ?? false;
        $makerNotesSafe  = $metadata->makerNotes?->isSafe();
        if ($makerNotesSafe === null) {
            $makerNotesSafe = $exifDocument?->makerNoteSafety();
        }

        $integrity = new Integrity(
            originalFileName: $xmpDocument?->string('http://ns.adobe.com/tiff/1.0/', 'OriginalFileName'),
            originalDigest: null,
            edited: $hasHistory ? true : null,
            historyLastSoftware: null,
            imageHistory: $exifDocument?->imageHistory(),
            makerNotesSafe: $makerNotesSafe,
        );

        return [
            'interop' => $interop,
            'tiff' => $tiff,
            'composite' => $composite,
            'standards' => $standards,
            'flashPix' => $flashPix,
            'multiPicture' => $multiPicture,
            'camera' => $camera,
            'lens' => $lens,
            'image' => $image,
            'exposure' => $exposure,
            'capture' => $capture,
            'gps' => $gps,
            'device' => $device,
            'apple' => $apple,
            'xmp' => $xmp,
            'file' => $file,
            'container' => $container,
            'preview' => $preview,
            'video' => $video,
            'audio' => $audio,
            'embeddedAudio' => $embeddedAudio,
            'colorProfile' => $colorProfile,
            'processing' => $processing,
            'whiteBalanceDetails' => $whiteBalanceDetails,
            'focus' => $focus,
            'motion' => $motion,
            'scene' => $scene,
            'regions' => $regions,
            'keywords' => $keywords,
            'rights' => $rights,
            'author' => $author,
            'temporal' => $temporal,
            'derived' => $derived,
            'related' => $related,
            'sensor' => $sensor,
            'uav' => $uav,
            'integrity' => $integrity,
        ];
    }

    /**
     * Builds the device metadata aggregate by combining EXIF helpers with QuickTime fallbacks.
     *
     * @param ExifDocument|null   $exif        Resolver exposing EXIF tag helpers.
     * @param QuickTimeMeta|null  $quickTime   QuickTime metadata container exposing software fields.
     * @param XmpDocument|null    $xmpDocument Placeholder for future XMP backed device metadata.
     *
     * @return Device Device value object describing capture hardware and software.
     */
    private function buildDevice(?ExifDocument $exif, ?QuickTimeMeta $quickTime, ?XmpDocument $xmpDocument): Device
    {
        $software = null;

        if ($exif instanceof ExifDocument) {
            $software = $exif->software();

            if ($software === null) {
                $software = $exif->hostComputer();
            }
        }

        if ($software === null) {
            $software = $this->quickTimeString(
                $quickTime,
                'com.apple.quicktime.software',
                'Software',
                'com.apple.quicktime.softwareversion',
                'com.apple.quicktime.software.version',
            );
        }

        return new Device(
            software: $software,
            rawDevelopingSoftware: $exif?->rawDevelopingSoftware(),
            imageEditingSoftware: $exif?->imageEditingSoftware(),
            metadataEditingSoftware: $exif?->metadataEditingSoftware(),
        );
    }

    /**
     * Builds the temporal value object derived from EXIF, QuickTime and XMP data.
     *
     * Fractional seconds are mirrored into the generic field to keep display values consistent
     * whenever only the original or digitized timestamp carries sub-second precision.
     *
     * @param ExifDocument   $resolver     Resolver exposing EXIF timestamps and offsets.
     * @param QuickTimeMeta|null $quickTime QuickTime metadata used for time fallbacks.
     * @param XmpDocument|null   $xmpDocument  XMP document providing timestamp fields.
     *
     * @return Temporal Normalised temporal metadata aggregate.
     */
    private function buildTemporal(?ExifDocument $resolver, ?QuickTimeMeta $quickTime, ?XmpDocument $xmpDocument): Temporal
    {
        $exifCreate = $resolver?->dateTimeDigitized();
        $exifModify = $resolver?->dateTime();

        $xmpCreate       = $this->parseFlexibleDate($xmpDocument?->string('http://ns.adobe.com/xap/1.0/', 'CreateDate'));
        $xmpModify       = $this->parseFlexibleDate($xmpDocument?->string('http://ns.adobe.com/xap/1.0/', 'ModifyDate'));
        $xmpDateCreated  = $this->parseFlexibleDate($xmpDocument?->string('http://ns.adobe.com/photoshop/1.0/', 'DateCreated'));
        $quickTimeCreate = $this->parseFlexibleDate($this->quickTimeString($quickTime, 'CreationDate'));
        $quickTimeModify = $this->parseFlexibleDate($this->quickTimeString($quickTime, 'ModifyDate'));

        $create = $exifCreate ?? $xmpCreate ?? $quickTimeCreate ?? $xmpDateCreated;
        $modify = $exifModify ?? $xmpModify ?? $quickTimeModify;

        [$original, $tz, $subOriginalRaw] = $this->originalTimestampComponents($resolver);

        $originalWithTz = $original;
        if ($original instanceof DateTimeImmutable && $tz instanceof DateTimeZone) {
            $originalWithTz = $original->setTimezone($tz);
        }

        $offsetTime          = $resolver?->offsetTime();
        $offsetTimeOriginal  = $resolver?->offsetTimeOriginal();
        $offsetTimeDigitized = $resolver?->offsetTimeDigitized();

        $subSecTime         = $this->sanitizeSubSeconds($resolver?->subSecTime());
        $subSecTimeDigitized = $this->sanitizeSubSeconds($resolver?->subSecTimeDigitized());
        $subSecOriginal     = $this->sanitizeSubSeconds($subOriginalRaw);

        if ($subSecTime === null) {
            $subSecTime = $subSecOriginal ?? $subSecTimeDigitized;
        }

        $timeZoneOffsets = $resolver?->timeZoneOffsetMinutes();

        $tzSource = null;
        if ($tz instanceof DateTimeZone) {
            if ($offsetTimeOriginal !== null && ValueConverters::parseOffset($offsetTimeOriginal) instanceof DateTimeZone) {
                $tzSource = 'OffsetTimeOriginal';
            } elseif (is_array($timeZoneOffsets) && $timeZoneOffsets !== []) {
                $tzSource = 'TimeZoneOffset';
            }
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
            timeZoneOffsetMinutes: $timeZoneOffsets,
        );
    }

    /**
     * Extracts the original capture timestamp components from the EXIF document.
     *
     * @return array{0:?DateTimeImmutable,1:?DateTimeZone,2:?string}
     */
    private function originalTimestampComponents(?ExifDocument $document): array
    {
        if (!$document instanceof ExifDocument) {
            return [null, null, null];
        }

        $offset = $document->offsetTimeOriginal();
        if ($offset === null) {
            $zoneOffsets = $document->timeZoneOffsetMinutes();
            if (is_array($zoneOffsets) && array_key_exists(0, $zoneOffsets)) {
                $offset = $zoneOffsets[0];
            }
        }

        if (is_int($offset)) {
            $absOffset = abs($offset);
            $hours     = $absOffset;
            $minutes   = 0;

            if ($absOffset > 14) {
                $hours   = intdiv($absOffset, 60);
                $minutes = $absOffset % 60;
            }

            if ($hours > 14 || ($hours === 14 && $minutes !== 0)) {
                $offset = null;
            } else {
                $sign   = $offset < 0 ? '-' : '+';
                $offset = sprintf('%s%02d:%02d', $sign, $hours, $minutes);
            }
        }

        $timezone = ValueConverters::parseOffset(is_string($offset) ? $offset : null);

        return [
            $document->captureDateTime(),
            $timezone,
            $document->subSecTimeOriginal(),
        ];
    }

    /**
     * Builds a camera value object using EXIF metadata.
     *
     * @param ExifDocument $exif Resolver exposing camera related EXIF tags.
     *
     * @return Camera Normalised camera metadata aggregate.
     */
    private function buildCamera(?ExifDocument $exif): Camera
    {
        if (!$exif instanceof ExifDocument) {
            return new Camera(
                make: null,
                model: null,
                ownerName: null,
                serialNumber: null,
                firmware: null,
                fileSource: null,
                sensingMethod: null,
            );
        }

        $profile = (float) $exif->exifProfile();

        $firmware = $exif->cameraFirmware();

        if ($firmware === null && $profile < 3.0) {
            $firmware = $exif->cameraFirmwareVersion();
        }

        if ($firmware === null) {
            $firmware = $exif->software();
        }

        return new Camera(
            make: $exif->cameraMake(),
            model: $exif->cameraModel(),
            ownerName: $exif->ownerName(),
            serialNumber: $exif->cameraSerialNumber(),
            firmware: $firmware,
            fileSource: $exif->fileSource(),
            sensingMethod: $exif->sensingMethod(),
        );
    }

    /**
     * Builds a lens value object using EXIF metadata.
     *
     * @param ExifDocument $exif Resolver exposing lens specific EXIF tags.
     *
     * @return Lens Normalised lens metadata aggregate.
     */
    private function buildLens(?ExifDocument $exif): Lens
    {
        if (!$exif instanceof ExifDocument) {
            return new Lens(
                lensMake: null,
                lensModel: null,
                lensSerialNumber: null,
                focalLengthMm: null,
                focalLengthIn35mm: null,
                maxApertureFNumber: null,
                lensSpecification: null,
            );
        }

        $maxApex = $exif->maxApertureApex();
        $maxF    = $maxApex !== null ? ValueConverters::apexToFNumber($maxApex) : null;

        return new Lens(
            lensMake: $exif->lensMake(),
            lensModel: $exif->lensModel(),
            lensSerialNumber: $exif->lensSerialNumber(),
            focalLengthMm: $exif->focalLengthMm(),
            focalLengthIn35mm: $exif->focalLength35Mm(),
            maxApertureFNumber: $maxF,
            lensSpecification: $exif->lensSpecification(),
        );
    }

    /**
     * Builds the image value object using EXIF metadata.
     *
     * @param Metadata        $metadata Metadata container supplying JPEG frame fallbacks.
     * @param ExifDocument|null $exif Resolver exposing image related EXIF tags.
     *
     * @return Image Normalised image metadata aggregate.
     */
    private function buildImage(Metadata $metadata, ?ExifDocument $exif): Image
    {
        $width  = $exif?->imageWidth() ?? $metadata->jpegFrameWidth;
        $height = $exif?->imageHeight() ?? $metadata->jpegFrameHeight;

        $orientation = Orientation::fromExifValue($exif?->orientation());

        $bitsPerSample = $exif?->bitsPerSample();
        if ($bitsPerSample === null) {
            $bitsPerSample = $metadata->jpegBitsPerSample;
        }

        return new Image(
            width: $width,
            height: $height,
            orientation: $orientation,
            bitsPerSample: $bitsPerSample,
            colorSpace: $this->normalizedColorSpace($exif),
            imageUniqueId: $exif?->imageUniqueId(),
            imageNumber: $exif?->imageNumber(),
            documentName: $exif?->documentName(),
            description: $exif?->imageDescription(),
            title: $exif?->imageTitle(),
            componentsConfiguration: $exif?->componentsConfiguration(),
            compressedBitsPerPixel: $exif?->compressedBitsPerPixel(),
            interlace: $exif?->interlace(),
            userComment: $exif?->userComment(),
        );
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

    /**
     * Builds the scene metadata aggregate using EXIF, QuickTime and Apple sources.
     *
     * @param ExifDocument|null   $exif      Resolver exposing EXIF scene metadata.
     * @param QuickTimeMeta|null $quickTime QuickTime metadata providing scene hints.
     * @param AppleMakerNotes   $apple     Aggregated Apple maker note metadata.
     * @param int|null          $faceCount Number of detected face regions.
     *
     * @return Scene Scene metadata value object.
     */
    private function buildScene(
        ?ExifDocument $exif,
        ?QuickTimeMeta $quickTime,
        AppleMakerNotes $apple,
        ?int $faceCount,
    ): Scene {
        $flags = $apple->flags;
        if (!is_array($flags)) {
            $flags = [];
        }

        $hdr   = $apple->hdrImageType;
        if ($hdr === null) {
            $hdr = $this->quickTimeString($quickTime, 'HDRImageType');
        }
        $night = $this->quickTimeBool($quickTime, 'NightMode');
        if ($night === null) {
            $night = $this->appleFlag($flags, 'nightMode');
        }

        $hdrScene = null;

        if ($hdr !== null && $this->isHdrSceneLabel($hdr)) {
            $hdrScene = true;
        }

        if ($hdrScene === null) {
            $hdrHeadroom = $apple->hdrHeadroom;
            if ($hdrHeadroom !== null && $hdrHeadroom > 0.0) {
                $hdrScene = true;
            } elseif (
                $this->appleFlag($flags, 'hdrEnabled') === true
                || $this->appleFlag($flags, 'hdrAuto') === true
            ) {
                $hdrScene = true;
            }
        }

        return new Scene(
            type: $exif?->sceneCaptureType(),
            sceneType: $exif?->sceneType(),
            light: $exif?->lightSource(),
            faceCount: $faceCount,
            hdrScene: $hdrScene,
            nightMode: $night,
            subjectDistanceRange: $exif?->subjectDistanceRange(),
        );
    }

    /**
     * Retrieves a boolean flag from the normalised Apple flag map.
     *
     * @param array<string, bool> $flags
     */
    private function isHdrSceneLabel(string $label): bool
    {
        $normalized = strtoupper(trim($label));

        return str_starts_with($normalized, 'HDR');
    }

    private function appleFlag(array $flags, string $key): ?bool
    {
        if (!array_key_exists($key, $flags)) {
            return null;
        }

        $value = $flags[$key];

        return is_bool($value) ? $value : null;
    }

    /**
     * Builds the motion metadata aggregate from EXIF and Apple motion sources.
     *
     * @param ExifDocument $exif  Resolver exposing EXIF camera orientation measurements.
     * @param AppleMakerNotes $apple Aggregated Apple metadata composed from maker notes and QuickTime sources.
     *
     * @return Motion Motion metadata aggregate with camera orientation and per-axis acceleration.
     */
    private function buildMotion(?ExifDocument $exif, AppleMakerNotes $apple): Motion
    {
        $rollDeg  = $exif?->cameraRollDeg();
        $pitchDeg = $exif?->cameraPitchDeg();
        $yawDeg   = $exif?->cameraYawDeg();

        $vector = $apple->accelerationVector;

        if (!is_array($vector)) {
            $vector = $exif?->accelerationVector();
        }

        $accelX = null;
        $accelY = null;
        $accelZ = null;

        if (is_array($vector)) {
            $accelX = $vector[0] ?? null;
            $accelY = $vector[1] ?? null;
            $accelZ = $vector[2] ?? null;
        }

        return new Motion($rollDeg, $pitchDeg, $yawDeg, $accelX, $accelY, $accelZ, null, null, null);
    }

    private function buildUav(?ExifDocument $exif, ?QuickTimeMeta $quickTime): Uav
    {
        $manufacturer = $exif?->aircraftMake();
        if ($manufacturer === null) {
            $manufacturer = $this->quickTimeString($quickTime, 'com.apple.quicktime.make');
        }

        $model = $exif?->aircraftModel();
        if ($model === null) {
            $model = $this->quickTimeString($quickTime, 'com.apple.quicktime.model');
        }

        $flightYaw = $exif?->flightYawDeg();
        if ($flightYaw === null) {
            $flightYaw = $this->quickTimeFloat($quickTime, 'com.apple.quicktime.flightYawDegree');
        }

        $flightPitch = $exif?->flightPitchDeg();
        if ($flightPitch === null) {
            $flightPitch = $this->quickTimeFloat($quickTime, 'com.apple.quicktime.flightPitchDegree');
        }

        $flightRoll = $exif?->flightRollDeg();
        if ($flightRoll === null) {
            $flightRoll = $this->quickTimeFloat($quickTime, 'com.apple.quicktime.flightRollDegree');
        }

        $gimbalYaw = $exif?->gimbalYawDeg();
        if ($gimbalYaw === null) {
            $gimbalYaw = $this->quickTimeFloat($quickTime, 'com.apple.quicktime.gimbalYawDegree');
        }

        $gimbalPitch = $exif?->gimbalPitchDeg();
        if ($gimbalPitch === null) {
            $gimbalPitch = $this->quickTimeFloat($quickTime, 'com.apple.quicktime.gimbalPitchDegree');
        }

        $gimbalRoll = $exif?->gimbalRollDeg();
        if ($gimbalRoll === null) {
            $gimbalRoll = $this->quickTimeFloat($quickTime, 'com.apple.quicktime.gimbalRollDegree');
        }

        return new Uav(
            manufacturer: $manufacturer,
            model: $model,
            flightYaw: $flightYaw,
            flightPitch: $flightPitch,
            flightRoll: $flightRoll,
            gimbalYaw: $gimbalYaw,
            gimbalPitch: $gimbalPitch,
            gimbalRoll: $gimbalRoll,
        );
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
     * Resolves the first non-empty QuickTime string value from the supplied keys.
     *
     * @param QuickTimeMeta|null $quickTime QuickTime metadata container used to read QuickTime keys.
     * @param string            ...$keys  Candidate metadata keys to inspect in order.
     *
     * @return string|null First matching string value or null when no value is present.
     */
    private function quickTimeString(?QuickTimeMeta $quickTime, string ...$keys): ?string
    {
        if ($quickTime === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $quickTime->stringValue($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolves the first available QuickTime float value from the provided keys.
     *
     * @param QuickTimeMeta|null $quickTime QuickTime metadata container used to read QuickTime keys.
     * @param string            ...$keys  Candidate metadata keys to inspect in order.
     *
     * @return float|null First matching float value or null when no value is present.
     */
    private function quickTimeFloat(?QuickTimeMeta $quickTime, string ...$keys): ?float
    {
        if ($quickTime === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $quickTime->floatValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function quickTimeBool(?QuickTimeMeta $quickTime, string ...$keys): ?bool
    {
        if ($quickTime === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $quickTime->boolValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolves the first available QuickTime integer value from the provided keys.
     *
     * @param QuickTimeMeta|null $quickTime QuickTime metadata container used to read QuickTime keys.
     * @param string            ...$keys  Candidate metadata keys to inspect in order.
     *
     * @return int|null First matching integer value or null when no value is present.
     */
    private function quickTimeInt(?QuickTimeMeta $quickTime, string ...$keys): ?int
    {
        if ($quickTime === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $quickTime->intValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Normalises the colour space based on interoperability metadata hints.
     *
     * @param ExifDocument $resolver Resolver exposing colour space and interoperability tags.
     *
     * @return ColorSpace|null Normalised colour space enumeration or null when undefined.
     */
    private function normalizedColorSpace(?ExifDocument $document): ?ColorSpace
    {
        if (!$document instanceof ExifDocument) {
            return null;
        }

        $colorSpace = ColorSpace::fromExifValue($document->colorSpace());

        if ($colorSpace === ColorSpace::UNCALIBRATED) {
            $interopIndex = $document->interopIndex();
            if (is_string($interopIndex)) {
                $normalizedInteropIndex = strtoupper($interopIndex);
                if ($normalizedInteropIndex === 'R03') {
                    return ColorSpace::ADOBE_RGB;
                }

                if ($normalizedInteropIndex === 'R98') {
                    return ColorSpace::SRGB;
                }
            }
        }

        return $colorSpace;
    }

    /**
     * Converts a flat list of values into XY point pairs.
     *
     * @param list<float> $values
     *
     * @return list<array{0: float, 1: float}>
     */
    private function chunkPairEntries(array $values): array
    {
        $count = count($values);
        if ($count < 2 || $count % 2 !== 0) {
            return [];
        }

        $pairs = [];
        for ($index = 0; $index < $count; $index += 2) {
            $pairs[] = [(float) $values[$index], (float) $values[$index + 1]];
        }

        return $pairs;
    }

    /**
     * Converts a flat list of values into RGB triplets.
     *
     * @param list<float> $values
     *
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private function chunkTripletEntries(array $values): array
    {
        $count = count($values);
        if ($count < 3 || $count % 3 !== 0) {
            return [];
        }

        $triplets = [];
        for ($index = 0; $index < $count; $index += 3) {
            $triplets[] = [
                (float) $values[$index],
                (float) $values[$index + 1],
                (float) $values[$index + 2],
            ];
        }

        return $triplets;
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
        if ($digits === '') {
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
}
