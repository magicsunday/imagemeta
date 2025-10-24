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
use MagicSunday\ImageMeta\Value\Gps;

use function array_map;
use function count;
use function preg_split;
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
        $lat   = null;
        $lon   = null;
        $alt   = null;
        $speed = null;

        if ($exifDocument instanceof ExifDocument) {
            $gps = $exifDocument->gps();
            $lat = $gps['lat'] ?? null;
            $lon = $gps['lon'] ?? null;
            $alt = $gps['alt'] ?? null;
            $speed = $gps['speed_ms'] ?? null;
        }

        if ($lat === null || $lon === null) {
            $latString = $this->xmpString($xmpDocument, self::NS_EXIF, 'GPSLatitude');
            $latRef    = $this->xmpString($xmpDocument, self::NS_EXIF, 'GPSLatitudeRef');
            $lonString = $this->xmpString($xmpDocument, self::NS_EXIF, 'GPSLongitude');
            $lonRef    = $this->xmpString($xmpDocument, self::NS_EXIF, 'GPSLongitudeRef');

            $lat = $lat ?? $this->parseCoordinate($latString, $latRef);
            $lon = $lon ?? $this->parseCoordinate($lonString, $lonRef);
        }

        if ($alt === null) {
            $altitude = $this->xmpFloat($xmpDocument, self::NS_EXIF, 'GPSAltitude');
            if ($altitude !== null) {
                $altRef = $this->xmpInt($xmpDocument, self::NS_EXIF, 'GPSAltitudeRef');
                if ($altRef === 1) {
                    $altitude = -$altitude;
                }

                $alt = $altitude;
            }
        }

        if ($lat === null && $lon === null && $alt === null && $speed === null) {
            return null;
        }

        return new Gps($lat, $lon, $alt, $speed);
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
            static fn (string $component): string => trim($component),
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
}
