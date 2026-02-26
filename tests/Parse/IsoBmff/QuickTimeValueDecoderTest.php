<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;
use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function strlen;

/**
 * Tests for QuickTimeValueDecoder covering data box parsing, payload decoding,
 * value coercion, fourcc conversion, locale validation, and data ordering.
 * Validates both successful decoding and error paths for malformed data.
 */
#[CoversClass(QuickTimeValueDecoder::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(BoxNavigator::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesTrait(NormalizesOffsets::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
final class QuickTimeValueDecoderTest extends TestCase
{
    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Creates a QuickTimeValueDecoder with a no-op nested parser closure.
     */
    private function createDecoder(): QuickTimeValueDecoder
    {
        return new QuickTimeValueDecoder(
            static fn (string $payload): array => ['keys' => [], 'atoms' => []],
        );
    }

    /**
     * Creates a BoxDescriptor wrapping the given content as a data box payload.
     */
    private function createDataBoxDescriptor(string $content): BoxDescriptor
    {
        $handle = fopen('php://temp', 'wb+');
        if ($handle === false) {
            self::fail('Unable to create temporary stream handle.');
        }

        fwrite($handle, $content);
        rewind($handle);

        $stream = new Stream($handle, strlen($content));
        $window = $stream->window(0, strlen($content));

        return new BoxDescriptor(
            type: 'data',
            size: 8 + strlen($content),
            offset: 0,
            contentOffset: 0,
            contentSize: strlen($content),
            window: $window,
            userType: null,
        );
    }

    // =========================================================================
    // parseDataBoxStructured — positive tests
    // =========================================================================

    /**
     * Parses a data box with a UTF-8 text payload.
     */
    #[Test]
    public function parseDataBoxStructuredUtf8(): void
    {
        $decoder = $this->createDecoder();
        $text    = 'Hello World';
        // type=1 (UTF-8), locale=0
        $content    = pack('N', 1) . pack('N', 0) . $text;
        $descriptor = $this->createDataBoxDescriptor($content);

        $result = $decoder->parseDataBoxStructured($descriptor);

        self::assertSame(1, $result['type']);
        self::assertSame(0, $result['locale']);
        self::assertSame('Hello World', $result['value']);
    }

    /**
     * Parses a data box with a signed integer payload.
     */
    #[Test]
    public function parseDataBoxStructuredSignedInt(): void
    {
        $decoder = $this->createDecoder();
        // type=21 (signed int), locale=0, payload = 1-byte value 200 => signed -56
        $content    = pack('N', 0x15) . pack('N', 0) . pack('C', 200);
        $descriptor = $this->createDataBoxDescriptor($content);

        $result = $decoder->parseDataBoxStructured($descriptor);

        self::assertSame(0x15, $result['type']);
        self::assertSame(-56, $result['value']);
    }

    /**
     * Parses a data box with an unsigned integer payload.
     */
    #[Test]
    public function parseDataBoxStructuredUnsignedInt(): void
    {
        $decoder = $this->createDecoder();
        // type=22 (unsigned int), locale=0, payload = 2-byte value 0x0102
        $content    = pack('N', 0x16) . pack('N', 0) . pack('n', 0x0102);
        $descriptor = $this->createDataBoxDescriptor($content);

        $result = $decoder->parseDataBoxStructured($descriptor);

        self::assertSame(0x16, $result['type']);
        self::assertSame(258, $result['value']);
    }

    /**
     * Parses a data box with a float32 payload.
     */
    #[Test]
    public function parseDataBoxStructuredFloat32(): void
    {
        $decoder = $this->createDecoder();
        // type=23 (float32), locale=0
        $content    = pack('N', 0x17) . pack('N', 0) . pack('G', 3.14);
        $descriptor = $this->createDataBoxDescriptor($content);

        $result = $decoder->parseDataBoxStructured($descriptor);

        self::assertSame(0x17, $result['type']);
        self::assertEqualsWithDelta(3.14, $result['value'], 0.001);
    }

    /**
     * Parses a data box with a float64 payload.
     */
    #[Test]
    public function parseDataBoxStructuredFloat64(): void
    {
        $decoder = $this->createDecoder();
        // type=24 (float64), locale=0
        $content    = pack('N', 0x18) . pack('N', 0) . pack('E', 2.718281828);
        $descriptor = $this->createDataBoxDescriptor($content);

        $result = $decoder->parseDataBoxStructured($descriptor);

        self::assertSame(0x18, $result['type']);
        self::assertEqualsWithDelta(2.718281828, $result['value'], 0.000001);
    }

    /**
     * Parses a data box with a locale indicator.
     */
    #[Test]
    public function parseDataBoxStructuredPreservesLocale(): void
    {
        $decoder = $this->createDecoder();
        // type=1, locale = country(1) << 16 | language(2)
        $locale     = (1 << 16) | 2;
        $content    = pack('N', 1) . pack('N', $locale) . 'text';
        $descriptor = $this->createDataBoxDescriptor($content);

        $result = $decoder->parseDataBoxStructured($descriptor);

        self::assertSame($locale, $result['locale']);
    }

    // =========================================================================
    // parseDataBoxStructured — negative tests
    // =========================================================================

    /**
     * Rejects a data box that is too small (less than 8 bytes).
     */
    #[Test]
    public function parseDataBoxStructuredRejectsTooSmall(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('data box too small');

        $decoder    = $this->createDecoder();
        $content    = pack('N', 1) . "\x00\x00\x00";
        $descriptor = $this->createDataBoxDescriptor($content);

        $decoder->parseDataBoxStructured($descriptor);
    }

    /**
     * Rejects a data box with a non-zero type indicator byte.
     */
    #[Test]
    public function parseDataBoxStructuredRejectsNonZeroIndicatorByte(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('data box type indicator byte must be 0');

        $decoder = $this->createDecoder();
        // type with non-zero indicator byte (0x01000001)
        $content    = pack('N', 0x01000001) . pack('N', 0) . 'text';
        $descriptor = $this->createDataBoxDescriptor($content);

        $decoder->parseDataBoxStructured($descriptor);
    }

    // =========================================================================
    // decodeDataPayload — negative tests
    // =========================================================================

    /**
     * Rejects invalid UTF-8 in a type 1 payload.
     */
    #[Test]
    public function decodeDataPayloadRejectsInvalidUtf8(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('data box UTF-8 payload contains invalid byte sequence');

        $decoder = $this->createDecoder();
        $invalid = "\xFF\xFE";
        $decoder->decodeDataPayload(1, $invalid, strlen($invalid));
    }

    /**
     * Rejects odd byte count in a UTF-16BE payload.
     */
    #[Test]
    public function decodeDataPayloadRejectsOddUtf16(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('data box UTF-16BE payload has odd byte count');

        $decoder = $this->createDecoder();
        $decoder->decodeDataPayload(2, "\x00\x41\x00", 3);
    }

    /**
     * Rejects integer payload with unsupported length.
     */
    #[Test]
    public function decodeDataPayloadRejectsOversizedIntegerPayload(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('QuickTime integer payload must be 1');

        $decoder = $this->createDecoder();
        $decoder->decodeDataPayload(0x15, "\x00\x00\x00\x00\x00", 5);
    }

    /**
     * Rejects a truncated float32 payload.
     */
    #[Test]
    public function decodeDataPayloadRejectsTruncatedFloat32(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('data box float32 payload truncated');

        $decoder = $this->createDecoder();
        $decoder->decodeDataPayload(0x17, "\x00\x00", 2);
    }

    /**
     * Rejects a float32 payload that is longer than 4 bytes.
     */
    #[Test]
    public function decodeDataPayloadRejectsOversizedFloat32(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('data box float32 payload must be exactly 4 bytes');

        $decoder = $this->createDecoder();
        $decoder->decodeDataPayload(0x17, "\x00\x00\x00\x00\x00", 5);
    }

    /**
     * Rejects a truncated float64 payload.
     */
    #[Test]
    public function decodeDataPayloadRejectsTruncatedFloat64(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('data box float64 payload truncated');

        $decoder = $this->createDecoder();
        $decoder->decodeDataPayload(0x18, "\x00\x00\x00\x00", 4);
    }

    // =========================================================================
    // coerceQuickTimeValue — positive tests
    // =========================================================================

    /**
     * Coerces a string numeric value to int for videoOrientation.
     */
    #[Test]
    public function coerceQuickTimeValueToInt(): void
    {
        $decoder = $this->createDecoder();

        $result = $decoder->coerceQuickTimeValue('com.apple.quicktime.videoOrientation', '90');

        self::assertSame(90, $result);
    }

    /**
     * Coerces a string numeric value to float for horizontal accuracy.
     */
    #[Test]
    public function coerceQuickTimeValueToFloat(): void
    {
        $decoder = $this->createDecoder();

        $result = $decoder->coerceQuickTimeValue('com.apple.quicktime.location.accuracy.horizontal', '12.5');

        self::assertSame(12.5, $result);
    }

    /**
     * Coerces string 'true' to boolean for isHDRVideo.
     */
    #[Test]
    public function coerceQuickTimeValueToBool(): void
    {
        $decoder = $this->createDecoder();

        $result = $decoder->coerceQuickTimeValue('com.apple.quicktime.isHDRVideo', 'true');

        self::assertTrue($result);
    }

    /**
     * Coerces string 'false' to boolean for isHDRVideo.
     */
    #[Test]
    public function coerceQuickTimeValueToBoolFalse(): void
    {
        $decoder = $this->createDecoder();

        $result = $decoder->coerceQuickTimeValue('com.apple.quicktime.isHDRVideo', 'false');

        self::assertFalse($result);
    }

    /**
     * Returns value unchanged for an unknown key.
     */
    #[Test]
    public function coerceQuickTimeValueReturnsUnchangedForUnknownKey(): void
    {
        $decoder = $this->createDecoder();

        $result = $decoder->coerceQuickTimeValue('com.example.unknown', 'hello');

        self::assertSame('hello', $result);
    }

    /**
     * Returns int unchanged when target type is int and value is already int.
     */
    #[Test]
    public function coerceQuickTimeValuePreservesIntForIntTarget(): void
    {
        $decoder = $this->createDecoder();

        $result = $decoder->coerceQuickTimeValue('com.apple.quicktime.videoOrientation', 90);

        self::assertSame(90, $result);
    }

    /**
     * Coerces boolean to int for videoOrientation.
     */
    #[Test]
    public function coerceQuickTimeValueBoolToInt(): void
    {
        $decoder = $this->createDecoder();

        $result = $decoder->coerceQuickTimeValue('com.apple.quicktime.videoOrientation', true);

        self::assertSame(1, $result);
    }

    /**
     * Returns original value when non-numeric string cannot be coerced to int.
     */
    #[Test]
    public function coerceQuickTimeValueReturnsOriginalWhenNonNumericToInt(): void
    {
        $decoder = $this->createDecoder();

        $result = $decoder->coerceQuickTimeValue('com.apple.quicktime.videoOrientation', 'not-a-number');

        self::assertSame('not-a-number', $result);
    }

    /**
     * Returns original value when non-numeric string cannot be coerced to float.
     */
    #[Test]
    public function coerceQuickTimeValueReturnsOriginalWhenNonNumericToFloat(): void
    {
        $decoder = $this->createDecoder();

        $result = $decoder->coerceQuickTimeValue('com.apple.quicktime.location.accuracy.horizontal', 'nope');

        self::assertSame('nope', $result);
    }

    /**
     * Returns original value when non-boolean string cannot be coerced to bool.
     */
    #[Test]
    public function coerceQuickTimeValueReturnsOriginalWhenUncoercibleBool(): void
    {
        $decoder = $this->createDecoder();

        $result = $decoder->coerceQuickTimeValue('com.apple.quicktime.isHDRVideo', 'maybe');

        self::assertSame('maybe', $result);
    }

    // =========================================================================
    // fourccToIndex — tests
    // =========================================================================

    /**
     * Converts a valid fourcc to its integer representation.
     */
    #[Test]
    public function fourccToIndexConvertsValidFourcc(): void
    {
        $decoder = $this->createDecoder();

        $result = $decoder->fourccToIndex("\x00\x00\x00\x01");

        self::assertSame(1, $result);
    }

    /**
     * Returns null for a non-4-byte string.
     */
    #[Test]
    public function fourccToIndexReturnsNullForInvalidLength(): void
    {
        $decoder = $this->createDecoder();

        self::assertNull($decoder->fourccToIndex('AB'));
    }

    // =========================================================================
    // validateLocaleIndicator — positive and negative tests
    // =========================================================================

    /**
     * Accepts a default locale (country=0, language=0).
     */
    #[Test]
    public function validateLocaleIndicatorAcceptsDefault(): void
    {
        $decoder = $this->createDecoder();

        // Should not throw
        $decoder->validateLocaleIndicator(0, [], []);
        $this->addToAssertionCount(1);
    }

    /**
     * Accepts direct ISO code values (>255).
     */
    #[Test]
    public function validateLocaleIndicatorAcceptsDirectIsoCodes(): void
    {
        $decoder = $this->createDecoder();
        // country=300 (>255), language=400 (>255) — direct codes, no lists needed
        $locale = (300 << 16) | 400;

        $decoder->validateLocaleIndicator($locale, [], []);
        $this->addToAssertionCount(1);
    }

    /**
     * Rejects a country index without a ctry list atom.
     */
    #[Test]
    public function validateLocaleIndicatorRejectsCountryIndexWithoutList(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('requires a ctry list atom');

        $decoder = $this->createDecoder();
        $locale  = (5 << 16) | 0;
        $decoder->validateLocaleIndicator($locale, [], []);
    }

    /**
     * Rejects a country index exceeding the ctry list entry count.
     */
    #[Test]
    public function validateLocaleIndicatorRejectsCountryIndexExceedingList(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('exceeds ctry list entry count');

        $decoder = $this->createDecoder();
        $locale  = (3 << 16) | 0;
        // Only 2 entries in ctry list
        $decoder->validateLocaleIndicator($locale, [[1], [2]], []);
    }

    /**
     * Rejects a language index without a lang list atom.
     */
    #[Test]
    public function validateLocaleIndicatorRejectsLanguageIndexWithoutList(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('requires a lang list atom');

        $decoder = $this->createDecoder();
        $locale  = 0 | 5;
        $decoder->validateLocaleIndicator($locale, [], []);
    }

    // =========================================================================
    // validateDataOrdering — positive and negative tests
    // =========================================================================

    /**
     * Accepts data atoms ordered from most-specific to most-general.
     */
    #[Test]
    public function validateDataOrderingAcceptsCorrectOrder(): void
    {
        $decoder = $this->createDecoder();

        $atoms = [
            ['type' => 1, 'locale' => (1 << 16) | 1, 'value' => 'specific'],
            ['type' => 1, 'locale' => (1 << 16) | 0, 'value' => 'country-only'],
            ['type' => 1, 'locale' => 0, 'value' => 'default'],
        ];

        $decoder->validateDataOrdering('test', $atoms);
        $this->addToAssertionCount(1);
    }

    /**
     * Rejects data atoms ordered from general to specific.
     */
    #[Test]
    public function validateDataOrderingRejectsWrongOrder(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('must be ordered from most-specific to most-general');

        $decoder = $this->createDecoder();

        $atoms = [
            ['type' => 1, 'locale' => 0, 'value' => 'default'],
            ['type' => 1, 'locale' => (1 << 16) | 1, 'value' => 'specific'],
        ];

        $decoder->validateDataOrdering('test', $atoms);
    }
}
