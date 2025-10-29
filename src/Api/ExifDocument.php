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
final readonly class ExifDocument
{
    private ?ModelExifDocument $raw;

    private StructuredCamera $camera;

    private StructuredLens $lens;

    private StructuredExposure $exposure;

    private StructuredGps $gps;

    private StructuredImage $image;

    private StructuredPreview $preview;

    private InteropValue $interop;

    /**
     * Creates the structured EXIF view by aggregating the curated value objects extracted from the parser.
     *
     * @param ModelExifDocument|null $document              Parsed EXIF document that provides the raw value objects.
     * @param int|null               $fallbackWidth         Pixel width used when the EXIF image width tag is not available.
     * @param int|null               $fallbackHeight        Pixel height used when the EXIF image height tag is not available.
     * @param int|null               $fallbackBitsPerSample Bit depth to fall back to when the EXIF component depth is missing.
     */
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

        // Build structured view models that expose the curated EXIF metadata slices.
        $this->camera   = new StructuredCamera($cameraValue);
        $this->lens     = new StructuredLens($lensValue, $derived);
        $this->exposure = new StructuredExposure($exposureValue, $derived);
        $this->gps      = new StructuredGps($gpsValue);
        $this->image    = new StructuredImage($imageValue);
        $this->preview  = new StructuredPreview($previewValue);
        $this->interop  = $interopValue;
    }

    /**
     * Returns the structured camera metadata derived from the raw EXIF document.
     *
     * @return StructuredCamera Camera metadata accessor.
     */
    public function camera(): StructuredCamera
    {
        return $this->camera;
    }

    /**
     * Returns the structured lens metadata derived from the raw EXIF document.
     *
     * @return StructuredLens Lens metadata accessor.
     */
    public function lens(): StructuredLens
    {
        return $this->lens;
    }

    /**
     * Returns the structured exposure metadata derived from the raw EXIF document.
     *
     * @return StructuredExposure Exposure metadata accessor.
     */
    public function exposure(): StructuredExposure
    {
        return $this->exposure;
    }

    /**
     * Returns the structured GPS metadata derived from the raw EXIF document.
     *
     * @return StructuredGps GPS metadata accessor.
     */
    public function gps(): StructuredGps
    {
        return $this->gps;
    }

    /**
     * Returns the structured image metadata derived from the raw EXIF document.
     *
     * @return StructuredImage Image metadata accessor.
     */
    public function image(): StructuredImage
    {
        return $this->image;
    }

    /**
     * Returns the structured preview metadata derived from the raw EXIF document.
     *
     * @return StructuredPreview Preview metadata accessor.
     */
    public function preview(): StructuredPreview
    {
        return $this->preview;
    }

    /**
     * Returns the interoperability metadata extracted from the raw EXIF document.
     *
     * @return InteropValue Interoperability metadata accessor.
     */
    public function interop(): InteropValue
    {
        return $this->interop;
    }

    /**
     * Returns the best-effort ISO value from the raw EXIF document, if available.
     *
     * @return int|null Normalised ISO value or null when unknown.
     */
    public function iso(): ?int
    {
        return $this->raw?->isoBestEffort();
    }

    /**
     * Returns the original capture timestamp resolved from the raw EXIF document.
     *
     * @return DateTimeImmutable|null Capture timestamp or null when unavailable.
     */
    public function dateTimeOriginal(): ?DateTimeImmutable
    {
        return $this->raw?->dateTimeOriginalBestEffort();
    }

    /**
     * Returns the user comment stored in the EXIF document without re-encoding.
     *
     * @return string|null Raw user comment or null when absent.
     */
    public function userComment(): ?string
    {
        return $this->raw?->userComment();
    }

    /**
     * Returns the best-effort encoding label for the user comment.
     *
     * @return string|null Encoding hint or null when unknown.
     */
    public function userCommentEncoding(): ?string
    {
        return $this->raw?->userCommentEncodingBestEffort();
    }

    /**
     * Indicates whether the document contains parsed EXIF data.
     *
     * @return bool True when a parsed EXIF document is available.
     */
    public function hasData(): bool
    {
        return $this->raw instanceof ModelExifDocument;
    }

    /**
     * Returns the underlying raw EXIF document instance.
     *
     * @return ModelExifDocument|null Raw model or null when no EXIF data was parsed.
     */
    public function raw(): ?ModelExifDocument
    {
        return $this->raw;
    }

    /**
     * Creates a camera value object from the parsed EXIF document.
     *
     * Falls back to an empty value object when the document is missing and
     * resolves firmware information by checking firmware, firmware version and
     * software tags in that order.
     *
     * @param ModelExifDocument|null $document Parsed EXIF document.
     *
     * @return CameraValue Normalised camera metadata.
     */
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

    /**
     * Creates a lens value object from the parsed EXIF document.
     *
     * Falls back to an empty value object when the document is missing and
     * converts the maximum aperture apex value to an F-number when present.
     *
     * @param ModelExifDocument|null $document Parsed EXIF document.
     *
     * @return LensValue Normalised lens metadata.
     */
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

    /**
     * Creates an exposure value object from the parsed EXIF document.
     *
     * Falls back to an empty value object when the document is missing and
     * maps numeric codes to their enum representations when available.
     *
     * @param ModelExifDocument|null $document Parsed EXIF document.
     *
     * @return ExposureValue Normalised exposure metadata.
     */
    private function createExposureValue(?ModelExifDocument $document): ExposureValue
    {
        if (!$document instanceof ModelExifDocument) {
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

        $flashInfo = ExifFlash::fromExifValue($document->flash());

        return new ExposureValue(
            iso: $document->isoBestEffort(),
            exposureTimeSec: $document->exposureTime(),
            fNumber: $document->fNumber(),
            exposureBiasEv: $document->exposureBias(),
            program: $document->exposureProgram(),
            meteringMode: $document->meteringMode(),
            flash: $flashInfo,
            whiteBalance: $document->whiteBalance(),
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

    /**
     * Creates derived exposure and lens values calculated from the provided metadata.
     *
     * @param LensValue     $lens     Lens metadata used for calculations.
     * @param ExposureValue $exposure Exposure metadata used for calculations.
     *
     * @return Derived Calculated helper metrics such as EV100 and hyperfocal distance.
     */
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

    /**
     * Creates a GPS value object from the parsed EXIF document.
     *
     * Falls back to an empty GPS value when the document is missing.
     *
     * @param ModelExifDocument|null $document Parsed EXIF document.
     *
     * @return GpsValue Normalised GPS metadata.
     */
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
        // ModelExifDocument::gps() returns a normalized array of EXIF GPSInfo tags
        // (EXIF 3.0 §4.6.8; EXIF 2.32 §4.6.8 retains the same tag catalogue).
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

    /**
     * Creates an image value object from the parsed EXIF document.
     *
     * Falls back to supplied width, height and bit depth values when the EXIF
     * tags are missing and resolves the orientation and color space enums.
     *
     * @param ModelExifDocument|null $document              Parsed EXIF document.
     * @param int|null               $fallbackWidth         Width used when the document is missing the tag.
     * @param int|null               $fallbackHeight        Height used when the document is missing the tag.
     * @param int|null               $fallbackBitsPerSample Bit depth used when the document is missing the tag.
     *
     * @return ImageValue Normalised image metadata.
     */
    private function createImageValue(
        ?ModelExifDocument $document,
        ?int $fallbackWidth,
        ?int $fallbackHeight,
        ?int $fallbackBitsPerSample,
    ): ImageValue {
        $orientation = $document?->orientation();

        $colorSpace = $document?->colorSpace();

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

    /**
     * Creates a preview value object from the parsed EXIF document.
     *
     * Falls back to an empty preview value when the document is missing and
     * resolves color space and compression enums when tags are available.
     *
     * @param ModelExifDocument|null $document Parsed EXIF document.
     *
     * @return PreviewValue Normalised preview metadata.
     */
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
