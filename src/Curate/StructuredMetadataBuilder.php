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
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
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
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;

use function array_map;
use function explode;
use function is_numeric;
use function is_string;
use function str_contains;
use function sprintf;
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

        $camera = $this->buildCamera($exifResolver, $xmpResolver, $quickTimeResolver);
        $lens   = $this->buildLens($exifResolver, $xmpResolver);
        $image  = $this->buildImage($exifResolver, $xmpResolver, $quickTimeResolver, $interop);

        if (
            $image->colorSpace === ColorSpace::UNCALIBRATED
            && $interop->index !== null
            && strtoupper($interop->index) === 'R03'
        ) {
            $image = new Image(
                width: $image->width,
                height: $image->height,
                orientation: $image->orientation,
                bitsPerSample: $image->bitsPerSample,
                colorSpace: ColorSpace::SRGB,
                imageUniqueId: $image->imageUniqueId,
                imageNumber: $image->imageNumber,
                documentName: $image->documentName,
                description: $image->description,
                title: $image->title,
                componentsConfiguration: $image->componentsConfiguration,
                compressedBitsPerPixel: $image->compressedBitsPerPixel,
                interlace: $image->interlace,
                userComment: $image->userComment,
            );
        }

        $exposure = new Exposure(
            iso: CompositeResolver::intISO([
                fn () => $exifResolver->iso(),
            ]),
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

        $flatKeywords          = $xmpResolver->stringList('http://purl.org/dc/elements/1.1/', 'subject');
        $hierarchicalKeywords  = $xmpResolver->stringList('http://ns.adobe.com/lightroom/1.0/', 'hierarchicalSubject');
        $keywords = new Keywords(
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

        $derived = new Derived(
            ev100: ValueConverters::calcEv100(
                $exposure->exposureTimeSec,
                $exposure->fNumber,
                $exposure->iso,
            ),
            hyperfocalM: ValueConverters::calcHyperfocalM(
                $lens->focalLengthMm,
                $exposure->fNumber,
                0.029,
            ),
            fovDeg: ValueConverters::calcFovDeg($lens->focalLengthIn35mm, null),
            focalLength35mm: $lens->focalLengthIn35mm,
            cropFactor: null,
        );

        $panoramaFlag = $xmpResolver->bool('http://ns.google.com/photos/1.0/panorama/', 'UsePanoramaViewer');
        $related = new RelatedAssets(
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
        $integrity = new Integrity(
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
        $exifCreate = $resolver->digitizedDateTime();
        $exifModify = $resolver->fileDateTime();

        $exifOriginal = CompositeResolver::dateOriginal([
            'DateTimeOriginal'  => fn () => $resolver->captureDateTime(),
            'DateTimeDigitized' => fn () => $exifCreate,
            'DateTime'          => fn () => $exifModify,
        ]);

        $original         = $exifOriginal['date'];
        $fallbackTz       = $original instanceof DateTimeImmutable ? $original->getTimezone() : null;
        $fallbackTzSource = is_string($exifOriginal['source']) ? $exifOriginal['source'] : null;

        $offsetTime          = $resolver->offsetTime();
        $offsetTimeOriginal  = $resolver->offsetTimeOriginal();
        $offsetTimeDigitized = $resolver->offsetTimeDigitized();
        $subSecTime          = $resolver->subSecTime();
        $subSecTimeOriginal  = $resolver->subSecTimeOriginal();
        $subSecTimeDigitized = $resolver->subSecTimeDigitized();
        $timeZoneOffsets     = $resolver->timeZoneOffsetMinutes();

        $create = $exifCreate ?? $this->parseFlexibleDate($xmp->string('http://ns.adobe.com/xap/1.0/', 'CreateDate'));
        $create = $create ?? $this->parseFlexibleDate($quickTime->string('CreationDate'));

        $modify = $exifModify ?? $this->parseFlexibleDate($xmp->string('http://ns.adobe.com/xap/1.0/', 'ModifyDate'));
        $modify = $modify ?? $this->parseFlexibleDate($quickTime->string('ModifyDate'));

        if ($original === null) {
            $original = $this->parseFlexibleDate($xmp->string('http://ns.adobe.com/photoshop/1.0/', 'DateCreated'));
            if ($original instanceof DateTimeImmutable) {
                $fallbackTz       = $original->getTimezone();
                $fallbackTzSource = 'XMP';
            }
        }

        if ($original === null) {
            $quickTimeDate = $this->parseFlexibleDate($quickTime->string('CreationDate'));
            if ($quickTimeDate instanceof DateTimeImmutable) {
                $original        = $quickTimeDate;
                $fallbackTz       = $quickTimeDate->getTimezone();
                $fallbackTzSource = 'QuickTime';
            }
        }

        if ($original === null && $create instanceof DateTimeImmutable) {
            $original         = $create;
            $fallbackTz       = $create->getTimezone();
            $fallbackTzSource = 'DateTimeDigitized';
        }

        if ($original === null && $modify instanceof DateTimeImmutable) {
            $original         = $modify;
            $fallbackTz       = $modify->getTimezone();
            $fallbackTzSource = 'DateTime';
        }

        $tz       = null;
        $tzSource = null;

        $offsetCandidates = [
            'OffsetTimeOriginal'  => $offsetTimeOriginal,
            'OffsetTimeDigitized' => $offsetTimeDigitized,
            'OffsetTime'          => $offsetTime,
        ];

        foreach ($offsetCandidates as $source => $offset) {
            $candidate = ValueConverters::parseOffset($offset);
            if ($candidate instanceof DateTimeZone) {
                $tz       = $candidate;
                $tzSource = $source;
                break;
            }
        }

        if ($tz === null && is_array($timeZoneOffsets) && $timeZoneOffsets !== []) {
            $candidate = $this->timeZoneFromMinutes($timeZoneOffsets[0]);
            if ($candidate instanceof DateTimeZone) {
                $tz       = $candidate;
                $tzSource = 'TimeZoneOffset';
            }
        }

        if ($tz === null && $fallbackTz instanceof DateTimeZone) {
            $tz       = $fallbackTz;
            $tzSource = $fallbackTzSource;
        }

        if ($tz instanceof DateTimeZone && $original instanceof DateTimeImmutable) {
            $original = $original->setTimezone($tz);
        }

        return new Temporal(
            create: $create,
            modify: $modify,
            original: $original,
            tz: $tz,
            tzSource: $tzSource,
            offsetTime: $offsetTime,
            offsetTimeOriginal: $offsetTimeOriginal,
            offsetTimeDigitized: $offsetTimeDigitized,
            subSecTime: $subSecTime,
            subSecTimeOriginal: $subSecTimeOriginal,
            subSecTimeDigitized: $subSecTimeDigitized,
            timeZoneOffsetMinutes: $timeZoneOffsets,
        );
    }

    /**
     * Builds a camera value object using EXIF, XMP and QuickTime fallbacks.
     */
    private function buildCamera(ExifTagResolver $exif, XmpResolver $xmp, QuickTimeResolver $quickTime): Camera
    {
        return new Camera(
            make: CompositeResolver::first([
                fn () => $exif->cameraMake(),
                fn () => $xmp->string('http://ns.adobe.com/tiff/1.0/', 'Make'),
                fn () => $quickTime->string('com.apple.quicktime.make'),
            ]),
            model: CompositeResolver::first([
                fn () => $exif->cameraModel(),
                fn () => $xmp->string('http://ns.adobe.com/tiff/1.0/', 'Model'),
                fn () => $quickTime->string('com.apple.quicktime.model'),
            ]),
            ownerName: CompositeResolver::first([
                fn () => $exif->ownerName(),
                fn () => $xmp->string('http://ns.adobe.com/xap/1.0/aux/', 'OwnerName'),
            ]),
            serialNumber: CompositeResolver::first([
                fn () => $exif->bodySerialNumber(),
                fn () => $xmp->string('http://ns.adobe.com/exif/1.0/aux/', 'SerialNumber'),
            ]),
            firmware: CompositeResolver::first([
                fn () => $exif->cameraFirmware(),
                fn () => $quickTime->string('CameraFirmwareVersion'),
                fn () => $exif->software(),
                fn () => $quickTime->string('com.apple.quicktime.software'),
            ]),
            fileSource: $exif->fileSource(),
            sensingMethod: $exif->sensingMethod(),
        );
    }

    /**
     * Builds a lens value object using EXIF and XMP information.
     */
    private function buildLens(ExifTagResolver $exif, XmpResolver $xmp): Lens
    {
        $focalLength = $exif->focalLength();
        $focalLength ??= $this->parseRationalString($xmp->string('http://ns.adobe.com/exif/1.0/aux/', 'FocalLength'));

        $lensSpecification = $exif->lensSpecification();
        if ($lensSpecification === null) {
            $lensSpecification = $this->parseLensInfoString($xmp->string('http://ns.adobe.com/exif/1.0/aux/', 'LensInfo'));
        }

        return new Lens(
            lensMake: CompositeResolver::first([
                fn () => $exif->lensMake(),
                fn () => $xmp->string('http://ns.adobe.com/exif/1.0/aux/', 'Lens'),
            ]),
            lensModel: CompositeResolver::first([
                fn () => $exif->lensModel(),
                fn () => $xmp->string('http://ns.adobe.com/exif/1.0/aux/', 'LensModel'),
            ]),
            lensSerialNumber: CompositeResolver::first([
                fn () => $exif->lensSerialNumber(),
                fn () => $xmp->string('http://ns.adobe.com/exif/1.0/aux/', 'LensSerialNumber'),
            ]),
            focalLengthMm: $focalLength,
            focalLengthIn35mm: $exif->focalLength35mm(),
            maxApertureFNumber: $exif->maxApertureFNumber(),
            lensSpecification: $lensSpecification,
        );
    }

    /**
     * Builds the image value object with EXIF and XMP fallbacks.
     */
    private function buildImage(ExifTagResolver $exif, XmpResolver $xmp, QuickTimeResolver $quickTime, Interop $interop): Image
    {
        $dimensions = CompositeResolver::dimensions(
            fn () => $exif->imageWidth(),
            fn () => $exif->imageHeight(),
        );

        $width  = $dimensions['width'];
        $height = $dimensions['height'];

        if ($width === null && $height === null) {
            $width  = $quickTime->int('ImageWidth');
            $height = $quickTime->int('ImageHeight');
        }

        $documentName = CompositeResolver::first([
            fn () => $xmp->string('http://ns.adobe.com/tiff/1.0/', 'DocumentName'),
            fn () => $xmp->string('http://purl.org/dc/elements/1.1/', 'title'),
        ]);

        $title = CompositeResolver::first([
            fn () => $exif->imageTitle(),
            fn () => $xmp->string('http://purl.org/dc/elements/1.1/', 'title'),
        ]);

        return new Image(
            width: $width,
            height: $height,
            orientation: $exif->orientation(),
            bitsPerSample: $exif->bitsPerSample(),
            colorSpace: $this->normalizedColorSpace($exif->colorSpace(), $interop),
            imageUniqueId: $exif->imageUniqueId(),
            imageNumber: $exif->imageNumber(),
            documentName: $documentName,
            description: CompositeResolver::first([
                fn () => $exif->imageDescription(),
                fn () => $xmp->string('http://purl.org/dc/elements/1.1/', 'description'),
            ]),
            title: $title,
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
    private function normalizedColorSpace(?ColorSpace $colorSpace, Interop $interop): ?ColorSpace
    {
        if ($colorSpace === ColorSpace::UNCALIBRATED && $interop->index === 'R03') {
            return ColorSpace::ADOBE_RGB;
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
     * Converts an EXIF TimeZoneOffset value expressed in minutes to a DateTimeZone.
     */
    private function timeZoneFromMinutes(?int $minutes): ?DateTimeZone
    {
        if (!is_int($minutes)) {
            return null;
        }

        if ($minutes < -14 * 60 || $minutes > 14 * 60) {
            return null;
        }

        $absolute = abs($minutes);
        $hours    = intdiv($absolute, 60);
        $mins     = $absolute % 60;
        $prefix   = $minutes < 0 ? '-' : '+';

        return ValueConverters::parseOffset(sprintf('%s%02d:%02d', $prefix, $hours, $mins));
    }

    /**
     * Parses a rational string representation (e.g. "50/1") into a float.
     */
    private function parseRationalString(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (str_contains($value, '/')) {
            [$num, $den] = array_map('trim', explode('/', $value, 2));
            if ($den === '0') {
                return null;
            }

            return (float) $num / (float) $den;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Parses a textual lens info representation.
     *
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    private function parseLensInfoString(?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parts = array_map('trim', explode(' ', $value));
        if (count($parts) !== 4) {
            return null;
        }

        $parsed = [];
        foreach ($parts as $part) {
            $float = $this->parseRationalString($part);
            if ($float === null) {
                return null;
            }

            $parsed[] = $float;
        }

        /** @var array{0:float,1:float,2:float,3:float} $parsed */
        return $parsed;
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
