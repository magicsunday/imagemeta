<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
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
use function strlen;

/**
 * Exercises BoundsError scenarios triggered by stream and buffer guard rails.
 * It forces read and seek operations beyond valid limits to ensure exceptions are raised.
 * The assertions require precise, contextual error messages for debugging.
 * This keeps bounds violations explicit and actionable instead of silent failures.
 */
#[CoversClass(BoundsError::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Stream::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesTrait(NormalizesOffsets::class)]
final class BoundsErrorTest extends TestCase
{
    use CreatesTempStream;

    #[Test]
    #[DataProvider('boundsErrorCases')]
    public function reportsContextInBoundsError(callable $operation, string $expectedMessage): void
    {
        $this->expectException(BoundsError::class);
        $this->expectExceptionMessage($expectedMessage);

        $operation();
    }

    /**
     * @return array<string, array{0: callable(): void, 1: string}>
     */
    public static function boundsErrorCases(): array
    {
        return [
            'stream read beyond EOF' => [
                function (): void {
                    $payload = 'meta';
                    $stream  = new Stream(self::createTempStreamStatic($payload), strlen($payload));

                    $stream->read(4);
                    $stream->read(1);
                },
                'read beyond EOF: 4+1 > 4',
            ],
            'memory buffer seek out of range' => [
                static function (): void {
                    $buffer = new MemoryBuffer('guard');

                    $buffer->seek(6);
                },
                'MemoryBuffer seek out of range: 6',
            ],
        ];
    }

    /**
     * @return resource
     */
    private static function createTempStreamStatic(string $payload)
    {
        $handle = fopen('php://temp', 'r+b');

        if ($handle === false) {
            Assert::fail('Unable to create temporary stream.');
        }

        $written = fwrite($handle, $payload);

        if ($written === false || $written !== strlen($payload)) {
            Assert::fail('Unable to populate temporary stream.');
        }

        if (rewind($handle) === false) {
            Assert::fail('Unable to rewind temporary stream.');
        }

        return $handle;
    }
}
