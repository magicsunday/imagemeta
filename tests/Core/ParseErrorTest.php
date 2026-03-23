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
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function rewind;
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
#[UsesTrait(NormalizesOffsets::class)]
final class ParseErrorTest extends TestCase
{
    use CreatesTempStream;

    #[Test]
    #[DataProvider('parseErrorCases')]
    public function reportsParseErrorsWithExpectedContext(callable $operation, string $expectedMessage, ?int $expectedCode): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage($expectedMessage);

        if ($expectedCode !== null) {
            $this->expectExceptionCode($expectedCode);
        }

        $operation();
    }

    /**
     * @return array<string, array{0: callable(): void, 1: string, 2: int|null}>
     */
    public static function parseErrorCases(): array
    {
        return [
            'stream short read' => [
                static function (): void {
                    $handle = fopen('php://temp', 'r+b');

                    if ($handle === false) {
                        Assert::fail('Unable to create temporary stream.');
                    }

                    $written = fwrite($handle, 'A');

                    if (($written === false) || ($written !== 1)) {
                        Assert::fail('Unable to populate temporary stream.');
                    }

                    if (rewind($handle) === false) {
                        Assert::fail('Unable to rewind temporary stream.');
                    }

                    (new Stream($handle, 2))->read(2);
                },
                'short read',
                null,
            ],
            'missing file path' => [
                static function (): void {
                    $path = sys_get_temp_dir() . '/imagemeta-missing-' . uniqid('', true);

                    Stream::fromPath($path);
                },
                'Cannot open the provided file path.',
                1010,
            ],
        ];
    }
}
