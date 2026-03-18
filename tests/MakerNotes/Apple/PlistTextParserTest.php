<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistTextCursor;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistTextParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Exercises PlistTextParser recursion depth protection.
 *
 * It verifies that a deeply nested plist exceeding the maximum depth limit throws
 * a ParseError, while a plist within the limit parses successfully.
 *
 * @internal
 */
#[CoversClass(PlistTextParser::class)]
#[UsesClass(PlistTextCursor::class)]
final class PlistTextParserTest extends TestCase
{
    /**
     * Builds a plist nested exactly at the maximum depth (64 levels).
     * Ensures the parser returns a result without throwing.
     */
    #[Test]
    public function parseSucceedsAtMaximumAllowedDepth(): void
    {
        // 64 levels of nested dicts: {k = {k = {...{k = v}...}}}
        $plist = str_repeat('{k = ', 64) . '"leaf"' . str_repeat('; }', 64);

        $parser = new PlistTextParser();
        $result = $parser->parse($plist);

        self::assertNotNull($result);
    }

    /**
     * Builds a plist nested one level deeper than the maximum (65 levels).
     * Ensures the parser throws a ParseError when the recursion limit is exceeded.
     */
    #[Test]
    public function parseThrowsWhenRecursionDepthIsExceeded(): void
    {
        // 65 levels of nested dicts: one more than the allowed 64
        $plist = str_repeat('{k = ', 65) . '"leaf"' . str_repeat('; }', 65);

        $parser = new PlistTextParser();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1122);
        $this->expectExceptionMessage('Recursion depth');

        $parser->parse($plist);
    }
}
