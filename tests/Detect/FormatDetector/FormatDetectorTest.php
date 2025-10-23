<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Detect\FormatDetector;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Detect\ContainerType;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fopen;
use function fwrite;
use function rewind;
use function strlen;

/**
 * Validates that the format detector resolves container types using magic bytes.
 *
 * @covers \MagicSunday\ImageMeta\Detect\FormatDetector
 */
final class FormatDetectorTest extends TestCase
{
    /**
     * Ensures known signatures resolve to the expected container types.
     *
     * @param string        $payload  Synthetic payload written to an in-memory stream.
     * @param ContainerType $expected Container type that should be detected for the payload.
     */
    #[Test]
    #[DataProvider('provideSuccessfulSignatures')]
    public function detectReturnsExpectedContainerType(string $payload, ContainerType $expected): void
    {
        $stream = $this->createStream($payload);

        $detected = FormatDetector::detect($stream);

        self::assertSame($expected, $detected);
    }

    /**
     * Ensures unknown signatures raise the configured runtime exception and truncated payloads fail with bounds errors.
     *
     * @param string $payload         Payload that should trigger an exception.
     * @param class-string<\Throwable> $expectedException Expected exception class.
     */
    #[Test]
    #[DataProvider('provideUnsupportedSignatures')]
    public function detectThrowsForUnsupportedSignature(string $payload, string $expectedException): void
    {
        $stream = $this->createStream($payload);

        $this->expectException($expectedException);

        FormatDetector::detect($stream);
    }

    /**
     * Builds a stream instance backed by php://memory for the provided payload.
     */
    private function createStream(string $payload): Stream
    {
        $handle = fopen('php://memory', 'r+b');
        fwrite($handle, $payload);
        rewind($handle);

        return new Stream($handle, strlen($payload));
    }

    /**
     * Provides known container signatures that should result in successful detection.
     *
     * @return iterable<string, array{0: string, 1: ContainerType}>
     */
    public static function provideSuccessfulSignatures(): iterable
    {
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0", ContainerType::JPEG];
        yield 'iso-base-media brand' => ["\x00\x00\x00\x18ftypisom", ContainerType::ISOBMFF];
    }

    /**
     * Provides payloads that should not match any supported signature.
     *
     * @return iterable<string, array{0: string, 1: class-string<\Throwable>}>
     */
    public static function provideUnsupportedSignatures(): iterable
    {
        yield 'unknown bytes' => ["\x00\x11\x22\x33bad!", RuntimeException::class];
        yield 'truncated stream' => ["\xFF", BoundsError::class];
    }
}
