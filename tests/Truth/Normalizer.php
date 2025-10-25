<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Truth;

final class Normalizer
{
    /** Delta für Floatvergleiche */
    public const DELTA = 1e-6;

    /** @var array<string, array<int|string, string>> */
    private array $enumMap;

    /**
     * @param array<string, array<int|string, string>> $enumMap
     */
    public function __construct(array $enumMap)
    {
        $this->enumMap = $enumMap;
    }

    /**
     * Enum → vergleichbarer String (Enum-Name) oder passt Wert unverändert durch.
     * Unterstützt reine PHP-Enums (->name) und Fallbacks.
     */
    public static function toComparable(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->name; // Name stabiler als ->value bei BackedEnums
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \DateTimeInterface) {
            // Sekundenpräzision (SubSec separat, wenn gewünscht)
            return $value->format('Y-m-d\TH:i:sP');
        }
        return $value;
    }

    /**
     * Vergleicht eine Structured-Enum gegen ExifTool(-n) Rohwert über die EnumMap.
     *
     * @param non-empty-string $group  z. B. 'Orientation', 'WhiteBalance'
     * @param int|string $exifNumeric
     * @param \UnitEnum|\BackedEnum|string|null $structuredEnum
     */
    public function compareEnum(string $group, int|string $exifNumeric, mixed $structuredEnum): bool
    {
        if (!isset($this->enumMap[$group])) {
            return false; // kein Mapping hinterlegt
        }
        $expectedName = $this->enumMap[$group][$exifNumeric] ?? null;
        if ($expectedName === null) {
            return false;
        }
        $actualName = is_string($structuredEnum) ? $structuredEnum : self::toComparable($structuredEnum);
        return $actualName === $expectedName;
    }

    public static function decodeExifFlash(int $val): array
    {
        // Spezifikation (EXIF 2.3):
        // Bit 0: Fired
        // Bits 1-2: Return status (0,2,3)
        // Bits 3-4: Mode (0..3)
        // Bit 5: Function (0=PRESENT, 1=ABSENT)
        // Bit 6: Red-eye reduction (1=true)
        $fired = (bool)($val & 0x01);

        $returnBits = ($val >> 1) & 0x03;
        $return = match ($returnBits) {
            0 => 'NO_STROBE_DETECTION',
            2 => 'RETURN_NOT_DETECTED',
            3 => 'RETURN_DETECTED',
            default => 'NO_STROBE_DETECTION',
        };

        $modeBits = ($val >> 3) & 0x03;
        $mode = match ($modeBits) {
            1 => 'COMPULSORY_FIRE',
            2 => 'COMPULSORY_SUPPRESS',
            3 => 'AUTO',
            default => 'UNKNOWN',
        };

        $function = ((($val >> 5) & 0x01) === 1) ? 'ABSENT' : 'PRESENT';
        $redEye   = ((($val >> 6) & 0x01) === 1);

        return [
            'fired'            => $fired,
            'returnDetection'  => $return,
            'mode'             => $mode,
            'functionPresence' => $function,
            'redEyeReduction'  => $redEye,
        ];
    }

    /**
     * Baut aus EXIF DateTime + SubSec + OffsetTime eine ISO-8601.
     * Erwartet ExifTool-JSON (mit -n/-struct).
     *
     * @param array<string,mixed> $exif
     * @param 'EXIF:CreateDate'|'EXIF:DateTimeOriginal'|'IFD0:ModifyDate' $baseKey
     */
    public static function buildIso8601FromExif(array $exif, string $baseKey): ?string
    {
        $base = self::get($exif, $baseKey);
        if (!is_string($base) || $base === '') {
            return null;
        }
        // "YYYY:MM:DD HH:MM:SS" → "YYYY-MM-DDTHH:MM:SS"
        $dt = preg_replace('/^(\d{4}):(\d{2}):(\d{2})\s+/', '$1-$2-$3T', $base) ?? null;
        if ($dt === null) {
            return null;
        }

        // SubSec (passender Schlüssel zu Base)
        $subSecKey = match ($baseKey) {
            'EXIF:CreateDate'       => 'EXIF:SubSecTimeDigitized',
            'EXIF:DateTimeOriginal' => 'EXIF:SubSecTimeOriginal',
            'IFD0:ModifyDate'       => 'EXIF:SubSecTime', // oft nicht vorhanden
        };
        $sub = self::get($exif, $subSecKey);
        $sub = is_string($sub) && $sub !== '' ? $sub : null;

        // Offset (passender Schlüssel zu Base)
        $offsetKey = match ($baseKey) {
            'EXIF:CreateDate'       => 'EXIF:OffsetTimeDigitized',
            'EXIF:DateTimeOriginal' => 'EXIF:OffsetTimeOriginal',
            'IFD0:ModifyDate'       => 'EXIF:OffsetTime',
        };
        $off = self::get($exif, $offsetKey);
        $off = is_string($off) && $off !== '' ? $off : '+00:00';

        // zusammensetzen
        if ($sub !== null) {
            // normalisiere auf 3 Stellen (Millis)
            $sub = str_pad(substr($sub, 0, 3), 3, '0');
            return sprintf('%s.%s%s', $dt, $sub, self::normalizeOffset($off));
        }
        return sprintf('%s%s', $dt, self::normalizeOffset($off));
    }

    /** @param array<string,mixed> $exif */
    private static function get(array $exif, string $key): mixed
    {
        // ExifTool-JSON gibt flache Keys "GROUP:Tag"
        return $exif[$key] ?? null;
    }

    private static function normalizeOffset(string $off): string
    {
        // erlaubt Formate "+01:00" oder "+0100" → immer "+01:00"
        if (preg_match('/^[\+\-]\d{2}:\d{2}$/', $off)) {
            return $off;
        }
        if (preg_match('/^([\+\-]\d{2})(\d{2})$/', $off, $m)) {
            return $m[1] . ':' . $m[2];
        }
        return '+00:00';
    }

    /**
     * Extrahiert MWG Face-Areas aus ExifTool-JSON → [{x,y,w,h}, ...]
     * @param array<string,mixed> $exif
     * @return array<int,array{x:float,y:float,w:float,h:float}>
     */
    public static function mwgFaces(array $exif): array
    {
        $faces = [];
        $types = [];
        foreach ($exif as $k => $v) {
            if (preg_match('#^XMP-mwg-rs:RegionList\[(\d+)\]/Type$#', $k, $m)) {
                $types[(int)$m[1]] = (string)$v;
            }
            if (preg_match('#^XMP-mwg-rs:RegionList\[(\d+)\]/Area/(X|Y|W|H)$#', $k, $m)) {
                $i = (int)$m[1];
                $c = strtolower($m[2]);
                $faces[$i][$c] = (float)$v;
            }
        }
        // nur Gesichter
        $out = [];
        ksort($faces);
        foreach ($faces as $i => $a) {
            if (($types[$i] ?? null) !== 'Face') {
                continue;
            }
            if (isset($a['x'], $a['y'], $a['w'], $a['h'])) {
                $out[] = ['x' => $a['x'], 'y' => $a['y'], 'w' => $a['w'], 'h' => $a['h']];
            }
        }
        return $out;
    }
}
