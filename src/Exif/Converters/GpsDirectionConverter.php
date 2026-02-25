<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;

use function fmod;
use function is_string;
use function strtoupper;
use function trim;

/**
 * Normalizes GPS bearing and direction values to the [0, 360) interval.
 *
 * EXIF 3.0 §4.6.7.1.15–§4.6.7.1.18 (track/image direction) and
 * §4.6.7.1.24–§4.6.7.1.25 (destination bearing) define the tags decoded here.
 */
final readonly class GpsDirectionConverter
{
    use ValidatesGpsRef;

    /**
     * EXIF 3.0 §4.6.7.1.15 GPSTrackRef, §4.6.7.1.17 GPSImgDirectionRef, §4.6.7.1.24 GPSDestBearingRef:
     * 'T' (true direction) or 'M' (magnetic direction).
     *
     * @var list<string>
     */
    private const array GPS_BEARING_REF_VALUES = ['T', 'M'];

    /**
     * @param RationalConverter $rationalConverter Dependency for rational conversions.
     */
    public function __construct(
        private RationalConverter $rationalConverter,
    ) {
    }

    /**
     * Extracts track, image direction and destination bearing data from a GPS IFD.
     *
     * @return array{
     *     track_ref: ?string,
     *     track: ?float,
     *     img_direction_ref: ?string,
     *     img_direction: ?float,
     *     dest_bearing_ref: ?string,
     *     dest_bearing: ?float,
     * }
     */
    public function extractFromIfd(Ifd $gps): array
    {
        $result = [
            'track_ref'         => null,
            'track'             => null,
            'img_direction_ref' => null,
            'img_direction'     => null,
            'dest_bearing_ref'  => null,
            'dest_bearing'      => null,
        ];

        // EXIF 3.0 §4.6.7.1.15 GPSTrackRef: 'T' or 'M'; default 'T'
        $trackRefEntry       = $gps->get(ExifTag::GPS_TRACK_REF);
        $trackEntry          = $gps->get(ExifTag::GPS_TRACK);
        $trackRefValue       = $trackRefEntry?->value;
        $trackRefNormalized  = is_string($trackRefValue) ? strtoupper(trim($trackRefValue)) : null;
        $result['track_ref'] = $this->validateGpsRef($trackRefNormalized, self::GPS_BEARING_REF_VALUES);
        $trackRefInvalid     = ($trackRefNormalized !== null) && ($result['track_ref'] === null);
        if ($result['track_ref'] === null && !$trackRefEntry instanceof IfdEntry && $trackEntry instanceof IfdEntry) {
            $result['track_ref'] = 'T';
        }

        $trackValue      = $this->rationalConverter->toFloat($trackEntry?->value);
        $result['track'] = $trackRefInvalid ? null : $this->normalizeBearing($trackValue);

        // EXIF 3.0 §4.6.7.1.17 GPSImgDirectionRef: 'T' or 'M'; default 'T'
        $imgDirRefEntry              = $gps->get(ExifTag::GPS_IMG_DIRECTION_REF);
        $imgDirEntry                 = $gps->get(ExifTag::GPS_IMG_DIRECTION);
        $imgDirRefValue              = $imgDirRefEntry?->value;
        $imgDirRefNormalized         = is_string($imgDirRefValue) ? strtoupper(trim($imgDirRefValue)) : null;
        $result['img_direction_ref'] = $this->validateGpsRef($imgDirRefNormalized, self::GPS_BEARING_REF_VALUES);
        $imgDirRefInvalid            = ($imgDirRefNormalized !== null) && ($result['img_direction_ref'] === null);
        if ($result['img_direction_ref'] === null && !$imgDirRefEntry instanceof IfdEntry && $imgDirEntry instanceof IfdEntry) {
            $result['img_direction_ref'] = 'T';
        }

        $imgDirectionValue       = $this->rationalConverter->toFloat($imgDirEntry?->value);
        $result['img_direction'] = $imgDirRefInvalid ? null : $this->normalizeBearing($imgDirectionValue);

        // EXIF 3.0 §4.6.7.1.24 GPSDestBearingRef: 'T' or 'M'; default 'T'
        $destBearRefEntry           = $gps->get(ExifTag::GPS_DEST_BEARING_REF);
        $destBearEntry              = $gps->get(ExifTag::GPS_DEST_BEARING);
        $destBearingRefValue        = $destBearRefEntry?->value;
        $destBearingRefNormalized   = is_string($destBearingRefValue) ? strtoupper(trim($destBearingRefValue)) : null;
        $result['dest_bearing_ref'] = $this->validateGpsRef($destBearingRefNormalized, self::GPS_BEARING_REF_VALUES);
        $destBearingRefInvalid      = ($destBearingRefNormalized !== null) && ($result['dest_bearing_ref'] === null);
        if ($result['dest_bearing_ref'] === null && !$destBearRefEntry instanceof IfdEntry && $destBearEntry instanceof IfdEntry) {
            $result['dest_bearing_ref'] = 'T';
        }

        $destBearingValue       = $this->rationalConverter->toFloat($destBearEntry?->value);
        $result['dest_bearing'] = $destBearingRefInvalid ? null : $this->normalizeBearing($destBearingValue);

        return $result;
    }

    /**
     * Validates a compass bearing is within the strict [0, 360) range.
     *
     * EXIF 3.0 §4.6.7.1.16/§4.6.7.1.18/§4.6.7.1.25: bearings must be 0.00–359.99.
     */
    public function normalizeBearing(int|float|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $bearing = (float) $value;

        // Tolerate out-of-range bearings — normalize via modular arithmetic.
        if ($bearing < 0.0 || $bearing >= 360.0) {
            $bearing = fmod($bearing, 360.0);
            if ($bearing < 0.0) {
                $bearing += 360.0;
            }
        }

        return $bearing;
    }
}
