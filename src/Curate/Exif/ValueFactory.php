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
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\DngProfileGainTableTag;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\ExifFlash;
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
use MagicSunday\ImageMeta\Value\MultiPictureEntry;
use MagicSunday\ImageMeta\Value\Preview;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\Regions\Region;
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
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;

use function abs;
use function array_any;
use function array_key_exists;
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
     * Produces normalised value objects derived from the supplied metadata container.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return array{
     *     file: File,
     *     container: Container,
     *     integrity: Integrity,
     *     camera: Camera,
     *     device: Device,
     *     lens: Lens,
     *     derived: Derived,
     *     image: Image,
     *     preview: Preview,
     *     video: Video,
     *     audio: Audio,
     *     embeddedAudio: AudioClips,
     *     colorProfile: ColorProfile,
     *     composite: CompositeImageInfo,
     *     multiPicture: MultiPicture,
     *     exposure: Exposure,
     *     capture: Capture,
     *     scene: Scene,
     *     temporal: Temporal,
     *     regions: Regions,
     *     keywords: Keywords,
     *     gps: Gps,
     *     sensor: Sensor,
     *     focus: Focus,
     *     motion: Motion,
     *     uav: Uav,
     *     processing: ProcessingSettings,
     *     whiteBalance: WhiteBalanceDetails,
     *     interop: Interop,
     *     tiff: TiffData,
     *     standards: Standards,
     *     flashPix: FlashPix,
     *     xmp: Xmp,
     *     rights: Rights,
     *     author: Author,
     *     related: RelatedAssets,
     *     makerNotesApple: AppleMakerNotes|null,
     * }
     */
    public function createComponents(Metadata $metadata): array
    {
        $xmpDocument = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();

        $gps             = $this->createGps($metadata, $xmpDocument);
        $regions         = $this->createRegions($xmpDocument);
        $multiPicture    = $this->createMultiPicture($metadata);
        $exifDocument    = $metadata->exifDoc;
        $quickTimeMeta   = $metadata->quickTime;
        $quickTimeLookup = new QuickTimeLookup($quickTimeMeta);
        $appleMakerNotes = $metadata->makerNotes?->apple;

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
            tiffEpStandardId: $exifDocument?->tiffEpStandardId(),
            tiffEpStandardString: $exifDocument?->tiffEpStandardIdString(),
        );

        $flashPix = new FlashPix($metadata->flashPixStreams);

        $camera = $this->buildCamera($exifDocument);
        $lens   = $this->buildLens($exifDocument);
        $image  = $this->buildImage($metadata, $exifDocument);

        $exposureProgram = $exifDocument?->exposureProgram();
        $meteringMode    = $exifDocument?->meteringMode();
        $whiteBalance    = $exifDocument?->whiteBalance();

        $flashInfo = ExifFlash::fromExifValue($exifDocument?->flash());

        $exposure = new Exposure(
            iso: $exifDocument?->isoBestEffort(),
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

        $previewColorSpace      = ColorSpace::fromExifValue($exifDocument?->previewColorSpace());
        $previewCompressionEnum = Compression::fromExifValue($exifDocument?->previewImageCompression());
        $previewStripOffsets    = $exifDocument?->previewImageStripOffsets();
        $previewStripByteCounts = $exifDocument?->previewImageStripByteCounts();
        $previewTileOffsets     = $exifDocument?->previewImageTileOffsets();
        $previewTileByteCounts  = $exifDocument?->previewImageTileByteCounts();
        $thumbnailCompression   = $exifDocument?->thumbnailCompression();
        $thumbnailStripOffsets  = $exifDocument?->thumbnailStripOffsets();
        $thumbnailStripCounts   = $exifDocument?->thumbnailStripByteCounts();
        $thumbnailTileOffsets   = $exifDocument?->thumbnailTileOffsets();
        $thumbnailTileCounts    = $exifDocument?->thumbnailTileByteCounts();

        $preview = new Preview(
            $exifDocument?->hasThumbnail(),
            $exifDocument?->hasPreviewImage(),
            $exifDocument?->previewImageWidth(),
            $exifDocument?->previewImageHeight(),
            $previewColorSpace,
            $exifDocument?->previewImageBitDepth(),
            $previewCompressionEnum,
            $exifDocument?->previewImageScale(),
            $exifDocument?->previewImageEncoding(),
            $exifDocument?->previewImageMimeType(),
            $exifDocument?->previewImageOffset(),
            $exifDocument?->previewImageLength(),
            $exifDocument?->jpegThumbnailOffset(),
            $exifDocument?->jpegThumbnailLength(),
            $thumbnailCompression,
            $thumbnailStripOffsets,
            $thumbnailStripCounts,
            $thumbnailTileOffsets,
            $thumbnailTileCounts,
            $previewStripOffsets,
            $previewStripByteCounts,
            $previewTileOffsets,
            $previewTileByteCounts,
        );

        $video = new Video(
            durationSec: $quickTimeLookup->float('com.apple.quicktime.duration'),
            frameRate: $quickTimeLookup->float('com.apple.quicktime.videoFrameRate'),
            width: $quickTimeLookup->int(QuickTimeMeta::VIDEO_WIDTH_KEY),
            height: $quickTimeLookup->int(QuickTimeMeta::VIDEO_HEIGHT_KEY),
            codec: $quickTimeLookup->string(QuickTimeMeta::COMPRESSOR_NAME_KEY,
                QuickTimeMeta::VIDEO_CODEC_KEY,
            ),
            hdr: $quickTimeLookup->bool('com.apple.quicktime.hdrFormat'),
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

        $hueSatMap     = null;
        $hueSatMapData = $exifDocument?->profileHueSatMap();
        if (is_array($hueSatMapData)) {
            $dimensions = $hueSatMapData['dimensions'] ?? null;
            $hueSatMap  = new ColorProfileHueSatMap(
                $dimensions[0] ?? null,
                $dimensions[1] ?? null,
                $dimensions[2] ?? null,
                $hueSatMapData['encodings'] ?? null,
                $hueSatMapData['map1'] ?? null,
                $hueSatMapData['map2'] ?? null,
                $hueSatMapData['map3'] ?? null,
            );
        }

        $lookTable     = null;
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

        $toneCurve     = null;
        $toneCurveData = $exifDocument?->profileToneCurve();
        if (is_array($toneCurveData) && $toneCurveData !== []) {
            $points = $this->chunkPairEntries($toneCurveData);
            if ($points !== []) {
                $toneCurve = new ColorProfileToneCurve($points);
            }
        }

        $gainMap     = null;
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
            $whiteBalanceKelvin = $quickTimeLookup->int('ColorTemperature');
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
            isPrimaryInBurst: $quickTimeLookup->bool('BurstSelected'),
            panoramaId: $panoramaFlag === true ? 'panorama' : null,
            depthDataId: $quickTimeLookup->string('DepthData'),
            relatedSoundFile: $exifDocument?->relatedSoundFile(),
        );

        $focalPlaneUnit     = null;
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

        $hasHistory     = $xmpDocument?->has('http://ns.adobe.com/xap/1.0/mm/', 'History') ?? false;
        $makerNotesSafe = $metadata->makerNotes?->isSafe;
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
            'file'            => $file,
            'container'       => $container,
            'integrity'       => $integrity,
            'camera'          => $camera,
            'device'          => $device,
            'lens'            => $lens,
            'derived'         => $derived,
            'image'           => $image,
            'preview'         => $preview,
            'video'           => $video,
            'audio'           => $audio,
            'embeddedAudio'   => $embeddedAudio,
            'colorProfile'    => $colorProfile,
            'composite'       => $composite,
            'multiPicture'    => $multiPicture,
            'exposure'        => $exposure,
            'capture'         => $capture,
            'scene'           => $scene,
            'temporal'        => $temporal,
            'regions'         => $regions,
            'keywords'        => $keywords,
            'gps'             => $gps,
            'sensor'          => $sensor,
            'focus'           => $focus,
            'motion'          => $motion,
            'uav'             => $uav,
            'processing'      => $processing,
            'whiteBalance'    => $whiteBalanceDetails,
            'interop'         => $interop,
            'tiff'            => $tiff,
            'standards'       => $standards,
            'flashPix'        => $flashPix,
            'xmp'             => $xmp,
            'rights'          => $rights,
            'author'          => $author,
            'related'         => $related,
            'makerNotesApple' => $apple,
        ];
    }

    /**
     * Builds the device metadata aggregate by combining EXIF helpers with QuickTime fallbacks.
     *
     * @param ParsedExif|null    $exif        Resolver exposing EXIF tag helpers.
     * @param QuickTimeMeta|null $quickTime   QuickTime metadata container exposing software fields.
     * @param XmpDocument|null   $xmpDocument Placeholder for future XMP backed device metadata.
     *
     * @return Device Device value object describing capture hardware and software.
     */
    private function buildDevice(?ParsedExif $exif, ?QuickTimeMeta $quickTime, ?XmpDocument $xmpDocument): Device
    {
        $software = null;

        if ($exif instanceof ParsedExif) {
            $software = $exif->software();

            if ($software === null) {
                $software = $exif->hostComputer();
            }
        }

        $lookup = new QuickTimeLookup($quickTime);

        if ($software === null) {
            $software = $lookup->string(
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

        $timeZoneOffsets = $exifDocument?->timeZoneOffsetMinutes();

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
    private function originalTimestampComponents(?ParsedExif $document): array
    {
        if (!$document instanceof ParsedExif) {
            return [null, null, null];
        }

        $original = $document->dateTimeOriginalBestEffort();

        $zoneOffsets = $document->timeZoneOffsetMinutes();
        $offset      = $document->offsetTimeOriginal();
        if ($offset === null && is_array($zoneOffsets) && array_key_exists(0, $zoneOffsets)) {
            $offset = $zoneOffsets[0];
        }

        if ($offset === null && $this->dateTimeStringEmpty($document->dateTimeOriginalRaw())) {
            $offset = $document->offsetTimeDigitized();
            if ($offset === null && is_array($zoneOffsets) && array_key_exists(1, $zoneOffsets)) {
                $offset = $zoneOffsets[1];
            }
        }

        if (
            $offset === null
            && $this->dateTimeStringEmpty($document->dateTimeOriginalRaw())
            && $this->dateTimeStringEmpty($document->dateTimeDigitizedRaw())
        ) {
            $offset = $document->offsetTime();
            if ($offset === null && is_array($zoneOffsets) && array_key_exists(0, $zoneOffsets)) {
                $offset = $zoneOffsets[0];
            }
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
     * Builds a camera value object using EXIF metadata.
     *
     * @param ParsedExif|null $exifDocument EXIF document exposing camera related tags.
     *
     * @return Camera Normalised camera metadata aggregate.
     */
    private function buildCamera(?ParsedExif $exifDocument): Camera
    {
        if (!$exifDocument instanceof ParsedExif) {
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

        $profile = (float) $exifDocument->exifProfile();

        $firmware = $exifDocument->cameraFirmware();

        if ($firmware === null && $profile < 3.0) {
            $firmware = $exifDocument->cameraFirmwareVersion();
        }

        if ($firmware === null) {
            $firmware = $exifDocument->software();
        }

        return new Camera(
            make: $exifDocument->cameraMake(),
            model: $exifDocument->cameraModel(),
            ownerName: $exifDocument->ownerName(),
            serialNumber: $exifDocument->cameraSerialNumber(),
            firmware: $firmware,
            fileSource: $exifDocument->fileSource(),
            sensingMethod: $exifDocument->sensingMethod(),
        );
    }

    /**
     * Builds a lens value object using EXIF metadata.
     *
     * @param ParsedExif|null $exifDocument EXIF document exposing lens specific tags.
     *
     * @return Lens Normalised lens metadata aggregate.
     */
    private function buildLens(?ParsedExif $exifDocument): Lens
    {
        if (!$exifDocument instanceof ParsedExif) {
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

        $maxApex = $exifDocument->maxApertureApex();
        $maxF    = $maxApex !== null ? ValueConverters::apexToFNumber($maxApex) : null;

        return new Lens(
            lensMake: $exifDocument->lensMake(),
            lensModel: $exifDocument->lensModel(),
            lensSerialNumber: $exifDocument->lensSerialNumber(),
            focalLengthMm: $exifDocument->focalLengthMm(),
            focalLengthIn35mm: $exifDocument->focalLength35Mm(),
            maxApertureFNumber: $maxF,
            lensSpecification: $exifDocument->lensSpecification(),
        );
    }

    /**
     * Builds the image value object using EXIF metadata.
     *
     * @param Metadata        $metadata     Metadata container supplying JPEG frame fallbacks.
     * @param ParsedExif|null $exifDocument EXIF document exposing image related tags.
     *
     * @return Image Normalised image metadata aggregate.
     */
    private function buildImage(Metadata $metadata, ?ParsedExif $exifDocument): Image
    {
        $width  = $exifDocument?->imageWidth() ?? $metadata->jpegFrameWidth;
        $height = $exifDocument?->imageHeight() ?? $metadata->jpegFrameHeight;

        $orientation = $exifDocument?->orientation();

        $bitsPerSample = $exifDocument?->bitsPerSample();
        if ($bitsPerSample === null) {
            $bitsPerSample = $metadata->jpegBitsPerSample;
        }

        return new Image(
            width: $width,
            height: $height,
            orientation: $orientation,
            bitsPerSample: $bitsPerSample,
            colorSpace: $this->normalizedColorSpace($exifDocument),
            imageUniqueId: $exifDocument?->imageUniqueId(),
            imageNumber: $exifDocument?->imageNumber(),
            documentName: $exifDocument?->documentName(),
            description: $exifDocument?->imageDescription(),
            title: $exifDocument?->imageTitle(),
            componentsConfiguration: $exifDocument?->componentsConfiguration(),
            compressedBitsPerPixel: $exifDocument?->compressedBitsPerPixel(),
            interlace: $exifDocument?->interlace(),
            userComment: $exifDocument?->userComment(),
            userCommentEncoding: $exifDocument?->userCommentEncodingBestEffort(),
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
     * @param ParsedExif|null    $exif      Resolver exposing EXIF scene metadata.
     * @param QuickTimeMeta|null $quickTime QuickTime metadata providing scene hints.
     * @param AppleMakerNotes    $apple     Aggregated Apple maker note metadata.
     * @param int|null           $faceCount Number of detected face regions.
     *
     * @return Scene Scene metadata value object.
     */
    private function buildScene(
        ?ParsedExif $exif,
        ?QuickTimeMeta $quickTime,
        AppleMakerNotes $apple,
        ?int $faceCount,
    ): Scene {
        $appleFlags = $apple->flags;

        $lookup = new QuickTimeLookup($quickTime);

        $hdrLabel = $apple->hdrImageType;
        if ($hdrLabel === null) {
            $hdrLabel = $lookup->string('HDRImageType');
        }

        $nightMode = $lookup->bool('NightMode');
        if ($nightMode === null) {
            $nightMode = $this->appleFlag($appleFlags, 'nightMode');
        }

        $hdrScene = null;

        if ($hdrLabel !== null && $this->isHdrSceneLabel($hdrLabel)) {
            $hdrScene = true;
        }

        if ($hdrScene === null) {
            $hdrHeadroom = $apple->hdrHeadroom;
            if ($hdrHeadroom !== null && $hdrHeadroom > 0.0) {
                $hdrScene = true;
            } elseif (
                $this->appleFlag($appleFlags, 'hdrEnabled') === true
                || $this->appleFlag($appleFlags, 'hdrAuto') === true
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
            nightMode: $nightMode,
            subjectDistanceRange: $exif?->subjectDistanceRange(),
        );
    }

    /**
     * Determines whether the supplied label denotes an HDR scene mode.
     *
     * Apple devices record the HDR scene state as free-form strings such as
     * "HDR" or "HDR+". The check therefore normalises the label to uppercase
     * and considers every value that starts with "HDR" as an affirmative
     * indicator.
     */
    private function isHdrSceneLabel(string $label): bool
    {
        $normalized = strtoupper(trim($label));

        return str_starts_with($normalized, 'HDR');
    }

    /**
     * Extracts a boolean flag from the Apple maker note flag map.
     *
     * @param array<string, bool> $flags Normalised Apple maker note flag map.
     * @param string              $key   Name of the flag to resolve.
     *
     * @return bool|null Resolved boolean flag or null when the flag is absent.
     */
    private function appleFlag(array $flags, string $key): ?bool
    {
        return $flags[$key] ?? null;
    }

    /**
     * Builds the motion metadata aggregate from EXIF and Apple motion sources.
     *
     * @param ParsedExif      $exif  Resolver exposing EXIF camera orientation measurements.
     * @param AppleMakerNotes $apple Aggregated Apple metadata composed from maker notes and QuickTime sources.
     *
     * @return Motion Motion metadata aggregate with camera orientation and per-axis acceleration.
     */
    private function buildMotion(?ParsedExif $exif, AppleMakerNotes $apple): Motion
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

    private function buildUav(?ParsedExif $exif, ?QuickTimeMeta $quickTime): Uav
    {
        $lookup = new QuickTimeLookup($quickTime);

        $manufacturer = $exif?->aircraftMake();
        if ($manufacturer === null) {
            $manufacturer = $lookup->string('com.apple.quicktime.make');
        }

        $model = $exif?->aircraftModel();
        if ($model === null) {
            $model = $lookup->string('com.apple.quicktime.model');
        }

        $flightYaw = $exif?->flightYawDeg();
        if ($flightYaw === null) {
            $flightYaw = $lookup->float('com.apple.quicktime.flightYawDegree');
        }

        $flightPitch = $exif?->flightPitchDeg();
        if ($flightPitch === null) {
            $flightPitch = $lookup->float('com.apple.quicktime.flightPitchDegree');
        }

        $flightRoll = $exif?->flightRollDeg();
        if ($flightRoll === null) {
            $flightRoll = $lookup->float('com.apple.quicktime.flightRollDegree');
        }

        $gimbalYaw = $exif?->gimbalYawDeg();
        if ($gimbalYaw === null) {
            $gimbalYaw = $lookup->float('com.apple.quicktime.gimbalYawDegree');
        }

        $gimbalPitch = $exif?->gimbalPitchDeg();
        if ($gimbalPitch === null) {
            $gimbalPitch = $lookup->float('com.apple.quicktime.gimbalPitchDegree');
        }

        $gimbalRoll = $exif?->gimbalRollDeg();
        if ($gimbalRoll === null) {
            $gimbalRoll = $lookup->float('com.apple.quicktime.gimbalRollDegree');
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
     * Normalises the colour space based on interoperability metadata hints.
     *
     * @param ParsedExif|null $exifDocument EXIF document exposing colour space and interoperability tags.
     *
     * @return ColorSpace|null Normalised colour space enumeration or null when undefined.
     */
    private function normalizedColorSpace(?ParsedExif $exifDocument): ?ColorSpace
    {
        if (!$exifDocument instanceof ParsedExif) {
            return null;
        }

        $colorSpace = $exifDocument->colorSpace();

        if ($colorSpace === ColorSpace::UNCALIBRATED) {
            $interopIndex = $exifDocument->interopIndex();
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
            $pairs[] = [$values[$index], $values[$index + 1]];
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
                $values[$index],
                $values[$index + 1],
                $values[$index + 2],
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

    private function createGps(Metadata $metadata, ?XmpDocument $xmpDocument): Gps
    {
        $gps = $this->resolveGps($metadata->exifDoc, $xmpDocument);

        if (!$gps instanceof Gps) {
            return new Gps();
        }

        return $gps;
    }

    private const string NS_EXIF = 'http://ns.adobe.com/exif/1.0/';

    /**
     * Builds a GPS value object from the available metadata.
     *
     * The GPS version defaults to 2.0.0.0 whenever EXIF omits the tag or only exposes padding bytes.
     */
    private function resolveGps(?ParsedExif $exifDocument, ?XmpDocument $xmpDocument): ?Gps
    {
        /**
         * @var array{
         *     lat_ref: string|null,
         *     lat: float|null,
         *     lon_ref: string|null,
         *     lon: float|null,
         *     alt_ref: int|null,
         *     alt: float|null,
         *     version: string|null,
         *     version_raw: string|null,
         *     satellites: string|null,
         *     status: string|null,
         *     measure_mode: string|null,
         *     dop: float|null,
         *     speed_ref: string|null,
         *     speed_ms: float|null,
         *     speed_original_ref: string|null,
         *     speed_original: float|null,
         *     track_ref: string|null,
         *     track: float|null,
         *     img_direction_ref: string|null,
         *     img_direction: float|null,
         *     map_datum: string|null,
         *     dest_lat_ref: string|null,
         *     dest_lat: float|null,
         *     dest_lon_ref: string|null,
         *     dest_lon: float|null,
         *     dest_bearing_ref: string|null,
         *     dest_bearing: float|null,
         *     dest_distance_ref: string|null,
         *     dest_distance_m: float|null,
         *     dest_distance_original_ref: string|null,
         *     dest_distance_original: float|null,
         *     processing_method: string|null,
         *     area_information: string|null,
         *     date: string|null,
         *     date_raw: string|null,
         *     time: string|null,
         *     timestamp: DateTimeImmutable|null,
         *     differential: int|null,
         *     h_positioning_error: float|null,
         * } $gpsData
         */
        $gpsData = $exifDocument instanceof ParsedExif
            ? $exifDocument->gps()
            : ValueConverters::emptyGpsResult();

        $latitude     = $this->floatValue($gpsData['lat']);
        $longitude    = $this->floatValue($gpsData['lon']);
        $latitudeRef  = $this->uppercase($gpsData['lat_ref']);
        $longitudeRef = $this->uppercase($gpsData['lon_ref']);
        $altitude     = $this->floatValue($gpsData['alt']);
        $altitudeRef  = $this->intValue($gpsData['alt_ref']);

        $version    = $this->stringValue($gpsData['version']);
        $versionRaw = $gpsData['version_raw'];
        if (!is_string($versionRaw)) {
            $versionRaw = null;
        }

        $satellites       = $this->stringValue($gpsData['satellites']);
        $status           = $this->stringValue($gpsData['status']);
        $measureMode      = $this->stringValue($gpsData['measure_mode']);
        $dop              = $this->floatValue($gpsData['dop']);
        $speedRef         = $this->uppercase($gpsData['speed_ref']);
        $speedMs          = $this->floatValue($gpsData['speed_ms']);
        $speedOriginalRef = $this->stringValue($gpsData['speed_original_ref']);
        $speedOriginal    = $this->floatValue($gpsData['speed_original']);
        $trackRef         = $this->uppercase($gpsData['track_ref']);
        $track            = $this->floatValue($gpsData['track']);
        $imgDirRef        = $this->uppercase($gpsData['img_direction_ref']);
        $imgDir           = $this->floatValue($gpsData['img_direction']);
        $mapDatum         = $this->stringValue($gpsData['map_datum']);

        $destLatRef          = $this->uppercase($gpsData['dest_lat_ref']);
        $destLat             = $this->floatValue($gpsData['dest_lat']);
        $destLonRef          = $this->uppercase($gpsData['dest_lon_ref']);
        $destLon             = $this->floatValue($gpsData['dest_lon']);
        $destBearRef         = $this->uppercase($gpsData['dest_bearing_ref']);
        $destBear            = $this->floatValue($gpsData['dest_bearing']);
        $destDistRef         = $this->uppercase($gpsData['dest_distance_ref']);
        $destDistMetre       = $this->floatValue($gpsData['dest_distance_m']);
        $destDistOriginalRef = $this->stringValue($gpsData['dest_distance_original_ref']);
        $destDistOriginal    = $this->floatValue($gpsData['dest_distance_original']);

        $processingMethod = $this->stringValue($gpsData['processing_method']);
        $areaInformation  = $this->stringValue($gpsData['area_information']);

        $date    = $this->normaliseDate($this->stringValue($gpsData['date']));
        $dateRaw = $gpsData['date_raw'];
        if (!is_string($dateRaw)) {
            $dateRaw = null;
        }

        $time = $this->stringValue($gpsData['time']);

        $timestamp = $exifDocument?->gpsTimestamp();
        if (!$timestamp instanceof DateTimeImmutable) {
            $timestamp = null;
        }

        if ($date === null) {
            $date = $this->normaliseDate($exifDocument?->gpsDateStamp());
        }

        if ($time === null) {
            $time = $this->stringValue($exifDocument?->gpsTimeStampString());
        }

        // Fill from XMP when EXIF values are absent.
        $xmpLatRef = $this->uppercase($xmpDocument?->string(self::NS_EXIF, 'GPSLatitudeRef'));
        if ($latitudeRef === null) {
            $latitudeRef = $xmpLatRef;
        }

        if ($latitude === null) {
            $latitude = $this->parseCoordinate(
                $xmpDocument?->string(self::NS_EXIF, 'GPSLatitude'),
                $xmpLatRef ?? $latitudeRef,
            );
        }

        $xmpLonRef = $this->uppercase($xmpDocument?->string(self::NS_EXIF, 'GPSLongitudeRef'));
        if ($longitudeRef === null) {
            $longitudeRef = $xmpLonRef;
        }

        if ($longitude === null) {
            $longitude = $this->parseCoordinate(
                $xmpDocument?->string(self::NS_EXIF, 'GPSLongitude'),
                $xmpLonRef ?? $longitudeRef,
            );
        }

        if ($altitude === null && $xmpDocument instanceof XmpDocument) {
            $altitudeXmp = $xmpDocument->float(self::NS_EXIF, 'GPSAltitude');
            if ($altitudeXmp !== null) {
                $altRefXmp = $this->intValue($xmpDocument->int(self::NS_EXIF, 'GPSAltitudeRef'));
                $altRef    = $altitudeRef ?? $altRefXmp;

                if ($altRef === 1) {
                    $altitudeXmp = -$altitudeXmp;
                }

                $altitude = $altitudeXmp;

                $altitudeRef ??= $altRefXmp;
            }
        }

        $xmpSpeedRef = $xmpDocument?->string(self::NS_EXIF, 'GPSSpeedRef');
        if ($speedRef === null) {
            $speedRef = $this->uppercase($xmpSpeedRef);
        }

        if ($speedOriginalRef === null) {
            $speedOriginalRef = $this->stringValue($xmpSpeedRef);
        }

        $speedValue = $xmpDocument?->float(self::NS_EXIF, 'GPSSpeed');
        if ($speedValue !== null) {
            if ($speedMs === null && $speedRef !== null) {
                $speedMs = $this->convertSpeedToMetresPerSecond($speedValue, $speedRef);
            }

            if ($speedOriginal === null) {
                $speedOriginal = $speedValue;
            }
        }

        $xmpDestDistRef = $xmpDocument?->string(self::NS_EXIF, 'GPSDestDistanceRef');
        if ($destDistRef === null) {
            $destDistRef = $this->uppercase($xmpDestDistRef);
        }

        if ($destDistOriginalRef === null) {
            $destDistOriginalRef = $this->stringValue($xmpDestDistRef);
        }

        $destDistValue = $xmpDocument?->float(self::NS_EXIF, 'GPSDestDistance');
        if ($destDistValue !== null) {
            if ($destDistMetre === null && $destDistRef !== null) {
                $convertedDistance = $this->convertDistanceToMetres($destDistValue, $destDistRef);
                if ($convertedDistance !== null) {
                    $destDistMetre = $convertedDistance;
                }
            }

            if ($destDistOriginal === null) {
                $destDistOriginal = $destDistValue;
            }
        }

        if ($date === null) {
            $date = $this->normaliseDate($xmpDocument?->string(self::NS_EXIF, 'GPSDateStamp'));
        }

        if ($time === null) {
            $time = $this->stringValue($xmpDocument?->string(self::NS_EXIF, 'GPSTimeStamp'));
        }

        if (!$timestamp instanceof DateTimeImmutable) {
            $timestamp = $this->parseXmpTimestamp($xmpDocument);
        }

        if (!$timestamp instanceof DateTimeImmutable) {
            $timestamp = $this->combineDateAndTime($date, $time);
        }

        $differential = $this->intValue($gpsData['differential'] ?? null);
        $hError       = $this->floatValue($gpsData['h_positioning_error'] ?? null);
        $hasData      = array_any([
            $latitude,
            $longitude,
            $altitude,
            $altitudeRef,
            $version,
            $versionRaw,
            $satellites,
            $status,
            $measureMode,
            $dop,
            $speedRef,
            $speedMs,
            $speedOriginalRef,
            $speedOriginal,
            $trackRef,
            $track,
            $imgDirRef,
            $imgDir,
            $mapDatum,
            $destLatRef,
            $destLat,
            $destLonRef,
            $destLon,
            $destBearRef,
            $destBear,
            $destDistRef,
            $destDistMetre,
            $destDistOriginalRef,
            $destDistOriginal,
            $processingMethod,
            $areaInformation,
            $date,
            $dateRaw,
            $time,
            $timestamp,
            $differential,
            $hError,
        ], fn ($value): bool => $value !== null);

        if (!$hasData) {
            return null;
        }

        return new Gps(
            latitude: $latitude,
            longitude: $longitude,
            latitudeRef: $latitudeRef,
            longitudeRef: $longitudeRef,
            altitude: $altitude,
            altitudeRef: $altitudeRef,
            version: $version,
            versionRaw: $versionRaw,
            satellites: $satellites,
            status: $status,
            measureMode: $measureMode,
            dop: $dop,
            speedRef: $speedRef,
            speedMs: $speedMs,
            speedOriginalRef: $speedOriginalRef,
            speedOriginal: $speedOriginal,
            trackRef: $trackRef,
            track: $track,
            imageDirectionRef: $imgDirRef,
            imageDirection: $imgDir,
            mapDatum: $mapDatum,
            destinationLatitudeRef: $destLatRef,
            destinationLatitude: $destLat,
            destinationLongitudeRef: $destLonRef,
            destinationLongitude: $destLon,
            destinationBearingRef: $destBearRef,
            destinationBearing: $destBear,
            destinationDistanceRef: $destDistRef,
            destinationDistanceMetres: $destDistMetre,
            destinationDistanceOriginalRef: $destDistOriginalRef,
            destinationDistanceOriginal: $destDistOriginal,
            processingMethod: $processingMethod,
            areaInformation: $areaInformation,
            date: $date,
            dateRaw: $dateRaw,
            time: $time,
            timestamp: $timestamp,
            differential: $differential,
            horizontalPositioningError: $hError,
        );
    }

    /**
     * Parses an XMP coordinate representation.
     */
    private function parseCoordinate(?string $value, ?string $ref): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = preg_split('/[\\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return null;
        }

        $parts = array_map(
            trim(...),
            $parts,
        );

        if (count($parts) === 3) {
            $deg = XmpDocument::parseNumericValue($parts[0]);
            $min = XmpDocument::parseNumericValue($parts[1]);
            $sec = XmpDocument::parseNumericValue($parts[2]);

            if ($deg !== null && $min !== null && $sec !== null) {
                $sign = $this->coordinateSign($ref);

                return $sign * ($deg + $min / 60.0 + $sec / 3600.0);
            }
        }

        $numeric = XmpDocument::parseNumericValue($parts[0]);
        if ($numeric === null) {
            return null;
        }

        $sign = $this->coordinateSign($ref);

        return $numeric * $sign;
    }

    /**
     * Determines the sign for the given coordinate reference.
     */
    private function coordinateSign(?string $ref): float
    {
        if ($ref === 'S' || $ref === 'W') {
            return -1.0;
        }

        return 1.0;
    }

    /**
     * Normalises a textual value to uppercase when present.
     */
    private function uppercase(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return strtoupper($trimmed);
    }

    /**
     * Returns the value as string when not empty.
     */
    private function stringValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Returns the value as float when numeric.
     */
    private function floatValue(int|float|null $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Returns the value as integer when numeric.
     */
    private function intValue(int|float|null $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        return null;
    }

    /**
     * Converts a textual GPS date into ISO format.
     */
    private function normaliseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\\d{4}:\\d{2}:\\d{2}$/', $trimmed) === 1) {
            return str_replace(':', '-', $trimmed);
        }

        return $trimmed;
    }

    /**
     * Parses an XMP GPSDateTime value.
     */
    private function parseXmpTimestamp(?XmpDocument $document): ?DateTimeImmutable
    {
        $value = $document?->string(self::NS_EXIF, 'GPSDateTime');
        if ($value === null) {
            return null;
        }

        try {
            $dateTime = new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Combines the supplied date and time strings into a UTC timestamp.
     */
    private function combineDateAndTime(?string $date, ?string $time): ?DateTimeImmutable
    {
        if ($date === null || $time === null) {
            return null;
        }

        $time = trim($time);
        if ($time === '') {
            return null;
        }

        $dateTimeString = sprintf('%sT%s', $date, $time);
        $hasZone        = str_contains($time, 'Z') || str_contains($time, 'z')
            || str_contains($time, '+') || str_contains($time, '-');

        if (!$hasZone) {
            $dateTimeString .= 'Z';
        }

        try {
            $dateTime = new DateTimeImmutable($dateTimeString);
        } catch (Exception) {
            return null;
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Converts destination distance into metres using GPSDestDistanceRef semantics.
     */
    private function convertDistanceToMetres(float $distance, string $distanceRef): ?float
    {
        return match ($distanceRef) {
            'K'     => $distance * 1000.0,
            'M'     => $distance * 1609.344,
            'N'     => $distance * 1852.0,
            default => null,
        };
    }

    /**
     * Converts speed in the provided unit to metres per second using GPSSpeedRef semantics.
     */
    private function convertSpeedToMetresPerSecond(float $speed, string $speedRef): float
    {
        return match ($speedRef) {
            'K'     => $speed / 3.6,
            'M'     => $speed * 0.44704,
            'N'     => $speed * 0.514444,
            default => $speed,
        };
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
                null,
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
     * @param array{w: float, h: float}|null $dimensions
     *
     * @return list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}>
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
     * @param list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}> $entries
     *
     * @return list<Region>
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
     * @param array<int, Region> $regions
     * @param array<int, Region> $supplemental
     *
     * @return array<int, Region>
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
     * @param list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}> $entries
     * @param list<Region>                                                                                                                                                      $mwgRegions
     *
     * @return array<int, Region>
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
     * @param list<Region>                                                                                                                                                $mwgRegions
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry
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
     * @param list<int> $indices
     *
     * @return list<int>
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
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry
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
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry
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
     * @return array{0: float, 1: float}
     */
    private function regionCenter(Region $region): array
    {
        return [
            $region->x + ($region->w / 2.0),
            $region->y + ($region->h / 2.0),
        ];
    }

    /**
     * @param list<string> $values
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
     * @param list<float|null> $confidenceLevels
     * @param list<float|null> $confidences
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
     * @param array{w: float, h: float}|null $dimensions
     *
     * @return array{x: float, y: float, w: float, h: float}|null
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
     * @return list<string>
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
     * @return list<float|null>
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
     * @return array{w: float, h: float}|null
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
