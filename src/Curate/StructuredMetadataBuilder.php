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
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\Preview;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\RawCharacteristics;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\Uav;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;

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
        $camera = new Camera(
            make: CompositeResolver::first([
                fn () => $exifResolver->cameraMake(),
                fn () => $xmpResolver->string('http://ns.adobe.com/tiff/1.0/', 'Make'),
            ]),
            model: CompositeResolver::first([
                fn () => $exifResolver->cameraModel(),
                fn () => $xmpResolver->string('http://ns.adobe.com/tiff/1.0/', 'Model'),
            ]),
            serialNumber: CompositeResolver::first([
                fn () => $exifResolver->bodySerialNumber(),
                fn () => $xmpResolver->string('http://ns.adobe.com/exif/1.0/aux/', 'SerialNumber'),
            ]),
            software: $xmpResolver->string('http://ns.adobe.com/xap/1.0/', 'CreatorTool'),
        );

        $lens = new Lens(
            model: CompositeResolver::first([
                fn () => $exifResolver->lensModel(),
                fn () => $xmpResolver->string('http://ns.adobe.com/exif/1.0/aux/', 'LensModel'),
            ]),
            focalLengthMm: $exifResolver->focalLength(),
        );

        $image = new Image(
            orientation: $exifResolver->orientation(),
            colorSpace: $exifResolver->colorSpace(),
        );

        $flash = $exifResolver->flash();
        $exposure = new Exposure(
            iso: $exifResolver->iso(),
            exposureTimeSeconds: $exifResolver->exposureTime(),
            apertureFNumber: $exifResolver->fNumber(),
            focalLengthMm: $exifResolver->focalLength(),
            program: $exifResolver->exposureProgram(),
            meteringMode: $exifResolver->meteringMode(),
            whiteBalance: $exifResolver->whiteBalance(),
            flash: $flash,
        );

        $capture = new Capture($exifResolver->captureDateTime());

        $gpsCoords = $exifResolver->gps();
        $gps = new Gps($gpsCoords['lat'], $gpsCoords['lon'], $gpsCoords['alt']);

        $device = $this->buildDevice($metadata->quickTime);

        $apple = new Apple($metadata->quickTime?->contentIdentifier());

        $xmp = $xmpResolver->value();

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
            width: $quickTimeResolver->int('ImageWidth'),
            height: $quickTimeResolver->int('ImageHeight'),
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

        $colorProfile = new ColorProfile(null, null, null, null);

        $processing = new ProcessingSettings(null, null, null, null, null, null);

        $whiteBalanceDetails = new WhiteBalanceDetails(
            mode: $exifResolver->whiteBalance(),
            kelvin: null,
            rgGain: null,
            bgGain: null,
        );

        $focusRect = $exifResolver->subjectArea();
        $rect = $focusRect !== null ? ValueConverters::subjectAreaToRect($focusRect) : ['x' => null, 'y' => null, 'w' => null, 'h' => null];
        $focus = new Focus(
            subjectDistanceM: $exifResolver->subjectDistance(),
            subjectAreaX: $rect['x'],
            subjectAreaY: $rect['y'],
            subjectAreaW: $rect['w'],
            subjectAreaH: $rect['h'],
            afMode: null,
        );

        $motion = new Motion(null, null, null, null, null, null, null, null, null);

        $scene = new Scene(
            type: null,
            light: null,
            faceCount: null,
            hdrScene: null,
            nightMode: null,
        );

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
            ownerName: $exifResolver->ownerName(),
            creator: CompositeResolver::first([
                fn () => $this->firstListValue($xmpResolver->stringList('http://purl.org/dc/elements/1.1/', 'creator')),
            ]),
            creatorEmail: $xmpResolver->string('http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/', 'CreatorContactInfo/Iptc4xmpCore:CiEmailWork'),
        );

        $temporal = $this->buildTemporal($exifResolver);

        $derived = new Derived(
            ev100: ValueConverters::calcEv100(
                $exposure->exposureTimeSeconds,
                $exposure->apertureFNumber,
                $exposure->iso,
            ),
            hyperfocalM: ValueConverters::calcHyperfocalM(
                $exposure->focalLengthMm,
                $exposure->apertureFNumber,
                0.029,
            ),
            fovDeg: ValueConverters::calcFovDeg($exifResolver->focalLength35mm(), null),
            focalLength35mm: $exifResolver->focalLength35mm(),
            cropFactor: null,
        );

        $related = new RelatedAssets(
            livePhotoPairId: $metadata->quickTime?->contentIdentifier(),
            burstId: $quickTimeResolver->string('BurstUUID'),
            isPrimaryInBurst: $quickTimeResolver->bool('BurstSelected'),
            panoramaId: $xmpResolver->bool('http://ns.google.com/photos/1.0/panorama/', 'UsePanoramaViewer') ? 'panorama' : null,
            depthDataId: $quickTimeResolver->string('DepthData'),
        );

        $raw = new RawCharacteristics(null, null, null, null, null);

        $sensor = new Sensor(null, null, null, null, null);

        $uav = new Uav(null, null, null, null, null, null, null, null);

        $integrity = new Integrity(
            originalFileName: $xmpResolver->string('http://ns.adobe.com/tiff/1.0/', 'OriginalFileName'),
            originalDigest: null,
            edited: $xmpResolver->has('http://ns.adobe.com/xap/1.0/mm/', 'History') ?: null,
            historyLastSoftware: null,
        );

        return new StructuredMetadata(
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
            raw: $raw,
            sensor: $sensor,
            uav: $uav,
            integrity: $integrity,
        );
    }

    /**
     * Builds a device value object using container level metadata.
     */
    private function buildDevice(?QuickTimeMeta $quickTime): Device
    {
        if (!$quickTime instanceof QuickTimeMeta) {
            return new Device(null, null, null);
        }

        return new Device(
            manufacturer: $quickTime->keys['com.apple.quicktime.make'] ?? null,
            model: $quickTime->keys['com.apple.quicktime.model'] ?? null,
            software: $quickTime->keys['com.apple.quicktime.software'] ?? null,
        );
    }

    /**
     * Builds the temporal value object derived from EXIF data.
     */
    private function buildTemporal(ExifTagResolver $resolver): Temporal
    {
        $original = $resolver->captureDateTime();
        $offset   = ValueConverters::parseOffset($resolver->originalOffset());

        $tzSource = null;
        if ($offset instanceof \DateTimeZone) {
            $tzSource = 'EXIF';
            if ($original instanceof DateTimeImmutable) {
                $original = $original->setTimezone($offset);
            }
        }

        return new Temporal(
            create: $resolver->digitizedDateTime(),
            modify: $resolver->fileDateTime(),
            original: $original,
            tz: $offset,
            tzSource: $tzSource,
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
}
