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
use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\Core\ValueConverters;
use MagicSunday\ImageMeta\Curate\Resolver\CompositeResolver;
use MagicSunday\ImageMeta\Curate\Resolver\ExifTagResolver;
use MagicSunday\ImageMeta\Curate\Resolver\QuickTimeResolver;
use MagicSunday\ImageMeta\Curate\Resolver\XmpResolver;
use MagicSunday\ImageMeta\Model\Metadata;
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

use function strtoupper;

/**
 * Builds the structured metadata aggregate by orchestrating specialised resolvers.
 */
final class StructuredMetadataBuilder
{
    /**
     * Builds the structured metadata aggregate from the supplied metadata container.
     */
    public function build(Metadata $metadata): StructuredMetadata
    {
        $exifResolver      = new ExifTagResolver($metadata->exifDoc);
        $xmpDocument       = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
        $xmpResolver       = new XmpResolver($xmpDocument);
        $quickTimeResolver = new QuickTimeResolver($metadata->quickTime);

        $interop = new Interop(index: $exifResolver->interopIndex());

        $tiff = new TiffData(
            samplesPerPixel: $exifResolver->samplesPerPixel(),
            rowsPerStrip: $exifResolver->rowsPerStrip(),
            compression: $exifResolver->compression(),
            photometric: $exifResolver->photometric(),
            planar: $exifResolver->planarConfiguration(),
            resolutionUnit: $exifResolver->resolutionUnit(),
            xResolution: $exifResolver->xResolution(),
            yResolution: $exifResolver->yResolution(),
            ycbcrPos: $exifResolver->ycbcrPositioning(),
            ycbcrSubSampling: $exifResolver->ycbcrSubSampling(),
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
        $profile     = ExifCapabilities::fromVersion($exifVersion);

        $standards = new Standards(
            exifVersion: $exifVersion,
            profile: $profile,
            flashpixVersion: $exifResolver->flashpixVersion(),
            tiffEpStandardId: $exifResolver->tiffEpStandardId(),
        );

        $camera = $this->buildCamera($exifResolver);
        $lens   = $this->buildLens($exifResolver);
        $image  = $this->buildImage($exifResolver);

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
            waterDepthM: $exifResolver->waterDepthMeters(),
            accelerationMs2: $exifResolver->accelerationMs2(),
            cameraElevationAngleDeg: $exifResolver->cameraElevationAngleDeg(),
            selfTimerModeSeconds: $exifResolver->selfTimerModeSeconds(),
        );

        $gpsCoords = $exifResolver->gps();
        $gps       = new Gps(
            $gpsCoords['lat'],
            $gpsCoords['lon'],
            $gpsCoords['alt'],
            $gpsCoords['speed_ms'] ?? null,
        );

        $device = $this->buildDevice($exifResolver, $quickTimeResolver);

        $apple = new Apple($metadata->quickTime?->contentIdentifier());
        $xmp   = $xmpResolver->value();

        $file = new File(null, null, null, null, null);

        $container = new Container(
            format: $quickTimeResolver->string('MajorBrand'),
            encoder: $quickTimeResolver->string('Encoder'),
            bitrate: CompositeResolver::first([
                fn () => $quickTimeResolver->int('AvgBitrate'),
                fn () => $quickTimeResolver->int('Bitrate'),
            ]),
            videoCodec: CompositeResolver::first([
                fn () => $quickTimeResolver->string('CompressorID'),
                fn () => $quickTimeResolver->string('HandlerDescription'),
            ]),
            audioCodec: CompositeResolver::first([
                fn () => $quickTimeResolver->string('AudioFormat'),
                fn () => $quickTimeResolver->string('AudioCodecID'),
            ]),
        );

        $preview = new Preview(null, null, null, null);

        $video = new Video(
            durationSec: $quickTimeResolver->float('Duration'),
            frameRate: $quickTimeResolver->float('VideoFrameRate'),
            width: CompositeResolver::first([
                fn () => $quickTimeResolver->int('ImageWidth'),
                fn () => $image->width,
            ]),
            height: CompositeResolver::first([
                fn () => $quickTimeResolver->int('ImageHeight'),
                fn () => $image->height,
            ]),
            codec: $quickTimeResolver->string('CompressorID'),
            hdr: $quickTimeResolver->bool('HDRFormat'),
            transferFunction: $quickTimeResolver->string('TransferFunction'),
            colorPrimaries: $quickTimeResolver->string('ColorPrimaries'),
        );

        $audio = new Audio(
            channels: $quickTimeResolver->int('AudioChannels'),
            sampleRate: $quickTimeResolver->int('AudioSampleRate'),
            codec: CompositeResolver::first([
                fn () => $quickTimeResolver->string('AudioFormat'),
                fn () => $quickTimeResolver->string('AudioCodecID'),
            ]),
            bitDepth: $quickTimeResolver->int('AudioBitsPerSample'),
        );

        $colorProfile = new ColorProfile(
            profileName: null,
            profileVersion: null,
            pcs: null,
            renderingIntent: null,
            gamma: $exifResolver->gamma(),
        );

        $processing = new ProcessingSettings(
            sharpness: null,
            contrast: null,
            saturation: null,
            pictureStyle: null,
            noiseReduction: null,
            clarity: null,
            customRendered: $exifResolver->customRendered()?->value,
            deviceSettingDescription: $exifResolver->deviceSettingDescription(),
        );

        $whiteBalanceDetails = new WhiteBalanceDetails(
            mode: $exifResolver->whiteBalance(),
            kelvin: null,
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

        $motion = new Motion(null, null, null, null, null, null, null, null, null);

        $scene = $this->buildScene($exifResolver, $quickTimeResolver);

        $regions = new Regions([]);

        $flatKeywords         = $xmpResolver->stringList('http://purl.org/dc/elements/1.1/', 'subject');
        $hierarchicalKeywords = $xmpResolver->stringList('http://ns.adobe.com/lightroom/1.0/', 'hierarchicalSubject');
        $keywords             = new Keywords(
            flat: $flatKeywords,
            hierarchical: $hierarchicalKeywords !== [] ? $hierarchicalKeywords : null,
        );

        $rights = new Rights(
            copyright: CompositeResolver::first([
                fn () => $xmpResolver->string('http://purl.org/dc/elements/1.1/', 'rights'),
                fn () => $exifResolver->artist(),
            ]),
            usageTerms: $xmpResolver->string('http://ns.adobe.com/xap/1.0/rights/', 'UsageTerms'),
            licenseUrl: $xmpResolver->string('http://ns.adobe.com/xap/1.0/rights/', 'WebStatement'),
            creditLine: $xmpResolver->string('http://ns.adobe.com/photoshop/1.0/', 'Credit'),
            securityClassification: $exifResolver->securityClassification(),
        );

        $author = new Author(
            artist: $exifResolver->artist(),
            ownerName: CompositeResolver::first([
                fn () => $exifResolver->ownerName(),
                fn () => $xmpResolver->string('http://ns.adobe.com/xap/1.0/aux/', 'OwnerName'),
            ]),
            creator: CompositeResolver::first([
                fn () => $this->firstListValue($xmpResolver->stringList('http://purl.org/dc/elements/1.1/', 'creator')),
            ]),
            creatorEmail: $xmpResolver->string('http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/', 'CreatorContactInfo/Iptc4xmpCore:CiEmailWork'),
            photographer: $exifResolver->photographer(),
            imageEditor: $exifResolver->imageEditor(),
        );

        $temporal = $this->buildTemporal($exifResolver, $quickTimeResolver, $xmpResolver);

        $cropFactor = ValueConverters::calcCropFactor($lens->focalLengthIn35mm, $lens->focalLengthMm);
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
            focalPlaneXResolution: $exifResolver->focalPlaneXResolution(),
            focalPlaneYResolution: $exifResolver->focalPlaneYResolution(),
            focalPlaneResolutionUnit: $exifResolver->focalPlaneResolutionUnit(),
        );

        $uav = new Uav(null, null, null, null, null, null, null, null);

        $hasHistory = $xmpResolver->has('http://ns.adobe.com/xap/1.0/mm/', 'History');
        $integrity  = new Integrity(
            originalFileName: $xmpResolver->string('http://ns.adobe.com/tiff/1.0/', 'OriginalFileName'),
            originalDigest: null,
            edited: $hasHistory === true ? true : null,
            historyLastSoftware: null,
            imageHistory: $exifResolver->imageHistory(),
        );

        return new StructuredMetadata(
            interop: $interop,
            tiff: $tiff,
            composite: $composite,
            standards: $standards,
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
     * Builds a device value object using container level metadata.
     */
    private function buildDevice(ExifTagResolver $exif, QuickTimeResolver $quickTimeResolver): Device
    {
        $softwareChain = CompositeResolver::first([
            fn () => $quickTimeResolver->string('com.apple.quicktime.software'),
            fn () => $exif->software(),
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
        $subSecDigitizedRaw = $this->sanitizeSubSeconds($resolver->string('SubSecTimeDigitized'));
        $subSecOriginal     = $this->sanitizeSubSeconds($subOriginalRaw);

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
            subSecTimeDigitized: $subSecDigitizedRaw,
            timeZoneOffsetMinutes: $timeZoneOffsets,
        );
    }

    /**
     * Builds a camera value object using EXIF metadata.
     */
    private function buildCamera(ExifTagResolver $exif): Camera
    {
        return new Camera(
            make: $exif->cameraMake(),
            model: $exif->cameraModel(),
            ownerName: $exif->ownerName(),
            serialNumber: $exif->bodySerialNumber(),
            firmware: CompositeResolver::first([
                fn () => $exif->cameraFirmware(),
                fn () => $exif->cameraFirmwareVersion(),
                fn () => $exif->software(),
            ]),
            fileSource: $exif->fileSource(),
            sensingMethod: $exif->sensingMethod(),
        );
    }

    /**
     * Builds a lens value object using EXIF metadata.
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
     */
    private function buildImage(ExifTagResolver $exif): Image
    {
        [$width, $height] = CompositeResolver::dimensions($exif);

        return new Image(
            width: $width,
            height: $height,
            orientation: $exif->orientation(),
            bitsPerSample: $exif->bitsPerSample(),
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
     * Builds the scene value object incorporating EXIF and container hints.
     */
    private function buildScene(ExifTagResolver $exif, QuickTimeResolver $quickTime): Scene
    {
        $hdr   = $quickTime->string('HDRImageType');
        $night = $quickTime->bool('NightMode');

        return new Scene(
            type: $exif->sceneCaptureType(),
            sceneType: $exif->sceneType()?->value,
            light: $exif->lightSource(),
            faceCount: null,
            hdrScene: $hdr !== null ? true : null,
            nightMode: $night,
            subjectDistanceRange: $exif->subjectDistanceRange(),
        );
    }

    /**
     * Normalises the colour space based on interoperability metadata hints.
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
     * @param list<string> $values
     */
    private function firstListValue(array $values): ?string
    {
        return $values[0] ?? null;
    }

    /**
     * Normalises EXIF fractional second strings.
     */
    private function sanitizeSubSeconds(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /**
     * Attempts to parse various ISO 8601 date representations.
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
