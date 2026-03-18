<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Model\Dji\DjiTelemetry;

use function is_finite;
use function max;
use function min;
use function strlen;
use function strpos;
use function substr;
use function unpack;

use const M_PI;

/**
 * Scans an ISO BMFF mdat payload for DJI per-frame protobuf telemetry records.
 *
 * DJI drones embed Protocol Buffers records in the video data stream at ~130-150 byte
 * intervals. Each record contains the drone model name (prefixed "DJI "), and surrounding
 * records contain GPS coordinates as f64 radians in nested messages.
 */
final readonly class DjiMdatTelemetryScanner
{
    private const string DJI_SIGNATURE     = 'DJI ';

    public const int SCAN_WINDOW           = 131072;

    private const int RECORD_CONTEXT_BYTES = 300;

    /**
     * Scans the tail of a stream for DJI telemetry records.
     *
     * Reads the last SCAN_WINDOW bytes from the stream and delegates to scanBytes().
     * This avoids loading the entire file into memory for multi-GB recordings.
     *
     * @param Stream $stream Source stream to scan.
     *
     * @return DjiTelemetry|null Extracted telemetry or null when no DJI records are found.
     */
    public function scanStream(Stream $stream): ?DjiTelemetry
    {
        $fileSize  = $stream->size();
        $readStart = max(0, $fileSize - self::SCAN_WINDOW);
        $readSize  = $fileSize - $readStart;

        if ($readSize <= 0) {
            return null;
        }

        $stream->seek($readStart);

        return $this->scanBytes($stream->read($readSize));
    }

    /**
     * Scans raw bytes for DJI telemetry records.
     *
     * @param string $data Raw mdat content (or tail portion thereof).
     *
     * @return DjiTelemetry|null Extracted telemetry or null when no DJI records are found.
     */
    public function scanBytes(string $data): ?DjiTelemetry
    {
        $length    = strlen($data);

        if ($length === 0) {
            return null;
        }

        $model     = null;
        $latitude  = null;
        $longitude = null;
        $altitude  = null;

        $offset    = 0;

        while (($pos = strpos($data, self::DJI_SIGNATURE, $offset)) !== false) {
            $model             = $this->extractModelAt($data, $pos);

            [$lat, $lon, $alt] = $this->searchGpsNear($data, $pos);

            if (($lat !== null) && ($lon !== null)) {
                $latitude  = $lat;
                $longitude = $lon;
                $altitude  = $alt;

                break;
            }

            $offset            = $pos + 1;
        }

        if ($model === null) {
            return null;
        }

        return new DjiTelemetry($model, $latitude, $longitude, $altitude);
    }

    /**
     * Extracts the DJI model string starting at a "DJI " signature position.
     *
     * Uses the protobuf length-delimited prefix byte (immediately before the string)
     * when available to determine exact string length. Falls back to scanning for
     * printable ASCII characters.
     */
    private function extractModelAt(string $data, int $pos): string
    {
        $len = strlen($data);

        // Try protobuf length-delimited prefix: byte before string gives its length
        if ($pos >= 1) {
            $prefixLen = ord($data[$pos - 1]);

            if (($prefixLen >= 4) && ($prefixLen <= 40) && (($pos + $prefixLen) <= $len)) {
                $valid = true;

                for ($i = 0; $i < $prefixLen; ++$i) {
                    $byte = ord($data[$pos + $i]);

                    if (($byte < 0x20) || ($byte > 0x7E)) {
                        $valid = false;

                        break;
                    }
                }

                if ($valid) {
                    return substr($data, $pos, $prefixLen);
                }
            }
        }

        // Fallback: scan forward for printable ASCII characters
        $end = $pos;

        while ($end < $len) {
            $byte = ord($data[$end]);

            if (($byte < 0x20) || ($byte > 0x7E)) {
                break;
            }

            ++$end;
        }

        return substr($data, $pos, $end - $pos);
    }

    /**
     * Searches for GPS coordinates (f64 radians) and altitude in protobuf records near a DJI signature.
     *
     * @return array{0: float|null, 1: float|null, 2: float|null} [latitude, longitude, altitude] in degrees/meters.
     */
    private function searchGpsNear(string $data, int $djiPos): array
    {
        $searchStart = max(0, $djiPos - 50);
        $searchEnd   = min(strlen($data), $djiPos + self::RECORD_CONTEXT_BYTES);
        $chunk       = substr($data, $searchStart, $searchEnd - $searchStart);

        // Search for f64 pairs that decode to valid GPS coordinates in radians
        $chunkLen    = strlen($chunk);

        for ($i = 0; $i < ($chunkLen - 16); ++$i) {
            $val1 = $this->tryDecodeGpsRadians(substr($chunk, $i, 8));

            if ($val1 === null) {
                continue;
            }

            // Check for a second f64 right after (possibly with a 1-byte protobuf tag between)
            for ($gap = 8; $gap <= 9; ++$gap) {
                if (($i + $gap + 8) > $chunkLen) {
                    continue;
                }

                $val2 = $this->tryDecodeGpsRadians(substr($chunk, $i + $gap, 8));

                if ($val2 === null) {
                    continue;
                }

                // One should be latitude (|deg| < 90), the other longitude (|deg| < 180)
                $deg1 = $val1 * 180.0 / M_PI;
                $deg2 = $val2 * 180.0 / M_PI;

                if ((abs($deg1) <= 90.0) && (abs($deg2) <= 180.0)) {
                    $alt = $this->tryDecodeAltitude($chunk, $i + $gap + 8, $chunkLen);

                    return [$deg1, $deg2, $alt];
                }

                if ((abs($deg2) <= 90.0) && (abs($deg1) <= 180.0)) {
                    $alt = $this->tryDecodeAltitude($chunk, $i + $gap + 8, $chunkLen);

                    return [$deg2, $deg1, $alt];
                }
            }
        }

        return [null, null, null];
    }

    /**
     * Attempts to decode an altitude value from bytes following the GPS coordinate pair.
     *
     * DJI telemetry stores altitude as an f64 immediately after or near the GPS lat/lon pair.
     * The value is in meters above sea level (typically 0–10000m for drones).
     */
    private function tryDecodeAltitude(string $chunk, int $offset, int $chunkLen): ?float
    {
        // Try f64 at various small gaps (0, 1, 8, 9 bytes after lon)
        foreach ([0, 1, 8, 9] as $gap) {
            $altOffset = $offset + $gap;

            if (($altOffset + 8) > $chunkLen) {
                continue;
            }

            /** @var array{1: float} $unpacked */
            $unpacked  = unpack('e', substr($chunk, $altOffset, 8));
            $val       = $unpacked[1];

            if (!is_finite($val)) {
                continue;
            }

            // Altitude range: -500m (Dead Sea) to 10000m (drone ceiling)
            if (($val >= -500.0) && ($val <= 10000.0) && ($val !== 0.0)) {
                return $val;
            }
        }

        return null;
    }

    /**
     * Attempts to decode 8 bytes as a GPS coordinate in radians.
     *
     * @return float|null Radian value if it looks like a valid GPS coordinate, null otherwise.
     */
    private function tryDecodeGpsRadians(string $bytes): ?float
    {
        if (strlen($bytes) !== 8) {
            return null;
        }

        /** @var array{1: float} $unpacked */
        $unpacked = unpack('e', $bytes);
        $val      = $unpacked[1];

        // GPS coordinates in radians: lat ∈ [-π/2, π/2], lon ∈ [-π, π]
        // Filter for reasonable range (> 0.01 rad ≈ 0.57°, < π ≈ 3.14159)
        if (!is_finite($val) || (abs($val) < 0.01) || (abs($val) > M_PI)) {
            return null;
        }

        return $val;
    }
}
