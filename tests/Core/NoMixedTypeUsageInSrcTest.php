<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function is_array;
use function is_string;
use function preg_match_all;
use function sort;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;
use function substr_count;
use function token_get_all;

/**
 * Enforces AGENTS.md §2 prohibition of mixed in src/ type declarations and PHPDoc tags.
 */
final class NoMixedTypeUsageInSrcTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function phpSourceFiles(string $srcRoot): array
    {
        /** @var list<string> $files */
        $files    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if (!$entry->isFile()) {
                continue;
            }

            if (!str_ends_with($entry->getFilename(), '.php')) {
                continue;
            }

            $files[] = $entry->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function mixedTypeViolations(string $filePath, string $repoRoot): array
    {
        $contents = file_get_contents($filePath);
        if (!is_string($contents)) {
            return [];
        }

        $tokens = token_get_all($contents);

        /** @var list<string> $violations */
        $violations = [];
        $relative   = substr($filePath, strlen($repoRoot) + 1);

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if (($id === T_STRING) && (strtolower($text) === 'mixed')) {
                $violations[] = $relative . ':' . $line . ' runtime type declaration';
                continue;
            }

            if ($id !== T_DOC_COMMENT) {
                continue;
            }

            $matches = [];
            preg_match_all(
                '/@(?:param|return|var|phpstan-(?:param|return|var|type|import-type|assert|assert-if-true|assert-if-false))\s+[^\n]*\bmixed\b/i',
                $text,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            foreach ($matches[0] as $match) {
                $offset       = $match[1];
                $lineOffset   = substr_count(substr($text, 0, $offset), "\n");
                $violations[] = $relative . ':' . ($line + $lineOffset) . ' PHPDoc type';
            }
        }

        return $violations;
    }

    #[Test]
    public function srcDoesNotUseMixedTypeDeclarationsOrPhpdocTypes(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $srcRoot  = $repoRoot . '/src';

        $violations = [];
        foreach ($this->phpSourceFiles($srcRoot) as $filePath) {
            foreach ($this->mixedTypeViolations($filePath, $repoRoot) as $violation) {
                $violations[] = $violation;
            }
        }

        sort($violations);

        self::assertSame([], $violations, 'Forbidden mixed usage detected in src/ types.');
    }
}
