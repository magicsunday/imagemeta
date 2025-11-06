#!/usr/bin/env php
<?php

/**
 * Generate markdown compliance table for README
 *
 * Reads compliance-report.json and generates a summary table
 * for inclusion in README.md or other documentation.
 */

declare(strict_types=1);

$reportFile = __DIR__ . '/../docs/compliance-report.json';

if (!file_exists($reportFile)) {
    echo "ERROR: Compliance report not found. Run analyze-exif-compliance.php first.\n";
    exit(1);
}

$report = json_decode(file_get_contents($reportFile), true);
if ($report === null) {
    echo "ERROR: Failed to parse compliance report JSON.\n";
    exit(1);
}

$summary = $report['summary'];

// Generate summary table
echo "## EXIF/TIFF Compliance Summary\n\n";
echo "| Metric | Count | Percentage |\n";
echo "|--------|------:|:----------:|\n";
echo sprintf(
    "| Total Specification Tags | %d | 100%% |\n",
    $summary['total_spec_tags']
);
echo sprintf(
    "| ✅ Implemented | %d | %.1f%% |\n",
    $summary['implemented'],
    ($summary['implemented'] / max($summary['total_spec_tags'], 1)) * 100
);
echo sprintf(
    "| ⚠️ Partial | %d | %.1f%% |\n",
    $summary['partial'],
    ($summary['partial'] / max($summary['total_spec_tags'], 1)) * 100
);
echo sprintf(
    "| ❌ Missing | %d | %.1f%% |\n",
    $summary['missing'],
    ($summary['missing'] / max($summary['total_spec_tags'], 1)) * 100
);
echo sprintf(
    "| ➕ Extra (not in spec) | %d | - |\n",
    $summary['extra']
);
echo sprintf(
    "| **Overall Coverage** | **%d/%d** | **%.1f%%** |\n",
    $summary['implemented'],
    $summary['total_spec_tags'],
    $summary['coverage_percent']
);
echo "\n";
echo "*Last updated: " . date('Y-m-d H:i:s T', strtotime($report['generated'])) . "*\n\n";

// Generate category breakdown
echo "### Coverage by Category\n\n";
echo "| Category | Implemented | Partial | Missing | Total | Coverage |\n";
echo "|----------|------------:|--------:|--------:|------:|---------:|\n";

$categoryNames = [
    'tiff_tags' => 'TIFF 6.0 Baseline',
    'exif_tags' => 'EXIF Tags',
    'gps_tags' => 'GPS Tags',
    'interop_tags' => 'Interoperability',
];

foreach ($categoryNames as $key => $name) {
    if (!isset($report['categories'][$key])) {
        continue;
    }

    $tags = $report['categories'][$key];
    $total = count($tags);
    $implemented = 0;
    $partial = 0;
    $missing = 0;

    foreach ($tags as $tag) {
        match ($tag['status']) {
            'implemented' => $implemented++,
            'partial' => $partial++,
            'missing' => $missing++,
            default => null,
        };
    }

    $coverage = $total > 0 ? ($implemented / $total) * 100 : 0;

    echo sprintf(
        "| %s | %d | %d | %d | %d | %.1f%% |\n",
        $name,
        $implemented,
        $partial,
        $missing,
        $total,
        $coverage
    );
}

echo "\n";
echo "For detailed compliance information, see [COMPLIANCE.md](docs/COMPLIANCE.md) or review the [compliance report](docs/compliance-report.json).\n";
