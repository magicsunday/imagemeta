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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Exercises ParseError scenarios raised by stream guard failures and read errors.
 * It triggers short reads and missing-file paths to force parsing failures.
 * The assertions verify that error messages retain meaningful context.
 * This ensures parse failures remain explicit and debuggable in client code.
 */
#[CoversClass(ParseError::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Stream::class)]
#[UsesTrait(NormalisesOffsets::class)]
final class ParseErrorTest extends TestCase
{
    use CreatesTempStream;

    /**
     * Declares a stream length larger than the payload to force a short read.
     * It asserts that the short-read ParseError is raised with the expected message.
     *
     * @return void
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
     * Attempts to open a missing file path to trigger an open failure.
     * It asserts the ParseError message includes the missing path.
     *
     * @return void
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
