<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;

/**
 * Edge case tests for UserComment encoding and special EXIF fields.
 *
 * EXIF 3.0 §4.6.5 (Table 11) and EXIF 2.32 §4.6.5 define the UserComment tag
 * structure with character code prefixes ("ASCII\0\0\0", "JIS\0\0\0\0\0", etc.).
 */
#[CoversClass(TiffExifReader::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(Endian::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
final class TiffExifReaderUserCommentTest extends TestCase
{
    /**
     * Tests UserComment with ASCII encoding prefix.
     *
     * EXIF 3.0 §4.6.5 Table 11 defines "ASCII\0\0\0" as the ASCII character code.
     */
    #[Test]
    public function parsesUserCommentWithAsciiEncoding(): void
    {
        $comment = "ASCII\0\0\0Hello World";
        $blob = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Tests UserComment with JIS encoding prefix.
     *
     * EXIF 3.0 §4.6.5 Table 11 defines "JIS\0\0\0\0\0" for JIS character code.
     */
    #[Test]
    public function parsesUserCommentWithJisEncoding(): void
    {
        $comment = "JIS\0\0\0\0\0Some text";
        $blob = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Tests UserComment with UNICODE encoding prefix.
     *
     * EXIF 3.0 §4.6.5 Table 11 defines "UNICODE\0" for Unicode character code.
     */
    #[Test]
    public function parsesUserCommentWithUnicodeEncoding(): void
    {
        $comment = "UNICODE\0Test";
        $blob = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Tests UserComment with undefined encoding (no prefix).
     *
     * EXIF 3.0 §4.6.5 allows undefined character codes (8 bytes of 0x00).
     */
    #[Test]
    public function parsesUserCommentWithUndefinedEncoding(): void
    {
        $comment = "\0\0\0\0\0\0\0\0Plain text";
        $blob = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Tests UserComment with truncated encoding prefix.
     */
    #[Test]
    public function handlesUserCommentTruncatedPrefix(): void
    {
        // Only partial encoding prefix (should still parse)
        $comment = "ASC";  // Truncated "ASCII\0\0\0"
        $blob = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Tests UserComment with invalid encoding marker.
     */
    #[Test]
    public function handlesUserCommentInvalidEncoding(): void
    {
        $comment = "INVALID\0Data";
        $blob = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Tests UserComment that is empty.
     */
    #[Test]
    public function handlesEmptyUserComment(): void
    {
        $blob = $this->buildTiffWithUserComment('');

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Tests UserComment with only the encoding prefix and no actual comment.
     */
    #[Test]
    public function handlesUserCommentEncodingOnly(): void
    {
        $comment = "ASCII\0\0\0";
        $blob = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Tests UserComment with non-printable characters.
     */
    #[Test]
    public function handlesUserCommentWithNonPrintable(): void
    {
        $comment = "ASCII\0\0\0\x01\x02\x03\x04\x05";
        $blob = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Tests UserComment with maximum length data.
     */
    #[Test]
    public function handlesUserCommentMaxLength(): void
    {
        // Create a large comment (but within reasonable bounds)
        $comment = "ASCII\0\0\0" . str_repeat('A', 1000);
        $blob = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Tests MakerNote field handling.
     *
     * EXIF 3.0 §4.6.5 (Table 4) defines the MakerNote tag for manufacturer-specific data.
     */
    #[Test]
    public function parsesMakerNote(): void
    {
        $makerNoteData = "Canon\0\0\0Some proprietary data";
        $blob = $this->buildTiffWithMakerNote($makerNoteData);

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Tests empty MakerNote.
     */
    #[Test]
    public function handlesEmptyMakerNote(): void
    {
        $blob = $this->buildTiffWithMakerNote('');

        $reader = new TiffExifReader();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Builds a TIFF blob with IFD0 and ExifIFD containing a UserComment entry.
     *
     * @param string $commentData The UserComment data including encoding prefix.
     *
     * @return string Binary TIFF blob.
     */
    private function buildTiffWithUserComment(string $commentData): string
    {
        // TIFF header
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);  // IFD0 at offset 8

        // IFD0 with ExifIFDPointer
        $blob .= pack('v', 1);  // 1 entry

        $blob .= pack('v', 0x8769)                    // ExifIFDPointer
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', 26);                          // ExifIFD at offset 26

        $blob .= pack('V', 0);  // Next IFD

        // ExifIFD at offset 26
        $exifIfdStart = strlen($blob);
        $blob .= pack('v', 1);  // 1 entry

        $userCommentOffset = $exifIfdStart + 14;  // After IFD entry + next offset

        $blob .= pack('v', 0x9286)                          // UserComment tag
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($commentData))
            . pack('V', $userCommentOffset);

        $blob .= pack('V', 0);  // Next IFD

        // UserComment data
        $blob .= $commentData;

        return $blob;
    }

    /**
     * Builds a TIFF blob with IFD0 and ExifIFD containing a MakerNote entry.
     *
     * @param string $makerNoteData The MakerNote data.
     *
     * @return string Binary TIFF blob.
     */
    private function buildTiffWithMakerNote(string $makerNoteData): string
    {
        // TIFF header
        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        // IFD0 with ExifIFDPointer
        $blob .= pack('v', 1);

        $blob .= pack('v', 0x8769)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', 26);

        $blob .= pack('V', 0);

        // ExifIFD at offset 26
        $exifIfdStart = strlen($blob);
        $blob .= pack('v', 1);

        $makerNoteOffset = $exifIfdStart + 14;

        $blob .= pack('v', 0x927C)                          // MakerNote tag
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($makerNoteData))
            . pack('V', $makerNoteOffset);

        $blob .= pack('V', 0);

        // MakerNote data
        $blob .= $makerNoteData;

        return $blob;
    }
}
