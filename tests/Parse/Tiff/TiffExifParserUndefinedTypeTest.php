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
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
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
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(ExifTag::class)]
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
