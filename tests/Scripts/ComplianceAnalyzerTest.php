<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Scripts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function count;
use function sprintf;

/**
 * Tests for EXIF/TIFF compliance analyzer script.
 */
#[CoversNothing]
final class ComplianceAnalyzerTest extends TestCase
{
    private const string SCRIPT_PATH = __DIR__ . '/../../scripts/analyze-exif-compliance.php';

    private const string SPEC_FILE = __DIR__ . '/../../resources/exif-spec-tags.yaml';

    private const string REPORT_JSON = __DIR__ . '/../../docs/compliance-report.json';

    private const string REPORT_YAML = __DIR__ . '/../../docs/compliance-report.yaml';

    /**
     * Test that specification file exists and is valid YAML.
     */
    public function testSpecificationFileExists(): void
    {
        self::assertFileExists(self::SPEC_FILE, 'Specification file must exist');

        $content = file_get_contents(self::SPEC_FILE);
        self::assertNotFalse($content, 'Failed to read specification file');

        $data = yaml_parse($content);
        self::assertIsArray($data, 'Specification file must contain valid YAML');
        self::assertArrayHasKey('tiff_tags', $data, 'Spec must include tiff_tags');
        self::assertArrayHasKey('exif_tags', $data, 'Spec must include exif_tags');
        self::assertArrayHasKey('gps_tags', $data, 'Spec must include gps_tags');
        self::assertArrayHasKey('interop_tags', $data, 'Spec must include interop_tags');
    }

    /**
     * Test that analyzer script exists and is executable.
     */
    public function testAnalyzerScriptExists(): void
    {
        self::assertFileExists(self::SCRIPT_PATH, 'Analyzer script must exist');
        self::assertTrue(is_executable(self::SCRIPT_PATH), 'Analyzer script must be executable');
    }

    /**
     * Test that analyzer generates valid reports.
     */
    public function testAnalyzerGeneratesValidReports(): void
    {
        // Run the analyzer
        $output   = [];
        $exitCode = 0;
        exec('php ' . escapeshellarg(self::SCRIPT_PATH) . ' 0 2>&1', $output, $exitCode);

        // Check that script completed (exit code 0 or 1 is acceptable, as it depends on threshold)
        self::assertContains(
            $exitCode,
            [0, 1],
            'Analyzer should exit with code 0 or 1. Output: ' . implode("\n", $output)
        );

        // Check JSON report exists
        self::assertFileExists(self::REPORT_JSON, 'JSON report must be generated');

        // Check JSON is valid
        $jsonContent = file_get_contents(self::REPORT_JSON);
        self::assertNotFalse($jsonContent, 'Failed to read JSON report');

        $report = json_decode($jsonContent, true);
        self::assertIsArray($report, 'JSON report must be valid JSON');

        // Validate report structure
        self::assertArrayHasKey('generated', $report, 'Report must include generation timestamp');
        self::assertArrayHasKey('summary', $report, 'Report must include summary');
        self::assertArrayHasKey('categories', $report, 'Report must include categories');

        // Validate summary structure
        $summary = $report['summary'];
        self::assertArrayHasKey('total_spec_tags', $summary);
        self::assertArrayHasKey('implemented', $summary);
        self::assertArrayHasKey('partial', $summary);
        self::assertArrayHasKey('missing', $summary);
        self::assertArrayHasKey('extra', $summary);
        self::assertArrayHasKey('coverage_percent', $summary);

        // Check numbers are valid
        self::assertIsInt($summary['total_spec_tags']);
        self::assertIsInt($summary['implemented']);
        self::assertIsInt($summary['partial']);
        self::assertIsInt($summary['missing']);
        self::assertGreaterThanOrEqual(0, $summary['total_spec_tags']);
        self::assertGreaterThanOrEqual(0, $summary['implemented']);
        self::assertGreaterThanOrEqual(0, $summary['coverage_percent']);
        self::assertLessThanOrEqual(100, $summary['coverage_percent']);

        // Check YAML report exists
        self::assertFileExists(self::REPORT_YAML, 'YAML report must be generated');

        $yamlContent = file_get_contents(self::REPORT_YAML);
        self::assertNotFalse($yamlContent, 'Failed to read YAML report');

        $yamlData = yaml_parse($yamlContent);
        self::assertIsArray($yamlData, 'YAML report must be valid YAML');
    }

    /**
     * Test that all specification tags have required fields.
     */
    public function testSpecificationTagsHaveRequiredFields(): void
    {
        $content = file_get_contents(self::SPEC_FILE);
        self::assertNotFalse($content);

        $spec = yaml_parse($content);
        self::assertIsArray($spec);

        foreach (['tiff_tags', 'exif_tags', 'gps_tags', 'interop_tags'] as $category) {
            self::assertArrayHasKey($category, $spec);
            $tags = $spec[$category];

            foreach ($tags as $tagId => $tagInfo) {
                self::assertArrayHasKey('name', $tagInfo, sprintf('Tag %s must have a name', $tagId));
                self::assertArrayHasKey('type', $tagInfo, sprintf('Tag %s must have a type', $tagId));
                self::assertArrayHasKey('ifd', $tagInfo, sprintf('Tag %s must specify IFD', $tagId));
                self::assertArrayHasKey('source', $tagInfo, sprintf('Tag %s must specify source', $tagId));

                // Validate tag name is not empty
                self::assertNotEmpty($tagInfo['name'], sprintf('Tag %s name cannot be empty', $tagId));

                // Validate IFD is one of the known values
                $validIfds = ['IFD0', 'IFD1', 'ExifIFD', 'GPSIFD', 'InteropIFD'];
                self::assertContains(
                    $tagInfo['ifd'],
                    $validIfds,
                    sprintf('Tag %s has invalid IFD: %s', $tagId, $tagInfo['ifd'])
                );
            }
        }
    }

    /**
     * Test that coverage calculation is accurate.
     */
    public function testCoverageCalculationIsAccurate(): void
    {
        $jsonContent = file_get_contents(self::REPORT_JSON);
        self::assertNotFalse($jsonContent);

        $report = json_decode($jsonContent, true);
        self::assertIsArray($report);

        $summary     = $report['summary'];
        $total       = $summary['total_spec_tags'];
        $implemented = $summary['implemented'];
        $coverage    = $summary['coverage_percent'];

        if ($total > 0) {
            $expectedCoverage = round(($implemented / $total) * 100, 2);
            self::assertEquals(
                $expectedCoverage,
                $coverage,
                'Coverage percentage should match calculation'
            );
        } else {
            self::assertEquals(0.0, $coverage, 'Coverage should be 0 when no tags exist');
        }
    }

    /**
     * Test that categories sum up to total.
     */
    public function testCategoriesSumToTotal(): void
    {
        $jsonContent = file_get_contents(self::REPORT_JSON);
        self::assertNotFalse($jsonContent);

        $report = json_decode($jsonContent, true);
        self::assertIsArray($report);

        $totalFromSummary = $report['summary']['total_spec_tags'];
        $categoriesTotal  = 0;

        foreach ($report['categories'] as $tags) {
            $categoriesTotal += count($tags);
        }

        self::assertEquals(
            $totalFromSummary,
            $categoriesTotal,
            'Sum of all category tags should equal total_spec_tags'
        );
    }
}
