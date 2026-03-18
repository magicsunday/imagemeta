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
use MagicSunday\ImageMeta\Model\Tiff\TiffItTag;
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
use function str_repeat;
use function strlen;

/**
 * Exercises ICC profile extraction from TIFF IFD0 tag 34675 (0x8773).
 *
 * TIFF 6.0 §Appendix (TIFF/IT) and ICC.1 define tag 34675 as the standard
 * mechanism for embedding ICC color profiles in TIFF files. The parser must
 * capture the raw ICC binary and expose it via ParsedExif.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(TiffItTag::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserIccProfileTest extends TestCase
{
    /**
     * Tag 34675 (0x8773) with a valid ICC profile payload is captured as iccProfileRaw.
     *
     * TIFF 6.0 §Appendix (TIFF/IT); ICC.1 — tag 34675, type UNDEFINED.
     */
    #[Test]
    public function extractsIccProfileFromIfd0Tag0x8773(): void
    {
        // Minimal synthetic ICC profile (128-byte header + minimal structure)
        $iccPayload = $this->buildMinimalIccProfile();
        $blob       = $this->buildTiffWithIccProfile($iccPayload);

        $result     = (new TiffExifParser())->parseFromBlob($blob);

        self::assertSame($iccPayload, $result->iccProfileRaw);
    }

    /**
     * When no tag 34675 is present, iccProfileRaw is null.
     */
    #[Test]
    public function returnsNullWhenNoIccProfilePresent(): void
    {
        $blob   = $this->buildMinimalTiffWithoutIcc();

        $result = (new TiffExifParser())->parseFromBlob($blob);

        self::assertNull($result->iccProfileRaw);
    }

    /**
     * Tag 34675 pointing beyond the buffer boundary is tolerated
     * and iccProfileRaw remains null (Postel's Law).
     */
    #[Test]
    public function toleratesTruncatedIccProfileData(): void
    {
        $blob   = $this->buildTiffWithTruncatedIccProfile();

        $result = (new TiffExifParser())->parseFromBlob($blob);

        self::assertNull($result->iccProfileRaw);
    }

    /**
     * Builds a minimal synthetic ICC profile (128-byte header only).
     */
    private function buildMinimalIccProfile(): string
    {
        // ICC.1:2022 §7.2 — 128-byte header
        $header = pack('N', 128)       // Profile size
            . 'appl'                   // CMM type
            . pack('N', 0x02100000)    // Version 2.1.0
            . 'mntr'                   // Profile class (monitor)
            . 'RGB '                   // Color space
            . 'XYZ '                   // PCS
            . str_repeat("\0", 12)     // Date/time
            . 'acsp'                   // Signature
            . str_repeat("\0", 4)      // Primary platform
            . str_repeat("\0", 52);    // Remaining header fields

        return $header;
    }

    /**
     * Builds a classic TIFF with an ICC profile in IFD0 tag 0x8773.
     */
    private function buildTiffWithIccProfile(string $iccPayload): string
    {
        $ifd0EntryCount = 3;
        $ifd0Offset     = 8;
        $ifd0Size       = 2 + ($ifd0EntryCount * 12) + 4;
        $iccOffset      = $ifd0Offset + $ifd0Size;

        $blob           = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset);

        $blob .= pack('v', $ifd0EntryCount);

        // ImageWidth SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // ImageLength SHORT[1] = 100
        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // Tag 0x8773 — ICC Profile, type UNDEFINED, external offset
        $blob .= pack('v', TiffItTag::ICC_PROFILE)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($iccPayload))
            . pack('V', $iccOffset);

        $blob .= pack('V', 0); // Next IFD = 0

        $blob .= $iccPayload;

        return $blob;
    }

    /**
     * Builds a minimal classic TIFF without tag 0x8773.
     */
    private function buildMinimalTiffWithoutIcc(): string
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 2);

        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        return $blob . pack('V', 0);
    }

    /**
     * Builds a TIFF where tag 0x8773 points beyond the buffer end.
     */
    private function buildTiffWithTruncatedIccProfile(): string
    {
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $blob .= pack('v', 3);

        $blob .= pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        $blob .= pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // Tag 0x8773 pointing to offset 9999, count 200
        $blob .= pack('v', TiffItTag::ICC_PROFILE)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', 200)
            . pack('V', 9999);

        return $blob . pack('V', 0);
    }
}
