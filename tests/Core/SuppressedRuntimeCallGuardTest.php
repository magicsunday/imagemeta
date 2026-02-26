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

use function array_key_exists;
use function count;
use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_string;
use function sort;
use function str_ends_with;
use function strlen;
use function substr;
use function token_get_all;

/**
 * Enforces AGENTS.md §4 by forbidding runtime @-suppressed call expressions in src/.
 *
 * @phpstan-type PhpToken array{0:int,1:string,2:int}|string
 */
final class SuppressedRuntimeCallGuardTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function phpSourceFiles(string $srcRoot): array
    {
        /** @var list<string> $files */
        $files = [];
        $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));

        foreach ($it as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            if (!str_ends_with($path, '.php')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<int>
     */
    private function suppressedRuntimeCallLines(string $contents): array
    {
        $tokens = token_get_all($contents);

        /** @var list<int> $lines */
        $lines = [];

        foreach ($tokens as $index => $token) {
            if ($token !== '@') {
                continue;
            }

            $cursor = $index + 1;
            while (array_key_exists($cursor, $tokens) && $this->isIgnorableToken($tokens[$cursor])) {
                ++$cursor;
            }

            if (!array_key_exists($cursor, $tokens)) {
                continue;
            }

            $first = $tokens[$cursor];
            if (!$this->isSuppressedCallStartToken($first)) {
                continue;
            }

            $line = $this->tokenLine($tokens, $cursor);
            if ($line === null) {
                continue;
            }

            while (array_key_exists($cursor, $tokens)) {
                $candidate = $tokens[$cursor];

                if ($candidate === '(') {
                    $lines[] = $line;
                    break;
                }

                if ($this->isIgnorableToken($candidate) || $this->isSuppressedCallBodyToken($candidate)) {
                    ++$cursor;
                    continue;
                }

                break;
            }
        }

        return $lines;
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private function tokenLine(array $tokens, int $index): ?int
    {
        $counter = count($tokens);
        for ($cursor = $index; $cursor < $counter; ++$cursor) {
            $token = $tokens[$cursor];
            if (is_array($token)) {
                return $token[2];
            }
        }

        return null;
    }

    /**
     * @param PhpToken $token
     */
    private function isIgnorableToken(array|string $token): bool
    {
        return is_array($token) && $token[0] === T_WHITESPACE;
    }

    /**
     * @param PhpToken $token
     */
    private function isSuppressedCallStartToken(array|string $token): bool
    {
        if ($token === '\\') {
            return true;
        }

        if (!is_array($token)) {
            return false;
        }

        return $token[0] === T_STRING
            || $token[0] === T_VARIABLE
            || (defined('T_NAME_QUALIFIED') && $token[0] === T_NAME_QUALIFIED)
            || (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED)
            || (defined('T_NAME_RELATIVE') && $token[0] === T_NAME_RELATIVE);
    }

    /**
     * @param PhpToken $token
     */
    private function isSuppressedCallBodyToken(array|string $token): bool
    {
        if ($token === '\\') {
            return true;
        }

        if (!is_array($token)) {
            return false;
        }

        return in_array($token[0], [T_STRING, T_VARIABLE, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            || (defined('T_NAME_QUALIFIED') && $token[0] === T_NAME_QUALIFIED)
            || (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED)
            || (defined('T_NAME_RELATIVE') && $token[0] === T_NAME_RELATIVE);
    }

    #[Test]
    public function srcDoesNotUseSuppressedRuntimeCalls(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $srcRoot  = $repoRoot . '/src';

        /** @var list<string> $violations */
        $violations = [];
        foreach ($this->phpSourceFiles($srcRoot) as $filePath) {
            $contents = file_get_contents($filePath);
            if (!is_string($contents)) {
                continue;
            }

            $relative = substr($filePath, strlen($repoRoot) + 1);

            foreach ($this->suppressedRuntimeCallLines($contents) as $line) {
                $violations[] = $relative . ':' . $line;
            }
        }

        sort($violations);

        self::assertSame([], $violations, 'Suppressed runtime calls detected in src/.');
    }

    #[Test]
    public function detectionIgnoresDocAnnotationsAndFindsRuntimeSuppressions(): void
    {
        $fixture = <<<'PHP'
<?php
/**
 * @param string $value
 * @return string
 */
function keepDocAnnotations(string $value): string
{
    return $value;
}

// @throws RuntimeException
@fopen('fixture.txt', 'rb');
@\Vendor\Package\Util::decode('data');
$text = '@iconv(value)';
PHP;

        $lines = $this->suppressedRuntimeCallLines($fixture);

        self::assertCount(2, $lines);
        self::assertSame([12, 13], $lines);
    }
}
