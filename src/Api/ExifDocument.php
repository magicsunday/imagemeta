<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Api;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Curate\Exif\Structured\Camera as StructuredCamera;
use MagicSunday\ImageMeta\Curate\Exif\Structured\Exposure as StructuredExposure;
use MagicSunday\ImageMeta\Curate\Exif\Structured\Gps as StructuredGps;
use MagicSunday\ImageMeta\Curate\Exif\Structured\Image as StructuredImage;
use MagicSunday\ImageMeta\Curate\Exif\Structured\Lens as StructuredLens;
use MagicSunday\ImageMeta\Curate\Exif\Structured\Preview as StructuredPreview;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument as ModelExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Value\Camera as CameraValue;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\ExifFlash;
use MagicSunday\ImageMeta\Value\Exposure as ExposureValue;
use MagicSunday\ImageMeta\Value\Gps as GpsValue;
use MagicSunday\ImageMeta\Value\Image as ImageValue;
use MagicSunday\ImageMeta\Value\Interop as InteropValue;
use MagicSunday\ImageMeta\Value\Lens as LensValue;
use MagicSunday\ImageMeta\Value\Preview as PreviewValue;

/**
 * Provides an EXIF-only structured view derived from a parsed document.
 */
final class ExifDocument
{
    private readonly ?ModelExifDocument $raw;

    private readonly StructuredCamera $camera;

    private readonly StructuredLens $lens;

    private readonly StructuredExposure $exposure;

    private readonly StructuredGps $gps;

    private readonly StructuredImage $image;

    private readonly StructuredPreview $preview;

    private readonly InteropValue $interop;

    public function __construct(
        ?ModelExifDocument $document,
        ?int $fallbackWidth = null,
        ?int $fallbackHeight = null,
        ?int $fallbackBitsPerSample = null,
    ) {
        $this->raw = $document;

        $cameraValue   = $this->createCameraValue($document);
        $lensValue     = $this->createLensValue($document);
        $exposureValue = $this->createExposureValue($document);
        $derived       = $this->createDerivedValues($lensValue, $exposureValue);
        $gpsValue      = $this->createGpsValue($document);
        $imageValue    = $this->createImageValue($document, $fallbackWidth, $fallbackHeight, $fallbackBitsPerSample);
        $previewValue  = $this->createPreviewValue($document);
        $interopValue  = new InteropValue(
            index: $document?->interopIndex(),
            version: $document?->interopVersion(),
            relatedImageFileFormat: $document?->relatedImageFileFormat(),
            relatedImageWidth: $document?->relatedImageWidth(),
            relatedImageLength: $document?->relatedImageLength(),
        );

        $this->camera   = new StructuredCamera($cameraValue);
        $this->lens     = new StructuredLens($lensValue, $derived);
        $this->exposure = new StructuredExposure($exposureValue, $derived);
        $this->gps      = new StructuredGps($gpsValue);
        $this->image    = new StructuredImage($imageValue);
        $this->preview  = new StructuredPreview($previewValue);
        $this->interop  = $interopValue;
    }

    public function camera(): StructuredCamera
    {
        return $this->camera;
    }

    public function lens(): StructuredLens
    {
        return $this->lens;
    }

    public function exposure(): StructuredExposure
    {
        return $this->exposure;
    }

    public function gps(): StructuredGps
    {
        return $this->gps;
    }

    public function image(): StructuredImage
    {
        return $this->image;
    }

    public function preview(): StructuredPreview
    {
        return $this->preview;
    }

    public function interop(): InteropValue
    {
        return $this->interop;
    }

    public function iso(): ?int
    {
        return $this->raw?->isoBestEffort();
    }

    public function dateTimeOriginal(): ?DateTimeImmutable
    {
        return $this->raw?->dateTimeOriginalBestEffort();
    }

    public function userComment(): ?string
    {
        return $this->raw?->userComment();
    }

    public function userCommentEncoding(): ?string
    {
        return $this->raw?->userCommentEncodingBestEffort();
    }

    public function hasData(): bool
    {
        return $this->raw instanceof ModelExifDocument;
    }

    public function raw(): ?ModelExifDocument
    {
        return $this->raw;
    }

    private function createCameraValue(?ModelExifDocument $document): CameraValue
    {
        if (!$document instanceof ModelExifDocument) {
            return new CameraValue(null, null, null, null, null, null, null);
        }

        $firmware = $document->cameraFirmware();
        if ($firmware === null && (float) $document->exifProfile() < 3.0) {
            $firmware = $document->cameraFirmwareVersion();
        }
        if ($firmware === null) {
            $firmware = $document->software();
        }

        return new CameraValue(
            make: $document->cameraMake(),
            model: $document->cameraModel(),
            ownerName: $document->ownerName(),
            serialNumber: $document->cameraSerialNumber(),
            firmware: $firmware,
            fileSource: $document->fileSource(),
            sensingMethod: $document->sensingMethod(),
        );
    }

    private function createLensValue(?ModelExifDocument $document): LensValue
    {
        if (!$document instanceof ModelExifDocument) {
            return new LensValue(null, null, null, null, null, null, null);
        }

        $maxApex = $document->maxApertureApex();
        $maxF    = $maxApex !== null ? ValueConverters::apexToFNumber($maxApex) : null;

        return new LensValue(
            lensMake: $document->lensMake(),
            lensModel: $document->lensModel(),
            lensSerialNumber: $document->lensSerialNumber(),
            focalLengthMm: $document->focalLengthMm(),
            focalLengthIn35mm: $document->focalLength35Mm(),
            maxApertureFNumber: $maxF,
            lensSpecification: $document->lensSpecification(),
        );
    }

    private function createExposureValue(?ModelExifDocument $document): ExposureValue
    {
        $program      = null;
        $metering     = null;
        $whiteBalance = null;
        $iso          = null;

        if ($document instanceof ModelExifDocument) {
            $programCode = $document->exposureProgram();
            if ($programCode !== null) {
                $program = ExposureProgram::tryFrom($programCode);
            }

            $meteringCode = $document->meteringMode();
            if ($meteringCode !== null) {
                $metering = MeteringMode::tryFrom($meteringCode);
            }

            $whiteBalanceCode = $document->whiteBalance();
            if ($whiteBalanceCode !== null) {
                $whiteBalance = WhiteBalance::tryFrom($whiteBalanceCode);
            }

            $flashInfo = ExifFlash::fromExifValue($document->flash());
            $iso       = $document->isoBestEffort();

            return new ExposureValue(
                iso: $iso,
                exposureTimeSec: $document->exposureTime(),
                fNumber: $document->fNumber(),
                exposureBiasEv: $document->exposureBias(),
                program: $program,
                meteringMode: $metering,
                flash: $flashInfo,
                whiteBalance: $whiteBalance,
                brightnessEv: $document->brightnessValue(),
                exposureMode: $document->exposureMode(),
                gainControl: $document->gainControl(),
                contrast: $document->contrast(),
                saturation: $document->saturation(),
                sharpness: $document->sharpness(),
                digitalZoomRatio: $document->digitalZoomRatio(),
                shutterSpeedEv: $document->shutterSpeedEv(),
                apertureEv: $document->apertureEv(),
                isoLatitudeYyy: $document->isoLatitudeYyy(),
                isoLatitudeZzz: $document->isoLatitudeZzz(),
                exposureIndex: $document->exposureIndex(),
                flashEnergy: $document->flashEnergy(),
            );
        }

        return new ExposureValue(
            iso: null,
            exposureTimeSec: null,
            fNumber: null,
            exposureBiasEv: null,
            program: null,
            meteringMode: null,
            flash: null,
            whiteBalance: null,
            brightnessEv: null,
            exposureMode: null,
            gainControl: null,
            contrast: null,
            saturation: null,
            sharpness: null,
            digitalZoomRatio: null,
            shutterSpeedEv: null,
            apertureEv: null,
            isoLatitudeYyy: null,
            isoLatitudeZzz: null,
            exposureIndex: null,
            flashEnergy: null,
        );
    }

    private function createDerivedValues(LensValue $lens, ExposureValue $exposure): Derived
    {
        $cropFactor          = ValueConverters::calcCropFactor($lens->focalLengthIn35mm, $lens->focalLengthMm);
        $circleOfConfusionMm = ValueConverters::calcCircleOfConfusionMm($cropFactor);

        return new Derived(
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
    }

    private function createGpsValue(?ModelExifDocument $document): GpsValue
    {
        if (!$document instanceof ModelExifDocument) {
            return new GpsValue();
        }

        /** @var array{
         *     lat_ref:?string,
         *     lat:?float,
         *     lon_ref:?string,
         *     lon:?float,
         *     alt_ref:?int,
         *     alt:?float,
         *     version:?string,
         *     version_raw:?string,
         *     satellites:?string,
         *     status:?string,
         *     measure_mode:?string,
         *     dop:?float,
         *     speed_ref:?string,
         *     speed_ms:?float,
         *     speed_original_ref:?string,
         *     speed_original:?float,
         *     track_ref:?string,
         *     track:?float,
         *     img_direction_ref:?string,
         *     img_direction:?float,
         *     map_datum:?string,
         *     dest_lat_ref:?string,
         *     dest_lat:?float,
         *     dest_lon_ref:?string,
         *     dest_lon:?float,
         *     dest_bearing_ref:?string,
         *     dest_bearing:?float,
         *     dest_distance_ref:?string,
         *     dest_distance_m:?float,
         *     dest_distance_original_ref:?string,
         *     dest_distance_original:?float,
         *     processing_method:?string,
         *     area_information:?string,
         *     date:?string,
         *     date_raw:?string,
         *     time:?string,
         *     timestamp:?DateTimeImmutable,
         *     differential:?int,
         *     h_positioning_error:?float
         * } $gpsValues */
        // ModelExifDocument::gps() returns a normalized array of EXIF GPSInfo tags (EXIF 2.31 §4.6.6).
        $gpsValues = $document->gps();

        return new GpsValue(
            latitude: $gpsValues['lat'],
            longitude: $gpsValues['lon'],
            latitudeRef: $gpsValues['lat_ref'],
            longitudeRef: $gpsValues['lon_ref'],
            altitude: $gpsValues['alt'],
            altitudeRef: $gpsValues['alt_ref'],
            version: $gpsValues['version'],
            versionRaw: $gpsValues['version_raw'],
            satellites: $gpsValues['satellites'],
            status: $gpsValues['status'],
            measureMode: $gpsValues['measure_mode'],
            dop: $gpsValues['dop'],
            speedRef: $gpsValues['speed_ref'],
            speedMs: $gpsValues['speed_ms'],
            speedOriginalRef: $gpsValues['speed_original_ref'],
            speedOriginal: $gpsValues['speed_original'],
            trackRef: $gpsValues['track_ref'],
            track: $gpsValues['track'],
            imageDirectionRef: $gpsValues['img_direction_ref'],
            imageDirection: $gpsValues['img_direction'],
            mapDatum: $gpsValues['map_datum'],
            destinationLatitudeRef: $gpsValues['dest_lat_ref'],
            destinationLatitude: $gpsValues['dest_lat'],
            destinationLongitudeRef: $gpsValues['dest_lon_ref'],
            destinationLongitude: $gpsValues['dest_lon'],
            destinationBearingRef: $gpsValues['dest_bearing_ref'],
            destinationBearing: $gpsValues['dest_bearing'],
            destinationDistanceRef: $gpsValues['dest_distance_ref'],
            destinationDistanceMetres: $gpsValues['dest_distance_m'],
            destinationDistanceOriginalRef: $gpsValues['dest_distance_original_ref'],
            destinationDistanceOriginal: $gpsValues['dest_distance_original'],
            processingMethod: $gpsValues['processing_method'],
            areaInformation: $gpsValues['area_information'],
            date: $gpsValues['date'],
            dateRaw: $gpsValues['date_raw'],
            time: $gpsValues['time'],
            timestamp: $gpsValues['timestamp'],
            differential: $gpsValues['differential'],
            horizontalPositioningError: $gpsValues['h_positioning_error'],
        );
    }

    private function createImageValue(
        ?ModelExifDocument $document,
        ?int $fallbackWidth,
        ?int $fallbackHeight,
        ?int $fallbackBitsPerSample,
    ): ImageValue {
        $orientation = Orientation::fromExifValue($document?->orientation());

        $colorSpace = null;
        if ($document instanceof ModelExifDocument) {
            $colorSpace = ColorSpace::fromExifValue($document->colorSpace());
        }

        $bitsPerSample = $document?->bitsPerSample();
        if ($bitsPerSample === null) {
            $bitsPerSample = $fallbackBitsPerSample;
        }

        return new ImageValue(
            width: $document?->imageWidth() ?? $fallbackWidth,
            height: $document?->imageHeight() ?? $fallbackHeight,
            orientation: $orientation,
            bitsPerSample: $bitsPerSample,
            colorSpace: $colorSpace,
            imageUniqueId: $document?->imageUniqueId(),
            imageNumber: $document?->imageNumber(),
            documentName: $document?->documentName(),
            description: $document?->imageDescription(),
            title: $document?->imageTitle(),
            componentsConfiguration: $document?->componentsConfiguration(),
            compressedBitsPerPixel: $document?->compressedBitsPerPixel(),
            interlace: $document?->interlace(),
            userComment: $document?->userComment(),
            userCommentEncoding: $document?->userCommentEncodingBestEffort(),
        );
    }

    private function createPreviewValue(?ModelExifDocument $document): PreviewValue
    {
        $previewColorSpace  = null;
        $previewCompression = null;
        if ($document instanceof ModelExifDocument) {
            $previewColorSpace  = ColorSpace::fromExifValue($document->previewColorSpace());
            $previewCompression = Compression::fromExifValue($document->previewImageCompression());
        }

        return new PreviewValue(
            hasThumbnail: $document?->hasThumbnail(),
            hasPreview: $document?->hasPreviewImage(),
            previewWidth: $document?->previewImageWidth(),
            previewHeight: $document?->previewImageHeight(),
            previewColorSpace: $previewColorSpace,
            previewBitDepth: $document?->previewImageBitDepth(),
            previewCompression: $previewCompression,
            previewScale: $document?->previewImageScale(),
            previewEncoding: $document?->previewImageEncoding(),
            previewMimeType: $document?->previewImageMimeType(),
            previewOffset: $document?->previewImageOffset(),
            previewLength: $document?->previewImageLength(),
        );
    }
}
