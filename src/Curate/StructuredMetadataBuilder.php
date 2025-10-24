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
use MagicSunday\ImageMeta\Curate\Resolver\QuickTimeResolver;
use MagicSunday\ImageMeta\Curate\Resolver\XmpResolver;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Apple;
use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Depth;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
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

use function array_map;
use function explode;
use function is_numeric;
use function str_contains;

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

        $interop = new Interop(
            index: $exifResolver->interopIndex(),
            version: $exifResolver->interopVersion(),
        );

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
        );

        $depth = new Depth(
            format: null,
            near: null,
            far: null,
            units: null,
            measureType: null,
        );

        $composite = new CompositeImageInfo(
            type: $exifResolver->compositeImage(),
            counts: $exifResolver->compositeImageCount(),
            exposureTimesTotal: $exifResolver->compositeExposureTimes(),
        );

        $standards = new Standards(
            exifVersion: $exifResolver->exifVersion(),
            flashpixVersion: $exifResolver->flashpixVersion(),
        );

        $camera = $this->buildCamera($exifResolver, $xmpResolver, $quickTimeResolver);
        $lens   = $this->buildLens($exifResolver, $xmpResolver);
        $image  = $this->buildImage($exifResolver, $xmpResolver, $quickTimeResolver);

        $exposure = new Exposure(
            iso: $exifResolver->iso(),
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
        );

        $capture = new Capture($exifResolver->captureDateTime());

        $gpsCoords = $exifResolver->gps();
        $gps       = new Gps($gpsCoords['lat'], $gpsCoords['lon'], $gpsCoords['alt']);

        $device = $this->buildDevice($metadata->quickTime, $exifResolver, $quickTimeResolver);

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

        $processing = new ProcessingSettings(null, null, null, null, null, null);

        $whiteBalanceDetails = new WhiteBalanceDetails(
            mode: $exifResolver->whiteBalance(),
            kelvin: null,
            rgGain: null,
            bgGain: null,
        );

        $focusRect = $exifResolver->subjectArea();
        $rect      = $focusRect !== null
            ? ValueConverters::subjectAreaToRect($focusRect)
            : ['x' => null, 'y' => null, 'w' => null, 'h' => null];
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

        $keywords = new Keywords(
            flat: $xmpResolver->stringList('http://purl.org/dc/elements/1.1/', 'subject'),
            hierarchical: $xmpResolver->stringList('http://ns.adobe.com/lightroom/1.0/', 'hierarchicalSubject') ?: null,
        );

        $rights = new Rights(
            copyright: CompositeResolver::first([
                fn () => $xmpResolver->string('http://purl.org/dc/elements/1.1/', 'rights'),
                fn () => $exifResolver->artist(),
            ]),
            usageTerms: $xmpResolver->string('http://ns.adobe.com/xap/1.0/rights/', 'UsageTerms'),
            licenseUrl: $xmpResolver->string('http://ns.adobe.com/xap/1.0/rights/', 'WebStatement'),
            creditLine: $xmpResolver->string('http://ns.adobe.com/photoshop/1.0/', 'Credit'),
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

        $related = new RelatedAssets(
            livePhotoPairId: $metadata->quickTime?->contentIdentifier(),
            burstId: $quickTimeResolver->string('BurstUUID'),
            isPrimaryInBurst: $quickTimeResolver->bool('BurstSelected'),
            panoramaId: $xmpResolver->bool('http://ns.google.com/photos/1.0/panorama/', 'UsePanoramaViewer') ? 'panorama' : null,
            depthDataId: $quickTimeResolver->string('DepthData'),
        );

        $sensor = new Sensor(null, null, null, null, null);

        $uav = new Uav(null, null, null, null, null, null, null, null);

        $integrity = new Integrity(
            originalFileName: $xmpResolver->string('http://ns.adobe.com/tiff/1.0/', 'OriginalFileName'),
            originalDigest: null,
            edited: $xmpResolver->has('http://ns.adobe.com/xap/1.0/mm/', 'History') ?: null,
            historyLastSoftware: null,
        );

        return new StructuredMetadata(
            interop: $interop,
            tiff: $tiff,
            depth: $depth,
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
    private function buildDevice(?QuickTimeMeta $quickTime, ExifTagResolver $exif, QuickTimeResolver $quickTimeResolver): Device
    {
        $softwareChain = CompositeResolver::first([
            fn () => $quickTimeResolver->string('com.apple.quicktime.software'),
            fn () => $exif->software(),
        ]);

        return new Device(
            software: $softwareChain,
            hostComputer: $exif->hostComputer(),
        );
    }

    /**
     * Builds the temporal value object derived from EXIF, QuickTime and XMP data.
     */
    private function buildTemporal(ExifTagResolver $resolver, QuickTimeResolver $quickTime, XmpResolver $xmp): Temporal
    {
        $create   = $resolver->digitizedDateTime();
        $modify   = $resolver->fileDateTime();
        $original = $resolver->captureDateTime();

        $create = $create ?? $this->parseFlexibleDate($xmp->string('http://ns.adobe.com/xap/1.0/', 'CreateDate'));
        $create = $create ?? $this->parseFlexibleDate($quickTime->string('CreationDate'));

        $modify = $modify ?? $this->parseFlexibleDate($xmp->string('http://ns.adobe.com/xap/1.0/', 'ModifyDate'));
        $modify = $modify ?? $this->parseFlexibleDate($quickTime->string('ModifyDate'));

        $tzSource = null;
        $tz       = null;

        if ($original instanceof DateTimeImmutable) {
            $offset = ValueConverters::parseOffset($resolver->originalOffset());
            if ($offset instanceof DateTimeZone) {
                $tz       = $offset;
                $tzSource = 'OffsetTimeOriginal';
                $original = $original->setTimezone($offset);
            }
        }

        if ($original === null) {
            $original = $this->parseFlexibleDate($xmp->string('http://ns.adobe.com/photoshop/1.0/', 'DateCreated'));
            if ($original instanceof DateTimeImmutable) {
                $tz       = $original->getTimezone();
                $tzSource = 'XMP';
            }
        }

        if ($original === null) {
            $quickTimeDate = $this->parseFlexibleDate($quickTime->string('CreationDate'));
            if ($quickTimeDate instanceof DateTimeImmutable) {
                $original = $quickTimeDate;
                $tz       = $quickTimeDate->getTimezone();
                $tzSource = 'QuickTime';
            }
        }

        if ($original === null && $create instanceof DateTimeImmutable) {
            $original = $create;
        }

        if ($original === null && $modify instanceof DateTimeImmutable) {
            $original = $modify;
        }

        return new Temporal(
            create: $create,
            modify: $modify,
            original: $original,
            tz: $tz,
            tzSource: $tzSource,
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

        $lensInfo = $exif->lensInfo();
        if ($lensInfo === null) {
            $lensInfo = $this->parseLensInfoString($xmp->string('http://ns.adobe.com/exif/1.0/aux/', 'LensInfo'));
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
            lensInfo: $lensInfo,
        );
    }

    /**
     * Builds the image value object with EXIF and XMP fallbacks.
     */
    private function buildImage(ExifTagResolver $exif, XmpResolver $xmp, QuickTimeResolver $quickTime): Image
    {
        $width = CompositeResolver::first([
            fn () => $exif->imageWidth(),
            fn () => $quickTime->int('ImageWidth'),
        ]);

        $height = CompositeResolver::first([
            fn () => $exif->imageHeight(),
            fn () => $quickTime->int('ImageHeight'),
        ]);

        return new Image(
            width: $width,
            height: $height,
            orientation: $exif->orientation(),
            bitsPerSample: $exif->bitsPerSample(),
            colorSpace: $exif->colorSpace(),
            imageUniqueId: $exif->imageUniqueId(),
            documentName: $exif->documentName(),
            description: CompositeResolver::first([
                fn () => $exif->imageDescription(),
                fn () => $xmp->string('http://purl.org/dc/elements/1.1/', 'description'),
            ]),
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
            light: $exif->lightSource(),
            faceCount: null,
            hdrScene: $hdr !== null ? true : null,
            nightMode: $night,
            subjectDistanceRange: $exif->subjectDistanceRange(),
        );
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
