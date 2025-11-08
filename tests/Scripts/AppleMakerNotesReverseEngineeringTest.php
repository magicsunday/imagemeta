<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Scripts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Apple Maker Notes reverse engineering workflow.
 *
 * These tests verify the reverse engineering utilities work correctly
 * for analyzing Apple maker note payloads.
 */
final class AppleMakerNotesReverseEngineeringTest extends TestCase
{
    private const string SCRIPT_PATH = __DIR__ . '/../../scripts/reverse-engineer-apple-makernotes.php';

    /**
     * Test that the reverse engineering script exists and is executable.
     */
    public function testScriptExists(): void
    {
        $this->assertFileExists(self::SCRIPT_PATH, 'Reverse engineering script should exist');
        $this->assertFileIsReadable(self::SCRIPT_PATH, 'Script should be readable');
    }

    /**
     * Test that script shows usage when run without arguments.
     */
    public function testScriptShowsUsageWithoutArguments(): void
    {
        $output = [];
        $exitCode = 0;

        exec('php ' . escapeshellarg(self::SCRIPT_PATH) . ' 2>&1', $output, $exitCode);

        $outputText = implode("\n", $output);

        $this->assertStringContainsString('Usage:', $outputText, 'Should show usage information');
        $this->assertStringContainsString('Options:', $outputText, 'Should list options');
        $this->assertStringContainsString('Examples:', $outputText, 'Should show examples');
    }

    /**
     * Test that documentation exists.
     */
    public function testDocumentationExists(): void
    {
        $docPath = __DIR__ . '/../../docs/APPLE-MAKERNOTES-REVERSE-ENGINEERING.md';

        $this->assertFileExists($docPath, 'Documentation should exist');
        $this->assertFileIsReadable($docPath, 'Documentation should be readable');

        $content = file_get_contents($docPath);
        $this->assertNotEmpty($content, 'Documentation should not be empty');

        // Check for key sections
        $this->assertStringContainsString('# Apple Maker Notes Reverse Engineering', $content);
        $this->assertStringContainsString('## Overview', $content);
        $this->assertStringContainsString('## Tools', $content);
        $this->assertStringContainsString('## Reverse Engineering Workflow', $content);
        $this->assertStringContainsString('## Known Fields Reference', $content);
        $this->assertStringContainsString('## Best Practices', $content);
    }

    /**
     * Test documentation covers required topics.
     */
    public function testDocumentationCoversRequiredTopics(): void
    {
        $docPath = __DIR__ . '/../../docs/APPLE-MAKERNOTES-REVERSE-ENGINEERING.md';
        $content = file_get_contents($docPath);

        // Tool documentation
        $this->assertStringContainsString('reverse-engineer-apple-makernotes.php', $content);

        // Workflow steps
        $this->assertStringContainsString('Collect Sample Images', $content);
        $this->assertStringContainsString('Extract Maker Notes', $content);
        $this->assertStringContainsString('Analyze Field Patterns', $content);
        $this->assertStringContainsString('Test Hypotheses', $content);

        // Technical details
        $this->assertStringContainsString('Binary Plist', $content);
        $this->assertStringContainsString('NSKeyedArchive', $content);

        // Known fields
        $this->assertStringContainsString('ContentIdentifier', $content);
        $this->assertStringContainsString('SemanticStyle', $content);
        $this->assertStringContainsString('HDR', $content);

        // Best practices
        $this->assertStringContainsString('Best Practices', $content);
    }

    /**
     * Test that script file contains expected classes/functions.
     */
    public function testScriptContainsExpectedStructure(): void
    {
        $content = file_get_contents(self::SCRIPT_PATH);

        // Check for main class
        $this->assertStringContainsString('class AppleMakerNotesAnalyzer', $content);

        // Check for key methods
        $this->assertStringContainsString('function analyzeFile', $content);
        $this->assertStringContainsString('function analyzeDirectory', $content);
        $this->assertStringContainsString('function outputJson', $content);
        $this->assertStringContainsString('function outputYaml', $content);
        $this->assertStringContainsString('function outputText', $content);
        $this->assertStringContainsString('function compareResults', $content);

        // Check for known fields list
        $this->assertStringContainsString('KNOWN_FIELDS', $content);
        $this->assertStringContainsString('ContentIdentifier', $content);
        $this->assertStringContainsString('SemanticStylePreset', $content);
    }

    /**
     * Test script has proper error handling structure.
     */
    public function testScriptHasErrorHandling(): void
    {
        $content = file_get_contents(self::SCRIPT_PATH);

        // Check for try-catch blocks
        $this->assertStringContainsString('try {', $content);
        $this->assertStringContainsString('} catch', $content);

        // Check for file existence checks
        $this->assertStringContainsString('file_exists', $content);
        $this->assertStringContainsString('is_file', $content);
        $this->assertStringContainsString('is_dir', $content);

        // Check for error messages
        $this->assertStringContainsString('RuntimeException', $content);
    }

    /**
     * Test script supports required output formats.
     */
    public function testScriptSupportsOutputFormats(): void
    {
        $content = file_get_contents(self::SCRIPT_PATH);

        $this->assertStringContainsString('--format=json', $content);
        $this->assertStringContainsString('--format=yaml', $content);
        $this->assertStringContainsString('--format=text', $content);

        $this->assertStringContainsString('outputJson', $content);
        $this->assertStringContainsString('outputYaml', $content);
        $this->assertStringContainsString('outputText', $content);
    }

    /**
     * Test script supports required options.
     */
    public function testScriptSupportsRequiredOptions(): void
    {
        $content = file_get_contents(self::SCRIPT_PATH);

        $this->assertStringContainsString('--output=', $content);
        $this->assertStringContainsString('--raw', $content);
        $this->assertStringContainsString('--compare', $content);
        $this->assertStringContainsString('--known-only', $content);
        $this->assertStringContainsString('--unknown-only', $content);
        $this->assertStringContainsString('--verbose', $content);
    }
}
