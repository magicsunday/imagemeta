<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Detect;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\Traits\NormalisesOffsets;
use MagicSunday\ImageMeta\Detect\ContainerType;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function hex2bin;
use function rewind;
use function str_repeat;
use function strlen;

/**
 * Verifies container detection based on signature bytes and header guards.
 * It covers JPEG SOI/APP0 detection and ISO BMFF brand parsing from ftyp boxes.
 * The tests include invalid or undersized payloads to assert safe failure behavior.
 * This keeps format detection predictable before deeper parsing begins.
 */
#[CoversClass(FormatDetector::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Stream::class)]
#[UsesTrait(NormalisesOffsets::class)]
final class FormatDetectorTest extends TestCase
{
    /**
     * Uses the JPEG SOI marker and APP0 prefix to identify JPEG containers.
     * This ensures the detector recognizes the canonical JPEG signature bytes.
     *
     * @return void
     */
    #[Test]
    public function detectRecognisesJpegSignature(): void
    {
        $stream = $this->createStream("\xFF\xD8\xFF\xE0");

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::JPEG, $detected);
    }

    /**
     * Reads the ftyp box header and identifies ISO BMFF containers.
     * This confirms brand-based detection for ISO BMFF signatures.
     *
     * @return void
     */
    #[Test]
    public function detectRecognisesIsoBmffBrand(): void
    {
        $stream = $this->createStream("\x00\x00\x00\x10ftypisom\x00\x00\x00\x00");

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Rejects an ftyp box when its declared size exceeds remaining stream bytes.
     * This prevents false-positive ISO BMFF detection on truncated signatures.
     *
     * @return void
     */
    #[Test]
    public function detectRejectsIsoBmffWhenFtypDeclaredSizeExceedsStreamBounds(): void
    {
        $stream = $this->createStream("\x00\x00\x00\x18ftypisom");

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported or unknown container');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Rejects an extended-size ftyp box when largesize exceeds remaining stream bytes.
     * This hardens signature scanning against out-of-bounds 64-bit size declarations.
     *
     * @return void
     */
    #[Test]
    public function detectRejectsIsoBmffWhenFtypLargeSizeExceedsStreamBounds(): void
    {
        $stream = $this->createStream("\x00\x00\x00\x01ftyp\x00\x00\x00\x00\x00\x00\x00\x20isom");

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported or unknown container');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Skips a QuickTime wide box and continues detection.
     * This verifies that early padding boxes do not hide ISO BMFF detection.
     *
     * @return void
     */
    #[Test]
    public function detectRecognisesQuickTimeWideBox(): void
    {
        $stream = $this->createStream("\x00\x00\x00\x08wide\x00\x00\x00\x08mdat");

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Skips a free box and then detects the subsequent ftyp brand.
     * This ensures the detector handles leading padding boxes correctly.
     *
     * @return void
     */
    #[Test]
    public function detectRecognisesIsoBmffAfterFreePadding(): void
    {
        $stream = $this->createStream("\x00\x00\x00\x08free\x00\x00\x00\x10ftypqt  \x00\x00\x00\x00");

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Leading uuid alone is not sufficient evidence for ISO-BMFF detection.
     *
     * @return void
     */
    #[Test]
    public function detectRejectsUuidOnlyTopLevelSignature(): void
    {
        $uuidOnly = hex2bin('0000001875756964' . str_repeat('00', 16));
        self::assertIsString($uuidOnly);

        $stream = $this->createStream($uuidOnly);
        $this->expectException(ParseError::class);

        (new FormatDetector())->detect($stream);
    }

    /**
     * Leading uuid followed by a valid structural signature must still detect ISO-BMFF.
     *
     * @return void
     */
    #[Test]
    public function detectRecognisesIsoBmffAfterLeadingUuid(): void
    {
        $uuidBox = hex2bin('0000001875756964' . str_repeat('00', 16));
        $ftyp    = hex2bin('000000106674797069736F6D00000000');
        self::assertIsString($uuidBox);
        self::assertIsString($ftyp);

        $stream   = $this->createStream($uuidBox . $ftyp);
        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Rejects ftyp with size=0 (extends to EOF) as it lacks proper box boundaries.
     */
    #[Test]
    public function detectRejectsIsoBmffWhenFtypHasZeroSize(): void
    {
        // size=0, type='ftyp', followed by payload
        $stream = $this->createStream("\x00\x00\x00\x00ftypisom\x00\x00\x00\x00");

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported or unknown container');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Supplies a stream with an unsupported signature.
     * This confirms a ParseError is thrown for unknown container bytes.
     *
     * @return void
     */
    #[Test]
    public function detectThrowsForUnsupportedSignature(): void
    {
        $stream = $this->createStream('UNSUPPORTED');

        $this->expectException(ParseError::class);

        (new FormatDetector())->detect($stream);
    }

    /**
     * Provides streams shorter than the required signature length.
     * This asserts a ParseError with a specific message when reads are insufficient.
     *
     * @param string $bytes byte sequence to test
     *
     * @return void
     */
    #[Test]
    #[DataProvider('tooShortStreamProvider')]
    public function detectThrowsWhenSignatureCannotBeRead(string $bytes): void
    {
        $stream = $this->createStream($bytes);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unable to read container signature');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Provides byte sequences that are insufficient to cover the signature reads.
     * These fixtures exercise the short-read branch in the detector.
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
     * This helper ensures the stream length matches the payload size.
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
