#!/usr/bin/env php
<?php

/**
 * Dumps Apple Maker Notes from JPEG/HEIC files using the MetadataReader infrastructure.
 *
 * Usage:
 *   php scripts/apple-makernotes-dump.php <image-file>
 *   php scripts/apple-makernotes-dump.php --json <image-file>
 */

declare(strict_types=1);

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MetadataReader;

require __DIR__ . '/../.build/vendor/autoload.php';

// --- CLI argument parsing ---

$args    = array_values(array_filter($argv, fn ($a) => !str_starts_with($a, '--')));
$asJson  = in_array('--json', $argv, true);

if (count($args) < 2) {
    fprintf(STDERR, "Usage: %s [--json] <image-file>\n", $argv[0]);
    exit(1);
}

$filePath = $args[1];

if (!is_file($filePath)) {
    fprintf(STDERR, "File not found: %s\n", $filePath);
    exit(1);
}

// --- Read metadata ---

$reader   = MetadataReader::createDefault();
$metadata = $reader->read($filePath);

if (!$metadata->makerNotes instanceof MakerNotesRecord) {
    fprintf(STDERR, "No maker notes found in: %s\n", $filePath);
    exit(1);
}

$record = $metadata->makerNotes;

if (!$record->apple instanceof AppleMakerNotes) {
    fprintf(STDERR, "Not an Apple maker note (vendor: %s)\n", $record->vendor);
    exit(1);
}

// --- Output ---

if ($asJson) {
    echo json_encode(objectToArray($record->apple), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo sprintf("File:   %s\n", $filePath);
echo sprintf("Vendor: %s\n", $record->vendor);
echo sprintf("Size:   %d bytes\n", $record->length);
echo sprintf("SHA1:   %s\n\n", $record->sha1);

echo "=== Apple Maker Notes ===\n\n";

dumpObject($record->apple, 0);

// --- Helper functions ---

function dumpObject(object $obj, int $depth): void
{
    $indent = str_repeat('  ', $depth);

    foreach (get_object_vars($obj) as $name => $value) {
        if ($value === null) {
            continue;
        }

        if (is_object($value)) {
            echo sprintf("%s%s:\n", $indent, $name);
            dumpObject($value, $depth + 1);

            continue;
        }

        echo sprintf("%s%-30s %s\n", $indent, $name, formatValue($value));
    }
}

function formatValue(string|int|float|bool|array $value): string
{
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
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '(binary) ' . bin2hex($value);
        }

        return $value;
    }

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $parts[] = sprintf('%s=%s', $k, formatValue($v));
            } else {
                $parts[] = is_scalar($v) ? formatValue($v) : '(...)';
            }
        }

        return '[' . implode(', ', $parts) . ']';
    }

    return '(unknown)';
}

/**
 * @return array<string, mixed>
 */
function objectToArray(object $obj): array
{
    $result = [];

    foreach (get_object_vars($obj) as $name => $value) {
        if ($value === null) {
            continue;
        }

        if (is_object($value)) {
            $result[$name] = objectToArray($value);

            continue;
        }

        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            $result[$name] = '(binary) ' . bin2hex($value);

            continue;
        }

        $result[$name] = $value;
    }

    return $result;
}
