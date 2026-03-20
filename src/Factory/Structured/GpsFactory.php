<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Factory\Structured;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Core\Util\DateTimeUtil;
use MagicSunday\ImageMeta\Core\Util\Iso6709Parser;
use MagicSunday\ImageMeta\Core\Util\StringUtil;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpNamespace;
use MagicSunday\ImageMeta\Value\Enum as GpsEnum;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\GpsDestination;
use MagicSunday\ImageMeta\Value\GpsMeasurement;
use MagicSunday\ImageMeta\Value\GpsMovement;
use MagicSunday\ImageMeta\Value\GpsPosition;
use MagicSunday\ImageMeta\Value\GpsTiming;

use function abs;
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
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Factory for creating GPS value objects from EXIF and XMP metadata.
 */
final readonly class GpsFactory
{
    public function __construct(
        private ValueConverters $converters = new ValueConverters(),
    ) {
    }

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
        $gps         = $this->resolveGps($metadata->exifDoc, $xmpDocument) ?? new Gps();

        if (!$gps->position instanceof GpsPosition) {
            $quickTimeLookup = $metadata->quickTimeLookup();
            $qtPosition      = $this->resolveQuickTimeGps($quickTimeLookup);

            if ($qtPosition instanceof GpsPosition) {
                $gps = new Gps(position: $qtPosition);
            }
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
        $gpsData = $exifDocument?->gps() ?? $this->converters->emptyGpsResult();

        $latitude     = $this->floatValue($gpsData['lat']);
        $longitude    = $this->floatValue($gpsData['lon']);
        $latitudeRef  = StringUtil::trimToUpperNull($gpsData['lat_ref']);
        $longitudeRef = StringUtil::trimToUpperNull($gpsData['lon_ref']);
        $altitude     = $this->floatValue($gpsData['alt']);
        $altitudeRef  = $this->intValue($gpsData['alt_ref']);

        $version    = StringUtil::trimToNull($gpsData['version']);
        $versionRaw = $gpsData['version_raw'];

        if (!is_string($versionRaw)) {
            $versionRaw = null;
        }

        $satellites       = StringUtil::trimToNull($gpsData['satellites']);
        $status           = StringUtil::trimToNull($gpsData['status']);
        $measureMode      = StringUtil::trimToNull($gpsData['measure_mode']);
        $dop              = $this->floatValue($gpsData['dop']);
        $speedRef         = StringUtil::trimToUpperNull($gpsData['speed_ref']);
        $speedMs          = $this->floatValue($gpsData['speed_ms']);
        $speedOriginalRef = StringUtil::trimToNull($gpsData['speed_original_ref']);
        $speedOriginal    = $this->floatValue($gpsData['speed_original']);
        $trackRef         = StringUtil::trimToUpperNull($gpsData['track_ref']);
        $track            = $this->floatValue($gpsData['track']);
        $imgDirRef        = StringUtil::trimToUpperNull($gpsData['img_direction_ref']);
        $imgDir           = $this->floatValue($gpsData['img_direction']);
        $mapDatum         = StringUtil::trimToNull($gpsData['map_datum']);

        $destLatRef          = StringUtil::trimToUpperNull($gpsData['dest_lat_ref']);
        $destLat             = $this->floatValue($gpsData['dest_lat']);
        $destLonRef          = StringUtil::trimToUpperNull($gpsData['dest_lon_ref']);
        $destLon             = $this->floatValue($gpsData['dest_lon']);
        $destBearRef         = StringUtil::trimToUpperNull($gpsData['dest_bearing_ref']);
        $destBear            = $this->floatValue($gpsData['dest_bearing']);
        $destDistRef         = StringUtil::trimToUpperNull($gpsData['dest_distance_ref']);
        $destDistMetre       = $this->floatValue($gpsData['dest_distance_m']);
        $destDistOriginalRef = StringUtil::trimToNull($gpsData['dest_distance_original_ref']);
        $destDistOriginal    = $this->floatValue($gpsData['dest_distance_original']);

        $processingMethod = StringUtil::trimToNull($gpsData['processing_method']);
        $areaInformation  = StringUtil::trimToNull($gpsData['area_information']);

        $date    = $this->normalizeDate(StringUtil::trimToNull($gpsData['date']));
        $dateRaw = $gpsData['date_raw'];

        if (!is_string($dateRaw)) {
            $dateRaw = null;
        }

        $time = StringUtil::trimToNull($gpsData['time']);

        $timestamp = $exifDocument?->gpsTimestamp();

        if (!$timestamp instanceof DateTimeImmutable) {
            $timestamp = null;
        }

        if ($date === null) {
            $date = $this->normalizeDate($exifDocument?->gpsDateStamp());
        }

        if ($time === null) {
            $time = StringUtil::trimToNull($exifDocument?->gpsTimeStampString());
        }

        [$latitude, $longitude, $latitudeRef, $longitudeRef] = $this->applyCoordinateFallbacks(
            $xmpDocument,
            $latitude,
            $longitude,
            $latitudeRef,
            $longitudeRef,
        );

        [$altitude, $altitudeRef] = $this->applyAltitudeFallbacks(
            $xmpDocument,
            $altitude,
            $altitudeRef,
        );

        [$status, $measureMode, $dop, $speedRef, $speedMs, $speedOriginalRef, $speedOriginal, $trackRef, $track, $imgDirRef, $imgDir, $mapDatum] = $this->applyMovementFallbacks(
            $xmpDocument,
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
        );

        [$destLatRef, $destLat, $destLonRef, $destLon, $destBearRef, $destBear, $destDistRef, $destDistMetre, $destDistOriginalRef, $destDistOriginal] = $this->applyDestinationFallbacks(
            $xmpDocument,
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
        );

        [$date, $time, $timestamp] = $this->applyTimingFallbacks(
            $xmpDocument,
            $date,
            $time,
            $timestamp,
        );

        $differential = $this->intValue($gpsData['differential'] ?? null);
        $hError       = $this->floatValue($gpsData['h_positioning_error'] ?? null);

        if (!$this->hasAnyGpsData(
            [$latitude, $longitude, $altitude, $altitudeRef, $mapDatum],
            [$satellites, $status, $measureMode, $dop, $differential, $hError],
            [$speedRef, $speedMs, $speedOriginalRef, $speedOriginal, $trackRef, $track, $imgDirRef, $imgDir],
            [$destLatRef, $destLat, $destLonRef, $destLon, $destBearRef, $destBear, $destDistRef, $destDistMetre, $destDistOriginalRef, $destDistOriginal],
            [$version, $versionRaw, $processingMethod, $areaInformation],
            [$date, $dateRaw, $time, $timestamp],
        )) {
            return null;
        }

        $position = new GpsPosition(
            latitude: $latitude,
            longitude: $longitude,
            latitudeRef: GpsEnum\GpsLatLonRef::fromExifValue($latitudeRef),
            longitudeRef: GpsEnum\GpsLatLonRef::fromExifValue($longitudeRef),
            altitude: $altitude,
            altitudeRef: GpsEnum\GpsAltitudeRef::fromExifValue($altitudeRef),
            mapDatum: $mapDatum,
        );

        $destination = new GpsDestination(
            latitude: $destLat,
            latitudeRef: GpsEnum\GpsLatLonRef::fromExifValue($destLatRef),
            longitude: $destLon,
            longitudeRef: GpsEnum\GpsLatLonRef::fromExifValue($destLonRef),
            bearingRef: GpsEnum\GpsDirectionRef::fromExifValue($destBearRef),
            bearing: $destBear,
            distanceRef: GpsEnum\GpsDistanceRef::fromExifValue($destDistRef),
            distanceMetres: $destDistMetre,
            distanceOriginalRef: GpsEnum\GpsDistanceRef::fromExifValue($destDistOriginalRef),
            distanceOriginal: $destDistOriginal,
        );

        $movement = new GpsMovement(
            speedRef: GpsEnum\GpsSpeedRef::fromExifValue($speedRef),
            speedMs: $speedMs,
            speedOriginalRef: GpsEnum\GpsSpeedRef::fromExifValue($speedOriginalRef),
            speedOriginal: $speedOriginal,
            trackRef: GpsEnum\GpsDirectionRef::fromExifValue($trackRef),
            track: $track,
            imageDirectionRef: GpsEnum\GpsDirectionRef::fromExifValue($imgDirRef),
            imageDirection: $imgDir,
        );

        $timing = new GpsTiming(
            date: $date,
            dateRaw: $dateRaw,
            time: $time,
            timestamp: $timestamp,
        );

        $measurement = new GpsMeasurement(
            satellites: $satellites,
            status: GpsEnum\GpsStatus::fromExifValue($status),
            measureMode: GpsEnum\GpsMeasureMode::fromExifValue($measureMode),
            dop: $dop,
            differential: GpsEnum\GpsDifferential::fromExifValue($differential),
            horizontalPositioningError: $hError,
        );

        return new Gps(
            position: $position,
            destination: $destination,
            movement: $movement,
            timing: $timing,
            measurement: $measurement,
            version: $version,
            versionRaw: $versionRaw,
            processingMethod: $processingMethod,
            areaInformation: $areaInformation,
        );
    }

    /**
     * Resolves GPS position from QuickTime metadata sources.
     *
     * Tries ISO 6709 location string first (©xyz atom), then falls back
     * to separate numeric latitude/longitude keys (DJI telemetry).
     */
    private function resolveQuickTimeGps(QuickTimeLookup $lookup): ?GpsPosition
    {
        $iso6709 = $lookup->string('com.apple.quicktime.location.ISO6709');

        if ($iso6709 !== null) {
            $parsed = Iso6709Parser::parse($iso6709);

            if ($parsed !== null) {
                return $this->positionFromSignedCoordinates(
                    $parsed['latitude'],
                    $parsed['longitude'],
                    $parsed['altitude'],
                );
            }
        }

        $lat = $lookup->float('com.apple.quicktime.location.latitude');
        $lon = $lookup->float('com.apple.quicktime.location.longitude');

        if (($lat !== null) && ($lon !== null)) {
            $alt = $lookup->float('com.apple.quicktime.location.altitude');

            return $this->positionFromSignedCoordinates($lat, $lon, $alt);
        }

        return null;
    }

    /**
     * Creates a GpsPosition from signed coordinates by decomposing each value
     * into its unsigned magnitude and directional reference.
     */
    private function positionFromSignedCoordinates(float $lat, float $lon, ?float $alt): GpsPosition
    {
        return new GpsPosition(
            latitude: abs($lat),
            longitude: abs($lon),
            latitudeRef: $lat >= 0 ? GpsEnum\GpsLatLonRef::North : GpsEnum\GpsLatLonRef::South,
            longitudeRef: $lon >= 0 ? GpsEnum\GpsLatLonRef::East : GpsEnum\GpsLatLonRef::West,
            altitude: $alt !== null ? abs($alt) : null,
            altitudeRef: $alt !== null
                ? ($alt >= 0 ? GpsEnum\GpsAltitudeRef::AboveEllipsoidalSurface : GpsEnum\GpsAltitudeRef::BelowEllipsoidalSurface)
                : null,
        );
    }

    /**
     * Applies EXIF-first coordinate fallback with XMP as secondary source.
     *
     * @return array{0:?float,1:?float,2:?string,3:?string}
     */
    private function applyCoordinateFallbacks(
        ?XmpDocument $xmpDocument,
        ?float $latitude,
        ?float $longitude,
        ?string $latitudeRef,
        ?string $longitudeRef,
    ): array {
        [$latitude, $longitude, $latitudeRef, $longitudeRef] = $this->applyXmpCoordinateFallback(
            $xmpDocument,
            $latitude,
            $longitude,
            $latitudeRef,
            $longitudeRef,
            'GPSLatitudeRef',
            'GPSLatitude',
            'GPSLongitudeRef',
            'GPSLongitude',
        );

        if ($latitude !== null) {
            $latitude = round($latitude, 6);
        }

        if ($longitude !== null) {
            $longitude = round($longitude, 6);
        }

        return [$latitude, $longitude, $latitudeRef, $longitudeRef];
    }

    /**
     * Resolves XMP coordinate fallbacks for a single lat/lon pair.
     *
     * @param string $xmpLatRefKey XMP key for latitude reference.
     * @param string $xmpLatKey    XMP key for latitude value.
     * @param string $xmpLonRefKey XMP key for longitude reference.
     * @param string $xmpLonKey    XMP key for longitude value.
     *
     * @return array{0:?float,1:?float,2:?string,3:?string}
     */
    private function applyXmpCoordinateFallback(
        ?XmpDocument $xmpDocument,
        ?float $latitude,
        ?float $longitude,
        ?string $latitudeRef,
        ?string $longitudeRef,
        string $xmpLatRefKey,
        string $xmpLatKey,
        string $xmpLonRefKey,
        string $xmpLonKey,
    ): array {
        $xmpLatRef = StringUtil::trimToUpperNull($xmpDocument?->string(XmpNamespace::EXIF->value, $xmpLatRefKey));

        if ($latitudeRef === null) {
            $latitudeRef = $xmpLatRef;
        }

        if ($latitude === null) {
            $latitude = $this->parseCoordinate(
                $xmpDocument?->string(XmpNamespace::EXIF->value, $xmpLatKey),
                $xmpLatRef ?? $latitudeRef,
            );
        }

        $xmpLonRef = StringUtil::trimToUpperNull($xmpDocument?->string(XmpNamespace::EXIF->value, $xmpLonRefKey));

        if ($longitudeRef === null) {
            $longitudeRef = $xmpLonRef;
        }

        if ($longitude === null) {
            $longitude = $this->parseCoordinate(
                $xmpDocument?->string(XmpNamespace::EXIF->value, $xmpLonKey),
                $xmpLonRef ?? $longitudeRef,
            );
        }

        return [$latitude, $longitude, $latitudeRef, $longitudeRef];
    }

    /**
     * Applies EXIF-first altitude fallback with XMP as secondary source.
     *
     * @return array{0:?float,1:?int}
     */
    private function applyAltitudeFallbacks(
        ?XmpDocument $xmpDocument,
        ?float $altitude,
        ?int $altitudeRef,
    ): array {
        if (($altitude === null) && ($xmpDocument instanceof XmpDocument)) {
            $altitudeXmp = $xmpDocument->float(XmpNamespace::EXIF->value, 'GPSAltitude');

            if ($altitudeXmp !== null) {
                $altRefXmp = $this->intValue($xmpDocument->int(XmpNamespace::EXIF->value, 'GPSAltitudeRef'));
                $altRef    = $altitudeRef ?? $altRefXmp;

                if (GpsEnum\GpsAltitudeRef::tryFrom($altRef ?? 0)?->isBelow() === true) {
                    $altitudeXmp = -$altitudeXmp;
                }

                $altitude = $altitudeXmp;

                $altitudeRef ??= $altRefXmp;
            }
        }

        return [$altitude, $altitudeRef];
    }

    /**
     * Applies EXIF-first movement and measurement fallbacks using XMP secondary values.
     *
     * @return array{0:?string,1:?string,2:?float,3:?string,4:?float,5:?string,6:?float,7:?string,8:?float,9:?string,10:?float,11:?string}
     */
    private function applyMovementFallbacks(
        ?XmpDocument $xmpDocument,
        ?string $status,
        ?string $measureMode,
        ?float $dop,
        ?string $speedRef,
        ?float $speedMs,
        ?string $speedOriginalRef,
        ?float $speedOriginal,
        ?string $trackRef,
        ?float $track,
        ?string $imgDirRef,
        ?float $imgDir,
        ?string $mapDatum,
    ): array {
        if ($status === null) {
            $status = StringUtil::trimToUpperNull($xmpDocument?->string(XmpNamespace::EXIF->value, 'GPSStatus'));
        }

        if ($measureMode === null) {
            $measureMode = StringUtil::trimToNull($xmpDocument?->string(XmpNamespace::EXIF->value, 'GPSMeasureMode'));
        }

        if ($dop === null) {
            $dop = $this->floatValue($xmpDocument?->float(XmpNamespace::EXIF->value, 'GPSDOP'));
        }

        if ($trackRef === null) {
            $trackRef = StringUtil::trimToUpperNull($xmpDocument?->string(XmpNamespace::EXIF->value, 'GPSTrackRef'));
        }

        if ($track === null) {
            $track = $this->floatValue($xmpDocument?->float(XmpNamespace::EXIF->value, 'GPSTrack'));
        }

        if ($imgDirRef === null) {
            $imgDirRef = StringUtil::trimToUpperNull($xmpDocument?->string(XmpNamespace::EXIF->value, 'GPSImgDirectionRef'));
        }

        if ($imgDir === null) {
            $imgDir = $this->floatValue($xmpDocument?->float(XmpNamespace::EXIF->value, 'GPSImgDirection'));
        }

        if ($mapDatum === null) {
            $mapDatum = StringUtil::trimToNull($xmpDocument?->string(XmpNamespace::EXIF->value, 'GPSMapDatum'));
        }

        $xmpSpeedRef = $xmpDocument?->string(XmpNamespace::EXIF->value, 'GPSSpeedRef');

        if ($speedRef === null) {
            $speedRef = StringUtil::trimToUpperNull($xmpSpeedRef);
        }

        if ($speedOriginalRef === null) {
            $speedOriginalRef = StringUtil::trimToNull($xmpSpeedRef);
        }

        $speedValue = $xmpDocument?->float(XmpNamespace::EXIF->value, 'GPSSpeed');

        if ($speedValue !== null) {
            if (($speedMs === null) && ($speedRef !== null)) {
                $speedMs = $this->convertSpeedToMetresPerSecond($speedValue, $speedRef);
            }

            if ($speedOriginal === null) {
                $speedOriginal = $speedValue;
            }
        }

        return [$status, $measureMode, $dop, $speedRef, $speedMs, $speedOriginalRef, $speedOriginal, $trackRef, $track, $imgDirRef, $imgDir, $mapDatum];
    }

    /**
     * Applies EXIF-first destination fallbacks using XMP secondary values.
     *
     * @return array{0:?string,1:?float,2:?string,3:?float,4:?string,5:?float,6:?string,7:?float,8:?string,9:?float}
     */
    private function applyDestinationFallbacks(
        ?XmpDocument $xmpDocument,
        ?string $destLatRef,
        ?float $destLat,
        ?string $destLonRef,
        ?float $destLon,
        ?string $destBearRef,
        ?float $destBear,
        ?string $destDistRef,
        ?float $destDistMetre,
        ?string $destDistOriginalRef,
        ?float $destDistOriginal,
    ): array {
        [$destLat, $destLon, $destLatRef, $destLonRef] = $this->applyXmpCoordinateFallback(
            $xmpDocument,
            $destLat,
            $destLon,
            $destLatRef,
            $destLonRef,
            'GPSDestLatitudeRef',
            'GPSDestLatitude',
            'GPSDestLongitudeRef',
            'GPSDestLongitude',
        );

        $xmpDestBearRef = StringUtil::trimToUpperNull($xmpDocument?->string(XmpNamespace::EXIF->value, 'GPSDestBearingRef'));

        if ($destBearRef === null) {
            $destBearRef = $xmpDestBearRef;
        }

        if ($destBear === null) {
            $xmpDestBear = $xmpDocument?->float(XmpNamespace::EXIF->value, 'GPSDestBearing');

            if ($xmpDestBear !== null) {
                $destBear = $xmpDestBear;
            }
        }

        $xmpDestDistRef = $xmpDocument?->string(XmpNamespace::EXIF->value, 'GPSDestDistanceRef');

        if ($destDistRef === null) {
            $destDistRef = StringUtil::trimToUpperNull($xmpDestDistRef);
        }

        if ($destDistOriginalRef === null) {
            $destDistOriginalRef = StringUtil::trimToNull($xmpDestDistRef);
        }

        $destDistValue = $xmpDocument?->float(XmpNamespace::EXIF->value, 'GPSDestDistance');

        if ($destDistValue !== null) {
            if (($destDistMetre === null) && ($destDistRef !== null)) {
                $convertedDistance = $this->convertDistanceToMetres($destDistValue, $destDistRef);

                if ($convertedDistance !== null) {
                    $destDistMetre = $convertedDistance;
                }
            }

            if ($destDistOriginal === null) {
                $destDistOriginal = $destDistValue;
            }
        }

        return [$destLatRef, $destLat, $destLonRef, $destLon, $destBearRef, $destBear, $destDistRef, $destDistMetre, $destDistOriginalRef, $destDistOriginal];
    }

    /**
     * Applies EXIF-first timing fallback with XMP and derived timestamp synthesis.
     *
     * @return array{0:?string,1:?string,2:?DateTimeImmutable}
     */
    private function applyTimingFallbacks(
        ?XmpDocument $xmpDocument,
        ?string $date,
        ?string $time,
        ?DateTimeImmutable $timestamp,
    ): array {
        if ($date === null) {
            $date = $this->normalizeDate($xmpDocument?->string(XmpNamespace::EXIF->value, 'GPSDateStamp'));
        }

        if ($time === null) {
            $time = StringUtil::trimToNull($xmpDocument?->string(XmpNamespace::EXIF->value, 'GPSTimeStamp'));
        }

        if (!$timestamp instanceof DateTimeImmutable) {
            $timestamp = $this->parseXmpTimestamp($xmpDocument);
        }

        if (!$timestamp instanceof DateTimeImmutable) {
            $timestamp = $this->combineDateAndTime($date, $time);
        }

        return [$date, $time, $timestamp];
    }

    /**
     * Returns true when any GPS group contains at least one non-null value.
     *
     * @param list<int|float|string|DateTimeImmutable|null> ...$groups
     */
    private function hasAnyGpsData(array ...$groups): bool
    {
        return array_any($groups, fn ($group): bool => array_any($group, static fn ($value): bool => $value !== null));
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

            if (($deg !== null) && ($min !== null) && ($sec !== null)) {
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
    private function normalizeDate(?string $value): ?string
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
        $value = $document?->string(XmpNamespace::EXIF->value, 'GPSDateTime');

        $dateTime = DateTimeUtil::parseIso8601($value, new DateTimeZone('UTC'));

        return $dateTime?->setTimezone(new DateTimeZone('UTC'));
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
        } catch (DateMalformedStringException) {
            // GPS timestamps from camera firmware may be malformed; yield null for graceful degradation.
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
            'N'     => $speed * 0.5144444444444444,
            default => null,
        };
    }
}
