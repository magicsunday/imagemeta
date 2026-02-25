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
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
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
use function pack;
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
#[UsesTrait(NormalizesOffsets::class)]
final class FormatDetectorTest extends TestCase
{
    /**
     * Uses the JPEG SOI marker and APP0 prefix to identify JPEG containers.
     * This ensures the detector recognizes the canonical JPEG signature bytes.
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
     */
    #[Test]
    public function detectRecognisesQuickTimeWideBox(): void
    {
        $stream = $this->createStream("\x00\x00\x00\x08wide\x00\x00\x00\x08moov");

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Skips a free box and then detects the subsequent ftyp brand.
     * This ensures the detector handles leading padding boxes correctly.
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
     * Rejects ftyp when payload is too short for major_brand + minor_version (< 8 bytes).
     */
    #[Test]
    public function detectRejectsFtypWithTooShortPayload(): void
    {
        // size=12 → payload=4 bytes (< 8 required), type='ftyp', 4 bytes payload
        $stream = $this->createStream("\x00\x00\x00\x0Cftypisom");

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported or unknown container');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Rejects ftyp when compatible-brands table is not aligned to 4 bytes.
     */
    #[Test]
    public function detectRejectsFtypWithMisalignedBrandsTable(): void
    {
        // size=19 → payload=11 bytes (8 + 3 leftover), type='ftyp'
        $stream = $this->createStream("\x00\x00\x00\x13ftypisom\x00\x00\x00\x00abc");

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported or unknown container');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Rejects a stream with an unknown non-padding box before the signature.
     */
    #[Test]
    public function detectRejectsUnknownNonPaddingLeadingBox(): void
    {
        // unknown box 'abcd'(size=8) + ftyp(size=16)
        $stream = $this->createStream(
            "\x00\x00\x00\x08abcd"
            . "\x00\x00\x00\x10ftypisom\x00\x00\x00\x00"
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported or unknown container');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Detects ISO-BMFF when more than four leading padding boxes precede ftyp.
     */
    #[Test]
    public function detectRecognisesIsoBmffAfterManyPaddingBoxes(): void
    {
        $padding = str_repeat("\x00\x00\x00\x08free", 5);
        $ftyp    = "\x00\x00\x00\x10ftypisom\x00\x00\x00\x00";

        $stream   = $this->createStream($padding . $ftyp);
        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Rejects a stream with only an mdat box as sole ISO-BMFF evidence.
     */
    #[Test]
    public function detectRejectsMdatOnlyAsIsoBmff(): void
    {
        // size=16, type='mdat', followed by 8 bytes of payload
        $stream = $this->createStream("\x00\x00\x00\x10mdat" . str_repeat("\x00", 8));

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported or unknown container');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Detects ISO-BMFF when mdat is followed by a valid moov box within scan window.
     */
    #[Test]
    public function detectRecognisesIsoBmffWhenMdatFollowedByMoov(): void
    {
        // mdat(size=16) + moov(size=8)
        $stream = $this->createStream(
            "\x00\x00\x00\x10mdat" . str_repeat("\x00", 8)
            . "\x00\x00\x00\x08moov"
        );

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Classic QuickTime MOV layout: large mdat followed by moov.
     * The scan budget must count only header bytes, not skipped payload.
     */
    #[Test]
    public function detectRecognisesIsoBmffWhenLargeMdatFollowedByMoov(): void
    {
        $wide            = "\x00\x00\x00\x08wide";
        $mdatPayloadSize = 100_000;
        $mdatSize        = 8 + $mdatPayloadSize;
        $mdat            = pack('N', $mdatSize) . 'mdat' . str_repeat("\x00", $mdatPayloadSize);
        $moov            = "\x00\x00\x00\x08moov";

        $stream   = $this->createStream($wide . $mdat . $moov);
        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Header budget still rejects streams with too many padding boxes.
     */
    #[Test]
    public function detectRejectsTooManyPaddingBoxesExceedingHeaderBudget(): void
    {
        $padding = str_repeat("\x00\x00\x00\x08free", 8193);
        $ftyp    = "\x00\x00\x00\x10ftypisom\x00\x00\x00\x00";

        $stream = $this->createStream($padding . $ftyp);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported or unknown container');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Accepts an ftyp box with size=0 (extends to EOF per ISO 14496-12 §4.2) as ISO BMFF.
     * The presence of the ftyp type alone is sufficient when the size field is zero.
     */
    #[Test]
    public function detectRecognisesIsoBmffWhenFtypHasZeroSize(): void
    {
        // size=0 means box extends to EOF; ftyp type is sufficient evidence
        $stream = $this->createStream("\x00\x00\x00\x00ftypisom\x00\x00\x00\x00");

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Accepts a styp box with size=0 (extends to EOF per ISO 14496-12 §4.2) as ISO BMFF.
     * The presence of the styp type alone is sufficient when the size field is zero.
     */
    #[Test]
    public function detectRecognisesIsoBmffWhenStypHasZeroSize(): void
    {
        // size=0 means box extends to EOF; styp type is sufficient evidence
        $stream = $this->createStream("\x00\x00\x00\x00stypiso6\x00\x00\x00\x00");

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::ISOBMFF, $detected);
    }

    /**
     * Detects classic TIFF with little-endian byte order (II + 0x002A).
     */
    #[Test]
    public function detectRecognisesClassicTiffLittleEndian(): void
    {
        $stream = $this->createStream('II' . pack('v', 0x002A) . pack('V', 8));

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::TIFF, $detected);
    }

    /**
     * Detects classic TIFF with big-endian byte order (MM + 0x002A).
     */
    #[Test]
    public function detectRecognisesClassicTiffBigEndian(): void
    {
        $stream = $this->createStream('MM' . pack('n', 0x002A) . pack('N', 8));

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::TIFF, $detected);
    }

    /**
     * Detects BigTIFF with little-endian byte order (II + 0x002B).
     */
    #[Test]
    public function detectRecognisesBigTiffLittleEndian(): void
    {
        $stream = $this->createStream('II' . pack('v', 0x002B) . pack('v', 8) . pack('v', 0) . pack('P', 16));

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::TIFF, $detected);
    }

    /**
     * Detects BigTIFF with big-endian byte order (MM + 0x002B).
     */
    #[Test]
    public function detectRecognisesBigTiffBigEndian(): void
    {
        $stream = $this->createStream('MM' . pack('n', 0x002B) . pack('n', 8) . pack('n', 0) . pack('J', 16));

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::TIFF, $detected);
    }

    /**
     * Rejects a stream starting with a valid byte-order mark but an invalid TIFF magic.
     */
    #[Test]
    public function detectRejectsTiffWithInvalidMagic(): void
    {
        $stream = $this->createStream('II' . pack('v', 0x0099) . pack('V', 8));

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported or unknown container');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Rejects a stream starting with II but too short to contain the magic number.
     */
    #[Test]
    public function detectRejectsTruncatedTiffHeader(): void
    {
        $stream = $this->createStream('II*');

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported or unknown container');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Detects a bare JPEG XL codestream by its 0xFF 0x0A signature.
     */
    #[Test]
    public function detectRecognisesBareJxlCodestream(): void
    {
        $stream = $this->createStream("\xFF\x0A" . str_repeat("\x00", 8));

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::JXL, $detected);
    }

    /**
     * Detects a JPEG XL ISO BMFF container by its 12-byte signature box.
     */
    #[Test]
    public function detectRecognisesJxlContainer(): void
    {
        $jxlSignature = "\x00\x00\x00\x0C\x4A\x58\x4C\x20\x0D\x0A\x87\x0A";
        $stream       = $this->createStream($jxlSignature . str_repeat("\x00", 8));

        $detected = (new FormatDetector())->detect($stream);

        self::assertSame(ContainerType::JXL, $detected);
    }

    /**
     * Rejects a truncated JXL container signature (fewer than 12 bytes).
     */
    #[Test]
    public function detectRejectsTruncatedJxlSignature(): void
    {
        $stream = $this->createStream("\x00\x00\x00\x0C\x4A\x58\x4C\x20\x0D\x0A\x87");

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Unsupported or unknown container');

        (new FormatDetector())->detect($stream);
    }

    /**
     * Supplies a stream with an unsupported signature.
     * This confirms a ParseError is thrown for unknown container bytes.
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
