<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Factory;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Enum as GpsEnum;
use MagicSunday\ImageMeta\Value\Gps;

use function array_any;
use function array_map;
use function count;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function preg_split;
use function round;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtoupper;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Factory for creating GPS value objects from EXIF and XMP metadata.
 */
final readonly class GpsFactory
{
    private const string NS_EXIF = 'http://ns.adobe.com/exif/1.0/';

    /**
     * Creates a GPS value object from EXIF and XMP metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return Gps GPS metadata aggregate or empty GPS when no data is available.
     */
    public function create(Metadata $metadata): Gps
    {
        $xmpDocument = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
        $gps         = $this->resolveGps($metadata->exifDoc, $xmpDocument);

        if (!$gps instanceof Gps) {
            return new Gps();
        }

        return $gps;
    }

    /**
     * Builds a GPS value object from the available metadata.
     *
     * The GPS version defaults to 2.4.0.0 whenever EXIF omits the tag or only exposes padding bytes.
     */
    private function resolveGps(?ParsedExif $exifDocument, ?XmpDocument $xmpDocument): ?Gps
    {
        /**
         * @var array{
         *     lat_ref: string|null,
         *     lat: float|null,
         *     lon_ref: string|null,
         *     lon: float|null,
         *     alt_ref: int|null,
         *     alt: float|null,
         *     version: string|null,
         *     version_raw: string|null,
         *     satellites: string|null,
         *     status: string|null,
         *     measure_mode: string|null,
         *     dop: float|null,
         *     speed_ref: string|null,
         *     speed_ms: float|null,
         *     speed_original_ref: string|null,
         *     speed_original: float|null,
         *     track_ref: string|null,
         *     track: float|null,
         *     img_direction_ref: string|null,
         *     img_direction: float|null,
         *     map_datum: string|null,
         *     dest_lat_ref: string|null,
         *     dest_lat: float|null,
         *     dest_lon_ref: string|null,
         *     dest_lon: float|null,
         *     dest_bearing_ref: string|null,
         *     dest_bearing: float|null,
         *     dest_distance_ref: string|null,
         *     dest_distance_m: float|null,
         *     dest_distance_original_ref: string|null,
         *     dest_distance_original: float|null,
         *     processing_method: string|null,
         *     area_information: string|null,
         *     date: string|null,
         *     date_raw: string|null,
         *     time: string|null,
         *     timestamp: DateTimeImmutable|null,
         *     differential: int|null,
         *     h_positioning_error: float|null,
         * } $gpsData
         */
        $gpsData = $exifDocument?->gps() ?? ValueConverters::emptyGpsResult();

        $latitude     = $this->floatValue($gpsData['lat']);
        $longitude    = $this->floatValue($gpsData['lon']);
        $latitudeRef  = $this->uppercase($gpsData['lat_ref']);
        $longitudeRef = $this->uppercase($gpsData['lon_ref']);
        $altitude     = $this->floatValue($gpsData['alt']);
        $altitudeRef  = $this->intValue($gpsData['alt_ref']);

        $version    = $this->stringValue($gpsData['version']);
        $versionRaw = $gpsData['version_raw'];
        if (!is_string($versionRaw)) {
            $versionRaw = null;
        }

        $satellites       = $this->stringValue($gpsData['satellites']);
        $status           = $this->stringValue($gpsData['status']);
        $measureMode      = $this->stringValue($gpsData['measure_mode']);
        $dop              = $this->floatValue($gpsData['dop']);
        $speedRef         = $this->uppercase($gpsData['speed_ref']);
        $speedMs          = $this->floatValue($gpsData['speed_ms']);
        $speedOriginalRef = $this->stringValue($gpsData['speed_original_ref']);
        $speedOriginal    = $this->floatValue($gpsData['speed_original']);
        $trackRef         = $this->uppercase($gpsData['track_ref']);
        $track            = $this->floatValue($gpsData['track']);
        $imgDirRef        = $this->uppercase($gpsData['img_direction_ref']);
        $imgDir           = $this->floatValue($gpsData['img_direction']);
        $mapDatum         = $this->stringValue($gpsData['map_datum']);

        $destLatRef          = $this->uppercase($gpsData['dest_lat_ref']);
        $destLat             = $this->floatValue($gpsData['dest_lat']);
        $destLonRef          = $this->uppercase($gpsData['dest_lon_ref']);
        $destLon             = $this->floatValue($gpsData['dest_lon']);
        $destBearRef         = $this->uppercase($gpsData['dest_bearing_ref']);
        $destBear            = $this->floatValue($gpsData['dest_bearing']);
        $destDistRef         = $this->uppercase($gpsData['dest_distance_ref']);
        $destDistMetre       = $this->floatValue($gpsData['dest_distance_m']);
        $destDistOriginalRef = $this->stringValue($gpsData['dest_distance_original_ref']);
        $destDistOriginal    = $this->floatValue($gpsData['dest_distance_original']);

        $processingMethod = $this->stringValue($gpsData['processing_method']);
        $areaInformation  = $this->stringValue($gpsData['area_information']);

        $date    = $this->normaliseDate($this->stringValue($gpsData['date']));
        $dateRaw = $gpsData['date_raw'];
        if (!is_string($dateRaw)) {
            $dateRaw = null;
        }

        $time = $this->stringValue($gpsData['time']);

        $timestamp = $exifDocument?->gpsTimestamp();
        if (!$timestamp instanceof DateTimeImmutable) {
            $timestamp = null;
        }

        if ($date === null) {
            $date = $this->normaliseDate($exifDocument?->gpsDateStamp());
        }

        if ($time === null) {
            $time = $this->stringValue($exifDocument?->gpsTimeStampString());
        }

        // Fill from XMP when EXIF values are absent.
        $xmpLatRef = $this->uppercase($xmpDocument?->string(self::NS_EXIF, 'GPSLatitudeRef'));
        if ($latitudeRef === null) {
            $latitudeRef = $xmpLatRef;
        }

        if ($latitude === null) {
            $latitude = $this->parseCoordinate(
                $xmpDocument?->string(self::NS_EXIF, 'GPSLatitude'),
                $xmpLatRef ?? $latitudeRef,
            );
        }

        $xmpLonRef = $this->uppercase($xmpDocument?->string(self::NS_EXIF, 'GPSLongitudeRef'));
        if ($longitudeRef === null) {
            $longitudeRef = $xmpLonRef;
        }

        if ($longitude === null) {
            $longitude = $this->parseCoordinate(
                $xmpDocument?->string(self::NS_EXIF, 'GPSLongitude'),
                $xmpLonRef ?? $longitudeRef,
            );
        }

        if ($latitude !== null) {
            $latitude = round($latitude, 6);
        }

        if ($longitude !== null) {
            $longitude = round($longitude, 6);
        }

        if ($altitude === null && $xmpDocument instanceof XmpDocument) {
            $altitudeXmp = $xmpDocument->float(self::NS_EXIF, 'GPSAltitude');
            if ($altitudeXmp !== null) {
                $altRefXmp = $this->intValue($xmpDocument->int(self::NS_EXIF, 'GPSAltitudeRef'));
                $altRef    = $altitudeRef ?? $altRefXmp;

                if ($altRef === 1 || $altRef === 3) {
                    $altitudeXmp = -$altitudeXmp;
                }

                $altitude = $altitudeXmp;

                $altitudeRef ??= $altRefXmp;
            }
        }

        if ($status === null) {
            $status = $this->uppercase($xmpDocument?->string(self::NS_EXIF, 'GPSStatus'));
        }

        if ($measureMode === null) {
            $measureMode = $this->stringValue($xmpDocument?->string(self::NS_EXIF, 'GPSMeasureMode'));
        }

        if ($dop === null) {
            $dop = $this->floatValue($xmpDocument?->float(self::NS_EXIF, 'GPSDOP'));
        }

        if ($trackRef === null) {
            $trackRef = $this->uppercase($xmpDocument?->string(self::NS_EXIF, 'GPSTrackRef'));
        }

        if ($track === null) {
            $track = $this->floatValue($xmpDocument?->float(self::NS_EXIF, 'GPSTrack'));
        }

        if ($imgDirRef === null) {
            $imgDirRef = $this->uppercase($xmpDocument?->string(self::NS_EXIF, 'GPSImgDirectionRef'));
        }

        if ($imgDir === null) {
            $imgDir = $this->floatValue($xmpDocument?->float(self::NS_EXIF, 'GPSImgDirection'));
        }

        if ($mapDatum === null) {
            $mapDatum = $this->stringValue($xmpDocument?->string(self::NS_EXIF, 'GPSMapDatum'));
        }

        $xmpSpeedRef = $xmpDocument?->string(self::NS_EXIF, 'GPSSpeedRef');
        if ($speedRef === null) {
            $speedRef = $this->uppercase($xmpSpeedRef);
        }

        if ($speedOriginalRef === null) {
            $speedOriginalRef = $this->stringValue($xmpSpeedRef);
        }

        $speedValue = $xmpDocument?->float(self::NS_EXIF, 'GPSSpeed');
        if ($speedValue !== null) {
            if ($speedMs === null && $speedRef !== null) {
                $speedMs = $this->convertSpeedToMetresPerSecond($speedValue, $speedRef);
            }

            if ($speedOriginal === null) {
                $speedOriginal = $speedValue;
            }
        }

        $xmpDestLatRef = $this->uppercase($xmpDocument?->string(self::NS_EXIF, 'GPSDestLatitudeRef'));
        if ($destLatRef === null) {
            $destLatRef = $xmpDestLatRef;
        }

        if ($destLat === null) {
            $destLat = $this->parseCoordinate(
                $xmpDocument?->string(self::NS_EXIF, 'GPSDestLatitude'),
                $xmpDestLatRef ?? $destLatRef,
            );
        }

        $xmpDestLonRef = $this->uppercase($xmpDocument?->string(self::NS_EXIF, 'GPSDestLongitudeRef'));
        if ($destLonRef === null) {
            $destLonRef = $xmpDestLonRef;
        }

        if ($destLon === null) {
            $destLon = $this->parseCoordinate(
                $xmpDocument?->string(self::NS_EXIF, 'GPSDestLongitude'),
                $xmpDestLonRef ?? $destLonRef,
            );
        }

        $xmpDestBearRef = $this->uppercase($xmpDocument?->string(self::NS_EXIF, 'GPSDestBearingRef'));
        if ($destBearRef === null) {
            $destBearRef = $xmpDestBearRef;
        }

        if ($destBear === null) {
            $xmpDestBear = $xmpDocument?->float(self::NS_EXIF, 'GPSDestBearing');
            if ($xmpDestBear !== null) {
                $destBear = $xmpDestBear;
            }
        }

        $xmpDestDistRef = $xmpDocument?->string(self::NS_EXIF, 'GPSDestDistanceRef');
        if ($destDistRef === null) {
            $destDistRef = $this->uppercase($xmpDestDistRef);
        }

        if ($destDistOriginalRef === null) {
            $destDistOriginalRef = $this->stringValue($xmpDestDistRef);
        }

        $destDistValue = $xmpDocument?->float(self::NS_EXIF, 'GPSDestDistance');
        if ($destDistValue !== null) {
            if ($destDistMetre === null && $destDistRef !== null) {
                $convertedDistance = $this->convertDistanceToMetres($destDistValue, $destDistRef);
                if ($convertedDistance !== null) {
                    $destDistMetre = $convertedDistance;
                }
            }

            if ($destDistOriginal === null) {
                $destDistOriginal = $destDistValue;
            }
        }

        if ($date === null) {
            $date = $this->normaliseDate($xmpDocument?->string(self::NS_EXIF, 'GPSDateStamp'));
        }

        if ($time === null) {
            $time = $this->stringValue($xmpDocument?->string(self::NS_EXIF, 'GPSTimeStamp'));
        }

        if (!$timestamp instanceof DateTimeImmutable) {
            $timestamp = $this->parseXmpTimestamp($xmpDocument);
        }

        if (!$timestamp instanceof DateTimeImmutable) {
            $timestamp = $this->combineDateAndTime($date, $time);
        }

        $differential = $this->intValue($gpsData['differential'] ?? null);
        $hError       = $this->floatValue($gpsData['h_positioning_error'] ?? null);
        $hasData      = array_any([
            $latitude,
            $longitude,
            $altitude,
            $altitudeRef,
            $version,
            $versionRaw,
            $satellites,
            $status,
            $measureMode,
            $dop,
            $speedRef,
            $speedMs,
            $speedOriginalRef,
            $speedOriginal,
            $trackRef,
            $track,
            $imgDirRef,
            $imgDir,
            $mapDatum,
            $destLatRef,
            $destLat,
            $destLonRef,
            $destLon,
            $destBearRef,
            $destBear,
            $destDistRef,
            $destDistMetre,
            $destDistOriginalRef,
            $destDistOriginal,
            $processingMethod,
            $areaInformation,
            $date,
            $dateRaw,
            $time,
            $timestamp,
            $differential,
            $hError,
        ], fn ($value): bool => $value !== null);

        if (!$hasData) {
            return null;
        }

        return new Gps(
            latitude: $latitude,
            longitude: $longitude,
            latitudeRef: $this->toGpsLatLonRef($latitudeRef),
            longitudeRef: $this->toGpsLatLonRef($longitudeRef),
            altitude: $altitude,
            altitudeRef: $this->toGpsAltitudeRef($altitudeRef),
            version: $version,
            versionRaw: $versionRaw,
            satellites: $satellites,
            status: $this->toGpsStatus($status),
            measureMode: $this->toGpsMeasureMode($measureMode),
            dop: $dop,
            speedRef: $this->toGpsSpeedRef($speedRef),
            speedMs: $speedMs,
            speedOriginalRef: $this->toGpsSpeedRef($speedOriginalRef),
            speedOriginal: $speedOriginal,
            trackRef: $this->toGpsDirectionRef($trackRef),
            track: $track,
            imageDirectionRef: $this->toGpsDirectionRef($imgDirRef),
            imageDirection: $imgDir,
            mapDatum: $mapDatum,
            destinationLatitudeRef: $this->toGpsLatLonRef($destLatRef),
            destinationLatitude: $destLat,
            destinationLongitudeRef: $this->toGpsLatLonRef($destLonRef),
            destinationLongitude: $destLon,
            destinationBearingRef: $this->toGpsDirectionRef($destBearRef),
            destinationBearing: $destBear,
            destinationDistanceRef: $this->toGpsDistanceRef($destDistRef),
            destinationDistanceMetres: $destDistMetre,
            destinationDistanceOriginalRef: $this->toGpsDistanceRef($destDistOriginalRef),
            destinationDistanceOriginal: $destDistOriginal,
            processingMethod: $processingMethod,
            areaInformation: $areaInformation,
            date: $date,
            dateRaw: $dateRaw,
            time: $time,
            timestamp: $timestamp,
            differential: $this->toGpsDifferential($differential),
            horizontalPositioningError: $hError,
        );
    }

    /**
     * Parses an XMP coordinate representation.
     */
    private function parseCoordinate(?string $value, ?string $ref): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = preg_split('/[\\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return null;
        }

        $parts = array_map(
            trim(...),
            $parts,
        );

        if (count($parts) === 3) {
            $deg = XmpDocument::parseNumericValue($parts[0]);
            $min = XmpDocument::parseNumericValue($parts[1]);
            $sec = XmpDocument::parseNumericValue($parts[2]);

            if ($deg !== null && $min !== null && $sec !== null) {
                if ($deg < 0.0 || $min < 0.0 || $sec < 0.0) {
                    return null;
                }

                if ($min >= 60.0 || $sec >= 60.0) {
                    return null;
                }

                $sign = $this->coordinateSign($ref);
                if ($sign === null) {
                    return null;
                }

                $coordinate = $sign * ($deg + ($min / 60.0) + ($sec / 3600.0));

                return $this->validateCoordinateRange(round($coordinate, 6), $ref);
            }
        }

        if (count($parts) !== 1) {
            return null;
        }

        $numeric = XmpDocument::parseNumericValue($parts[0]);
        if ($numeric === null || $numeric < 0.0) {
            return null;
        }

        $sign = $this->coordinateSign($ref);
        if ($sign === null) {
            return null;
        }

        $coordinate = $numeric * $sign;

        return $this->validateCoordinateRange(round($coordinate, 6), $ref);
    }

    /**
     * Returns the coordinate if within geographic bounds, null otherwise.
     */
    private function validateCoordinateRange(float $coordinate, ?string $ref): ?float
    {
        $isLatitude = $ref === 'N' || $ref === 'S';
        $limit      = $isLatitude ? 90.0 : 180.0;

        if ($coordinate < -$limit || $coordinate > $limit) {
            return null;
        }

        return $coordinate;
    }

    /**
     * Determines the sign for the given coordinate reference.
     */
    private function coordinateSign(?string $ref): ?float
    {
        return match ($ref) {
            'N', 'E' => 1.0,
            'S', 'W' => -1.0,
            default => null,
        };
    }

    /**
     * Normalises a textual value to uppercase when present.
     */
    private function uppercase(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return strtoupper($trimmed);
    }

    /**
     * Returns the value as string when not empty.
     */
    private function stringValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Returns the value as float when numeric.
     */
    private function floatValue(int|float|null $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Returns the value as integer when numeric.
     */
    private function intValue(int|float|null $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        return null;
    }

    /**
     * Converts a textual GPS date into ISO format.
     */
    private function normaliseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\\d{4}:\\d{2}:\\d{2}$/', $trimmed) === 1) {
            return str_replace(':', '-', $trimmed);
        }

        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $trimmed) === 1) {
            return $trimmed;
        }

        return null;
    }

    /**
     * Parses an XMP GPSDateTime value.
     */
    private function parseXmpTimestamp(?XmpDocument $document): ?DateTimeImmutable
    {
        $value = $document?->string(self::NS_EXIF, 'GPSDateTime');
        if ($value === null) {
            return null;
        }

        // Accept only ISO 8601 date-time: YYYY-MM-DDThh:mm:ss[.frac][Z|±hh:mm]
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/', $value) !== 1) {
            return null;
        }

        try {
            // Parse with UTC fallback for timezone-less values (GPS time is always UTC)
            $dateTime = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Combines the supplied date and time strings into a UTC timestamp.
     */
    private function combineDateAndTime(?string $date, ?string $time): ?DateTimeImmutable
    {
        if ($date === null || $time === null) {
            return null;
        }

        $time = trim($time);
        if ($time === '') {
            return null;
        }

        $dateTimeString = sprintf('%sT%s', $date, $time);
        $hasZone        = str_contains($time, 'Z') || str_contains($time, 'z')
            || str_contains($time, '+') || str_contains($time, '-');

        if (!$hasZone) {
            $dateTimeString .= 'Z';
        }

        try {
            $dateTime = new DateTimeImmutable($dateTimeString);
        } catch (Exception) {
            return null;
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Converts destination distance into metres using GPSDestDistanceRef semantics.
     */
    private function convertDistanceToMetres(float $distance, string $distanceRef): ?float
    {
        return match ($distanceRef) {
            'K'     => $distance * 1000.0,
            'M'     => $distance * 1609.344,
            'N'     => $distance * 1852.0,
            default => null,
        };
    }

    /**
     * Converts speed in the provided unit to metres per second using GPSSpeedRef semantics.
     */
    private function convertSpeedToMetresPerSecond(float $speed, string $speedRef): ?float
    {
        return match ($speedRef) {
            'K'     => $speed / 3.6,
            'M'     => $speed * 0.44704,
            'N'     => $speed * 0.514444,
            default => null,
        };
    }

    /**
     * Converts string latitude/longitude reference to enum.
     *
     * EXIF 3.0 §4.6.6 Table 27.
     */
    private function toGpsLatLonRef(?string $value): ?GpsEnum\GpsLatLonRef
    {
        return GpsEnum\GpsLatLonRef::fromExifValue($value);
    }

    /**
     * Converts int/string altitude reference to enum.
     *
     * EXIF 3.0 §4.6.6 Table 27.
     */
    private function toGpsAltitudeRef(?int $value): ?GpsEnum\GpsAltitudeRef
    {
        return GpsEnum\GpsAltitudeRef::fromExifValue($value);
    }

    /**
     * Converts string GPS status to enum.
     *
     * EXIF 3.0 §4.6.6 Table 27.
     */
    private function toGpsStatus(?string $value): ?GpsEnum\GpsStatus
    {
        return GpsEnum\GpsStatus::fromExifValue($value);
    }

    /**
     * Converts string GPS measure mode to enum.
     *
     * EXIF 3.0 §4.6.6 Table 27.
     */
    private function toGpsMeasureMode(?string $value): ?GpsEnum\GpsMeasureMode
    {
        return GpsEnum\GpsMeasureMode::fromExifValue($value);
    }

    /**
     * Converts string speed reference to enum.
     *
     * EXIF 3.0 §4.6.6 Table 27.
     */
    private function toGpsSpeedRef(?string $value): ?GpsEnum\GpsSpeedRef
    {
        return GpsEnum\GpsSpeedRef::fromExifValue($value);
    }

    /**
     * Converts string direction reference to enum.
     *
     * EXIF 3.0 §4.6.6 Table 27.
     */
    private function toGpsDirectionRef(?string $value): ?GpsEnum\GpsDirectionRef
    {
        return GpsEnum\GpsDirectionRef::fromExifValue($value);
    }

    /**
     * Converts string distance reference to enum.
     *
     * EXIF 3.0 §4.6.6 Table 27.
     */
    private function toGpsDistanceRef(?string $value): ?GpsEnum\GpsDistanceRef
    {
        return GpsEnum\GpsDistanceRef::fromExifValue($value);
    }

    /**
     * Converts int/string differential to enum.
     *
     * EXIF 3.0 §4.6.6 Table 27.
     */
    private function toGpsDifferential(?int $value): ?GpsEnum\GpsDifferential
    {
        return GpsEnum\GpsDifferential::fromExifValue($value);
    }
}
