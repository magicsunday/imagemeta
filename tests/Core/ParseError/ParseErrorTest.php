<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core\ParseError;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function restore_error_handler;
use function rewind;
use function set_error_handler;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Tests covering the ParseError exception raised by stream guard failures.
 */
final class ParseErrorTest extends TestCase
{
    /**
     * Declares a stream size larger than the written payload to force a short read ParseError.
     */
    #[Test]
    public function testStreamReadThrowsParseErrorOnShortRead(): void
    {
        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, 'A');
        rewind($fh);

        $stream = new Stream($fh, 2);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('short read');

        $stream->read(2);
    }

    /**
     * Attempts to open a non-existent file path using Stream::fromPath to verify the error message.
     */
    #[Test]
    public function testStreamFromPathThrowsParseErrorWhenFileMissing(): void
    {
        $path = sys_get_temp_dir() . '/imagemeta-missing-' . uniqid('', true);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage("Cannot open: {$path}");

        $previousHandler = set_error_handler(static function (int $errno, string $errstr) use ($path, &$previousHandler): bool {
            if (str_contains($errstr, $path)) {
                return true;
            }

            if ($previousHandler !== null) {
                return (bool) $previousHandler($errno, $errstr);
            }

            return false;
        });

        try {
            Stream::fromPath($path);
        } finally {
            restore_error_handler();
        }
    }
}
