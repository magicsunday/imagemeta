<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

use DateTimeImmutable;
use DateTimeZone;

final class IfdEntry
{
    public function __construct(
        public readonly int $tag,
        public readonly int $type,
        public readonly int $count,
        public readonly mixed $value,
    ) {
    }
}

final class Ifd
{
    /** @param array<int, IfdEntry> $entries */
    public function __construct(
        public readonly array $entries,
        public readonly ?int $nextIfdOffset = null,
    ) {
    }

    public function get(int $tag): ?IfdEntry
    {
        return $this->entries[$tag] ?? null;
    }
}

final class ExifDocument
{
    public function __construct(
        public readonly Ifd $ifd0,
        public readonly ?Ifd $exifIfd,
        public readonly ?Ifd $gpsIfd,
        public readonly ?Ifd $interopIfd,
        public readonly ?Ifd $ifd1,
    ) {
    }

    // ---- Convenience (Strings/Numbers)
    public function cameraMake(): ?string
    {
        return $this->str($this->ifd0, 0x010F);
    }

    public function cameraModel(): ?string
    {
        return $this->str($this->ifd0, 0x0110);
    }

    public function lensModel(): ?string
    {
        return $this->str($this->exifIfd, 0xA434);
    }

    public function orientation(): ?int
    {
        return $this->int($this->ifd0, 0x0112);
    }

    public function iso(): ?int
    {
        // EXIF ISO tag (PhotographicSensitivity) 0x8827
        return $this->int($this->exifIfd, 0x8827);
    }

    public function exposureTime(): ?float
    {
        // Tag 0x829A RATIONAL
        $v = $this->exifIfd?->get(0x829A)?->value ?? null;

        return ValueConverters::rationalToFloat($v);
    }

    public function fNumber(): ?float
    {
        // Tag 0x829D RATIONAL
        $v = $this->exifIfd?->get(0x829D)?->value ?? null;

        return ValueConverters::rationalToFloat($v);
    }

    public function focalLengthMm(): ?float
    {
        // Tag 0x920A RATIONAL
        $v = $this->exifIfd?->get(0x920A)?->value ?? null;

        return ValueConverters::rationalToFloat($v);
    }

    public function dateTimeOriginalRaw(): ?string
    {
        return $this->str($this->exifIfd, 0x9003);
    }

    public function offsetTimeOriginalRaw(): ?string
    {
        return $this->str($this->exifIfd, 0x9011);
    }

    /** @return array{lat:?float, lon:?float, alt:?float} */
    public function gps(): array
    {
        if (!$this->gpsIfd) {
            return ['lat' => null, 'lon' => null, 'alt' => null];
        }

        return ValueConverters::gpsFromIfd($this->gpsIfd);
    }

    /** Best-effort CaptureTime (DateTimeImmutable). Falls kein Offset vorhanden, Timezone = UTC. */
    public function captureDateTime(): ?DateTimeImmutable
    {
        $raw = $this->dateTimeOriginalRaw();
        if (!$raw) {
            return null;
        }
        $offset = $this->offsetTimeOriginalRaw(); // like "+01:00"
        $tz     = $offset ? new DateTimeZone($offset) : new DateTimeZone('UTC');

        // EXIF uses "YYYY:MM:DD HH:MM:SS"
        $normalized = str_replace(':', '-', substr($raw, 0, 10)) . substr($raw, 10); // YYYY-MM-DD HH:MM:SS
        $dt         = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, $tz);

        return $dt ?: null;
    }

    private function str(?Ifd $ifd, int $tag): ?string
    {
        $e = $ifd?->get($tag);

        return is_string($e?->value) ? rtrim($e->value, "\0") : null;
    }

    private function int(?Ifd $ifd, int $tag): ?int
    {
        $v = $ifd?->get($tag)?->value ?? null;

        return is_int($v) ? $v : (is_float($v) ? (int) $v : null);
    }
}

final class ValueConverters
{
    public static function rationalToFloat(mixed $v): ?float
    {
        if (is_array($v) && count($v) === 2 && (int) $v[1] !== 0) {
            return (float) $v[0] / (float) $v[1];
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        return null;
    }

    /** @return array{lat:?float,lon:?float,alt:?float} */
    public static function gpsFromIfd(Ifd $gps): array
    {
        $latRef = $gps->get(0x0001)?->value ?? null;
        $latVal = $gps->get(0x0002)?->value ?? null;
        $lonRef = $gps->get(0x0003)?->value ?? null;
        $lonVal = $gps->get(0x0004)?->value ?? null;

        $lat = self::dmsToFloat($latRef, $latVal);
        $lon = self::dmsToFloat($lonRef, $lonVal);

        $alt = null;
        if (($e = $gps->get(0x0006)) && is_array($e->value) && count($e->value) === 2 && (int) $e->value[1] !== 0) {
            $alt = $e->value[0] / $e->value[1];
            if (($ref = $gps->get(0x0005)) && (int) ($ref->value ?? 0) === 1) {
                $alt = -$alt;
            }
        }

        return ['lat' => $lat, 'lon' => $lon, 'alt' => $alt];
    }

    private static function dmsToFloat(?string $ref, mixed $val): ?float
    {
        if (!is_string($ref) || !is_array($val) || count($val) < 3) {
            return null;
        }
        $deg = self::rationalToFloat($val[0] ?? null);
        $min = self::rationalToFloat($val[1] ?? null);
        $sec = self::rationalToFloat($val[2] ?? null);
        if ($deg === null || $min === null || $sec === null) {
            return null;
        }

        $sign = ($ref === 'S' || $ref === 'W') ? -1.0 : 1.0;

        return $sign * ($deg + $min / 60.0 + $sec / 3600.0);
    }
}
