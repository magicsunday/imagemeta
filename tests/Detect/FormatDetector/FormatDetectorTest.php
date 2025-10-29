<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Detect\FormatDetector;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Detect\ContainerType;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function rewind;
use function strlen;

/**
 * Validates format detection based on common signature bytes.
 *
 * @covers \MagicSunday\ImageMeta\Detect\FormatDetector
 */
final class FormatDetectorTest extends TestCase
{
    /**
     * Ensures that a JPEG SOI header is detected as a JPEG container.
     */
    #[Test]
    public function detectRecognisesJpegSignature(): void
    {
        $stream = $this->createStream("\xFF\xD8\xFF\xE0");

        $detected = FormatDetector::detect($stream);

        self::assertSame(ContainerType::JPEG, $detected);
    }

    /**
     * Ensures that an ISO BMFF brand at offset four triggers detection of the ISOBMFF container type.
     */
    #[Test]
    public function detectRecognisesIsoBmffBrand(): void
    {
        $stream = $this->createStream("\x00\x00\x00\x18ftypisom");

        $detected = FormatDetector::detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Ensures that an unsupported signature results in a parse error.
     */
    #[Test]
    public function detectThrowsForUnsupportedSignature(): void
    {
        $stream = $this->createStream('UNSUPPORTED');

        $this->expectException(ParseError::class);

        FormatDetector::detect($stream);
    }

    /**
     * Ensures that too short streams raise a parse error because the signature cannot be read.
     *
     * @param string $bytes byte sequence to test
     */
    #[Test]
    #[DataProvider('tooShortStreamProvider')]
    public function detectThrowsWhenSignatureCannotBeRead(string $bytes): void
    {
        $stream = $this->createStream($bytes);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unable to read container signature');

        FormatDetector::detect($stream);
    }

    /**
     * Provides byte sequences that are insufficient to cover the signature reads.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function tooShortStreamProvider(): iterable
    {
        yield 'empty stream' => [''];
        yield 'single byte' => ["\xFF"];
    }

    /**
     * Creates a Stream instance backed by an in-memory temporary resource containing the provided bytes.
     */
    private function createStream(string $bytes): Stream
    {
        $handle = fopen('php://temp', 'w+b');

        if ($handle === false) {
            self::fail('Unable to create temporary stream resource.');
        }

        $length  = strlen($bytes);
        $written = fwrite($handle, $bytes);

        if ($written === false || $written !== $length) {
            self::fail('Unable to write bytes to temporary stream resource.');
        }

        if (rewind($handle) === false) {
            self::fail('Unable to rewind temporary stream resource.');
        }

        return new Stream($handle, $length);
    }
}
