<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Gps;

use function array_map;
use function count;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtoupper;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Resolves GPS information from EXIF and XMP sources.
 */
final readonly class GpsResolver
{
    use XmpPropertyAccess;

    private const string NS_EXIF = 'http://ns.adobe.com/exif/1.0/';

    /**
     * Builds a GPS value object from the available metadata.
     */
    public function resolve(?ExifDocument $exifDocument, ?XmpDocument $xmpDocument): ?Gps
    {
        $gpsData = $exifDocument instanceof ExifDocument ? $exifDocument->gps() : [];

        $latitude     = $this->floatValue($gpsData['lat'] ?? null);
        $longitude    = $this->floatValue($gpsData['lon'] ?? null);
        $latitudeRef  = $this->uppercase($gpsData['lat_ref'] ?? null);
        $longitudeRef = $this->uppercase($gpsData['lon_ref'] ?? null);
        $altitude     = $this->floatValue($gpsData['alt'] ?? null);
        $altitudeRef  = $this->intValue($gpsData['alt_ref'] ?? null);

        $version     = $this->stringValue($gpsData['version'] ?? null);
        $satellites  = $this->stringValue($gpsData['satellites'] ?? null);
        $status      = $this->stringValue($gpsData['status'] ?? null);
        $measureMode = $this->stringValue($gpsData['measure_mode'] ?? null);
        $dop         = $this->floatValue($gpsData['dop'] ?? null);
        $speedRef    = $this->uppercase($gpsData['speed_ref'] ?? null);
        $speedMs     = $this->floatValue($gpsData['speed_ms'] ?? null);
        $trackRef    = $this->uppercase($gpsData['track_ref'] ?? null);
        $track       = $this->floatValue($gpsData['track'] ?? null);
        $imgDirRef   = $this->uppercase($gpsData['img_direction_ref'] ?? null);
        $imgDir      = $this->floatValue($gpsData['img_direction'] ?? null);
        $mapDatum    = $this->stringValue($gpsData['map_datum'] ?? null);

        $destLatRef    = $this->uppercase($gpsData['dest_lat_ref'] ?? null);
        $destLat       = $this->floatValue($gpsData['dest_lat'] ?? null);
        $destLonRef    = $this->uppercase($gpsData['dest_lon_ref'] ?? null);
        $destLon       = $this->floatValue($gpsData['dest_lon'] ?? null);
        $destBearRef   = $this->uppercase($gpsData['dest_bearing_ref'] ?? null);
        $destBear      = $this->floatValue($gpsData['dest_bearing'] ?? null);
        $destDistRef   = $this->uppercase($gpsData['dest_distance_ref'] ?? null);
        $destDistMetre = $this->floatValue($gpsData['dest_distance_m'] ?? null);

        $processingMethod = $this->stringValue($gpsData['processing_method'] ?? null);
        $areaInformation  = $this->stringValue($gpsData['area_information'] ?? null);

        $date = $this->normaliseDate($this->stringValue($gpsData['date'] ?? null));
        $time = $this->stringValue($gpsData['time'] ?? null);

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
        $xmpLatRef = $this->uppercase($this->xmpString($xmpDocument, self::NS_EXIF, 'GPSLatitudeRef'));
        if ($latitudeRef === null) {
            $latitudeRef = $xmpLatRef;
        }

        if ($latitude === null) {
            $latitude = $this->parseCoordinate(
                $this->xmpString($xmpDocument, self::NS_EXIF, 'GPSLatitude'),
                $xmpLatRef ?? $latitudeRef,
            );
        }

        $xmpLonRef = $this->uppercase($this->xmpString($xmpDocument, self::NS_EXIF, 'GPSLongitudeRef'));
        if ($longitudeRef === null) {
            $longitudeRef = $xmpLonRef;
        }

        if ($longitude === null) {
            $longitude = $this->parseCoordinate(
                $this->xmpString($xmpDocument, self::NS_EXIF, 'GPSLongitude'),
                $xmpLonRef ?? $longitudeRef,
            );
        }

        if ($altitude === null) {
            $altitudeXmp = $this->xmpFloat($xmpDocument, self::NS_EXIF, 'GPSAltitude');
            if ($altitudeXmp !== null) {
                $altRefXmp = $this->intValue($this->xmpInt($xmpDocument, self::NS_EXIF, 'GPSAltitudeRef'));
                $altRef    = $altitudeRef ?? $altRefXmp;

                if ($altRef === 1) {
                    $altitudeXmp = -$altitudeXmp;
                }

                $altitude = $altitudeXmp;

                if ($altitudeRef === null) {
                    $altitudeRef = $altRefXmp;
                }
            }
        }

        if ($speedRef === null) {
            $speedRef = $this->uppercase($this->xmpString($xmpDocument, self::NS_EXIF, 'GPSSpeedRef'));
        }

        if ($speedMs === null) {
            $speedValue = $this->xmpFloat($xmpDocument, self::NS_EXIF, 'GPSSpeed');
            if ($speedValue !== null && $speedRef !== null) {
                $speedMs = $this->convertSpeedToMetresPerSecond($speedValue, $speedRef);
            }
        }

        if ($date === null) {
            $date = $this->normaliseDate($this->xmpString($xmpDocument, self::NS_EXIF, 'GPSDateStamp'));
        }

        if ($time === null) {
            $time = $this->stringValue($this->xmpString($xmpDocument, self::NS_EXIF, 'GPSTimeStamp'));
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
            $satellites,
            $status,
            $measureMode,
            $dop,
            $speedRef,
            $speedMs,
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
            $processingMethod,
            $areaInformation,
            $date,
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
            latitudeRef: $latitudeRef,
            longitudeRef: $longitudeRef,
            altitude: $altitude,
            altitudeRef: $altitudeRef,
            version: $version,
            satellites: $satellites,
            status: $status,
            measureMode: $measureMode,
            dop: $dop,
            speedRef: $speedRef,
            speedMs: $speedMs,
            trackRef: $trackRef,
            track: $track,
            imageDirectionRef: $imgDirRef,
            imageDirection: $imgDir,
            mapDatum: $mapDatum,
            destinationLatitudeRef: $destLatRef,
            destinationLatitude: $destLat,
            destinationLongitudeRef: $destLonRef,
            destinationLongitude: $destLon,
            destinationBearingRef: $destBearRef,
            destinationBearing: $destBear,
            destinationDistanceRef: $destDistRef,
            destinationDistanceMetres: $destDistMetre,
            processingMethod: $processingMethod,
            areaInformation: $areaInformation,
            date: $date,
            time: $time,
            timestamp: $timestamp,
            differential: $differential,
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
            $deg = $this->parseNumericString($parts[0]);
            $min = $this->parseNumericString($parts[1]);
            $sec = $this->parseNumericString($parts[2]);

            if ($deg !== null && $min !== null && $sec !== null) {
                $sign = $this->coordinateSign($ref);

                return $sign * ($deg + $min / 60.0 + $sec / 3600.0);
            }
        }

        $numeric = $this->parseNumericString($parts[0]);
        if ($numeric === null) {
            return null;
        }

        $sign = $this->coordinateSign($ref);

        return $numeric * $sign;
    }

    /**
     * Determines the sign for the given coordinate reference.
     */
    private function coordinateSign(?string $ref): float
    {
        if ($ref === 'S' || $ref === 'W') {
            return -1.0;
        }

        return 1.0;
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
    private function stringValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Returns the value as float when numeric.
     */
    private function floatValue(mixed $value): ?float
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
    private function intValue(mixed $value): ?int
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

        return $trimmed;
    }

    /**
     * Parses an XMP GPSDateTime value.
     */
    private function parseXmpTimestamp(?XmpDocument $document): ?DateTimeImmutable
    {
        $value = $this->xmpString($document, self::NS_EXIF, 'GPSDateTime');
        if ($value === null) {
            return null;
        }

        try {
            $dateTime = new DateTimeImmutable($value);
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
     * Converts speed in the provided unit to metres per second using GPSSpeedRef semantics.
     */
    private function convertSpeedToMetresPerSecond(float $speed, string $speedRef): float
    {
        return match ($speedRef) {
            'K'     => $speed / 3.6,
            'M'     => $speed * 0.44704,
            'N'     => $speed * 0.514444,
            default => $speed,
        };
    }
}
