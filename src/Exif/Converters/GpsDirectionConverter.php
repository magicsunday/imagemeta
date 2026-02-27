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
        // EXIF 3.0 §4.6.7.1.15 GPSTrackRef: 'T' or 'M'; default 'T'
        [$trackRef, $track] = $this->extractBearing($gps, ExifTag::GPS_TRACK_REF, ExifTag::GPS_TRACK);

        // EXIF 3.0 §4.6.7.1.17 GPSImgDirectionRef: 'T' or 'M'; default 'T'
        [$imgDirectionRef, $imgDirection] = $this->extractBearing(
            $gps,
            ExifTag::GPS_IMG_DIRECTION_REF,
            ExifTag::GPS_IMG_DIRECTION,
        );

        // EXIF 3.0 §4.6.7.1.24 GPSDestBearingRef: 'T' or 'M'; default 'T'
        [$destBearingRef, $destBearing] = $this->extractBearing(
            $gps,
            ExifTag::GPS_DEST_BEARING_REF,
            ExifTag::GPS_DEST_BEARING,
        );

        return [
            'track_ref'         => $trackRef,
            'track'             => $track,
            'img_direction_ref' => $imgDirectionRef,
            'img_direction'     => $imgDirection,
            'dest_bearing_ref'  => $destBearingRef,
            'dest_bearing'      => $destBearing,
        ];
    }

    /**
     * Resolves a bearing reference/value pair with EXIF defaulting semantics.
     *
     * @return array{0:?string, 1:?float}
     */
    private function extractBearing(Ifd $gps, int $referenceTag, int $valueTag): array
    {
        $referenceEntry     = $gps->get($referenceTag);
        $valueEntry         = $gps->get($valueTag);
        $referenceRaw       = $referenceEntry?->value;
        $referenceCandidate = is_string($referenceRaw) ? strtoupper(trim($referenceRaw)) : null;
        $reference          = $this->validateGpsRef($referenceCandidate, self::GPS_BEARING_REF_VALUES);
        $referenceInvalid   = ($referenceCandidate !== null) && ($reference === null);

        if (($reference === null) && (!$referenceEntry instanceof IfdEntry) && ($valueEntry instanceof IfdEntry)) {
            $reference = 'T';
        }

        $bearingValue = $this->rationalConverter->toFloat($valueEntry?->value);
        $bearing      = $referenceInvalid ? null : $this->normalizeBearing($bearingValue);

        return [$reference, $bearing];
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
