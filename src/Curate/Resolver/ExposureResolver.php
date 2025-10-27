<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\FlashInfo;

/**
 * Resolves exposure related metadata by preferring EXIF tags.
 */
final readonly class ExposureResolver
{

    private const string NS_EXIF = 'http://ns.adobe.com/exif/1.0/';

    /**
     * Builds an exposure value object from the provided metadata.
     */
    public function resolve(?ExifDocument $exifDocument, ?XmpDocument $xmpDocument): ?Exposure
    {
        $iso          = $exifDocument?->iso() ?? $xmpDocument?->int(self::NS_EXIF, 'ISOSpeedRatings');
        $exposureTime = $exifDocument?->exposureTime() ?? $xmpDocument?->float(self::NS_EXIF, 'ExposureTime');
        $aperture     = $exifDocument?->fNumber() ?? $xmpDocument?->float(self::NS_EXIF, 'FNumber');
        $exposureBias = $exifDocument?->exposureBias();
        $program      = ExposureProgram::fromExifValue($exifDocument?->exposureProgram() ?? $xmpDocument?->int(self::NS_EXIF, 'ExposureProgram'));
        $metering     = MeteringMode::fromExifValue($exifDocument?->meteringMode() ?? $xmpDocument?->int(self::NS_EXIF, 'MeteringMode'));
        $flash        = FlashInfo::fromExifValue($exifDocument?->flash() ?? $xmpDocument?->int(self::NS_EXIF, 'Flash'));
        $whiteBalance = WhiteBalance::fromExifValue($exifDocument?->whiteBalance() ?? $xmpDocument?->int(self::NS_EXIF, 'WhiteBalance'));
        $brightness   = $exifDocument?->brightnessValue();

        if (
            $iso === null
            && $exposureTime === null
            && $aperture === null
            && $exposureBias === null
            && !$program instanceof ExposureProgram
            && !$metering instanceof MeteringMode
            && !$whiteBalance instanceof WhiteBalance
            && !$flash instanceof FlashInfo
            && $brightness === null
        ) {
            return null;
        }

        return new Exposure(
            iso: $iso,
            exposureTimeSec: $exposureTime,
            fNumber: $aperture,
            exposureBiasEv: $exposureBias,
            program: $program,
            meteringMode: $metering,
            flash: $flash,
            whiteBalance: $whiteBalance,
            brightnessEv: $brightness,
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
}
