<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate;

use MagicSunday\ImageMeta\Curate\Resolver\AppleResolver;
use MagicSunday\ImageMeta\Curate\Resolver\CameraResolver;
use MagicSunday\ImageMeta\Curate\Resolver\CaptureResolver;
use MagicSunday\ImageMeta\Curate\Resolver\DeviceResolver;
use MagicSunday\ImageMeta\Curate\Resolver\ExposureResolver;
use MagicSunday\ImageMeta\Curate\Resolver\GpsResolver;
use MagicSunday\ImageMeta\Curate\Resolver\ImageResolver;
use MagicSunday\ImageMeta\Curate\Resolver\LensResolver;
use MagicSunday\ImageMeta\Curate\Resolver\XmpResolver;
use MagicSunday\ImageMeta\Model\Metadata;

/**
 * Builds the structured metadata aggregate by orchestrating specialised resolvers.
 */
final class StructuredMetadataBuilder
{
    private CameraResolver $cameraResolver;

    private LensResolver $lensResolver;

    private ImageResolver $imageResolver;

    private ExposureResolver $exposureResolver;

    private CaptureResolver $captureResolver;

    private GpsResolver $gpsResolver;

    private DeviceResolver $deviceResolver;

    private AppleResolver $appleResolver;

    private XmpResolver $xmpResolver;

    /**
     * @param CameraResolver|null  $cameraResolver  Resolver for camera metadata.
     * @param LensResolver|null    $lensResolver    Resolver for lens metadata.
     * @param ImageResolver|null   $imageResolver   Resolver for image metadata.
     * @param ExposureResolver|null $exposureResolver Resolver for exposure metadata.
     * @param CaptureResolver|null $captureResolver Resolver for capture metadata.
     * @param GpsResolver|null     $gpsResolver     Resolver for GPS metadata.
     * @param DeviceResolver|null  $deviceResolver  Resolver for device metadata.
     * @param AppleResolver|null   $appleResolver   Resolver for Apple metadata.
     * @param XmpResolver|null     $xmpResolver     Resolver for wrapping XMP data.
     */
    public function __construct(
        ?CameraResolver $cameraResolver = null,
        ?LensResolver $lensResolver = null,
        ?ImageResolver $imageResolver = null,
        ?ExposureResolver $exposureResolver = null,
        ?CaptureResolver $captureResolver = null,
        ?GpsResolver $gpsResolver = null,
        ?DeviceResolver $deviceResolver = null,
        ?AppleResolver $appleResolver = null,
        ?XmpResolver $xmpResolver = null,
    ) {
        $this->cameraResolver  = $cameraResolver ?? new CameraResolver();
        $this->lensResolver    = $lensResolver ?? new LensResolver();
        $this->imageResolver   = $imageResolver ?? new ImageResolver();
        $this->exposureResolver = $exposureResolver ?? new ExposureResolver();
        $this->captureResolver = $captureResolver ?? new CaptureResolver();
        $this->gpsResolver     = $gpsResolver ?? new GpsResolver();
        $this->deviceResolver  = $deviceResolver ?? new DeviceResolver();
        $this->appleResolver   = $appleResolver ?? new AppleResolver();
        $this->xmpResolver     = $xmpResolver ?? new XmpResolver();
    }

    /**
     * Builds the structured metadata aggregate from the supplied metadata container.
     */
    public function build(Metadata $metadata): StructuredMetadata
    {
        $exifDocument = $metadata->exifDoc;
        $xmpDocument  = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
        $quickTime    = $metadata->quickTime;

        return new StructuredMetadata(
            camera: $this->cameraResolver->resolve($exifDocument, $xmpDocument),
            lens: $this->lensResolver->resolve($exifDocument, $xmpDocument),
            image: $this->imageResolver->resolve($exifDocument, $xmpDocument),
            exposure: $this->exposureResolver->resolve($exifDocument, $xmpDocument),
            capture: $this->captureResolver->resolve($exifDocument, $xmpDocument),
            gps: $this->gpsResolver->resolve($exifDocument, $xmpDocument),
            device: $this->deviceResolver->resolve($quickTime, $xmpDocument),
            apple: $this->appleResolver->resolve($quickTime),
            xmp: $this->xmpResolver->resolve($xmpDocument),
        );
    }
}
