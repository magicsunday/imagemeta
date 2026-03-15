<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleAutoExposure;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleCameraCapture;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleCaptureIdentity;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleDictionaryValueExtractor;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleFlagExtractor;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleHdr;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleJpegIfdParser;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesBuilder;
use MagicSunday\ImageMeta\MakerNotes\Apple\KeyedArchiveResolver;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistTextCursor;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistTextParser;
use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_repeat;
use function strlen;

/**
 * Unit and integration tests for the Apple JPEG MakerNote TIFF IFD parser.
 *
 * Verifies that {@see AppleJpegIfdParser} correctly decodes the "Apple iOS\0" prefixed
 * TIFF IFD structure found in JPEG MakerNotes, and that the result integrates with
 * {@see AppleDecoder} and {@see AppleMakerNotesBuilder}.
 */
#[CoversClass(AppleJpegIfdParser::class)]
#[UsesClass(AppleAutoExposure::class)]
#[UsesClass(AppleCameraCapture::class)]
#[UsesClass(AppleCaptureIdentity::class)]
#[UsesClass(AppleDecoder::class)]
#[UsesClass(AppleDictionaryValueExtractor::class)]
#[UsesClass(AppleFlagExtractor::class)]
#[UsesClass(AppleHdr::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(AppleMakerNotesBuilder::class)]
#[UsesClass(KeyedArchiveResolver::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(PlistTextCursor::class)]
#[UsesClass(PlistTextParser::class)]
#[UsesClass(Unpack::class)]
final class AppleJpegIfdParserTest extends TestCase
{
    private const int TIFF_TYPE_ASCII = 2;

    private const int TIFF_TYPE_SLONG = 9;

    private const int TIFF_TYPE_SRATIONAL = 10;

    /**
     * Builds a synthetic Apple JPEG MakerNote payload.
     *
     * Layout: signature(10) + version(2) + byte order(2) + entry count(2) + N*entry(12) + nextIFD(4) + extraData.
     * Out-of-line value offsets are relative to position 0 of the raw payload.
     *
     * @param bool                                                        $bigEndian Whether to use big-endian byte order.
     * @param list<array{tag: int, type: int, count: int, value: string}> $entries   IFD entries with inline or placeholder values.
     * @param string                                                      $extraData Additional data appended after the IFD (for out-of-line values).
     */
    private function buildPayload(bool $bigEndian, array $entries, string $extraData = ''): string
    {
        $signature = "Apple iOS\x00";
        $version   = "\x00\x01";
        $bo        = $bigEndian ? 'MM' : 'II';

        $entryCount = $bigEndian ? pack('n', count($entries)) : pack('v', count($entries));

        $entryData = '';

        foreach ($entries as $entry) {
            $entryData .= $bigEndian
                ? pack('n', $entry['tag']) . pack('n', $entry['type']) . pack('N', $entry['count'])
                : pack('v', $entry['tag']) . pack('v', $entry['type']) . pack('V', $entry['count']);
            // Value/offset field: always 4 bytes, caller provides exactly 4 bytes
            $entryData .= $entry['value'];
        }

        // Next IFD offset (0 = no next IFD)
        $nextIfd = pack('N', 0);

        return $signature . $version . $bo
            . $entryCount . $entryData . $nextIfd . $extraData;
    }

    /**
     * Packs an SLONG value into a 4-byte inline value field.
     */
    private function inlineSLong(bool $bigEndian, int $value): string
    {
        // Pack as unsigned 32-bit; signed conversion happens on read
        $unsigned = $value & 0xFFFFFFFF;

        return $bigEndian ? pack('N', $unsigned) : pack('V', $unsigned);
    }

    /**
     * Packs an SRATIONAL (num/den) into 8 bytes for out-of-line storage.
     */
    private function sRational(bool $bigEndian, int $numerator, int $denominator): string
    {
        $numU = $numerator & 0xFFFFFFFF;
        $denU = $denominator & 0xFFFFFFFF;

        return $bigEndian
            ? pack('N', $numU) . pack('N', $denU)
            : pack('V', $numU) . pack('V', $denU);
    }

    /**
     * Packs an offset into a 4-byte inline value field.
     */
    private function offsetField(bool $bigEndian, int $offset): string
    {
        return $bigEndian ? pack('N', $offset) : pack('V', $offset);
    }

    #[Test]
    public function parseReturnsNullForEmptyPayload(): void
    {
        $parser = new AppleJpegIfdParser();

        self::assertNull($parser->parse(''));
    }

    #[Test]
    public function parseReturnsNullForNonAppleSignature(): void
    {
        $parser = new AppleJpegIfdParser();

        // Something that's long enough but not "Apple iOS\0"
        $raw = str_repeat("\x00", 40);

        self::assertNull($parser->parse($raw));
    }

    #[Test]
    public function parseReturnsNullForInvalidByteOrder(): void
    {
        $parser = new AppleJpegIfdParser();

        // Valid signature + version but invalid byte order "XX"
        $raw = "Apple iOS\x00\x00\x01XX" . pack('n', 0) . pack('N', 0);

        self::assertNull($parser->parse($raw));
    }

    #[Test]
    public function parseReturnsNullForTruncatedPayload(): void
    {
        $parser = new AppleJpegIfdParser();

        // Valid signature + version but too short to contain byte order
        $raw = "Apple iOS\x00\x00\x01";

        self::assertNull($parser->parse($raw));
    }

    #[Test]
    public function parseBigEndianSLongEntry(): void
    {
        $parser = new AppleJpegIfdParser();

        $payload = $this->buildPayload(true, [
            ['tag' => 0x0004, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 1)],
        ]);

        $result = $parser->parse($payload);

        self::assertIsArray($result);
        self::assertArrayHasKey('AEStable', $result);
        self::assertSame(1, $result['AEStable']);
    }

    #[Test]
    public function parseLittleEndianSLongEntry(): void
    {
        $parser = new AppleJpegIfdParser();

        $payload = $this->buildPayload(false, [
            ['tag' => 0x0005, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(false, 220)],
        ]);

        $result = $parser->parse($payload);

        self::assertIsArray($result);
        self::assertArrayHasKey('AETarget', $result);
        self::assertSame(220, $result['AETarget']);
    }

    #[Test]
    public function parseHandlesNegativeSLongValues(): void
    {
        $parser = new AppleJpegIfdParser();

        $payload = $this->buildPayload(true, [
            ['tag' => 0x0006, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, -1)],
        ]);

        $result = $parser->parse($payload);

        self::assertIsArray($result);
        self::assertArrayHasKey('AEAverage', $result);
        self::assertSame(-1, $result['AEAverage']);
    }

    #[Test]
    public function parseAsciiStringTag(): void
    {
        $parser = new AppleJpegIfdParser();

        // "AB\0" is 3 bytes, fits inline (padded to 4 bytes)
        $asciiValue = "AB\x00\x00";

        $payload = $this->buildPayload(true, [
            ['tag' => 0x000B, 'type' => self::TIFF_TYPE_ASCII, 'count' => 3, 'value' => $asciiValue],
        ]);

        $result = $parser->parse($payload);

        self::assertIsArray($result);
        self::assertArrayHasKey('BurstUUID', $result);
        self::assertSame('AB', $result['BurstUUID']);
    }

    #[Test]
    public function parseSRationalAsFloat(): void
    {
        $parser = new AppleJpegIfdParser();

        // SRATIONAL is 8 bytes -> out-of-line
        // Extra data starts after: sig(10)+ver(2)+BO(2)+count(2)+1*entry(12)+nextIFD(4) = 32
        // Offsets are relative to position 0
        $extraData = $this->sRational(true, 3, 2);

        $payload = $this->buildPayload(true, [
            ['tag' => 0x001D, 'type' => self::TIFF_TYPE_SRATIONAL, 'count' => 1, 'value' => $this->offsetField(true, 32)],
        ], $extraData);

        $result = $parser->parse($payload);

        self::assertIsArray($result);
        self::assertArrayHasKey('LuminanceNoiseAmplitude', $result);
        self::assertEqualsWithDelta(1.5, $result['LuminanceNoiseAmplitude'], 1e-12);
    }

    #[Test]
    public function parseSRationalListForMultipleEntries(): void
    {
        $parser = new AppleJpegIfdParser();

        // 3 SRATIONALs = 24 bytes -> out-of-line at offset 32 from start
        $extraData = $this->sRational(true, 1, 4)
            . $this->sRational(true, -1, 2)
            . $this->sRational(true, 5, 10);

        $payload = $this->buildPayload(true, [
            ['tag' => 0x0008, 'type' => self::TIFF_TYPE_SRATIONAL, 'count' => 3, 'value' => $this->offsetField(true, 32)],
        ], $extraData);

        $result = $parser->parse($payload);

        self::assertIsArray($result);
        self::assertArrayHasKey('AccelerationVector', $result);

        $vector = $result['AccelerationVector'];
        self::assertIsArray($vector);
        self::assertCount(3, $vector);
        self::assertEqualsWithDelta(0.25, $vector[0], 1e-12);
        self::assertEqualsWithDelta(-0.5, $vector[1], 1e-12);
        self::assertEqualsWithDelta(0.5, $vector[2], 1e-12);
    }

    #[Test]
    public function parseSkipsZeroDenominatorSRational(): void
    {
        $parser = new AppleJpegIfdParser();

        // SRATIONAL with denominator = 0 plus a valid SLONG tag to keep dictionary non-empty
        // After 2 entries: sig(10)+ver(2)+BO(2)+count(2)+2*entry(24)+nextIFD(4) = 44
        // Offsets are relative to position 0
        $extraData = $this->sRational(true, 1, 0);

        $payload = $this->buildPayload(true, [
            ['tag' => 0x0004, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 1)],
            ['tag' => 0x001D, 'type' => self::TIFF_TYPE_SRATIONAL, 'count' => 1, 'value' => $this->offsetField(true, 44)],
        ], $extraData);

        $result = $parser->parse($payload);

        self::assertIsArray($result);
        self::assertArrayHasKey('AEStable', $result);
        self::assertArrayNotHasKey('LuminanceNoiseAmplitude', $result);
    }

    #[Test]
    public function parseHandlesOutOfLineAsciiData(): void
    {
        $parser = new AppleJpegIfdParser();

        // "ABCDE\0" is 6 bytes -> out-of-line at offset 32 from start
        $asciiString = "ABCDE\x00";
        $extraData   = $asciiString;

        $payload = $this->buildPayload(true, [
            ['tag' => 0x0011, 'type' => self::TIFF_TYPE_ASCII, 'count' => 6, 'value' => $this->offsetField(true, 32)],
        ], $extraData);

        $result = $parser->parse($payload);

        self::assertIsArray($result);
        self::assertArrayHasKey('ContentIdentifier', $result);
        self::assertSame('ABCDE', $result['ContentIdentifier']);
    }

    #[Test]
    public function parseSkipsUnknownTags(): void
    {
        $parser = new AppleJpegIfdParser();

        $payload = $this->buildPayload(true, [
            // Known tag
            ['tag' => 0x0004, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 1)],
            // Unknown tag 0xFFFF
            ['tag' => 0xFFFF, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 99)],
        ]);

        $result = $parser->parse($payload);

        self::assertIsArray($result);
        self::assertArrayHasKey('AEStable', $result);
        self::assertArrayHasKey('Apple_0xFFFF', $result);
        self::assertSame(99, $result['Apple_0xFFFF']);
    }

    #[Test]
    public function parseReturnsNullForEntryCountBeyondPayload(): void
    {
        $parser = new AppleJpegIfdParser();

        // Valid header but entry count claims 9999 entries which exceed payload length
        $raw = "Apple iOS\x00\x00\x01MM" . pack('n', 9999);

        self::assertNull($parser->parse($raw));
    }

    #[Test]
    public function parseStoresSemanticStylePresetAsString(): void
    {
        $parser = new AppleJpegIfdParser();

        $payload = $this->buildPayload(true, [
            ['tag' => 0x0040, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 4)],
        ]);

        $result = $parser->parse($payload);

        self::assertIsArray($result);
        self::assertArrayHasKey('SemanticStylePreset', $result);
        self::assertSame('4', $result['SemanticStylePreset']);
    }

    #[Test]
    public function parseDecodesMultipleTags(): void
    {
        $parser = new AppleJpegIfdParser();

        $payload = $this->buildPayload(true, [
            ['tag' => 0x0001, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 15)],
            ['tag' => 0x0004, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 1)],
            ['tag' => 0x0005, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 220)],
            ['tag' => 0x0006, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 199)],
            ['tag' => 0x000A, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 4)],
            ['tag' => 0x002E, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 1)],
        ]);

        $result = $parser->parse($payload);

        self::assertIsArray($result);
        self::assertSame(15, $result['MakerNoteVersion']);
        self::assertSame(1, $result['AEStable']);
        self::assertSame(220, $result['AETarget']);
        self::assertSame(199, $result['AEAverage']);
        self::assertSame(4, $result['HDRImageType']);
        self::assertSame(1, $result['CameraType']);
    }

    /**
     * Integration test: a synthetic IFD payload flows through AppleDecoder
     * and produces a valid AppleMakerNotes with correctly populated sections.
     */
    #[Test]
    public function decoderProducesAppleMakerNotesFromIfdPayload(): void
    {
        // Build a payload with ContentIdentifier (ASCII, out-of-line), CameraType, AEStable,
        // AETarget, AEAverage, HDRImageType
        $contentId = "photo-uuid-123\x00";
        $extraData = $contentId;

        // After 6 entries: sig(10)+ver(2)+BO(2)+count(2)+6*entry(72)+nextIFD(4) = 92
        // Offsets are relative to position 0
        $payload = $this->buildPayload(true, [
            ['tag' => 0x0004, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 1)],
            ['tag' => 0x0005, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 200)],
            ['tag' => 0x0006, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 180)],
            ['tag' => 0x000A, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 3)],
            ['tag' => 0x0011, 'type' => self::TIFF_TYPE_ASCII, 'count' => strlen($contentId), 'value' => $this->offsetField(true, 92)],
            ['tag' => 0x002E, 'type' => self::TIFF_TYPE_SLONG, 'count' => 1, 'value' => $this->inlineSLong(true, 0)],
        ], $extraData);

        $decoder = new AppleDecoder();
        $record  = $decoder->decode($payload, 'Apple', 'iPhone 15');

        self::assertSame('Apple', $record->vendor);
        self::assertSame(strlen($payload), $record->length);
        self::assertInstanceOf(AppleMakerNotes::class, $record->apple);

        $apple = $record->apple;
        self::assertSame('photo-uuid-123', $apple->identity?->contentIdentifier);
        self::assertSame('Back Wide Angle', $apple->camera?->type);
        self::assertTrue($apple->autoExposure?->stable);
        self::assertEqualsWithDelta(200.0, $apple->autoExposure->target, 1e-12);
        self::assertEqualsWithDelta(180.0, $apple->autoExposure->average, 1e-12);
        self::assertSame('HDR Image', $apple->hdr?->imageType);
    }

    /**
     * Integration test: text plist payloads still work when no IFD signature is present.
     */
    #[Test]
    public function decoderFallsThroughToTextPlistWhenIfdAbsent(): void
    {
        $raw     = '{ ContentIdentifier = "plist-uuid"; }';
        $decoder = new AppleDecoder();
        $record  = $decoder->decode($raw, 'Apple', 'iPhone');

        self::assertInstanceOf(AppleMakerNotes::class, $record->apple);
        self::assertSame('plist-uuid', $record->apple->identity?->contentIdentifier);
    }
}
