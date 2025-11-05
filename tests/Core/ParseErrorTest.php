<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\Traits\NormalisesOffsets;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Tests covering the ParseError exception raised by stream guard failures.
 */
#[CoversClass(ParseError::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Stream::class)]
#[UsesClass(NormalisesOffsets::class)]
final class ParseErrorTest extends TestCase
{
    use CreatesTempStream;

    /**
     * Declares a stream size larger than the written payload to force a short read ParseError.
     */
    #[Test]
    public function streamReadThrowsParseErrorOnShortRead(): void
    {
        $stream = new Stream($this->createTempStream('A'), 2);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('short read');

        $stream->read(2);
    }

    /**
     * Attempts to open a non-existent file path using Stream::fromPath to verify the error message.
     */
    #[Test]
    public function streamFromPathThrowsParseErrorWhenFileMissing(): void
    {
        $path = sys_get_temp_dir() . '/imagemeta-missing-' . uniqid('', true);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Cannot open: ' . $path);

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
