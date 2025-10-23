<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Detect\FormatDetector;

use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Detect\ContainerType;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
     * Ensures that an unsupported signature results in a runtime exception.
     */
    #[Test]
    public function detectThrowsForUnsupportedSignature(): void
    {
        $stream = $this->createStream('UNSUPPORTED');

        $this->expectException(RuntimeException::class);

        FormatDetector::detect($stream);
    }

    /**
     * Creates a Stream instance backed by an in-memory temporary resource containing the provided bytes.
     */
    private function createStream(string $bytes): Stream
    {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, $bytes);
        rewind($handle);

        return new Stream($handle, strlen($bytes));
    }
}
