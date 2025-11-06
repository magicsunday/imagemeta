#!/usr/bin/env php
<?php

/**
 * EXIF/TIFF Compliance Analyzer
 *
 * Analyzes implementation coverage of EXIF 3.0, EXIF 2.32, and TIFF 6.0 tags
 * against the official specifications.
 *
 * Generates machine-readable compliance reports in JSON/YAML format.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Scripts;

use RuntimeException;

// Check if we're using composer or standalone
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

// We don't actually need external dependencies for this script
// yaml_parse and yaml_emit are PHP extensions

/**
 * Compliance analyzer for EXIF/TIFF tag coverage.
 */
final class ComplianceAnalyzer
{
    private const string SPEC_FILE = __DIR__ . '/../resources/exif-spec-tags.yaml';
    private const string IMPL_MAP_FILE = __DIR__ . '/../resources/exif-map.yaml';
    private const string EXIF_TAG_CLASS = __DIR__ . '/../src/Model/Exif/ExifTag.php';
    private const string OUTPUT_JSON = __DIR__ . '/../docs/compliance-report.json';
    private const string OUTPUT_YAML = __DIR__ . '/../docs/compliance-report.yaml';

    private array $specTags = [];
    private array $implMap = [];
    private array $exifConstants = [];
    private array $complianceReport = [];

    public function __construct()
    {
        $this->loadSpecifications();
        $this->loadImplementation();
    }

    /**
     * Load official EXIF/TIFF tag specifications.
     */
    private function loadSpecifications(): void
    {
        if (!file_exists(self::SPEC_FILE)) {
            throw new RuntimeException('Specification file not found: ' . self::SPEC_FILE);
        }

        $content = file_get_contents(self::SPEC_FILE);
        if ($content === false) {
            throw new RuntimeException('Failed to read specification file');
        }

        $data = yaml_parse($content);
        if ($data === false) {
            throw new RuntimeException('Failed to parse specification YAML');
        }

        $this->specTags = $data;
    }

    /**
     * Load current implementation mapping and constants.
     */
    private function loadImplementation(): void
    {
        // Load exif-map.yaml
        if (file_exists(self::IMPL_MAP_FILE)) {
            $content = file_get_contents(self::IMPL_MAP_FILE);
            if ($content !== false) {
                $this->implMap = yaml_parse($content) ?: [];
            }
        }

        // Parse ExifTag.php for constants
        if (file_exists(self::EXIF_TAG_CLASS)) {
            $this->parseExifTagConstants();
        }
    }

    /**
     * Extract tag constants from ExifTag.php.
     */
    private function parseExifTagConstants(): void
    {
        $content = file_get_contents(self::EXIF_TAG_CLASS);
        if ($content === false) {
            return;
        }

        // Match: public const int TAG_NAME = 0xHEX;
        $pattern = '/public\s+const\s+int\s+([A-Z_0-9]+)\s*=\s*0x([0-9A-F]+);/i';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
            return;
        }

        foreach ($matches as $match) {
            $name = $match[1];
            $hex = $match[2];
            $tagId = hexdec($hex);
            $this->exifConstants[$tagId] = $name;
        }
    }

    /**
     * Analyze compliance for all tag categories.
     */
    public function analyze(): void
    {
        $this->complianceReport = [
            'generated' => date('c'),
            'summary' => [
                'total_spec_tags' => 0,
                'implemented' => 0,
                'partial' => 0,
                'missing' => 0,
                'extra' => 0,
            ],
            'categories' => [],
        ];

        // Analyze each category
        foreach (['tiff_tags', 'exif_tags', 'gps_tags', 'interop_tags'] as $category) {
            if (!isset($this->specTags[$category])) {
                continue;
            }

            $this->analyzeCategory($category, $this->specTags[$category]);
        }

        // Find extra tags not in spec
        $this->findExtraTags();

        // Calculate summary
        $this->calculateSummary();
    }

    /**
     * Analyze a specific tag category.
     */
    private function analyzeCategory(string $category, array $tags): void
    {
        $results = [];

        foreach ($tags as $tagIdHex => $specInfo) {
            $tagId = is_int($tagIdHex) ? $tagIdHex : hexdec(ltrim($tagIdHex, '0x'));
            $tagName = $specInfo['name'] ?? 'Unknown';

            $status = $this->determineTagStatus($tagId, $tagName, $specInfo);

            $results[$tagIdHex] = [
                'tag_id' => sprintf('0x%04X', $tagId),
                'name' => $tagName,
                'ifd' => $specInfo['ifd'] ?? 'Unknown',
                'source' => $specInfo['source'] ?? 'Unknown',
                'required' => $specInfo['required'] ?? false,
                'deprecated' => $specInfo['deprecated'] ?? false,
                'status' => $status['status'],
                'constant_defined' => $status['constant_defined'],
                'mapping_exists' => $status['mapping_exists'],
                'vo_getter' => $status['vo_getter'],
                'notes' => $status['notes'],
            ];
        }

        $this->complianceReport['categories'][$category] = $results;
    }

    /**
     * Determine implementation status of a tag.
     *
     * @return array{status: string, constant_defined: bool, mapping_exists: bool, vo_getter: array<string>, notes: array<string>}
     */
    private function determineTagStatus(int $tagId, string $tagName, array $specInfo): array
    {
        $constantDefined = isset($this->exifConstants[$tagId]);
        $mappingExists = false;
        $voGetter = [];
        $notes = [];

        // Check if tag is in exif-map.yaml
        $tagNameUpper = strtoupper($this->camelToSnake($tagName));
        if (isset($this->implMap[$tagNameUpper])) {
            $mappingExists = true;
            $voGetter = $this->implMap[$tagNameUpper]['voGetter'] ?? [];
        }

        // Determine overall status
        $status = 'missing';
        if ($constantDefined && $mappingExists && !empty($voGetter)) {
            $status = 'implemented';
        } elseif ($constantDefined || $mappingExists) {
            $status = 'partial';
            if (!$constantDefined) {
                $notes[] = 'Missing constant in ExifTag.php';
            }
            if (!$mappingExists) {
                $notes[] = 'Missing entry in exif-map.yaml';
            }
            if (empty($voGetter)) {
                $notes[] = 'No VO getter methods mapped';
            }
        }

        // Check for deprecated tags
        if (($specInfo['deprecated'] ?? false) === true) {
            $notes[] = 'Tag is deprecated in specification';
        }

        return [
            'status' => $status,
            'constant_defined' => $constantDefined,
            'mapping_exists' => $mappingExists,
            'vo_getter' => $voGetter,
            'notes' => $notes,
        ];
    }

    /**
     * Find tags implemented but not in specification.
     */
    private function findExtraTags(): void
    {
        $specTagIds = [];
        foreach (['tiff_tags', 'exif_tags', 'gps_tags', 'interop_tags'] as $category) {
            if (!isset($this->specTags[$category])) {
                continue;
            }

            foreach (array_keys($this->specTags[$category]) as $tagIdHex) {
                $tagId = is_int($tagIdHex) ? $tagIdHex : hexdec(ltrim($tagIdHex, '0x'));
                $specTagIds[$tagId] = true;
            }
        }

        $extraTags = [];
        foreach ($this->exifConstants as $tagId => $constantName) {
            if (!isset($specTagIds[$tagId])) {
                $extraTags[] = [
                    'tag_id' => sprintf('0x%04X', $tagId),
                    'constant_name' => $constantName,
                    'note' => 'Implemented but not in EXIF 3.0/2.32/TIFF 6.0 spec',
                ];
            }
        }

        if (!empty($extraTags)) {
            $this->complianceReport['extra_tags'] = $extraTags;
        }
    }

    /**
     * Calculate summary statistics.
     */
    private function calculateSummary(): void
    {
        $total = 0;
        $implemented = 0;
        $partial = 0;
        $missing = 0;

        foreach ($this->complianceReport['categories'] as $tags) {
            foreach ($tags as $tag) {
                $total++;
                match ($tag['status']) {
                    'implemented' => $implemented++,
                    'partial' => $partial++,
                    'missing' => $missing++,
                    default => null,
                };
            }
        }

        $this->complianceReport['summary']['total_spec_tags'] = $total;
        $this->complianceReport['summary']['implemented'] = $implemented;
        $this->complianceReport['summary']['partial'] = $partial;
        $this->complianceReport['summary']['missing'] = $missing;
        $this->complianceReport['summary']['extra'] = count($this->complianceReport['extra_tags'] ?? []);
        $this->complianceReport['summary']['coverage_percent'] = $total > 0
            ? round(($implemented / $total) * 100, 2)
            : 0.0;
    }

    /**
     * Convert CamelCase to SNAKE_CASE.
     */
    private function camelToSnake(string $input): string
    {
        $result = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $input);

        return strtoupper($result ?? $input);
    }

    /**
     * Generate and save reports.
     */
    public function generateReports(): void
    {
        // JSON report
        $json = json_encode($this->complianceReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode JSON report');
        }

        if (file_put_contents(self::OUTPUT_JSON, $json) === false) {
            throw new RuntimeException('Failed to write JSON report');
        }

        echo "JSON report generated: " . self::OUTPUT_JSON . "\n";

        // YAML report
        $yaml = yaml_emit($this->complianceReport, YAML_UTF8_ENCODING);
        if ($yaml === false) {
            throw new RuntimeException('Failed to encode YAML report');
        }

        if (file_put_contents(self::OUTPUT_YAML, $yaml) === false) {
            throw new RuntimeException('Failed to write YAML report');
        }

        echo "YAML report generated: " . self::OUTPUT_YAML . "\n";
    }

    /**
     * Print summary to console.
     */
    public function printSummary(): void
    {
        $summary = $this->complianceReport['summary'];

        echo "\n";
        echo "=== EXIF/TIFF Compliance Summary ===\n";
        echo "Total specification tags: {$summary['total_spec_tags']}\n";
        echo "Implemented: {$summary['implemented']}\n";
        echo "Partial: {$summary['partial']}\n";
        echo "Missing: {$summary['missing']}\n";
        echo "Extra (not in spec): {$summary['extra']}\n";
        echo "Coverage: {$summary['coverage_percent']}%\n";
        echo "\n";
    }

    /**
     * Check if compliance meets minimum threshold.
     */
    public function checkThreshold(float $minCoverage = 90.0): bool
    {
        $coverage = $this->complianceReport['summary']['coverage_percent'] ?? 0.0;

        if ($coverage < $minCoverage) {
            echo "WARNING: Coverage ({$coverage}%) is below threshold ({$minCoverage}%)\n";

            return false;
        }

        echo "Coverage ({$coverage}%) meets threshold ({$minCoverage}%)\n";

        return true;
    }
}

// Main execution
try {
    $analyzer = new ComplianceAnalyzer();
    $analyzer->analyze();
    $analyzer->generateReports();
    $analyzer->printSummary();

    // Check threshold (can be used in CI to fail build)
    $threshold = 90.0;
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $threshold = (float) $argv[1];
    }

    $meetsThreshold = $analyzer->checkThreshold($threshold);
    exit($meetsThreshold ? 0 : 1);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
