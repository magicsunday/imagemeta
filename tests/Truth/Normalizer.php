<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Truth;

use BackedEnum;
use DateTimeInterface;
use UnitEnum;

/**
 * Provides helpers to normalize metadata for comparisons against ExifTool truth data.
 */
final class Normalizer
{
    /** Delta used for floating point comparisons. */
    public const DELTA = 1e-6;

    /** @var array<string, array<int|string, string>> */
    private array $enumMap;

    /**
     * Create a new normalizer with the provided enum mapping.
     *
     * @param array<string, array<int|string, string>> $enumMap
     */
    public function __construct(array $enumMap)
    {
        $this->enumMap = $enumMap;
    }

    /**
     * Convert enum or temporal values into comparable scalar representations.
     */
    public static function toComparable(BackedEnum|UnitEnum|DateTimeInterface|string|int|float|bool|null $value): string|int|float|bool|null
    {
        if ($value instanceof BackedEnum) {
            return $value->name;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }
        return $value;
    }

    /**
     * Compare a structured enum value against an ExifTool numeric value via the enum map.
     *
     * @param non-empty-string $group Identifier in the enum map, e.g. "Orientation" or "WhiteBalance".
     * @param int|string       $exifNumeric
     */
    public function compareEnum(string $group, int|string $exifNumeric, BackedEnum|UnitEnum|string|null $structuredEnum): bool
    {
        if (!isset($this->enumMap[$group])) {
            return false; // no mapping available
        }
        $expectedName = $this->enumMap[$group][$exifNumeric] ?? null;
        if ($expectedName === null) {
            return false;
        }
        $actualName = is_string($structuredEnum) ? $structuredEnum : self::toComparable($structuredEnum);
        return $actualName === $expectedName;
    }

    /**
     * Decode the EXIF flash flag bitmask into structured fields.
     *
     * @return array{fired:bool,returnDetection:string,mode:string,functionPresence:string,redEyeReduction:bool}
     */
    public static function decodeExifFlash(int $val): array
    {
        // Specification (EXIF 2.3):
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
     * Build an ISO-8601 timestamp from EXIF base, subseconds and offset values.
     *
     * @param array<string,string|int|float|bool|null>                    $exif
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

        // SubSec (matching key for the base field)
        $subSecKey = match ($baseKey) {
            'EXIF:CreateDate'       => 'EXIF:SubSecTimeDigitized',
            'EXIF:DateTimeOriginal' => 'EXIF:SubSecTimeOriginal',
            'IFD0:ModifyDate'       => 'EXIF:SubSecTime', // often missing in the data
        };
        $sub = self::get($exif, $subSecKey);
        $sub = is_string($sub) && $sub !== '' ? $sub : null;

        // Offset (matching key for the base field)
        $offsetKey = match ($baseKey) {
            'EXIF:CreateDate'       => 'EXIF:OffsetTimeDigitized',
            'EXIF:DateTimeOriginal' => 'EXIF:OffsetTimeOriginal',
            'IFD0:ModifyDate'       => 'EXIF:OffsetTime',
        };
        $off = self::get($exif, $offsetKey);
        $off = is_string($off) && $off !== '' ? $off : '+00:00';

        // combine the individual parts
        if ($sub !== null) {
            // normalize to milliseconds (three digits)
            $sub = str_pad(substr($sub, 0, 3), 3, '0');
            return sprintf('%s.%s%s', $dt, $sub, self::normalizeOffset($off));
        }
        return sprintf('%s%s', $dt, self::normalizeOffset($off));
    }

    /**
     * Retrieve a value from the ExifTool JSON array.
     *
     * @param array<string,string|int|float|bool|null> $exif
     */
    private static function get(array $exif, string $key): string|int|float|bool|null
    {
        // ExifTool JSON exposes flattened keys "GROUP:Tag"
        return $exif[$key] ?? null;
    }

    /**
     * Normalize offsets like "+0100" to the canonical "+01:00" representation.
     */
    private static function normalizeOffset(string $off): string
    {
        // accept "+01:00" or "+0100" and always return "+01:00"
        if (preg_match('/^[\+\-]\d{2}:\d{2}$/', $off)) {
            return $off;
        }
        if (preg_match('/^([\+\-]\d{2})(\d{2})$/', $off, $m)) {
            return $m[1] . ':' . $m[2];
        }
        return '+00:00';
    }

    /**
     * Extract MWG face areas from the ExifTool JSON representation.
     *
     * @param array<string,string|int|float|bool|null> $exif
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
        // keep only face regions
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
