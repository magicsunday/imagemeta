#!/usr/bin/env php
<?php

/**
 * Apple Maker Notes Reverse Engineering Tool
 *
 * This script extracts and analyzes Apple maker note payloads from JPEG, HEIC, and MOV files.
 * It helps developers understand unknown fields and structures by displaying:
 * - Raw binary plist structures
 * - Decoded NSKeyedArchive objects
 * - Type information and value representations
 * - Comparative analysis across multiple images
 *
 * Usage:
 *   php scripts/reverse-engineer-apple-makernotes.php <image-file> [options]
 *   php scripts/reverse-engineer-apple-makernotes.php <directory> [options]
 *
 * Options:
 *   --format=json|yaml|text   Output format (default: text)
 *   --output=<file>           Write output to file instead of stdout
 *   --raw                     Include raw binary payload (hex dump)
 *   --compare                 Compare fields across multiple files
 *   --known-only              Show only known/mapped fields
 *   --unknown-only            Show only unknown/unmapped fields
 *   --verbose                 Show detailed type information
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Scripts;

use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use RuntimeException;

use function array_key_exists;
use function array_keys;
use function count;
use function file_exists;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function realpath;
use function scandir;
use function str_repeat;
use function substr;
use function yaml_emit;

// Autoload dependencies
$autoloadPaths = [
    __DIR__ . '/../.build/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

/**
 * Reverse engineering analyzer for Apple maker notes.
 */
final class AppleMakerNotesAnalyzer
{
    private const array KNOWN_FIELDS = [
        'ContentIdentifier',
        'CameraType',
        'HdrHeadroom',
        'HDRHeadroom',
        'HdrGain',
        'HDRGain',
        'SNRSetting',
        'SNR',
        'AEStable',
        'AETarget',
        'AEAverage',
        'AFStable',
        'AFPerformance',
        'SignalToNoiseRatioType',
        'LuminanceNoiseAmplitude',
        'FocusPosition',
        'RunTime',
        'ColorTemperature',
        'SemanticStylePreset',
        'SemanticStyleWarmth',
        'SemanticStyleTone',
        'AccelerationVector',
        'ImageCaptureRequestID',
        'QualityHint',
        'ColorCorrectionMatrix',
        'MakerNoteVersion',
        'HDRImageType',
        'HdrImageType',
        'BurstUUID',
        'FocusDistanceRange',
        'OISMode',
        'ImageCaptureType',
        'ImageUniqueID',
        'PhotoIdentifier',
        'AFMeasuredDepth',
        'AFConfidence',
        // Flag fields
        'HDR',
        'LivePhoto',
        'NightMode',
        'LongExposure',
        'PersonInPhoto',
        'PetInPhoto',
        'SceneFlags',
        'ImageProcessingFlags',
        'PhotosAppFeatureFlags',
    ];

    private MetadataReader $reader;
    private string $format = 'text';
    private bool $includeRaw = false;
    private bool $compareMode = false;
    private bool $knownOnly = false;
    private bool $unknownOnly = false;
    private bool $verbose = false;
    private ?string $outputFile = null;

    /** @var array<string, array<string, mixed>> */
    private array $analysisResults = [];

    public function __construct()
    {
        $this->reader = new MetadataReader();
    }

    /**
     * Parse command line arguments.
     *
     * @param array<int, string> $argv Command line arguments.
     */
    public function parseArguments(array $argv): void
    {
        for ($i = 1; $i < count($argv); ++$i) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--format=')) {
                $this->format = substr($arg, 9);
                continue;
            }

            if (str_starts_with($arg, '--output=')) {
                $this->outputFile = substr($arg, 9);
                continue;
            }

            if ($arg === '--raw') {
                $this->includeRaw = true;
                continue;
            }

            if ($arg === '--compare') {
                $this->compareMode = true;
                continue;
            }

            if ($arg === '--known-only') {
                $this->knownOnly = true;
                continue;
            }

            if ($arg === '--unknown-only') {
                $this->unknownOnly = true;
                continue;
            }

            if ($arg === '--verbose') {
                $this->verbose = true;
                continue;
            }
        }
    }

    /**
     * Analyze a single image file.
     *
     * @param string $path Path to image file.
     */
    public function analyzeFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        if (!is_file($path)) {
            throw new RuntimeException("Not a file: {$path}");
        }

        echo "Analyzing: {$path}\n";

        try {
            $metadata = $this->reader->read($path);
            $exifDoc = $metadata->exifDoc;

            if ($exifDoc === null) {
                echo "  No EXIF data found\n";
                return;
            }

            $makerNotes = $exifDoc->makerNotes();
            if ($makerNotes === null) {
                echo "  No maker notes found\n";
                return;
            }

            if (!($makerNotes instanceof MakerNotesRecord)) {
                echo "  Maker notes format not recognized\n";
                return;
            }

            if ($makerNotes->make !== 'Apple') {
                echo "  Not Apple maker notes (found: {$makerNotes->make})\n";
                return;
            }

            $this->analyzeMakerNotes($path, $makerNotes);
        } catch (\Throwable $e) {
            echo "  Error: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Analyze maker notes record.
     *
     * @param string $path Path to source file.
     * @param MakerNotesRecord $record Maker notes record.
     */
    private function analyzeMakerNotes(string $path, MakerNotesRecord $record): void
    {
        $analysis = [
            'file' => $path,
            'make' => $record->make,
            'size' => $record->rawSize,
            'digest' => $record->rawDigest,
            'parsed' => null,
            'raw_fields' => [],
            'known_fields' => [],
            'unknown_fields' => [],
            'raw_payload' => null,
        ];

        if ($record->parsed instanceof AppleMakerNotes) {
            $analysis['parsed'] = $this->extractParsedFields($record->parsed);
        }

        // Store analysis
        $this->analysisResults[basename($path)] = $analysis;

        // Output for single file
        if (!$this->compareMode) {
            $this->outputAnalysis($analysis);
        }
    }

    /**
     * Extract parsed field values from AppleMakerNotes.
     *
     * @param AppleMakerNotes $notes Parsed maker notes.
     *
     * @return array<string, mixed> Field values.
     */
    private function extractParsedFields(AppleMakerNotes $notes): array
    {
        $fields = [];

        $reflection = new \ReflectionClass($notes);
        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            $value = $property->getValue($notes);

            if ($value === null) {
                continue;
            }

            $fields[$name] = $this->formatValue($value);
        }

        return $fields;
    }

    /**
     * Format a value for display.
     *
     * @param mixed $value Value to format.
     *
     * @return mixed Formatted value.
     */
    private function formatValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_map($this->formatValue(...), $value);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return get_class($value);
        }

        return null;
    }

    /**
     * Output analysis results.
     *
     * @param array<string, mixed> $analysis Analysis data.
     */
    private function outputAnalysis(array $analysis): void
    {
        if ($this->format === 'json') {
            $this->outputJson($analysis);
        } elseif ($this->format === 'yaml') {
            $this->outputYaml($analysis);
        } else {
            $this->outputText($analysis);
        }
    }

    /**
     * Output as JSON.
     *
     * @param array<string, mixed> $analysis Analysis data.
     */
    private function outputJson(array $analysis): void
    {
        $json = json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode JSON');
        }

        if ($this->outputFile !== null) {
            file_put_contents($this->outputFile, $json);
        } else {
            echo $json . "\n";
        }
    }

    /**
     * Output as YAML.
     *
     * @param array<string, mixed> $analysis Analysis data.
     */
    private function outputYaml(array $analysis): void
    {
        $yaml = yaml_emit($analysis, YAML_UTF8_ENCODING);
        if ($yaml === false) {
            throw new RuntimeException('Failed to encode YAML');
        }

        if ($this->outputFile !== null) {
            file_put_contents($this->outputFile, $yaml);
        } else {
            echo $yaml;
        }
    }

    /**
     * Output as human-readable text.
     *
     * @param array<string, mixed> $analysis Analysis data.
     */
    private function outputText(array $analysis): void
    {
        $output = "\n";
        $output .= "=== Apple Maker Notes Analysis ===\n";
        $output .= "File: " . $analysis['file'] . "\n";
        $output .= "Make: " . $analysis['make'] . "\n";
        $output .= "Raw Size: " . $analysis['size'] . " bytes\n";
        $output .= "Digest: " . $analysis['digest'] . "\n";
        $output .= "\n";

        if ($analysis['parsed'] !== null && is_array($analysis['parsed'])) {
            $output .= "--- Parsed Fields ---\n";
            foreach ($analysis['parsed'] as $key => $value) {
                if ($this->knownOnly && !in_array($key, self::KNOWN_FIELDS, true)) {
                    continue;
                }

                if ($this->unknownOnly && in_array($key, self::KNOWN_FIELDS, true)) {
                    continue;
                }

                $typeInfo = '';
                if ($this->verbose) {
                    $typeInfo = ' (' . $this->getTypeInfo($value) . ')';
                }

                $output .= "  {$key}{$typeInfo}: " . $this->formatValueForText($value) . "\n";
            }
        }

        if ($this->outputFile !== null) {
            file_put_contents($this->outputFile, $output);
        } else {
            echo $output;
        }
    }

    /**
     * Get type information for a value.
     *
     * @param mixed $value Value to inspect.
     *
     * @return string Type description.
     */
    private function getTypeInfo(mixed $value): string
    {
        if (is_bool($value)) {
            return 'bool';
        }

        if (is_int($value)) {
            return 'int';
        }

        if (is_float($value)) {
            return 'float';
        }

        if (is_string($value)) {
            return 'string';
        }

        if (is_array($value)) {
            return 'array[' . count($value) . ']';
        }

        if (is_object($value)) {
            return get_class($value);
        }

        return 'unknown';
    }

    /**
     * Format value for text output.
     *
     * @param mixed $value Value to format.
     *
     * @return string Formatted string.
     */
    private function formatValueForText(mixed $value, int $indent = 0): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return '"' . $value . '"';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $items = [];
            foreach ($value as $k => $v) {
                $prefix = str_repeat('  ', $indent + 1);
                if (is_string($k)) {
                    $items[] = $prefix . $k . ': ' . $this->formatValueForText($v, $indent + 1);
                } else {
                    $items[] = $prefix . '- ' . $this->formatValueForText($v, $indent + 1);
                }
            }

            return "\n" . implode("\n", $items);
        }

        if (is_object($value)) {
            return get_class($value);
        }

        return 'unknown';
    }

    /**
     * Analyze all files in a directory.
     *
     * @param string $path Directory path.
     */
    public function analyzeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            throw new RuntimeException("Not a directory: {$path}");
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            throw new RuntimeException("Invalid directory path: {$path}");
        }

        $files = scandir($realPath);
        if ($files === false) {
            throw new RuntimeException("Cannot read directory: {$path}");
        }

        $imageFiles = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $realPath . '/' . $file;
            if (!is_file($filePath)) {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'heic', 'mov', 'mp4'], true)) {
                $imageFiles[] = $filePath;
            }
        }

        if ($imageFiles === []) {
            echo "No image files found in directory\n";
            return;
        }

        foreach ($imageFiles as $file) {
            $this->analyzeFile($file);
        }

        if ($this->compareMode) {
            $this->compareResults();
        }
    }

    /**
     * Compare results across multiple files.
     */
    private function compareResults(): void
    {
        if (count($this->analysisResults) < 2) {
            echo "\nNeed at least 2 files for comparison\n";
            return;
        }

        $allFields = [];
        foreach ($this->analysisResults as $filename => $analysis) {
            if (!isset($analysis['parsed']) || !is_array($analysis['parsed'])) {
                continue;
            }

            foreach (array_keys($analysis['parsed']) as $field) {
                if (!array_key_exists($field, $allFields)) {
                    $allFields[$field] = [];
                }

                $allFields[$field][] = $filename;
            }
        }

        echo "\n=== Field Comparison ===\n";
        echo "Total files analyzed: " . count($this->analysisResults) . "\n\n";

        echo "Fields present in all files:\n";
        foreach ($allFields as $field => $files) {
            if (count($files) === count($this->analysisResults)) {
                echo "  - {$field}\n";
            }
        }

        echo "\nFields present in some files:\n";
        foreach ($allFields as $field => $files) {
            if (count($files) < count($this->analysisResults) && count($files) > 1) {
                echo "  - {$field} (" . count($files) . "/" . count($this->analysisResults) . " files)\n";
            }
        }

        echo "\nFields present in only one file:\n";
        foreach ($allFields as $field => $files) {
            if (count($files) === 1) {
                echo "  - {$field} (in {$files[0]})\n";
            }
        }
    }

    /**
     * Display usage information.
     */
    public static function showUsage(): void
    {
        echo <<<'USAGE'
Apple Maker Notes Reverse Engineering Tool

Usage:
  php scripts/reverse-engineer-apple-makernotes.php <image-file> [options]
  php scripts/reverse-engineer-apple-makernotes.php <directory> [options]

Options:
  --format=json|yaml|text   Output format (default: text)
  --output=<file>           Write output to file instead of stdout
  --raw                     Include raw binary payload (hex dump)
  --compare                 Compare fields across multiple files
  --known-only              Show only known/mapped fields
  --unknown-only            Show only unknown/unmapped fields
  --verbose                 Show detailed type information

Examples:
  # Analyze single file
  php scripts/reverse-engineer-apple-makernotes.php photo.heic

  # Analyze with verbose output
  php scripts/reverse-engineer-apple-makernotes.php photo.jpg --verbose

  # Compare multiple files
  php scripts/reverse-engineer-apple-makernotes.php photos/ --compare

  # Export unknown fields to JSON
  php scripts/reverse-engineer-apple-makernotes.php photo.heic --unknown-only --format=json --output=unknown.json

USAGE;
    }
}

// Main execution
try {
    if ($argc < 2) {
        AppleMakerNotesAnalyzer::showUsage();
        exit(1);
    }

    $analyzer = new AppleMakerNotesAnalyzer();
    $analyzer->parseArguments($argv);

    $target = $argv[1];

    if (is_file($target)) {
        $analyzer->analyzeFile($target);
    } elseif (is_dir($target)) {
        $analyzer->analyzeDirectory($target);
    } else {
        echo "Error: Target not found: {$target}\n\n";
        AppleMakerNotesAnalyzer::showUsage();
        exit(1);
    }

    exit(0);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    if (isset($argv) && in_array('--verbose', $argv, true)) {
        echo $e->getTraceAsString() . "\n";
    }
    exit(1);
}
