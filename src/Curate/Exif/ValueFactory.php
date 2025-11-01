<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif;

use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\ExifFlash;
use MagicSunday\ImageMeta\Value\File;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;

use function array_merge;
use function is_string;
use function str_contains;
use function strtoupper;

/**
 * Builds curated value objects exclusively from EXIF sources.
 */
final class ValueFactory
{
    /**
     * Produces normalised value objects derived from the supplied metadata container.
     *
     * @return array{
     *     exif:?ParsedExif,
     *     file:File,
     *     container:Container,
     *     integrity:Integrity,
     *     image:Image,
     *     exposure:Exposure,
     *     gps:Gps,
     * }
     */
    public function createComponents(Metadata $metadata): array
    {
        $exif = $metadata->exifDoc;

        return [
            'exif'      => $exif,
            'file'      => $this->buildFile($metadata),
            'container' => $this->buildContainer($metadata, $exif),
            'integrity' => $this->buildIntegrity($exif),
            'image'     => $this->buildImage($metadata, $exif),
            'exposure'  => $this->buildExposure($exif),
            'gps'       => $this->buildGps($exif),
        ];
    }

    private function buildFile(Metadata $metadata): File
    {
        return new File(
            $metadata->mimeType,
            $metadata->fileSize,
            $metadata->extension,
            $metadata->digestSha1,
            $metadata->digestMd5,
        );
    }

    private function buildContainer(Metadata $metadata, ?ParsedExif $exif): Container
    {
        $format = null;

        if (is_string($metadata->mimeType) && str_contains(strtoupper($metadata->mimeType), 'JPEG')) {
            $format = 'JPEG';
        } elseif (is_string($metadata->extension) && strtoupper($metadata->extension) === 'JPG') {
            $format = 'JPEG';
        } elseif ($exif instanceof ParsedExif) {
            $format = 'JPEG';
        }

        return new Container(
            $format,
            $exif?->software(),
            null,
            null,
            null,
        );
    }

    private function buildIntegrity(?ParsedExif $exif): Integrity
    {
        return new Integrity(
            originalFileName: null,
            originalDigest: null,
            edited: null,
            historyLastSoftware: null,
            imageHistory: $exif?->imageHistory(),
            makerNotesSafe: $exif?->makerNoteSafety(),
        );
    }

    private function buildImage(Metadata $metadata, ?ParsedExif $exif): Image
    {
        $width  = $exif?->imageWidth() ?? $metadata->jpegFrameWidth;
        $height = $exif?->imageHeight() ?? $metadata->jpegFrameHeight;

        $bitsPerSample = $exif?->bitsPerSample();
        if ($bitsPerSample === null) {
            $bitsPerSample = $metadata->jpegBitsPerSample;
        }

        return new Image(
            width: $width,
            height: $height,
            orientation: $exif?->orientation(),
            bitsPerSample: $bitsPerSample,
            colorSpace: $exif?->colorSpace(),
            imageUniqueId: $exif?->imageUniqueId(),
            imageNumber: $exif?->imageNumber(),
            documentName: $exif?->documentName(),
            description: $exif?->imageDescription(),
            title: null,
            componentsConfiguration: $exif?->componentsConfiguration(),
            compressedBitsPerPixel: $exif?->compressedBitsPerPixel(),
            interlace: $exif?->interlace(),
            userComment: $exif?->userComment(),
            userCommentEncoding: $exif?->userCommentEncodingBestEffort(),
        );
    }

    private function buildExposure(?ParsedExif $exif): Exposure
    {
        $flashInfo = ExifFlash::fromExifValue($exif?->flash());

        return new Exposure(
            iso: $exif?->isoBestEffort(),
            exposureTimeSec: $exif?->exposureTime(),
            fNumber: $exif?->fNumber(),
            exposureBiasEv: $exif?->exposureBias(),
            program: $exif?->exposureProgram(),
            meteringMode: $exif?->meteringMode(),
            flash: $flashInfo,
            whiteBalance: $exif?->whiteBalance(),
            brightnessEv: $exif?->brightnessValue(),
            exposureMode: $exif?->exposureMode(),
            gainControl: $exif?->gainControl(),
            contrast: $exif?->contrast(),
            saturation: $exif?->saturation(),
            sharpness: $exif?->sharpness(),
            digitalZoomRatio: $exif?->digitalZoomRatio(),
            shutterSpeedEv: $exif?->shutterSpeedEv(),
            apertureEv: $exif?->apertureEv(),
            isoLatitudeYyy: $exif?->isoLatitudeYyy(),
            isoLatitudeZzz: $exif?->isoLatitudeZzz(),
            exposureIndex: $exif?->exposureIndex(),
            flashEnergy: $exif?->flashEnergy(),
        );
    }

    private function buildGps(?ParsedExif $exif): Gps
    {
        $gpsData = $exif?->gps() ?? [];
        $gpsData = array_merge([
            'lat'                  => null,
            'lat_ref'              => null,
            'lon'                  => null,
            'lon_ref'              => null,
            'alt'                  => null,
            'alt_ref'              => null,
            'version'              => null,
            'satellites'           => null,
            'status'               => null,
            'measure_mode'         => null,
            'dop'                  => null,
            'speed_ref'            => null,
            'speed_ms'             => null,
            'track_ref'            => null,
            'track'                => null,
            'img_direction_ref'    => null,
            'img_direction'        => null,
            'map_datum'            => null,
            'dest_lat_ref'         => null,
            'dest_lat'             => null,
            'dest_lon_ref'         => null,
            'dest_lon'             => null,
            'dest_bearing_ref'     => null,
            'dest_bearing'         => null,
            'dest_distance_ref'    => null,
            'dest_distance_m'      => null,
            'processing_method'    => null,
            'area_information'     => null,
            'date'                 => null,
            'time'                 => null,
            'timestamp'            => null,
            'differential'         => null,
            'h_positioning_error'  => null,
        ], $gpsData);

        return new Gps(
            latitude: $gpsData['lat'],
            longitude: $gpsData['lon'],
            latitudeRef: $gpsData['lat_ref'],
            longitudeRef: $gpsData['lon_ref'],
            altitude: $gpsData['alt'],
            altitudeRef: $gpsData['alt_ref'],
            version: $gpsData['version'],
            versionRaw: null,
            satellites: $gpsData['satellites'],
            status: $gpsData['status'],
            measureMode: $gpsData['measure_mode'],
            dop: $gpsData['dop'],
            speedRef: $gpsData['speed_ref'],
            speedMs: $gpsData['speed_ms'],
            speedOriginalRef: null,
            speedOriginal: null,
            trackRef: $gpsData['track_ref'],
            track: $gpsData['track'],
            imageDirectionRef: $gpsData['img_direction_ref'],
            imageDirection: $gpsData['img_direction'],
            mapDatum: $gpsData['map_datum'],
            destinationLatitudeRef: $gpsData['dest_lat_ref'],
            destinationLatitude: $gpsData['dest_lat'],
            destinationLongitudeRef: $gpsData['dest_lon_ref'],
            destinationLongitude: $gpsData['dest_lon'],
            destinationBearingRef: $gpsData['dest_bearing_ref'],
            destinationBearing: $gpsData['dest_bearing'],
            destinationDistanceRef: $gpsData['dest_distance_ref'],
            destinationDistanceMetres: $gpsData['dest_distance_m'],
            destinationDistanceOriginalRef: null,
            destinationDistanceOriginal: null,
            processingMethod: $gpsData['processing_method'],
            areaInformation: $gpsData['area_information'],
            date: $exif?->gpsDateStamp(),
            dateRaw: $gpsData['date'],
            time: $exif?->gpsTimeStampString(),
            timestamp: $exif?->gpsTimestamp(),
            differential: $gpsData['differential'],
            horizontalPositioningError: $gpsData['h_positioning_error'],
        );
    }
}
