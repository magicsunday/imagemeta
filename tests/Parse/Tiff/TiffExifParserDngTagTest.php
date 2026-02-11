<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;

/**
 * Verifies parsing and exposure of baseline DNG tags from TIFF/EXIF payloads.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(DngTag::class)]
#[UsesClass(ExifTag::class)]
#[UsesClass(TiffTag::class)]
#[UsesClass(MakerNotesRecord::class)]
final class TiffExifParserDngTagTest extends TestCase
{
    /**
     * Parses DNG core tags from IFD0 and exposes them through ParsedExif accessors.
     *
     * @return void
     */
    #[Test]
    public function parsesCoreDngTagsFromIfd0(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($this->buildClassicDngTiff());

        self::assertSame('1.7.1.0', $parsed->dngVersion());
        self::assertSame('1.4.0.0', $parsed->dngBackwardVersion());
        self::assertSame('MagicSunday Camera', $parsed->uniqueCameraModel());
    }

    /**
     * Returns null for DNG accessors when corresponding tags are not present.
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenCoreDngTagsAreMissing(): void
    {
        $parser = new TiffExifParser();
        // IFD0 with a single non-DNG tag (ImageWidth=1)
        $parsed = $parser->parseFromBlob(
            'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 1)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', 1)
            . pack('V', 0),
        );

        self::assertNull($parsed->dngVersion());
        self::assertNull($parsed->dngBackwardVersion());
        self::assertNull($parsed->uniqueCameraModel());
    }

    /**
     * DNG 1.7.1.0: LocalizedCameraModel may use BYTE type instead of ASCII.
     * When stored as BYTE, the parser decodes the raw bytes as a NUL-terminated
     * UTF-8 string rather than a numeric list.
     *
     * @return void
     */
    #[Test]
    public function decodesLocalizedCameraModelByteAsUtf8String(): void
    {
        $model     = "Camera Model\0";
        $ifdOffset = 8;
        $ifdSize   = 2 + 12 + 4;
        $valOffset = $ifdOffset + $ifdSize;

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', 1)
            . pack('v', DngTag::LOCALIZED_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', strlen($model))
            . pack('V', $valOffset)
            . pack('V', 0)
            . $model;

        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($blob);

        $entry = $parsed->ifd0->get(DngTag::LOCALIZED_CAMERA_MODEL);
        self::assertNotNull($entry);
        self::assertSame('Camera Model', $entry->value);
    }

    /**
     * Valid DNGPrivateData with NUL-terminated ASCII prefix parses successfully.
     */
    #[Test]
    public function parsesValidDngPrivateData(): void
    {
        $privateData = "Adobe\0\x01\x02\x03\x04";
        $blob        = $this->buildTiffWithDngPrivateData($privateData);

        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($blob);

        $entry = $parsed->ifd0->get(DngTag::DNG_PRIVATE_DATA);
        self::assertNotNull($entry);
    }

    /**
     * DNGPrivateData without NUL terminator is rejected.
     */
    #[Test]
    public function rejectDngPrivateDataWithoutNulTerminator(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DNGPrivateData block must start with a NUL-terminated ASCII string per DNG 1.7.1.0.');

        $privateData = 'AdobeNoNul';
        $blob        = $this->buildTiffWithDngPrivateData($privateData);

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * DNGPrivateData with non-ASCII byte in prefix is rejected.
     */
    #[Test]
    public function rejectDngPrivateDataWithNonAsciiPrefix(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DNGPrivateData manufacturer name contains non-ASCII byte 0x80 at offset 5 per DNG 1.7.1.0.');

        $privateData = "Adobe\x80\0\x01\x02";
        $blob        = $this->buildTiffWithDngPrivateData($privateData);

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * DNGPrivateData with empty manufacturer name is rejected.
     */
    #[Test]
    public function rejectDngPrivateDataWithEmptyManufacturerName(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DNGPrivateData manufacturer name must not be empty per DNG 1.7.1.0.');

        $privateData = "\0\x01\x02\x03\x04";
        $blob        = $this->buildTiffWithDngPrivateData($privateData);

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * MakerNoteSafety=1 is exposed as safe=true on the MakerNotesRecord.
     */
    #[Test]
    public function exposesMakerNoteSafetyTrue(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($this->buildTiffWithMakerNoteSafety(1));

        self::assertNotNull($parsed->makerNotes());
        self::assertTrue($parsed->makerNotes()->safe);
    }

    /**
     * MakerNoteSafety=0 is exposed as safe=false on the MakerNotesRecord.
     */
    #[Test]
    public function exposesMakerNoteSafetyFalse(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($this->buildTiffWithMakerNoteSafety(0));

        self::assertNotNull($parsed->makerNotes());
        self::assertFalse($parsed->makerNotes()->safe);
    }

    /**
     * MakerNoteSafety value outside {0, 1} triggers a ParseError.
     */
    #[Test]
    public function rejectInvalidMakerNoteSafetyDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('MakerNoteSafety value 2 is outside the valid domain {0, 1} per DNG 1.7.1.0.');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithMakerNoteSafety(2));
    }

    /**
     * Builds a classic TIFF payload with required baseline DNG tags in IFD0.
     *
     * DNG 1.7.1.0 (DNG Tags, pp. 24-25) defines DNGVersion and
     * DNGBackwardVersion as BYTE[4], and UniqueCameraModel as ASCII.
     */
    private function buildClassicDngTiff(): string
    {
        $ifdOffset         = 8;
        $entryCount        = 3;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'MagicSunday Camera');
        $modelOffset       = $ifdOffset + $ifdSize;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('V', 0x00010701)
            . pack('v', DngTag::DNG_BACKWARD_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('V', 0x00000401)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('V', 0)
            . $uniqueCameraModel;
    }

    /**
     * Builds a classic TIFF payload with a single DNGPrivateData tag in IFD0.
     */
    private function buildTiffWithDngPrivateData(string $privateData): string
    {
        $ifdOffset = 8;
        $ifdSize   = 2 + 12 + 4;
        $valOffset = $ifdOffset + $ifdSize;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', 1)
            . pack('v', DngTag::DNG_PRIVATE_DATA)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', strlen($privateData))
            . pack('V', $valOffset)
            . pack('V', 0)
            . $privateData;
    }

    /**
     * Builds a TIFF with IFD0 containing EXIF_IFD_POINTER + MakerNoteSafety,
     * and an EXIF IFD containing a minimal MakerNote.
     */
    /**
     * Enhanced IFD with valid EnhanceParams parses without error.
     */
    #[Test]
    public function parsesEnhancedIfdWithValidEnhanceParams(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($this->buildTiffWithEnhancedIfd("Adobe Enhance\0"));

        $entry = $parsed->ifd0->get(DngTag::ENHANCE_PARAMS);
        self::assertNotNull($entry);
        self::assertSame('Adobe Enhance', $entry->value);
    }

    /**
     * Enhanced IFD without EnhanceParams triggers a ParseError.
     */
    #[Test]
    public function rejectEnhancedIfdMissingEnhanceParams(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Enhanced IFD (NewSubfileType bit 4) requires an EnhanceParams tag per DNG 1.5.');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithEnhancedIfd(null));
    }

    /**
     * Enhanced IFD with empty EnhanceParams triggers a ParseError.
     */
    #[Test]
    public function rejectEnhancedIfdWithEmptyEnhanceParams(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('EnhanceParams must not be empty for an Enhanced IFD per DNG 1.5.');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithEnhancedIfd("\0"));
    }

    /**
     * Builds a TIFF with IFD0 containing NewSubfileType=16 (enhanced) and
     * optionally an EnhanceParams tag.
     */
    private function buildTiffWithEnhancedIfd(?string $enhanceParams): string
    {
        $ifdOffset  = 8;
        $entryCount = $enhanceParams !== null ? 2 : 1;
        $ifdSize    = 2 + (12 * $entryCount) + 4;
        $valOffset  = $ifdOffset + $ifdSize;

        // NewSubfileType: LONG, count=1, value=16 (enhanced) — fits inline
        $ifdData = pack('v', $entryCount)
            . pack('v', TiffTag::NEW_SUBFILE_TYPE)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', 16);

        $outOfLine = '';

        if ($enhanceParams !== null) {
            $len = strlen($enhanceParams);

            $ifdData .= pack('v', DngTag::ENHANCE_PARAMS)
                . pack('v', TiffConst::TYPE_ASCII)
                . pack('V', $len);

            if ($len <= 4) {
                // Inline: pad value to 4 bytes
                $ifdData .= str_pad($enhanceParams, 4, "\0");
            } else {
                $ifdData .= pack('V', $valOffset);
                $outOfLine = $enhanceParams;
            }
        }

        $ifdData .= pack('V', 0); // next IFD offset

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData
            . $outOfLine;
    }

    private function buildTiffWithMakerNoteSafety(int $safetyValue): string
    {
        // Layout: header(8) + IFD0(2 + 2*12 + 4 = 30) + EXIF IFD(2 + 12 + 4 = 18)
        $ifd0Offset     = 8;
        $ifd0EntryCount = 2;
        $ifd0Size       = 2 + (12 * $ifd0EntryCount) + 4;
        $exifIfdOffset  = $ifd0Offset + $ifd0Size;

        // IFD0: ExifIfdPointer (0x8769) < MakerNoteSafety (0xC635) — ascending tag order
        $ifd0 = pack('v', $ifd0EntryCount)
            // ExifIfdPointer: LONG, count=1, value=offset to EXIF IFD
            . pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $exifIfdOffset)
            // MakerNoteSafety: SHORT, count=1, value inline
            . pack('v', DngTag::MAKER_NOTE_SAFETY)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', $safetyValue) . pack('v', 0)
            // next IFD offset
            . pack('V', 0);

        // EXIF IFD: MakerNote with 4 bytes inline (UNDEFINED type)
        $exifIfd = pack('v', 1)
            . pack('v', ExifTag::MAKER_NOTE)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', 4)
            . pack('a4', 'TEST')
            . pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $ifd0
            . $exifIfd;
    }
}
