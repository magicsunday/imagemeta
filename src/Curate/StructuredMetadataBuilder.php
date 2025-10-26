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
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Curate\Resolver\CompositeResolver;
use MagicSunday\ImageMeta\Curate\Resolver\AppleResolver;
use MagicSunday\ImageMeta\Curate\Resolver\ExifTagResolver;
use MagicSunday\ImageMeta\Curate\Resolver\GpsResolver;
use MagicSunday\ImageMeta\Curate\Resolver\MultiPictureResolver;
use MagicSunday\ImageMeta\Curate\Resolver\QuickTimeResolver;
use MagicSunday\ImageMeta\Curate\Resolver\RegionsResolver;
use MagicSunday\ImageMeta\Curate\Resolver\XmpResolver;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\Icc\IccDecoder;
use MagicSunday\ImageMeta\Value\AudioClips;
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

use function array_key_exists;
use function count;
use function is_array;
use function is_bool;
use function preg_replace;
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
     * @var list<string>
     */
    private const array APPLE_HDR_SCENE_LABELS = ['HDR', 'HDR2', 'HDR3'];

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
        $multiPictureResolver = new MultiPictureResolver();

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
            tileWidth: $exifResolver->tileWidth(),
            tileLength: $exifResolver->tileLength(),
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
            tileOffsets: $exifResolver->tileOffsets(),
            tileByteCounts: $exifResolver->tileByteCounts(),
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
            tiffEpStandardString: $exifResolver->tiffEpStandardIdString(),
        );

        $flashPix = new FlashPix($metadata->flashPixStreams);
        $multiPicture = $multiPictureResolver->resolve($metadata->mpfDocument);

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

        $device = $this->buildDevice($exifResolver, $quickTimeResolver, $xmpResolver);

        $apple = $this->buildApple($appleMakerNotes, $metadata->quickTime);
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

        $preview = new Preview(
            $exifResolver->hasThumbnail(),
            $exifResolver->hasPreviewImage(),
            $exifResolver->previewImageWidth(),
            $exifResolver->previewImageHeight(),
        );

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
            gamma: $exifResolver->gamma(),
            profileId: $iccData['profileId'] ?? null,
            cameraCalibrationSignature: $exifResolver->cameraCalibrationSignature(),
            profileCalibrationSignature: $exifResolver->profileCalibrationSignature(),
            hueSatMap: $exifResolver->profileHueSatMap(),
            lookTable: $exifResolver->profileLookTable(),
            toneCurve: $exifResolver->profileToneCurve(),
            gainMap: $exifResolver->profileGainMap(),
        );

        $processing = new ProcessingSettings(
            sharpness: $exifResolver->sharpness(),
            contrast: $exifResolver->contrast(),
            saturation: $exifResolver->saturation(),
            pictureStyle: null,
            noiseReduction: $exifResolver->noiseReduction(),
            clarity: null,
            customRendered: $exifResolver->customRendered()?->value,
            deviceSettingDescription: $exifResolver->deviceSettingDescription(),
            processingSoftware: $exifResolver->processingSoftware(),
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

        $motion = $this->buildMotion($exifResolver, $apple);

        $regions = $regionsResolver->resolve($xmpDocument);

        $scene = $this->buildScene(
            $exifResolver,
            $quickTimeResolver,
            $apple,
            $this->countFaceRegions($regions),
        );

        $flatKeywords         = $xmpResolver->stringList('http://purl.org/dc/elements/1.1/', 'subject');
        $hierarchicalKeywords = $xmpResolver->stringList('http://ns.adobe.com/lightroom/1.0/', 'hierarchicalSubject');

        if ($flatKeywords === []) {
            $xpKeywords = $exifResolver->xpKeywords();
            if ($xpKeywords !== null) {
                $flatKeywords = $xpKeywords;
            }
        }

        $keywords = new Keywords(
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
            cfaWidth: $exifResolver->cfaRepeatPatternWidth(),
            cfaHeight: $exifResolver->cfaRepeatPatternHeight(),
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

        $uav = $this->buildUav($exifResolver, $quickTimeResolver);

        $hasHistory      = $xmpResolver->has('http://ns.adobe.com/xap/1.0/mm/', 'History');
        $makerNotesSafe  = $metadata->makerNotes?->isSafe();
        if ($makerNotesSafe === null) {
            $makerNotesSafe = $exifResolver->makerNoteSafety();
        }

        $integrity = new Integrity(
            originalFileName: $xmpResolver->string('http://ns.adobe.com/tiff/1.0/', 'OriginalFileName'),
            originalDigest: null,
            edited: $hasHistory ? true : null,
            historyLastSoftware: null,
            imageHistory: $exifResolver->imageHistory(),
            makerNotesSafe: $makerNotesSafe,
        );

        return new StructuredMetadata(
            interop: $interop,
            tiff: $tiff,
            composite: $composite,
            standards: $standards,
            flashPix: $flashPix,
            multiPicture: $multiPicture,
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
            embeddedAudio: $embeddedAudio,
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
     * Builds the device metadata aggregate by combining EXIF, QuickTime and XMP sources.
     *
     * @param ExifTagResolver   $exif              Resolver exposing EXIF tag helpers.
     * @param QuickTimeResolver $quickTimeResolver Resolver exposing QuickTime metadata fields.
     * @param XmpResolver       $xmpResolver       Resolver exposing XMP metadata fields.
     *
     * @return Device Device value object describing capture hardware and software.
     */
    private function buildDevice(ExifTagResolver $exif, QuickTimeResolver $quickTimeResolver, XmpResolver $xmpResolver): Device
    {
        $softwareChain = CompositeResolver::first([
            fn (): ?string => $quickTimeResolver->string('com.apple.quicktime.software'),
            fn (): ?string => $xmpResolver->string('http://ns.adobe.com/xap/1.0/', 'CreatorTool'),
            $exif->software(...),
            $exif->hostComputer(...),
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
            serialNumber: $exif->cameraSerialNumber(),
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
            documentName: $exif->documentName(),
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
     * @param AppleMakerNotes    $apple     Aggregated Apple maker note metadata.
     * @param int|null          $faceCount Number of detected face regions.
     *
     * @return Scene Scene metadata value object.
     */
    private function buildScene(
        ExifTagResolver $exif,
        QuickTimeResolver $quickTime,
        AppleMakerNotes $apple,
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
     * @param AppleMakerNotes|null $makerNotes    Parsed Apple maker note payload.
     * @param QuickTimeMeta|null   $quickTimeMeta QuickTime metadata container.
     *
     * @return AppleMakerNotes Aggregated Apple metadata value object.
     */
    private function buildApple(
        ?AppleMakerNotes $makerNotes,
        ?QuickTimeMeta $quickTimeMeta,
    ): AppleMakerNotes {
        $quickTimeApple = (new AppleResolver())->resolve($quickTimeMeta);

        if ($makerNotes === null && $quickTimeApple === null) {
            return $this->emptyAppleMakerNotes();
        }

        if ($makerNotes === null) {
            return $quickTimeApple ?? $this->emptyAppleMakerNotes();
        }

        if ($quickTimeApple === null) {
            return $makerNotes;
        }

        return $this->mergeAppleMakerNotes($makerNotes, $quickTimeApple);
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

    private function mergeAppleMakerNotes(AppleMakerNotes $primary, AppleMakerNotes $secondary): AppleMakerNotes
    {
        $flags = $this->mergeAppleFlags($primary->flags, $secondary->flags);

        return new AppleMakerNotes(
            contentIdentifier: $primary->contentIdentifier ?? $secondary->contentIdentifier,
            cameraType: $primary->cameraType ?? $secondary->cameraType,
            hdrHeadroom: $primary->hdrHeadroom ?? $secondary->hdrHeadroom,
            hdrGain: $primary->hdrGain ?? $secondary->hdrGain,
            snr: $primary->snr ?? $secondary->snr,
            aeStable: $primary->aeStable ?? $secondary->aeStable,
            aeTarget: $primary->aeTarget ?? $secondary->aeTarget,
            aeAverage: $primary->aeAverage ?? $secondary->aeAverage,
            afStable: $primary->afStable ?? $secondary->afStable,
            afPerformance: $primary->afPerformance ?? $secondary->afPerformance,
            signalToNoiseRatioType: $primary->signalToNoiseRatioType ?? $secondary->signalToNoiseRatioType,
            luminanceNoiseAmplitude: $primary->luminanceNoiseAmplitude ?? $secondary->luminanceNoiseAmplitude,
            focusPosition: $primary->focusPosition ?? $secondary->focusPosition,
            livePhotoIndex: $primary->livePhotoIndex ?? $secondary->livePhotoIndex,
            colorTemperature: $primary->colorTemperature ?? $secondary->colorTemperature,
            semanticStylePreset: $primary->semanticStylePreset ?? $secondary->semanticStylePreset,
            semanticStyleWarmth: $primary->semanticStyleWarmth ?? $secondary->semanticStyleWarmth,
            semanticStyleTone: $primary->semanticStyleTone ?? $secondary->semanticStyleTone,
            flags: $flags,
            accelerationVector: $primary->accelerationVector ?? $secondary->accelerationVector,
            imageCaptureRequestId: $primary->imageCaptureRequestId ?? $secondary->imageCaptureRequestId,
            qualityHint: $primary->qualityHint ?? $secondary->qualityHint,
            colorCorrectionMatrix: $primary->colorCorrectionMatrix ?? $secondary->colorCorrectionMatrix,
            livePhotoTime: $primary->livePhotoTime ?? $secondary->livePhotoTime,
            runTime: $primary->runTime ?? $secondary->runTime,
            makerNoteVersion: $primary->makerNoteVersion ?? $secondary->makerNoteVersion,
            hdrImageType: $primary->hdrImageType ?? $secondary->hdrImageType,
            burstUuid: $primary->burstUuid ?? $secondary->burstUuid,
            focusDistanceRange: $primary->focusDistanceRange ?? $secondary->focusDistanceRange,
            oisMode: $primary->oisMode ?? $secondary->oisMode,
            imageCaptureType: $primary->imageCaptureType ?? $secondary->imageCaptureType,
            imageUniqueId: $primary->imageUniqueId ?? $secondary->imageUniqueId,
            photoIdentifier: $primary->photoIdentifier ?? $secondary->photoIdentifier,
            afMeasuredDepth: $primary->afMeasuredDepth ?? $secondary->afMeasuredDepth,
            afConfidence: $primary->afConfidence ?? $secondary->afConfidence,
        );
    }

    /**
     * @param array<string, bool> $primary
     * @param array<string, bool> $secondary
     *
     * @return array<string, bool>
     */
    private function mergeAppleFlags(array $primary, array $secondary): array
    {
        $flags = $primary;

        foreach ($secondary as $key => $value) {
            if (!array_key_exists($key, $flags)) {
                $flags[$key] = $value;
            }
        }

        return $flags;
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
     * Builds the motion metadata aggregate from EXIF and Apple motion sources.
     *
     * @param ExifTagResolver $exif  Resolver exposing EXIF camera orientation measurements.
     * @param AppleMakerNotes  $apple Aggregated Apple metadata composed from maker notes and QuickTime sources.
     *
     * @return Motion Motion metadata aggregate with camera orientation and per-axis acceleration.
     */
    private function buildMotion(ExifTagResolver $exif, AppleMakerNotes $apple): Motion
    {
        $rollDeg  = $exif->cameraRollDeg();
        $pitchDeg = $exif->cameraPitchDeg();
        $yawDeg   = $exif->cameraYawDeg();

        $vector = $apple->accelerationVector;

        if (!is_array($vector)) {
            $vector = $exif->accelerationVector();
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

    private function buildUav(ExifTagResolver $exif, QuickTimeResolver $quickTime): Uav
    {
        $manufacturer = $exif->aircraftMake();
        if ($manufacturer === null) {
            $manufacturer = $quickTime->string('com.apple.quicktime.make');
        }

        $model = $exif->aircraftModel();
        if ($model === null) {
            $model = $quickTime->string('com.apple.quicktime.model');
        }

        $flightYaw = $exif->flightYawDeg();
        if ($flightYaw === null) {
            $flightYaw = $quickTime->float('com.apple.quicktime.flightYawDegree');
        }

        $flightPitch = $exif->flightPitchDeg();
        if ($flightPitch === null) {
            $flightPitch = $quickTime->float('com.apple.quicktime.flightPitchDegree');
        }

        $flightRoll = $exif->flightRollDeg();
        if ($flightRoll === null) {
            $flightRoll = $quickTime->float('com.apple.quicktime.flightRollDegree');
        }

        $gimbalYaw = $exif->gimbalYawDeg();
        if ($gimbalYaw === null) {
            $gimbalYaw = $quickTime->float('com.apple.quicktime.gimbalYawDegree');
        }

        $gimbalPitch = $exif->gimbalPitchDeg();
        if ($gimbalPitch === null) {
            $gimbalPitch = $quickTime->float('com.apple.quicktime.gimbalPitchDegree');
        }

        $gimbalRoll = $exif->gimbalRollDeg();
        if ($gimbalRoll === null) {
            $gimbalRoll = $quickTime->float('com.apple.quicktime.gimbalRollDegree');
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
            if ($interopIndex !== null) {
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
