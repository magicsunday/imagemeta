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
        $this->assertFileExists(self::SPEC_FILE, 'Specification file must exist');

        $content = file_get_contents(self::SPEC_FILE);
        $this->assertNotFalse($content, 'Failed to read specification file');

        $data = yaml_parse($content);
        $this->assertIsArray($data, 'Specification file must contain valid YAML');
        $this->assertArrayHasKey('tiff_tags', $data, 'Spec must include tiff_tags');
        $this->assertArrayHasKey('exif_tags', $data, 'Spec must include exif_tags');
        $this->assertArrayHasKey('gps_tags', $data, 'Spec must include gps_tags');
        $this->assertArrayHasKey('interop_tags', $data, 'Spec must include interop_tags');
    }

    /**
     * Test that analyzer script exists and is executable.
     */
    public function testAnalyzerScriptExists(): void
    {
        $this->assertFileExists(self::SCRIPT_PATH, 'Analyzer script must exist');
        $this->assertTrue(is_executable(self::SCRIPT_PATH), 'Analyzer script must be executable');
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
        $this->assertContains(
            $exitCode,
            [0, 1],
            'Analyzer should exit with code 0 or 1. Output: ' . implode("\n", $output)
        );

        // Check JSON report exists
        $this->assertFileExists(self::REPORT_JSON, 'JSON report must be generated');

        // Check JSON is valid
        $jsonContent = file_get_contents(self::REPORT_JSON);
        $this->assertNotFalse($jsonContent, 'Failed to read JSON report');

        $report = json_decode($jsonContent, true);
        $this->assertIsArray($report, 'JSON report must be valid JSON');

        // Validate report structure
        $this->assertArrayHasKey('generated', $report, 'Report must include generation timestamp');
        $this->assertArrayHasKey('summary', $report, 'Report must include summary');
        $this->assertArrayHasKey('categories', $report, 'Report must include categories');

        // Validate summary structure
        $summary = $report['summary'];
        $this->assertArrayHasKey('total_spec_tags', $summary);
        $this->assertArrayHasKey('implemented', $summary);
        $this->assertArrayHasKey('partial', $summary);
        $this->assertArrayHasKey('missing', $summary);
        $this->assertArrayHasKey('extra', $summary);
        $this->assertArrayHasKey('coverage_percent', $summary);

        // Check numbers are valid
        $this->assertIsInt($summary['total_spec_tags']);
        $this->assertIsInt($summary['implemented']);
        $this->assertIsInt($summary['partial']);
        $this->assertIsInt($summary['missing']);
        $this->assertGreaterThanOrEqual(0, $summary['total_spec_tags']);
        $this->assertGreaterThanOrEqual(0, $summary['implemented']);
        $this->assertGreaterThanOrEqual(0, $summary['coverage_percent']);
        $this->assertLessThanOrEqual(100, $summary['coverage_percent']);

        // Check YAML report exists
        $this->assertFileExists(self::REPORT_YAML, 'YAML report must be generated');

        $yamlContent = file_get_contents(self::REPORT_YAML);
        $this->assertNotFalse($yamlContent, 'Failed to read YAML report');

        $yamlData = yaml_parse($yamlContent);
        $this->assertIsArray($yamlData, 'YAML report must be valid YAML');
    }

    /**
     * Test that all specification tags have required fields.
     */
    public function testSpecificationTagsHaveRequiredFields(): void
    {
        $content = file_get_contents(self::SPEC_FILE);
        $this->assertNotFalse($content);

        $spec = yaml_parse($content);
        $this->assertIsArray($spec);

        foreach (['tiff_tags', 'exif_tags', 'gps_tags', 'interop_tags'] as $category) {
            $this->assertArrayHasKey($category, $spec);
            $tags = $spec[$category];

            foreach ($tags as $tagId => $tagInfo) {
                $this->assertArrayHasKey('name', $tagInfo, sprintf('Tag %s must have a name', $tagId));
                $this->assertArrayHasKey('type', $tagInfo, sprintf('Tag %s must have a type', $tagId));
                $this->assertArrayHasKey('ifd', $tagInfo, sprintf('Tag %s must specify IFD', $tagId));
                $this->assertArrayHasKey('source', $tagInfo, sprintf('Tag %s must specify source', $tagId));

                // Validate tag name is not empty
                $this->assertNotEmpty($tagInfo['name'], sprintf('Tag %s name cannot be empty', $tagId));

                // Validate IFD is one of the known values
                $validIfds = ['IFD0', 'IFD1', 'ExifIFD', 'GPSIFD', 'InteropIFD', 'PreviewIFD'];
                $this->assertContains(
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
        $this->assertNotFalse($jsonContent);

        $report = json_decode($jsonContent, true);
        $this->assertIsArray($report);

        $summary     = $report['summary'];
        $total       = $summary['total_spec_tags'];
        $implemented = $summary['implemented'];
        $coverage    = $summary['coverage_percent'];

        if ($total > 0) {
            $expectedCoverage = round(($implemented / $total) * 100, 2);
            $this->assertEquals(
                $expectedCoverage,
                $coverage,
                'Coverage percentage should match calculation'
            );
        } else {
            $this->assertEquals(0.0, $coverage, 'Coverage should be 0 when no tags exist');
        }
    }

    /**
     * Test that categories sum up to total.
     */
    public function testCategoriesSumToTotal(): void
    {
        $jsonContent = file_get_contents(self::REPORT_JSON);
        $this->assertNotFalse($jsonContent);

        $report = json_decode($jsonContent, true);
        $this->assertIsArray($report);

        $totalFromSummary = $report['summary']['total_spec_tags'];
        $categoriesTotal  = 0;

        foreach ($report['categories'] as $tags) {
            $categoriesTotal += count($tags);
        }

        $this->assertEquals(
            $totalFromSummary,
            $categoriesTotal,
            'Sum of all category tags should equal total_spec_tags'
        );
    }
}
