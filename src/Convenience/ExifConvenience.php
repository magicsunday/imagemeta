<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Convenience;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;

final class ExifConvenience
{
    public static function camera(ExifDocument $doc): array
    {
        return [
            'make'  => $doc->cameraMake(),
            'model' => $doc->cameraModel(),
            'lens'  => $doc->lensModel(),
        ];
    }

    public static function captureDateTime(ExifDocument $doc): ?DateTimeImmutable
    {
        $dt = $doc->dateTimeOriginal();          // "YYYY:MM:DD HH:MM:SS"
        if ($dt === null || $dt === '') {
            return null;
        }
        $off = $doc->offsetTimeOriginal();       // e.g. "+01:00" or "-05:30"
        // Normalize EXIF date format to ISO 8601
        $iso = str_replace(':', '-', substr($dt, 0, 10)) . ' ' . substr($dt, 11);
        $tz  = $off !== null && $off !== '' ? $off : '+00:00';
        try {
            return new DateTimeImmutable($iso . $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function gps(ExifDocument $doc): array
    {
        return $doc->gps(); // ['lat'=>?float,'lon'=>?float,'alt'=>?float]
    }

    public static function exposureTime(ExifDocument $doc): ?float
    {
        $e = self::find($doc, 0x829A); // ExposureTime (rational)
        return self::rationalToFloat($e?->value);
    }

    public static function fNumber(ExifDocument $doc): ?float
    {
        $e = self::find($doc, 0x829D); // FNumber (rational)
        return self::rationalToFloat($e?->value);
    }

    public static function focalLength(ExifDocument $doc): ?float
    {
        $e = self::find($doc, 0x920A); // FocalLength (rational)
        return self::rationalToFloat($e?->value);
    }

    public static function iso(ExifDocument $doc): ?int
    {
        // ISO can be ExifIFD tag 0x8827 (old) or PhotographicSensitivity 0x8833
        $e = self::find($doc, 0x8833) ?? self::find($doc, 0x8827);
        if (!$e) return null;
        $v = $e->value;
        if (is_array($v)) {
            $v = $v[0] ?? null;
        }
        return is_int($v) ? $v : (is_float($v) ? (int)$v : null);
    }

    /** Unified associative array (handy for DB ingest) */
    public static function toArray(ExifDocument $doc): array
    {
        $dt = self::captureDateTime($doc);
        $gps = $doc->gps();
        return [
            'make'         => $doc->cameraMake(),
            'model'        => $doc->cameraModel(),
            'lens'         => $doc->lensModel(),
            'orientation'  => $doc->orientation(),
            'captured_at'  => $dt?->format(DATE_ATOM),
            'exposure_s'   => self::exposureTime($doc),
            'fnumber'      => self::fNumber($doc),
            'focal_mm'     => self::focalLength($doc),
            'iso'          => self::iso($doc),
            'gps_lat'      => $gps['lat'],
            'gps_lon'      => $gps['lon'],
            'gps_alt'      => $gps['alt'],
        ];
    }

    private static function find(ExifDocument $doc, int $tag): ?IfdEntry
    {
        return $doc->exifIfd?->get($tag) ?? $doc->ifd0->get($tag) ?? null;
    }

    private static function rationalToFloat(mixed $v): ?float
    {
        if (is_array($v)) {
            // [num, den] or list of rationals
            if (count($v) === 2 && is_numeric($v[0] ?? null) && is_numeric($v[1] ?? null) && (int)$v[1] !== 0) {
                return (float)$v[0] / (float)$v[1];
            }
            if (isset($v[0]) && is_array($v[0]) && count($v[0]) === 2) {
                $n = $v[0][0] ?? 0; $d = $v[0][1] ?? 1;
                return (int)$d !== 0 ? (float)$n / (float)$d : null;
            }
        }
        return is_int($v) || is_float($v) ? (float)$v : null;
    }
}