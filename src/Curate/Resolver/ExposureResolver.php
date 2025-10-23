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
    use XmpPropertyAccess;

    private const string NS_EXIF = 'http://ns.adobe.com/exif/1.0/';

    /**
     * Builds an exposure value object from the provided metadata.
     */
    public function resolve(?ExifDocument $exifDocument, ?XmpDocument $xmpDocument): ?Exposure
    {
        $iso           = $exifDocument?->iso() ?? $this->xmpInt($xmpDocument, self::NS_EXIF, 'ISOSpeedRatings');
        $exposureTime  = $exifDocument?->exposureTime() ?? $this->xmpFloat($xmpDocument, self::NS_EXIF, 'ExposureTime');
        $aperture      = $exifDocument?->fNumber() ?? $this->xmpFloat($xmpDocument, self::NS_EXIF, 'FNumber');
        $focalLength   = $exifDocument?->focalLengthMm() ?? $this->xmpFloat($xmpDocument, self::NS_EXIF, 'FocalLength');
        $program       = ExposureProgram::fromExifValue($this->xmpInt($xmpDocument, self::NS_EXIF, 'ExposureProgram'));
        $metering      = MeteringMode::fromExifValue($this->xmpInt($xmpDocument, self::NS_EXIF, 'MeteringMode'));
        $whiteBalance  = WhiteBalance::fromExifValue($this->xmpInt($xmpDocument, self::NS_EXIF, 'WhiteBalance'));
        $flash         = FlashInfo::fromExifValue($this->xmpInt($xmpDocument, self::NS_EXIF, 'Flash'));

        if (
            $iso === null
            && $exposureTime === null
            && $aperture === null
            && $focalLength === null
            && $program === null
            && $metering === null
            && $whiteBalance === null
            && $flash === null
        ) {
            return null;
        }

        return new Exposure(
            $iso,
            $exposureTime,
            $aperture,
            $focalLength,
            $program,
            $metering,
            $whiteBalance,
            $flash,
        );
    }
}
