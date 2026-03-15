#!/usr/bin/env php
<?php

/**
 * Dumps the raw Apple Maker Notes dictionary from JPEG/HEIC files.
 *
 * Decodes the binary plist / NSKeyedArchive / text plist payload and shows ALL
 * keys — including unknown/undocumented fields that the parser does not yet map.
 * Useful for reverse engineering Apple's proprietary metadata format.
 *
 * Usage:
 *   php scripts/apple-makernotes-dump.php <image-file>
 *   php scripts/apple-makernotes-dump.php --json <image-file>
 */

declare(strict_types=1);

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleJpegIfdParser;
use MagicSunday\ImageMeta\MakerNotes\Apple\KeyedArchiveResolver;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistTextParser;
use MagicSunday\ImageMeta\MetadataReader;

require __DIR__ . '/../.build/vendor/autoload.php';

// --- CLI ---

$args   = array_values(array_filter($argv, fn ($a) => !str_starts_with($a, '--')));
$asJson = in_array('--json', $argv, true);

if (count($args) < 2) {
    fprintf(STDERR, "Usage: %s [--json] <image-file>\n\n", $argv[0]);
    fprintf(STDERR, "Decodes the raw Apple Maker Notes dictionary and displays ALL keys,\n");
    fprintf(STDERR, "including unknown/undocumented fields.\n");
    exit(1);
}

$filePath = $args[1];

if (!is_file($filePath)) {
    fprintf(STDERR, "File not found: %s\n", $filePath);
    exit(1);
}

// --- Step 1: Extract raw MakerNote bytes via MetadataReader ---

$reader   = MetadataReader::createDefault();
$metadata = $reader->read($filePath);

if ($metadata->makerNotes === null) {
    fprintf(STDERR, "No maker notes found in: %s\n", $filePath);
    exit(1);
}

if ($metadata->makerNotes->vendor !== 'Apple') {
    fprintf(STDERR, "Not an Apple maker note (vendor: %s)\n", $metadata->makerNotes->vendor);
    exit(1);
}

// --- Step 2: Re-extract raw MakerNote from EXIF to get binary payload ---

$exifBlobs = $metadata->exifBlobs;

if ($exifBlobs === []) {
    fprintf(STDERR, "No EXIF data found (cannot extract raw MakerNote payload)\n");
    exit(1);
}

// Find MakerNote tag (0x927C) in the primary EXIF blob
$rawMakerNote = extractMakerNoteFromExif($exifBlobs[0]);

if ($rawMakerNote === null) {
    fprintf(STDERR, "MakerNote tag (0x927C) not found in EXIF\n");
    exit(1);
}

// --- Step 3: Decode to raw dictionary (bypass AppleMakerNotesBuilder) ---

$dictionary = decodeAppleDictionary($rawMakerNote);

if ($dictionary === null) {
    fprintf(STDERR, "Failed to decode Apple maker note payload (%d bytes)\n", strlen($rawMakerNote));
    exit(1);
}

// --- Step 4: Output ---

if ($asJson) {
    echo json_encode(
        sanitizeForJson($dictionary),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ) . "\n";
    exit(0);
}

echo sprintf("File:   %s\n", $filePath);
echo sprintf("Vendor: Apple\n");
echo sprintf("Size:   %d bytes (raw MakerNote)\n", strlen($rawMakerNote));
echo sprintf("Keys:   %d\n\n", count($dictionary));

echo "=== Raw Apple Maker Notes Dictionary ===\n\n";

ksort($dictionary);

foreach ($dictionary as $key => $value) {
    echo sprintf("  %-40s %s\n", $key, formatValue($value, 42));
}

// ==========================================================================
// Helper functions
// ==========================================================================

function extractMakerNoteFromExif(string $tiffBlob): ?string
{
    if (strlen($tiffBlob) < 8) {
        return null;
    }

    $bo = match (substr($tiffBlob, 0, 2)) {
        'II'    => 'v',
        'MM'    => 'n',
        default => null,
    };

    if ($bo === null) {
        return null;
    }

    $u16 = fn (int $off) => unpack($bo, $tiffBlob, $off)[1];
    $u32 = fn (int $off) => unpack($bo === 'v' ? 'V' : 'N', $tiffBlob, $off)[1];

    // Navigate IFD0 → ExifIFD → MakerNote
    $ifdOffset = $u32(4);
    $makerNote = findTagInIfd($tiffBlob, $ifdOffset, 0x8769, $u16, $u32); // ExifIFD pointer

    if ($makerNote === null) {
        return null;
    }

    $exifIfdOffset = $u32($makerNote + 8);

    return findTagValue($tiffBlob, $exifIfdOffset, 0x927C, $u16, $u32);
}

function findTagInIfd(string $data, int $ifdOffset, int $targetTag, callable $u16, callable $u32): ?int
{
    $len = strlen($data);

    if (($ifdOffset + 2) > $len) {
        return null;
    }

    $count = $u16($ifdOffset);

    for ($i = 0; $i < $count; ++$i) {
        $entryOffset = $ifdOffset + 2 + ($i * 12);

        if (($entryOffset + 12) > $len) {
            return null;
        }

        if ($u16($entryOffset) === $targetTag) {
            return $entryOffset;
        }
    }

    return null;
}

function findTagValue(string $data, int $ifdOffset, int $targetTag, callable $u16, callable $u32): ?string
{
    $entry = findTagInIfd($data, $ifdOffset, $targetTag, $u16, $u32);

    if ($entry === null) {
        return null;
    }

    $count     = $u32($entry + 4);
    $valueData = $u32($entry + 8);

    if ($count <= 4) {
        return substr($data, $entry + 8, $count);
    }

    if (($valueData + $count) > strlen($data)) {
        return null;
    }

    return substr($data, $valueData, $count);
}

/**
 * Decodes raw Apple MakerNote bytes to a dictionary, bypassing the builder.
 *
 * @return array<string, mixed>|null
 */
function decodeAppleDictionary(string $raw): ?array
{
    // Try JPEG IFD format first
    $ifdParser  = new AppleJpegIfdParser();
    $ifdResult  = $ifdParser->parse($raw);

    if (is_array($ifdResult) && $ifdResult !== []) {
        return $ifdResult;
    }

    // Try binary plist / NSKeyedArchive
    $resolver = new KeyedArchiveResolver();
    $decoded  = $resolver->decodeBinaryPropertyList($raw);

    if ($decoded === null) {
        $textParser = new PlistTextParser();
        $decoded    = $textParser->parse($raw);
    }

    if (!is_array($decoded) || !KeyedArchiveResolver::isStringKeyedDictionary($decoded)) {
        return null;
    }

    $resolved = $resolver->resolveKeyedArchiveDictionary($decoded);

    if ($resolved === null || !KeyedArchiveResolver::isStringKeyedDictionary($resolved)) {
        return $decoded;
    }

    return $resolved;
}

function formatValue(mixed $value, int $indent = 0): string
{
    if ($value === null) {
        return '(null)';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value)) {
        return (string) $value;
    }

    if (is_float($value)) {
        return sprintf('%.6f', $value);
    }

    if (is_string($value)) {
        // Try decoding binary plists
        if (str_starts_with($value, 'bplist00')) {
            try {
                $resolver = new KeyedArchiveResolver();
                $decoded  = $resolver->decodeBinaryPropertyList($value);

                if (is_array($decoded)) {
                    $resolved = $resolver->resolveKeyedArchiveDictionary($decoded);

                    return formatValue($resolved ?? $decoded, $indent);
                }
            } catch (Throwable) {
                // Fall through to binary display
            }
        }

        // Try known binary formats before falling back to hex dump
        $structuredBinary = tryDecodeBinaryStructure($value);

        if ($structuredBinary !== null) {
            return $structuredBinary;
        }

        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F]/', $value)) {
            return sprintf('(binary %d bytes) %s', strlen($value), strtoupper(bin2hex(substr($value, 0, 32))));
        }

        return '"' . $value . '"';
    }

    if (is_array($value)) {
        if ($value === []) {
            return '[]';
        }

        // Flat numeric array
        if (array_is_list($value) && count($value) <= 10 && !is_array($value[0] ?? null)) {
            $parts = array_map(fn ($v) => formatValue($v), $value);

            return '[' . implode(', ', $parts) . ']';
        }

        // Nested structure
        $lines = [];

        foreach ($value as $k => $v) {
            $prefix = is_string($k) ? "$k: " : "$k: ";
            $lines[] = str_repeat(' ', $indent + 2) . $prefix . formatValue($v, $indent + 2);
        }

        return "{\n" . implode("\n", $lines) . "\n" . str_repeat(' ', $indent) . '}';
    }

    return '(unknown type)';
}

/**
 * @return array<string, mixed>
 */
function sanitizeForJson(array $data): array
{
    $result = [];

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $result[$key] = sanitizeForJson($value);
        } elseif (is_string($value) && str_starts_with($value, 'bplist00')) {
            try {
                $decoded = (new BinaryPlistDecoder())->decode($value);

                if (is_array($decoded)) {
                    $result[$key] = sanitizeForJson($decoded);

                    continue;
                }
            } catch (Throwable) {
                // Fall through
            }

            $result[$key] = '(binary) ' . bin2hex($value);
        } elseif (is_string($value) && (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F]/', $value))) {
            $result[$key] = '(binary) ' . bin2hex($value);
        } else {
            $result[$key] = $value;
        }
    }

    return $result;
}

/**
 * Attempts to decode known Apple binary structures into human-readable format.
 */
function tryDecodeBinaryStructure(string $data): ?string
{
    $len = strlen($data);

    // AE Metering Matrix: 512 bytes = 256 × uint16 big-endian → 16×16 grid
    if ($len === 512) {
        return decodeAeMatrix($data);
    }

    // Color Correction Matrix: header(8 bytes) + 9 × float32 LE = 44 bytes
    if ($len === 44 && unpack('V', $data, 0)[1] === 1) {
        return decodeColorMatrix($data);
    }

    return null;
}

function decodeAeMatrix(string $data): string
{
    $values = [];

    for ($i = 0; $i < 512; $i += 2) {
        $values[] = unpack('n', $data, $i)[1];
    }

    $rows = [];

    for ($row = 0; $row < 16; ++$row) {
        $cols = [];

        for ($col = 0; $col < 16; ++$col) {
            $cols[] = sprintf('%5d', $values[$row * 16 + $col]);
        }

        $rows[] = '    ' . implode(' ', $cols);
    }

    return sprintf(
        "(16x16 AE metering matrix, min=%d, max=%d, avg=%d)\n%s",
        min($values),
        max($values),
        (int) round(array_sum($values) / count($values)),
        implode("\n", $rows),
    );
}

function decodeColorMatrix(string $data): string
{
    $floats = [];

    for ($i = 8; $i < 44; $i += 4) {
        $floats[] = unpack('g', $data, $i)[1];
    }

    return sprintf(
        "(3x3 color correction matrix)\n"
        . "    [%10.6f %10.6f %10.6f]\n"
        . "    [%10.6f %10.6f %10.6f]\n"
        . "    [%10.6f %10.6f %10.6f]",
        ...$floats,
    );
}
