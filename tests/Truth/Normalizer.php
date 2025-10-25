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
    /** Delta for floating point comparisons. */
    public const DELTA = 1e-6;

    /** @var array<string, array<int|string, string>> */
    private array $enumMap;

    /**
     * @param array<string, array<int|string, string>> $enumMap Mapping from numeric codes to enum names.
     */
    public function __construct(array $enumMap)
    {
        $this->enumMap = $enumMap;
    }

    /**
     * Converts enums and date/time objects into comparable scalar values.
     *
     * @param mixed $value Value returned by the structured metadata API.
     *
     * @return mixed Enum name, formatted date/time or the original value when no conversion is needed.
     */
    public static function toComparable(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->name; // The enum name is more stable than ->value for backed enums.
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \DateTimeInterface) {
            // Keep second precision; SubSec is handled separately when needed.
            return $value->format('Y-m-d\TH:i:sP');
        }
        return $value;
    }

    /**
     * Compares a structured enum value against the ExifTool raw value through the configured enum map.
     *
     * @param non-empty-string                     $group          Enumeration group such as 'Orientation' or 'WhiteBalance'.
     * @param int|string                           $exifNumeric    Raw ExifTool numeric value.
     * @param \UnitEnum|\BackedEnum|string|null $structuredEnum Enum value returned by the metadata reader.
     */
    public function compareEnum(string $group, int|string $exifNumeric, mixed $structuredEnum): bool
    {
        if (!isset($this->enumMap[$group])) {
            return false; // Missing mapping for this group.
        }
        $expectedName = $this->enumMap[$group][$exifNumeric] ?? null;
        if ($expectedName === null) {
            return false;
        }
        $actualName = is_string($structuredEnum) ? $structuredEnum : self::toComparable($structuredEnum);
        return $actualName === $expectedName;
    }

    /**
     * Decodes the EXIF Flash bitmask into its logical components for comparison.
     *
     * @param int $val Raw EXIF Flash value from the truth data.
     *
     * @return array{fired: bool, returnDetection: string, mode: string, functionPresence: string, redEyeReduction: bool}
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
     * Builds an ISO 8601 timestamp from EXIF DateTime, SubSec and OffsetTime components.
     * Expects ExifTool JSON output produced with -n/-struct flags.
     *
     * @param array<string,mixed> $exif Parsed ExifTool data.
     * @param 'EXIF:CreateDate'|'EXIF:DateTimeOriginal'|'IFD0:ModifyDate' $baseKey Base key that defines the date component.
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

        // SubSec key matching the selected base value.
        $subSecKey = match ($baseKey) {
            'EXIF:CreateDate'       => 'EXIF:SubSecTimeDigitized',
            'EXIF:DateTimeOriginal' => 'EXIF:SubSecTimeOriginal',
            'IFD0:ModifyDate'       => 'EXIF:SubSecTime', // Often missing in the source data.
        };
        $sub = self::get($exif, $subSecKey);
        $sub = is_string($sub) && $sub !== '' ? $sub : null;

        // Offset key matching the selected base value.
        $offsetKey = match ($baseKey) {
            'EXIF:CreateDate'       => 'EXIF:OffsetTimeDigitized',
            'EXIF:DateTimeOriginal' => 'EXIF:OffsetTimeOriginal',
            'IFD0:ModifyDate'       => 'EXIF:OffsetTime',
        };
        $off = self::get($exif, $offsetKey);
        $off = is_string($off) && $off !== '' ? $off : '+00:00';

        // Assemble the timestamp components.
        if ($sub !== null) {
            // Normalise to three digits representing milliseconds.
            $sub = str_pad(substr($sub, 0, 3), 3, '0');
            return sprintf('%s.%s%s', $dt, $sub, self::normalizeOffset($off));
        }
        return sprintf('%s%s', $dt, self::normalizeOffset($off));
    }

    /**
     * Fetches a value from the ExifTool array using the flat GROUP:Tag key.
     *
     * @param array<string,mixed> $exif ExifTool data indexed by GROUP:Tag.
     */
    private static function get(array $exif, string $key): mixed
    {
        // ExifTool JSON exposes flat keys formatted as "GROUP:Tag"
        return $exif[$key] ?? null;
    }

    /**
     * Normalises various timezone offset formats to the canonical ±HH:MM form.
     *
     * @param string $off Offset string from the truth data.
     *
     * @return string Normalised offset.
     */
    private static function normalizeOffset(string $off): string
    {
        // Accepts formats like "+01:00" or "+0100" and always returns "+01:00"
        if (preg_match('/^[\+\-]\d{2}:\d{2}$/', $off)) {
            return $off;
        }
        if (preg_match('/^([\+\-]\d{2})(\d{2})$/', $off, $m)) {
            return $m[1] . ':' . $m[2];
        }
        return '+00:00';
    }

    /**
     * Extracts MWG face regions from ExifTool JSON into [{x,y,w,h}, ...].
     *
     * @param array<string,mixed> $exif ExifTool truth data.
     *
     * @return array<int,array{x:float,y:float,w:float,h:float}> Normalised face rectangles.
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
        // Only collect entries marked as faces.
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
