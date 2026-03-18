<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormalizer;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffBinaryReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffOffsetValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;

/**
 * Verifies that TIFF UNDEFINED values remain byte-exact at parser level.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(ExifTag::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserUndefinedTypeTest extends TestCase
{
    /**
     * TIFF 6.0 §2.2 defines UNDEFINED as opaque bytes.
     * Printable payloads must therefore not be trimmed or coerced to text.
     */
    #[Test]
    public function keepsPrintableUndefinedPayloadByteExact(): void
    {
        $payload = "ASCII\x00\x00";
        $parsed  = $this->parseWithExifUndefinedTag(ExifTag::MAKER_NOTE, $payload);

        $entry = $parsed->exifIfd?->get(ExifTag::MAKER_NOTE);
        self::assertNotNull($entry);
        self::assertSame($payload, $entry->value);
    }

    /**
     * Embedded and trailing NUL bytes must be preserved exactly for UNDEFINED.
     */
    #[Test]
    public function keepsEmbeddedNulUndefinedPayloadByteExact(): void
    {
        $payload = "\x41\x00\x42\x00\x43\x00";
        $parsed  = $this->parseWithExifUndefinedTag(ExifTag::MAKER_NOTE, $payload);

        $entry = $parsed->exifIfd?->get(ExifTag::MAKER_NOTE);
        self::assertNotNull($entry);
        self::assertSame($payload, $entry->value);
    }

    /**
     * Mixed binary UNDEFINED payloads must remain byte-exact.
     */
    #[Test]
    public function keepsMixedBinaryUndefinedPayloadByteExact(): void
    {
        $payload = "\xFF\x00\x7F\x80\x41\x00\xFE";
        $parsed  = $this->parseWithExifUndefinedTag(ExifTag::MAKER_NOTE, $payload);

        $entry = $parsed->exifIfd?->get(ExifTag::MAKER_NOTE);
        self::assertNotNull($entry);
        self::assertSame($payload, $entry->value);
    }

    /**
     * Tag-specific UNDEFINED converters remain responsible for textual interpretation.
     *
     * EXIF 3.0 §4.6.4 defines UserComment with an 8-byte character-code prefix.
     */
    #[Test]
    public function keepsTagSpecificUndefinedConvertersWorking(): void
    {
        $payload = pack('H*', '415343494900000048656C6C6F20576F726C6400');
        $parsed  = $this->parseWithExifUndefinedTag(ExifTag::USER_COMMENT, $payload);

        $entry = $parsed->exifIfd?->get(ExifTag::USER_COMMENT);
        self::assertNotNull($entry);
        self::assertSame($payload, $entry->value);
        self::assertSame('Hello World', $parsed->userComment());
    }

    /**
     * GPS undefined-text decoding remains functional when parser keeps raw UNDEFINED bytes.
     */
    #[Test]
    public function keepsGpsUndefinedConvertersWorkingWithRawBytes(): void
    {
        $payload = "ASCII\0\0\0GPS";
        $parsed  = $this->parseWithGpsUndefinedTag(ExifTag::GPS_PROCESSING_METHOD, $payload);

        $entry = $parsed->gpsIfd?->get(ExifTag::GPS_PROCESSING_METHOD);
        self::assertNotNull($entry);
        self::assertSame($payload, $entry->value);
        self::assertSame('GPS', $parsed->gps()['processing_method']);
    }

    /**
     * Non-UNDEFINED decoding paths remain unchanged.
     */
    #[Test]
    public function keepsAsciiDecodingUnchangedForNonUndefinedTypes(): void
    {
        $parsed = $this->parseWithIfd0AsciiTag(ExifTag::MAKE, 'Canon');

        $entry = $parsed->ifd0->get(ExifTag::MAKE);
        self::assertNotNull($entry);
        self::assertSame('Canon', $entry->value);
    }

    /**
     * Builds and parses a minimal classic TIFF with one GPS IFD UNDEFINED entry.
     *
     * @param int    $tag     GPS tag stored in GPS IFD.
     * @param string $payload Raw UNDEFINED payload.
     */
    private function parseWithGpsUndefinedTag(int $tag, string $payload): ParsedExif
    {
        $ifd0Offset      = 8;
        $ifd0EntryCount  = 3;
        $ifd0Size        = 2 + ($ifd0EntryCount * 12) + 4;
        $gpsIfdOffset    = $ifd0Offset + $ifd0Size;
        $gpsEntryCount   = 1;
        $gpsIfdSize      = 2 + ($gpsEntryCount * 12) + 4;
        $payloadOffset   = $gpsIfdOffset + $gpsIfdSize;
        $payloadByteSize = strlen($payload);

        $ifd0 = pack('v', $ifd0EntryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::GPS_IFD_POINTER)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $gpsIfdOffset)
            . pack('V', 0);

        $gpsIfd = pack('v', $gpsEntryCount)
            . pack('v', $tag)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', $payloadByteSize)
            . pack('V', $payloadOffset)
            . pack('V', 0);

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $ifd0
            . $gpsIfd
            . $payload;

        return (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Builds and parses a minimal classic TIFF with one IFD0 ASCII entry.
     *
     * @param int    $tag   IFD0 tag stored as ASCII.
     * @param string $value ASCII text without trailing NUL.
     */
    private function parseWithIfd0AsciiTag(int $tag, string $value): ParsedExif
    {
        $ifd0Offset     = 8;
        $ifd0EntryCount = 3;
        $ifd0Size       = 2 + ($ifd0EntryCount * 12) + 4;
        $asciiPayload   = $value . "\0";
        $payloadOffset  = $ifd0Offset + $ifd0Size;

        $ifd0 = pack('v', $ifd0EntryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', $tag)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($asciiPayload))
            . pack('V', $payloadOffset)
            . pack('V', 0);

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $ifd0
            . $asciiPayload;

        return (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Builds and parses a minimal classic TIFF with one EXIF IFD UNDEFINED entry.
     *
     * @param int    $tag     EXIF tag stored in ExifIFD.
     * @param string $payload Raw UNDEFINED payload.
     */
    private function parseWithExifUndefinedTag(int $tag, string $payload): ParsedExif
    {
        $ifd0Offset      = 8;
        $ifd0EntryCount  = 3;
        $ifd0Size        = 2 + ($ifd0EntryCount * 12) + 4;
        $exifIfdOffset   = $ifd0Offset + $ifd0Size;
        $exifEntryCount  = 1;
        $exifIfdSize     = 2 + ($exifEntryCount * 12) + 4;
        $payloadOffset   = $exifIfdOffset + $exifIfdSize;
        $payloadByteSize = strlen($payload);

        $ifd0 = pack('v', $ifd0EntryCount)
            // ImageWidth SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            // ImageLength SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            // ExifIFD pointer
            . pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $exifIfdOffset)
            . pack('V', 0);

        $exifIfd = pack('v', $exifEntryCount)
            . pack('v', $tag)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', $payloadByteSize)
            . pack('V', $payloadOffset)
            . pack('V', 0);

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $ifd0
            . $exifIfd
            . $payload;

        return (new TiffExifParser())->parseFromBlob($blob);
    }
}
