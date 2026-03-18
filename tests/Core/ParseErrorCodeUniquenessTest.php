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

use function array_filter;
use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function ksort;
use function ltrim;
use function preg_match;
use function sort;
use function sprintf;
use function str_ends_with;
use function strtolower;
use function substr;
use function token_get_all;
use function trim;

/**
 * Enforces AGENTS.md §4.1 global uniqueness of ParseError numeric codes in src/.
 */
final class ParseErrorCodeUniquenessTest extends TestCase
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
     * @return list<array{code:int,line:int}>
     */
    private function parseErrorCodeLiterals(string $filePath): array
    {
        $content = (string) file_get_contents($filePath);
        $tokens  = token_get_all($content);

        $hits    = [];
        $n       = count($tokens);

        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] !== T_NEW) {
                continue;
            }

            $j          = $i + 1;

            while ($j < $n) {
                $t = $tokens[$j];

                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    ++$j;

                    continue;
                }

                break;
            }

            if ($j >= $n) {
                continue;
            }

            $nameParts  = [];
            $k          = $j;

            while ($k < $n) {
                $t = $tokens[$k];

                if (is_array($t)) {
                    if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $nameParts[] = $t[1];
                        ++$k;

                        continue;
                    }

                    if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        ++$k;

                        continue;
                    }
                }

                break;
            }

            if ($nameParts === []) {
                continue;
            }

            $name       = ltrim(implode('', $nameParts), '\\');

            if (strtolower(substr($name, -10)) !== 'parseerror') {
                continue;
            }

            while ($k < $n) {
                $t = $tokens[$k];

                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    ++$k;

                    continue;
                }

                break;
            }

            if ($k >= $n) {
                continue;
            }

            if ($tokens[$k] !== '(') {
                continue;
            }

            $depth      = 0;
            $argIndex   = 1;
            $argTokens  = [];
            $numberLine = null;

            for ($m = $k; $m < $n; ++$m) {
                $tt   = $tokens[$m];
                $text = is_array($tt) ? $tt[1] : $tt;

                if ($text === '(') {
                    ++$depth;

                    continue;
                }

                if ($text === ')') {
                    --$depth;

                    if ($depth === 0) {
                        break;
                    }
                }

                if (($depth === 1) && ($text === ',')) {
                    ++$argIndex;

                    continue;
                }

                if (($argIndex === 2) && ($depth >= 1)) {
                    $argTokens[] = $tt;

                    if (is_array($tt) && ($tt[0] === T_LNUMBER)) {
                        $numberLine = $tt[2];
                    }
                }
            }

            if ($argTokens === []) {
                continue;
            }

            if ($numberLine === null) {
                continue;
            }

            $argText    = '';

            foreach ($argTokens as $tt) {
                $argText .= is_array($tt) ? $tt[1] : $tt;
            }

            $trimmed    = trim($argText);

            if (preg_match('/^\d+$/', $trimmed) !== 1) {
                continue;
            }

            $hits[]     = [
                'code' => (int) $trimmed,
                'line' => $numberLine,
            ];
        }

        return $hits;
    }

    #[Test]
    public function parseErrorCodesAreGloballyUniqueAcrossSrc(): void
    {
        $srcRoot         = dirname(__DIR__, 2) . '/src';

        /** @var array<int, list<string>> $locationsByCode */
        $locationsByCode = [];

        foreach ($this->phpSourceFiles($srcRoot) as $filePath) {
            $relativePath = substr($filePath, strlen(dirname(__DIR__, 2)) + 1);

            foreach ($this->parseErrorCodeLiterals($filePath) as $hit) {
                $code                     = $hit['code'];

                if (!isset($locationsByCode[$code])) {
                    $locationsByCode[$code] = [];
                }

                $locationsByCode[$code][] = sprintf('%s:%d', $relativePath, $hit['line']);
            }
        }

        /** @var array<int, list<string>> $duplicates */
        $duplicates      = array_filter($locationsByCode, static fn (array $locations): bool => count($locations) > 1);

        ksort($duplicates);

        self::assertSame([], $duplicates, 'Duplicate ParseError codes detected in src/.');
    }
}
