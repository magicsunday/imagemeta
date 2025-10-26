<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MagicSunday\ImageMeta\Core\ValueConverters;
use MagicSunday\ImageMeta\Curate\Resolver\CompositeResolver;
use MagicSunday\ImageMeta\Curate\Resolver\ExifTagResolver;
use MagicSunday\ImageMeta\Curate\Resolver\GpsResolver;
use MagicSunday\ImageMeta\Curate\Resolver\QuickTimeResolver;
use MagicSunday\ImageMeta\Curate\Resolver\RegionsResolver;
use MagicSunday\ImageMeta\Curate\Resolver\XmpResolver;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\RunTime as AppleRunTime;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\Icc\IccDecoder;
use MagicSunday\ImageMeta\Value\Apple;
use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\Preview;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\Regions\RegionType;
use MagicSunday\ImageMeta\Value\RunTime as ValueRunTime;
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

use function array_is_list;
use function array_key_exists;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_replace;
use function preg_split;
use function str_pad;
use function substr;
use function trim;
use function strtoupper;
use function in_array;

/**
 * Builds the structured metadata aggregate by orchestrating specialised resolvers.
 */
final class StructuredMetadataBuilder
{
    /**
     * @var array<int, string>
     */
    private const array APPLE_HDR_IMAGE_TYPE_MAP = [
        0 => 'Standard',
        1 => 'HDR',
        2 => 'HDR2',
        3 => 'HDR3',
    ];

    /**
     * @var list<string>
     */
    private const array APPLE_HDR_SCENE_LABELS = ['HDR', 'HDR2', 'HDR3'];

    /**
     * @var array<int, string>
     */
    private const array APPLE_IMAGE_CAPTURE_TYPE_MAP = [
        0  => 'Unknown',
        1  => 'ProRAW',
        2  => 'Portrait',
        3  => 'Live Photo',
        4  => 'Live Photo Long Exposure',
        5  => 'Burst',
        6  => 'Night Mode',
        7  => 'Night Mode Portrait',
        10 => 'Photo',
        11 => 'Manual Focus',
        12 => 'Scene',
    ];

    /**
     * @var array<string, string>
     */
    private const array APPLE_FLAG_KEYS = [
        'LivePhotoAuto'         => 'livePhotoAuto',
        'LivePhotoEnabled'      => 'livePhotoEnabled',
        'LivePhotoActive'       => 'livePhotoActive',
        'LivePhotoLongExposure' => 'livePhotoLongExposure',
        'LivePhoto'             => 'livePhoto',
        'HdrAuto'               => 'hdrAuto',
        'HdrEnabled'            => 'hdrEnabled',
        'NightMode'             => 'nightMode',
        'LongExposure'          => 'longExposure',
        'PersonInPhoto'         => 'personInPhoto',
        'PetInPhoto'            => 'petInPhoto',
    ];

    /**
     * Builds the structured metadata aggregate from the supplied metadata container.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return StructuredMetadata Structured aggregate composed from specialised resolvers.
     */
    public function build(Metadata $metadata): StructuredMetadata
    {
        $exifResolver      = new ExifTagResolver($metadata->exifDoc);
        $xmpDocument       = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
        $xmpResolver       = new XmpResolver($xmpDocument);
        $quickTimeResolver = new QuickTimeResolver($metadata->quickTime);
        $appleMakerNotes   = $metadata->makerNotes?->apple();
        $gpsResolver       = new GpsResolver();
        $regionsResolver   = new RegionsResolver();

        $interop = new Interop(
            index: $exifResolver->interopIndex(),
            version: $exifResolver->interopVersion(),
            relatedImageFileFormat: $exifResolver->relatedImageFileFormat(),
            relatedImageWidth: $exifResolver->relatedImageWidth(),
            relatedImageLength: $exifResolver->relatedImageLength(),
        );

        $bitsPerSample    = $exifResolver->bitsPerSample() ?? $metadata->jpegBitsPerSample;
        $ycbcrSubSampling = $exifResolver->ycbcrSubSampling();
        if ($ycbcrSubSampling === null) {
            $ycbcrSubSampling = $metadata->jpegYCbCrSubSampling;
        }

        $tiff = new TiffData(
            samplesPerPixel: $exifResolver->samplesPerPixel(),
            bitsPerSample: $bitsPerSample,
            rowsPerStrip: $exifResolver->rowsPerStrip(),
            compression: $exifResolver->compression(),
            photometric: $exifResolver->photometric(),
            planar: $exifResolver->planarConfiguration(),
            resolutionUnit: $exifResolver->resolutionUnit(),
            xResolution: $exifResolver->xResolution(),
            yResolution: $exifResolver->yResolution(),
            ycbcrPos: $exifResolver->ycbcrPositioning(),
            ycbcrSubSampling: $ycbcrSubSampling,
            ycbcrCoefficients: $exifResolver->ycbcrCoefficients(),
            whitePoint: $exifResolver->whitePoint(),
            primaryChromaticities: $exifResolver->primaryChromaticities(),
            stripOffsets: $exifResolver->stripOffsets(),
            stripByteCounts: $exifResolver->stripByteCounts(),
            transferFunction: $exifResolver->transferFunction(),
            jpegInterchangeFormat: $exifResolver->jpegInterchangeFormat(),
            jpegInterchangeFormatLength: $exifResolver->jpegInterchangeFormatLength(),
            referenceBlackWhite: $exifResolver->referenceBlackWhite(),
            copyright: $exifResolver->copyright(),
        );

        $composite = new CompositeImageInfo(
            type: $exifResolver->compositeImage(),
            counts: $exifResolver->sourceImageNumberOfCompositeImage(),
            exposureTimesTotal: $exifResolver->sourceExposureTimesOfCompositeImage(),
        );

        $exifVersion = $exifResolver->exifVersion();
        $profile     = $exifResolver->exifProfile();

        $standards = new Standards(
            exifVersion: $exifVersion,
            profile: $profile,
            flashpixVersion: $exifResolver->flashpixVersion(),
            tiffEpStandardId: $exifResolver->tiffEpStandardId(),
        );

        $flashPix = new FlashPix($metadata->flashPixStreams);

        $camera = $this->buildCamera($exifResolver);
        $lens   = $this->buildLens($exifResolver);
        $image  = $this->buildImage($metadata, $exifResolver);

        $exposure = new Exposure(
            iso: CompositeResolver::intISO($exifResolver),
            exposureTimeSec: $exifResolver->exposureTime(),
            fNumber: $exifResolver->fNumber(),
            exposureBiasEv: $exifResolver->exposureBias(),
            program: $exifResolver->exposureProgram(),
            meteringMode: $exifResolver->meteringMode(),
            flash: $exifResolver->flash(),
            whiteBalance: $exifResolver->whiteBalance(),
            brightnessEv: $exifResolver->brightnessValue(),
            exposureMode: $exifResolver->exposureMode(),
            gainControl: $exifResolver->gainControl(),
            contrast: $exifResolver->contrast(),
            saturation: $exifResolver->saturation(),
            sharpness: $exifResolver->sharpness(),
            digitalZoomRatio: $exifResolver->digitalZoomRatio(),
            shutterSpeedEv: $exifResolver->shutterSpeedEv(),
            apertureEv: $exifResolver->apertureEv(),
            isoLatitudeYyy: $exifResolver->isoLatitudeYyy(),
            isoLatitudeZzz: $exifResolver->isoLatitudeZzz(),
            exposureIndex: $exifResolver->exposureIndex(),
            flashEnergy: $exifResolver->flashEnergy(),
        );

        $capture = new Capture(
            dateTime: $exifResolver->captureDateTime(),
            temperatureC: $exifResolver->temperatureCelsius(),
            humidityPercent: $exifResolver->humidityPercent(),
            pressureHPa: $exifResolver->pressureHPa(),
            batteryLevelPercent: $exifResolver->batteryLevelPercent(),
            waterDepthM: $exifResolver->waterDepthMeters(),
            accelerationMs2: $exifResolver->accelerationMs2(),
            cameraElevationAngleDeg: $exifResolver->cameraElevationAngleDeg(),
            selfTimerModeSeconds: $exifResolver->selfTimerModeSeconds(),
        );

        $gps = $gpsResolver->resolve($metadata->exifDoc, $xmpDocument) ?? new Gps();

        $device = $this->buildDevice($exifResolver, $quickTimeResolver);

        $apple = $this->buildApple($appleMakerNotes, $quickTimeResolver, $metadata->quickTime);
        $xmp   = $xmpResolver->value();

        $file = new File(
            $metadata->mimeType,
            $metadata->fileSize,
            $metadata->extension,
            $metadata->digestSha1,
            $metadata->digestMd5,
        );

        $container = new Container(
            format: $quickTimeResolver->string(QuickTimeMeta::MAJOR_BRAND_KEY),
            encoder: CompositeResolver::first([
                fn (): ?string => $quickTimeResolver->string('com.apple.quicktime.encoder'),
                fn (): ?string => $quickTimeResolver->string('Encoder'),
            ]),
            bitrate: CompositeResolver::first([
                fn (): ?int => $quickTimeResolver->int('com.apple.quicktime.avgBitrate'),
                fn (): ?int => $quickTimeResolver->int('com.apple.quicktime.bitrate'),
                fn (): ?int => $quickTimeResolver->int('com.apple.quicktime.dataRate'),
                fn (): ?int => $quickTimeResolver->int('AvgBitrate'),
                fn (): ?int => $quickTimeResolver->int('Bitrate'),
            ]),
            videoCodec: CompositeResolver::first([
                fn (): ?string => $quickTimeResolver->string(QuickTimeMeta::COMPRESSOR_NAME_KEY),
                fn (): ?string => $quickTimeResolver->string(QuickTimeMeta::VIDEO_CODEC_KEY),
                fn (): ?string => $quickTimeResolver->string(QuickTimeMeta::HANDLER_DESCRIPTION_KEY),
            ]),
            audioCodec: CompositeResolver::first([
                fn (): ?string => $quickTimeResolver->string(QuickTimeMeta::AUDIO_FORMAT_KEY),
                fn (): ?string => $quickTimeResolver->string(QuickTimeMeta::AUDIO_CODEC_KEY),
            ]),
        );

        $preview = new Preview(null, null, null, null);

        $video = new Video(
            durationSec: $quickTimeResolver->float('com.apple.quicktime.duration'),
            frameRate: $quickTimeResolver->float('com.apple.quicktime.videoFrameRate'),
            width: $quickTimeResolver->int(QuickTimeMeta::VIDEO_WIDTH_KEY),
            height: $quickTimeResolver->int(QuickTimeMeta::VIDEO_HEIGHT_KEY),
            codec: CompositeResolver::first([
                fn (): ?string => $quickTimeResolver->string(QuickTimeMeta::COMPRESSOR_NAME_KEY),
                fn (): ?string => $quickTimeResolver->string(QuickTimeMeta::VIDEO_CODEC_KEY),
            ]),
            hdr: $quickTimeResolver->bool('com.apple.quicktime.hdrFormat'),
            transferFunction: $quickTimeResolver->string('com.apple.quicktime.transferFunction'),
            colorPrimaries: $quickTimeResolver->string('com.apple.quicktime.colorPrimaries'),
        );

        $audio = new Audio(
            channels: $quickTimeResolver->int(QuickTimeMeta::AUDIO_CHANNELS_KEY),
            sampleRate: $quickTimeResolver->int(QuickTimeMeta::AUDIO_SAMPLE_RATE_KEY),
            codec: CompositeResolver::first([
                fn (): ?string => $quickTimeResolver->string(QuickTimeMeta::AUDIO_FORMAT_KEY),
                fn (): ?string => $quickTimeResolver->string(QuickTimeMeta::AUDIO_CODEC_KEY),
            ]),
            bitDepth: $quickTimeResolver->int(QuickTimeMeta::AUDIO_BITS_PER_SAMPLE_KEY),
        );

        $iccData = null;
        if ($metadata->iccProfile !== null || $metadata->iccSegments !== []) {
            $iccData = (new IccDecoder())->decode($metadata->iccProfile, $metadata->iccSegments);
        }

        $colorProfile = new ColorProfile(
            profileName: $iccData['description'] ?? null,
            profileVersion: $iccData['version'] ?? null,
            pcs: $iccData['pcs'] ?? null,
            renderingIntent: $iccData['renderingIntent'] ?? null,
            gamma: $exifResolver->gamma(),
            profileId: $iccData['profileId'] ?? null,
        );

        $processing = new ProcessingSettings(
            sharpness: null,
            contrast: null,
            saturation: null,
            pictureStyle: null,
            noiseReduction: $exifResolver->noiseReduction(),
            clarity: null,
            customRendered: $exifResolver->customRendered()?->value,
            deviceSettingDescription: $exifResolver->deviceSettingDescription(),
        );

        $whiteBalanceKelvin = $apple->colorTemperature;
        if ($whiteBalanceKelvin === null) {
            $whiteBalanceKelvin = $this->quickTimeInt($quickTimeResolver, 'ColorTemperature');
        }

        $whiteBalanceDetails = new WhiteBalanceDetails(
            mode: $exifResolver->whiteBalance(),
            kelvin: $whiteBalanceKelvin,
            rgGain: null,
            bgGain: null,
        );

        $rect      = null;
        $focusRect = $exifResolver->subjectArea();
        if ($focusRect !== null) {
            $rect = ValueConverters::subjectAreaToRect($focusRect);
        }

        if ($rect === null) {
            $location = $exifResolver->subjectLocation();
            $rect     = ($location !== null && count($location) >= 2)
                ? ['x' => $location[0], 'y' => $location[1], 'w' => null, 'h' => null]
                : ['x' => null, 'y' => null, 'w' => null, 'h' => null];
        }

        $focus = new Focus(
            subjectDistanceM: $exifResolver->subjectDistance(),
            subjectAreaX: $rect['x'],
            subjectAreaY: $rect['y'],
            subjectAreaW: $rect['w'],
            subjectAreaH: $rect['h'],
            afMode: null,
        );

        $motion = $this->buildMotion($apple);

        $regions = $regionsResolver->resolve($xmpDocument);

        $scene = $this->buildScene(
            $exifResolver,
            $quickTimeResolver,
            $apple,
            $this->countFaceRegions($regions),
        );

        $flatKeywords         = $xmpResolver->stringList('http://purl.org/dc/elements/1.1/', 'subject');
        $hierarchicalKeywords = $xmpResolver->stringList('http://ns.adobe.com/lightroom/1.0/', 'hierarchicalSubject');
        $keywords             = new Keywords(
            flat: $flatKeywords,
            hierarchical: $hierarchicalKeywords !== [] ? $hierarchicalKeywords : null,
        );

        $rights = new Rights(
            copyright: CompositeResolver::first([
                fn (): ?string => $xmpResolver->string('http://purl.org/dc/elements/1.1/', 'rights'),
                $exifResolver->artist(...),
            ]),
            usageTerms: $xmpResolver->string('http://ns.adobe.com/xap/1.0/rights/', 'UsageTerms'),
            licenseUrl: $xmpResolver->string('http://ns.adobe.com/xap/1.0/rights/', 'WebStatement'),
            creditLine: $xmpResolver->string('http://ns.adobe.com/photoshop/1.0/', 'Credit'),
            securityClassification: $exifResolver->securityClassification(),
        );

        $author = new Author(
            artist: $exifResolver->artist(),
            ownerName: CompositeResolver::first([
                $exifResolver->ownerName(...),
                fn (): ?string => $xmpResolver->string('http://ns.adobe.com/xap/1.0/aux/', 'OwnerName'),
            ]),
            creator: CompositeResolver::first([
                fn (): ?string => $this->firstListValue($xmpResolver->stringList('http://purl.org/dc/elements/1.1/', 'creator')),
            ]),
            creatorEmail: $xmpResolver->string('http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/', 'CreatorContactInfo/Iptc4xmpCore:CiEmailWork'),
            photographer: $exifResolver->photographer(),
            imageEditor: $exifResolver->imageEditor(),
        );

        $temporal = $this->buildTemporal($exifResolver, $quickTimeResolver, $xmpResolver);

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

        $panoramaFlag = $xmpResolver->bool('http://ns.google.com/photos/1.0/panorama/', 'UsePanoramaViewer');
        $related      = new RelatedAssets(
            livePhotoPairId: $metadata->quickTime?->contentIdentifier(),
            burstId: $quickTimeResolver->string('BurstUUID'),
            isPrimaryInBurst: $quickTimeResolver->bool('BurstSelected'),
            panoramaId: $panoramaFlag === true ? 'panorama' : null,
            depthDataId: $quickTimeResolver->string('DepthData'),
            relatedSoundFile: $exifResolver->relatedSoundFile(),
        );

        $sensor = new Sensor(
            pixelPitchUm: null,
            cfaWidth: null,
            cfaHeight: null,
            sensorType: null,
            ibis: null,
            cfaPattern: $exifResolver->cfaPattern(),
            spectralSensitivity: $exifResolver->spectralSensitivity(),
            oecf: $exifResolver->oecf(),
            spatialFrequencyResponse: $exifResolver->spatialFrequencyResponse(),
            focalPlaneXResolution: $exifResolver->focalPlaneXResolution(),
            focalPlaneYResolution: $exifResolver->focalPlaneYResolution(),
            focalPlaneResolutionUnit: $exifResolver->focalPlaneResolutionUnit(),
        );

        $uav = new Uav(null, null, null, null, null, null, null, null);

        $hasHistory = $xmpResolver->has('http://ns.adobe.com/xap/1.0/mm/', 'History');
        $integrity  = new Integrity(
            originalFileName: $xmpResolver->string('http://ns.adobe.com/tiff/1.0/', 'OriginalFileName'),
            originalDigest: null,
            edited: $hasHistory ? true : null,
            historyLastSoftware: null,
            imageHistory: $exifResolver->imageHistory(),
        );

        return new StructuredMetadata(
            interop: $interop,
            tiff: $tiff,
            composite: $composite,
            standards: $standards,
            flashPix: $flashPix,
            camera: $camera,
            lens: $lens,
            image: $image,
            exposure: $exposure,
            capture: $capture,
            gps: $gps,
            device: $device,
            apple: $apple,
            xmp: $xmp,
            file: $file,
            container: $container,
            preview: $preview,
            video: $video,
            audio: $audio,
            colorProfile: $colorProfile,
            processing: $processing,
            whiteBalanceDetails: $whiteBalanceDetails,
            focus: $focus,
            motion: $motion,
            scene: $scene,
            regions: $regions,
            keywords: $keywords,
            rights: $rights,
            author: $author,
            temporal: $temporal,
            derived: $derived,
            related: $related,
            sensor: $sensor,
            uav: $uav,
            integrity: $integrity,
        );
    }

    /**
     * Builds the device metadata aggregate by combining EXIF and QuickTime sources.
     *
     * @param ExifTagResolver   $exif              Resolver exposing EXIF tag helpers.
     * @param QuickTimeResolver $quickTimeResolver Resolver exposing QuickTime metadata fields.
     *
     * @return Device Device value object describing capture hardware and software.
     */
    private function buildDevice(ExifTagResolver $exif, QuickTimeResolver $quickTimeResolver): Device
    {
        $softwareChain = CompositeResolver::first([
            fn (): ?string => $quickTimeResolver->string('com.apple.quicktime.software'),
            $exif->software(...),
        ]);

        return new Device(
            software: $softwareChain,
            rawDevelopingSoftware: $exif->rawDevelopingSoftware(),
            imageEditingSoftware: $exif->imageEditingSoftware(),
            metadataEditingSoftware: $exif->metadataEditingSoftware(),
        );
    }

    /**
     * Builds the temporal value object derived from EXIF, QuickTime and XMP data.
     *
     * Fractional seconds are mirrored into the generic field to keep display values consistent
     * whenever only the original or digitized timestamp carries sub-second precision.
     *
     * @param ExifTagResolver   $resolver  Resolver exposing EXIF timestamps and offsets.
     * @param QuickTimeResolver $quickTime QuickTime metadata resolver used for time fallbacks.
     * @param XmpResolver       $xmp       Resolver providing XMP timestamp fields.
     *
     * @return Temporal Normalised temporal metadata aggregate.
     */
    private function buildTemporal(ExifTagResolver $resolver, QuickTimeResolver $quickTime, XmpResolver $xmp): Temporal
    {
        $exifCreate = $resolver->date('DateTimeDigitized');
        $exifModify = $resolver->date('DateTime');

        $xmpCreate       = $this->parseFlexibleDate($xmp->string('http://ns.adobe.com/xap/1.0/', 'CreateDate'));
        $xmpModify       = $this->parseFlexibleDate($xmp->string('http://ns.adobe.com/xap/1.0/', 'ModifyDate'));
        $xmpDateCreated  = $this->parseFlexibleDate($xmp->string('http://ns.adobe.com/photoshop/1.0/', 'DateCreated'));
        $quickTimeCreate = $this->parseFlexibleDate($quickTime->string('CreationDate'));
        $quickTimeModify = $this->parseFlexibleDate($quickTime->string('ModifyDate'));

        $create = $exifCreate ?? $xmpCreate ?? $quickTimeCreate ?? $xmpDateCreated;
        $modify = $exifModify ?? $xmpModify ?? $quickTimeModify;

        [$original, $tz, $subOriginalRaw] = CompositeResolver::dateOriginal($resolver, ValueConverters::class);

        $originalWithTz = $original;
        if ($original instanceof DateTimeImmutable && $tz instanceof DateTimeZone) {
            $originalWithTz = $original->setTimezone($tz);
        }

        $offsetTime          = $resolver->string('OffsetTime');
        $offsetTimeOriginal  = $resolver->string('OffsetTimeOriginal');
        $offsetTimeDigitized = $resolver->string('OffsetTimeDigitized');

        $subSecTime         = $this->sanitizeSubSeconds($resolver->string('SubSecTime'));
        $subSecTimeDigitized = $this->sanitizeSubSeconds($resolver->string('SubSecTimeDigitized'));
        $subSecOriginal     = $this->sanitizeSubSeconds($subOriginalRaw);

        if ($subSecTime === null) {
            $subSecTime = $subSecOriginal ?? $subSecTimeDigitized;
        }

        $timeZoneOffsets = $resolver->ints('TimeZoneOffset');

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
     * Builds a camera value object using EXIF metadata.
     *
     * @param ExifTagResolver $exif Resolver exposing camera related EXIF tags.
     *
     * @return Camera Normalised camera metadata aggregate.
     */
    private function buildCamera(ExifTagResolver $exif): Camera
    {
        $profile = (float) $exif->exifProfile();

        $firmwareCandidates = [
            $exif->cameraFirmware(...),
        ];

        if ($profile < 3.0) {
            $firmwareCandidates[] = $exif->cameraFirmwareVersion(...);
        }

        $firmwareCandidates[] = $exif->software(...);

        return new Camera(
            make: $exif->cameraMake(),
            model: $exif->cameraModel(),
            ownerName: $exif->ownerName(),
            serialNumber: $exif->bodySerialNumber(),
            firmware: CompositeResolver::first($firmwareCandidates),
            fileSource: $exif->fileSource(),
            sensingMethod: $exif->sensingMethod(),
        );
    }

    /**
     * Builds a lens value object using EXIF metadata.
     *
     * @param ExifTagResolver $exif Resolver exposing lens specific EXIF tags.
     *
     * @return Lens Normalised lens metadata aggregate.
     */
    private function buildLens(ExifTagResolver $exif): Lens
    {
        $maxApex = ValueConverters::rationalToFloat($exif->rational('MaxApertureValue'));
        $maxF    = $maxApex !== null ? ValueConverters::apexToFNumber($maxApex) : null;

        return new Lens(
            lensMake: $exif->lensMake(),
            lensModel: $exif->lensModel(),
            lensSerialNumber: $exif->lensSerialNumber(),
            focalLengthMm: $exif->focalLength(),
            focalLengthIn35mm: $exif->focalLength35mm(),
            maxApertureFNumber: $maxF,
            lensSpecification: $exif->lensSpecification(),
        );
    }

    /**
     * Builds the image value object using EXIF metadata.
     *
     * @param Metadata        $metadata Metadata container supplying JPEG frame fallbacks.
     * @param ExifTagResolver $exif      Resolver exposing image related EXIF tags.
     *
     * @return Image Normalised image metadata aggregate.
     */
    private function buildImage(Metadata $metadata, ExifTagResolver $exif): Image
    {
        [$width, $height] = CompositeResolver::dimensions($exif);

        if ($width === null) {
            $width = $metadata->jpegFrameWidth;
        }

        if ($height === null) {
            $height = $metadata->jpegFrameHeight;
        }

        $orientation = $exif->orientation();

        $bitsPerSample = $exif->bitsPerSample();
        if ($bitsPerSample === null) {
            $bitsPerSample = $metadata->jpegBitsPerSample;
        }

        return new Image(
            width: $width,
            height: $height,
            orientation: $orientation,
            bitsPerSample: $bitsPerSample,
            colorSpace: $this->normalizedColorSpace($exif),
            imageUniqueId: $exif->imageUniqueId(),
            imageNumber: $exif->imageNumber(),
            documentName: null,
            description: $exif->imageDescription(),
            title: $exif->imageTitle(),
            componentsConfiguration: $exif->componentsConfiguration(),
            compressedBitsPerPixel: $exif->compressedBitsPerPixel(),
            interlace: $exif->interlace(),
            userComment: $exif->userComment(),
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
     * @param ExifTagResolver   $exif      Resolver exposing EXIF scene metadata.
     * @param QuickTimeResolver $quickTime Resolver providing QuickTime scene hints.
     * @param Apple             $apple     Aggregated Apple maker note metadata.
     * @param int|null          $faceCount Number of detected face regions.
     *
     * @return Scene Scene metadata value object.
     */
    private function buildScene(
        ExifTagResolver $exif,
        QuickTimeResolver $quickTime,
        Apple $apple,
        ?int $faceCount,
    ): Scene {
        $flags = $apple->flags;
        if (!is_array($flags)) {
            $flags = [];
        }

        $hdr   = $apple->hdrImageType;
        if ($hdr === null) {
            $hdr = $quickTime->string('HDRImageType');
        }
        $night = $quickTime->bool('NightMode');
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
            type: $exif->sceneCaptureType(),
            sceneType: $exif->sceneType(),
            light: $exif->lightSource(),
            faceCount: $faceCount,
            hdrScene: $hdrScene,
            nightMode: $night,
            subjectDistanceRange: $exif->subjectDistanceRange(),
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

        return in_array($normalized, self::APPLE_HDR_SCENE_LABELS, true);
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
     * Builds the Apple metadata aggregate by combining maker note values with QuickTime fallbacks.
     *
     * @param AppleMakerNotes|null $makerNotes        Parsed Apple maker note payload.
     * @param QuickTimeResolver    $quickTimeResolver Resolver exposing QuickTime metadata entries.
     * @param QuickTimeMeta|null   $quickTimeMeta     QuickTime metadata container used for content identifiers.
     *
     * @return Apple Apple metadata value object with normalised fields.
     */
    private function buildApple(
        ?AppleMakerNotes $makerNotes,
        QuickTimeResolver $quickTimeResolver,
        ?QuickTimeMeta $quickTimeMeta,
    ): Apple {
        $contentIdentifier = $makerNotes?->contentIdentifier;
        if ($contentIdentifier === null) {
            $contentIdentifier = $quickTimeMeta?->contentIdentifier();
        }

        $cameraType = $makerNotes?->cameraType;
        if ($cameraType === null) {
            $cameraType = $this->quickTimeString($quickTimeResolver, 'CameraType');
        }

        $hdrHeadroom = $makerNotes?->hdrHeadroom;
        if ($hdrHeadroom === null) {
            $hdrHeadroom = $this->quickTimeFloat($quickTimeResolver, 'HdrHeadroom', 'HDRHeadroom');
        }

        $hdrGain = $makerNotes?->hdrGain;
        if ($hdrGain === null) {
            $hdrGain = $this->quickTimeFloatList($quickTimeResolver, 'HdrGain', 'HDRGain');
        }

        $snr = $makerNotes?->snr;
        if ($snr === null) {
            $snr = $this->quickTimeFloat($quickTimeResolver, 'SNRSetting', 'SNR');
        }

        $focusPosition = $makerNotes?->focusPosition;
        if ($focusPosition === null) {
            $focusPosition = $this->quickTimeFloat($quickTimeResolver, 'FocusPosition');
        }

        $livePhotoIndex = $makerNotes?->livePhotoIndex;
        if ($livePhotoIndex === null) {
            $livePhotoIndex = $this->quickTimeInt($quickTimeResolver, 'LivePhotoVideoIndex', 'LivePhotoMovieIndex');
        }

        $livePhotoTime = $makerNotes?->livePhotoTime;

        $colorTemperature = $makerNotes?->colorTemperature;
        if ($colorTemperature === null) {
            $colorTemperature = $this->quickTimeInt($quickTimeResolver, 'ColorTemperature');
        }

        $semanticPreset = $makerNotes?->semanticStylePreset;
        if ($semanticPreset === null) {
            $semanticPreset = $this->quickTimeString($quickTimeResolver, 'SemanticStylePreset');
        }

        $semanticWarmth = $makerNotes?->semanticStyleWarmth;
        if ($semanticWarmth === null) {
            $semanticWarmth = $this->quickTimeFloat($quickTimeResolver, 'SemanticStyleWarmth');
        }

        $semanticTone = $makerNotes?->semanticStyleTone;
        if ($semanticTone === null) {
            $semanticTone = $this->quickTimeFloat($quickTimeResolver, 'SemanticStyleTone');
        }

        $semanticStyleComposite = $this->quickTimeSemanticStyle($quickTimeMeta);
        if ($semanticStyleComposite !== null) {
            [$compositePreset, $compositeWarmth, $compositeTone] = $semanticStyleComposite;

            if ($semanticPreset === null && $compositePreset !== null) {
                $semanticPreset = $compositePreset;
            }

            if ($semanticWarmth === null && $compositeWarmth !== null) {
                $semanticWarmth = $compositeWarmth;
            }

            if ($semanticTone === null && $compositeTone !== null) {
                $semanticTone = $compositeTone;
            }
        }

        $accelerationVector = $makerNotes?->accelerationVector;
        if ($accelerationVector === null) {
            $accelerationVector = $this->quickTimeFloatList($quickTimeResolver, 'AccelerationVector');
        }

        $flags          = $makerNotes instanceof AppleMakerNotes ? $makerNotes->flags : [];
        $quickTimeFlags = $this->quickTimeFlags($quickTimeResolver);
        foreach ($quickTimeFlags as $key => $value) {
            if (!array_key_exists($key, $flags)) {
                $flags[$key] = $value;
            }
        }

        $runTime = $this->appleRunTime($makerNotes?->runTime);

        $makerNoteVersion = $makerNotes?->makerNoteVersion;
        if ($makerNoteVersion === null) {
            $makerNoteVersion = $this->quickTimeString($quickTimeResolver, 'MakerNoteVersion');
        }

        $hdrImageType = $this->normalizeEnumerated($makerNotes?->hdrImageType, self::APPLE_HDR_IMAGE_TYPE_MAP);
        if ($hdrImageType === null) {
            $hdrImageType = $this->quickTimeEnumerated($quickTimeResolver, self::APPLE_HDR_IMAGE_TYPE_MAP, 'HDRImageType', 'HdrImageType');
        }

        $burstUuid = $makerNotes?->burstUuid;
        if ($burstUuid === null) {
            $burstUuid = $this->quickTimeString($quickTimeResolver, 'BurstUUID');
        }

        $focusDistanceRange = $makerNotes?->focusDistanceRange;
        if ($focusDistanceRange === null) {
            $focusDistanceRange = $this->quickTimeFocusDistanceRange($quickTimeResolver);
        }

        $oisMode = $makerNotes?->oisMode;
        if ($oisMode === null) {
            $oisMode = $this->quickTimeStringOrNumeric($quickTimeResolver, 'OISMode');
        }

        $imageCaptureType = $this->normalizeEnumerated($makerNotes?->imageCaptureType, self::APPLE_IMAGE_CAPTURE_TYPE_MAP);
        if ($imageCaptureType === null) {
            $imageCaptureType = $this->quickTimeEnumerated($quickTimeResolver, self::APPLE_IMAGE_CAPTURE_TYPE_MAP, 'ImageCaptureType');
        }

        $imageUniqueId = $makerNotes?->imageUniqueId;
        if ($imageUniqueId === null) {
            $imageUniqueId = $this->quickTimeString($quickTimeResolver, 'ImageUniqueID');
        }

        $photoIdentifier = $makerNotes?->photoIdentifier;
        if ($photoIdentifier === null) {
            $photoIdentifier = $this->quickTimeString($quickTimeResolver, 'PhotoIdentifier');
        }

        $afMeasuredDepth = $makerNotes?->afMeasuredDepth;
        if ($afMeasuredDepth === null) {
            $afMeasuredDepth = $this->quickTimeFloat($quickTimeResolver, 'AFMeasuredDepth');
        }

        $afConfidence = $makerNotes?->afConfidence;
        if ($afConfidence === null) {
            $afConfidence = $this->quickTimeFloat($quickTimeResolver, 'AFConfidence');
        }

        return new Apple(
            $contentIdentifier,
            $cameraType,
            $hdrHeadroom,
            $hdrGain,
            $snr,
            $focusPosition,
            $livePhotoIndex,
            $livePhotoTime,
            $colorTemperature,
            $semanticPreset,
            $semanticWarmth,
            $semanticTone,
            $flags,
            $accelerationVector,
            $runTime,
            $makerNoteVersion,
            $hdrImageType,
            $burstUuid,
            $focusDistanceRange,
            $oisMode,
            $imageCaptureType,
            $imageUniqueId,
            $photoIdentifier,
            $afMeasuredDepth,
            $afConfidence,
        );
    }

    /**
     * Converts a maker note runtime structure into its curated representation.
     */
    private function appleRunTime(?AppleRunTime $runTime): ?ValueRunTime
    {
        if (!$runTime instanceof AppleRunTime) {
            return null;
        }

        return new ValueRunTime(
            $runTime->epoch,
            $runTime->timescale,
            $runTime->value,
            $runTime->flags,
        );
    }

    /**
     * Builds the motion metadata aggregate from the Apple acceleration vector.
     *
     * @param Apple $apple Aggregated Apple metadata composed from maker notes and QuickTime sources.
     *
     * @return Motion Motion metadata aggregate with per-axis acceleration.
     */
    private function buildMotion(Apple $apple): Motion
    {
        $vector = $apple->accelerationVector;

        $accelX = null;
        $accelY = null;
        $accelZ = null;

        if (is_array($vector)) {
            $accelX = $vector[0] ?? null;
            $accelY = $vector[1] ?? null;
            $accelZ = $vector[2] ?? null;
        }

        return new Motion(null, null, null, $accelX, $accelY, $accelZ, null, null, null);
    }

    /**
     * Resolves the first non-empty QuickTime string value from the supplied keys.
     *
     * @param QuickTimeResolver $resolver Resolver used to read QuickTime metadata keys.
     * @param string            ...$keys  Candidate metadata keys to inspect in order.
     *
     * @return string|null First matching string value or null when no value is present.
     */
    private function quickTimeString(QuickTimeResolver $resolver, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $resolver->string($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolves the first available QuickTime float value from the provided keys.
     *
     * @param QuickTimeResolver $resolver Resolver used to read QuickTime metadata keys.
     * @param string            ...$keys  Candidate metadata keys to inspect in order.
     *
     * @return float|null First matching float value or null when no value is present.
     */
    private function quickTimeFloat(QuickTimeResolver $resolver, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            $value = $resolver->float($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolves the first available QuickTime integer value from the provided keys.
     *
     * @param QuickTimeResolver $resolver Resolver used to read QuickTime metadata keys.
     * @param string            ...$keys  Candidate metadata keys to inspect in order.
     *
     * @return int|null First matching integer value or null when no value is present.
     */
    private function quickTimeInt(QuickTimeResolver $resolver, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $value = $resolver->int($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolves a list of floating point values from a space or comma separated QuickTime field.
     *
     * @param QuickTimeResolver $resolver Resolver used to read QuickTime metadata keys.
     * @param string            ...$keys  Candidate metadata keys to inspect in order.
     *
     * @return list<float>|null Normalised list of float values or null when unavailable.
     */
    private function quickTimeFloatList(QuickTimeResolver $resolver, string ...$keys): ?array
    {
        $raw = $this->quickTimeString($resolver, ...$keys);
        if ($raw === null) {
            return null;
        }

        $parts = preg_split('/[\\s,]+/', $raw);
        if ($parts === false) {
            return null;
        }

        $values = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (is_numeric($part)) {
                $values[] = (float) $part;
            }
        }

        return $values !== [] ? $values : null;
    }

    /**
     * @return list<float>|null
     */
    private function quickTimeFocusDistanceRange(QuickTimeResolver $resolver): ?array
    {
        $range = $this->quickTimeFloatList($resolver, 'FocusDistanceRange');
        if ($range !== null) {
            return $range;
        }

        $near = $this->quickTimeFloat($resolver, 'FocusDistanceRangeNear', 'FocusDistanceNear');
        $far  = $this->quickTimeFloat($resolver, 'FocusDistanceRangeFar', 'FocusDistanceFar');

        $values = [];
        if ($near !== null) {
            $values[] = $near;
        }

        if ($far !== null) {
            $values[] = $far;
        }

        return $values !== [] ? $values : null;
    }

    private function quickTimeStringOrNumeric(QuickTimeResolver $resolver, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->quickTimeString($resolver, $key);
            if ($value !== null) {
                return $value;
            }

            $intValue = $resolver->int($key);
            if ($intValue !== null) {
                return (string) $intValue;
            }

            $floatValue = $resolver->float($key);
            if ($floatValue !== null) {
                return (string) $floatValue;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $map
     */
    private function normalizeEnumerated(?string $value, array $map): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            $code = (int) $trimmed;

            return $map[$code] ?? $trimmed;
        }

        return $trimmed;
    }

    /**
     * @param array<int, string> $map
     */
    private function quickTimeEnumerated(QuickTimeResolver $resolver, array $map, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $string = $this->quickTimeString($resolver, $key);
            if ($string !== null) {
                if (is_numeric($string)) {
                    $code = (int) $string;

                    return $map[$code] ?? $string;
                }

                return $string;
            }

            $code = $resolver->int($key);
            if ($code !== null) {
                return $map[$code] ?? (string) $code;
            }
        }

        return null;
    }

    /**
     * Extracts semantic style values from QuickTime composite metadata entries.
     *
     * @return array{0:?string,1:?float,2:?float}|null
     */
    private function quickTimeSemanticStyle(?QuickTimeMeta $meta): ?array
    {
        if ($meta === null) {
            return null;
        }

        $value = $meta->keys['SemanticStyle'] ?? null;
        if (!is_array($value)) {
            return null;
        }

        /** @var array<int|string, mixed> $semantic */
        $semantic = $value;

        $entries = $this->normaliseSemanticStyleEntries($semantic);
        if ($entries === null) {
            return null;
        }

        $presetRaw      = $this->semanticStyleEntry($entries, 0);
        $legacyWarmth   = $this->semanticStyleEntry($entries, 1);
        $modernWarmth   = $legacyWarmth === null ? $this->semanticStyleEntry($entries, 2) : null;
        $warmthRaw      = $legacyWarmth ?? $modernWarmth;
        $toneRawLegacy  = $legacyWarmth !== null ? $this->semanticStyleEntry($entries, 2) : null;
        $toneRawModern  = $legacyWarmth === null ? $this->semanticStyleEntry($entries, 3, 2) : null;
        $toneRaw        = $toneRawLegacy ?? $toneRawModern;

        $preset = $this->semanticStylePreset($presetRaw);
        $warmth = $this->semanticStyleFloat($warmthRaw);
        $tone   = $this->semanticStyleFloat($toneRaw);

        if ($preset === null && $warmth === null && $tone === null) {
            return null;
        }

        return [$preset, $warmth, $tone];
    }

    /**
     * @param array<int|string, mixed> $semantic
     *
     * @return array<int|string, string|int|float|bool|null>|null
     */
    private function normaliseSemanticStyleEntries(array $semantic): ?array
    {
        if (!array_is_list($semantic)) {
            foreach (['values', 'Values'] as $key) {
                if (array_key_exists($key, $semantic) && is_array($semantic[$key])) {
                    /** @var array<int|string, mixed> $values */
                    $values = $semantic[$key];

                    return $this->normaliseSemanticStyleEntries($values);
                }
            }
        }

        return $semantic;
    }

    /**
     * @param array<int|string, string|int|float|bool|null> $entries
     */
    private function semanticStyleEntry(array $entries, int ...$indexes): string|int|float|bool|null
    {
        foreach ($indexes as $index) {
            $candidates = [$index, (string) $index, '_' . $index];
            foreach ($candidates as $key) {
                if (!array_key_exists($key, $entries)) {
                    continue;
                }

                $value = $entries[$key];
                if (is_array($value)) {
                    foreach (['value', 'Value'] as $innerKey) {
                        if (array_key_exists($innerKey, $value)) {
                            $inner = $value[$innerKey];
                            if (!is_array($inner)) {
                                $value = $inner;
                            }

                            break;
                        }
                    }

                    if (is_array($value)) {
                        continue;
                    }
                }

                if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function semanticStylePreset(string|int|float|bool|null $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private function semanticStyleFloat(string|int|float|bool|null $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value) || is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Builds a normalised map of QuickTime boolean flags relevant to Apple metadata.
     *
     * @param QuickTimeResolver $resolver Resolver used to read QuickTime metadata keys.
     *
     * @return array<string, bool> Normalised flag map keyed by camelCase identifiers.
     */
    private function quickTimeFlags(QuickTimeResolver $resolver): array
    {
        $flags = [];
        foreach (self::APPLE_FLAG_KEYS as $key => $normalized) {
            $value = $resolver->bool($key);
            if ($value !== null) {
                $flags[$normalized] = $value;
            }
        }

        return $flags;
    }

    /**
     * Normalises the colour space based on interoperability metadata hints.
     *
     * @param ExifTagResolver $resolver Resolver exposing colour space and interoperability tags.
     *
     * @return ColorSpace|null Normalised colour space enumeration or null when undefined.
     */
    private function normalizedColorSpace(ExifTagResolver $resolver): ?ColorSpace
    {
        $colorSpace = $resolver->enum('ColorSpace', ColorSpace::class);

        if ($colorSpace === ColorSpace::UNCALIBRATED) {
            $interopIndex = $resolver->string('InteropIndex');
            if ($interopIndex !== null && strtoupper($interopIndex) === 'R03') {
                return ColorSpace::ADOBE_RGB;
            }
        }

        return $colorSpace;
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
