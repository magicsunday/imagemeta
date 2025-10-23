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
 * Validates that the format detector resolves container types using magic bytes.
 *
 * @covers \MagicSunday\ImageMeta\Detect\FormatDetector
 */
final class FormatDetectorTest extends TestCase
{
    /**
     * Ensures the JPEG magic number at the beginning of the stream is detected.
     */
    #[Test]
    public function detectsJpegMagicNumber(): void
    {
        $payload = "\xFF\xD8\xFF\xE0";

        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, $payload);
        rewind($fh);

        $stream = new Stream($fh, strlen($payload));

        $detected = FormatDetector::detect($stream);

        self::assertSame(ContainerType::JPEG, $detected);
    }

    /**
     * Ensures an ISO base media file brand at offset four is detected as ISOBMFF.
     */
    #[Test]
    public function detectsIsoBmffBrand(): void
    {
        $payload = "\x00\x00\x00\x00ftypisom";

        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, $payload);
        rewind($fh);

        $stream = new Stream($fh, strlen($payload));

        $detected = FormatDetector::detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Ensures unsupported signatures raise a runtime exception.
     */
    #[Test]
    public function throwsWhenSignatureIsUnknown(): void
    {
        $payload = "\x00\x11\x22\x33bad!";

        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, $payload);
        rewind($fh);

        $stream = new Stream($fh, strlen($payload));

        $this->expectException(RuntimeException::class);

        FormatDetector::detect($stream);
    }
}
