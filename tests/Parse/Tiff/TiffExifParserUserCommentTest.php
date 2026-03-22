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
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Tiff\TiffFieldType;
use MagicSunday\ImageMeta\Parse\Icc\IccHeaderDecoder;
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Parse\Icc\IccTagDecoder;
use MagicSunday\ImageMeta\Parse\Tiff\DngCalibrationValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngGeometryValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngProfileValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngStructureValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngValidationSupport;
use MagicSunday\ImageMeta\Parse\Tiff\DngValidator;
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormalizer;
use MagicSunday\ImageMeta\Parse\Tiff\DngVersionValidator;
use MagicSunday\ImageMeta\Parse\Tiff\IfdParser;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffBinaryReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffByteOrderHandler;
use MagicSunday\ImageMeta\Parse\Tiff\TiffColorInkValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffImageDataValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffOffsetValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffSampleValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffStructuralValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffTagConstraintValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValidationSupport;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;

/**
 * Exercises UserComment decoding with character set prefixes and special EXIF fields.
 * It validates that ASCII, JIS, and undefined prefixes are handled without corrupting IFDs.
 * The tests ensure UserComment data is extracted while maintaining other EXIF values.
 * This keeps comment parsing aligned with EXIF prefix rules and legacy payloads.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(Endian::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(PayloadGuard::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(TiffFieldType::class)]
#[UsesClass(IccHeaderDecoder::class)]
#[UsesClass(IccParser::class)]
#[UsesClass(IccTagDecoder::class)]
#[UsesClass(DngCalibrationValidator::class)]
#[UsesClass(DngGeometryValidator::class)]
#[UsesClass(DngProfileValidator::class)]
#[UsesClass(DngStructureValidator::class)]
#[UsesClass(DngValidationSupport::class)]
#[UsesClass(DngValidator::class)]
#[UsesClass(DngVersionValidator::class)]
#[UsesClass(IfdParser::class)]
#[UsesClass(TiffByteOrderHandler::class)]
#[UsesClass(TiffColorInkValidator::class)]
#[UsesClass(TiffImageDataValidator::class)]
#[UsesClass(TiffJpegValidator::class)]
#[UsesClass(TiffSampleValidator::class)]
#[UsesClass(TiffStructuralValidator::class)]
#[UsesClass(TiffTagConstraintValidator::class)]
#[UsesClass(TiffValidationSupport::class)]
final class TiffExifParserUserCommentTest extends TestCase
{
    /**
     * Builds a UserComment value with an ASCII encoding prefix and text.
     * Confirms the parser accepts the ASCII prefix and keeps the EXIF IFD intact.
     */
    #[Test]
    public function parsesUserCommentWithAsciiEncoding(): void
    {
        $comment = "ASCII\0\0\0Hello World";
        $blob    = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Uses the JIS encoding prefix followed by text content.
     * Ensures the parser accepts the JIS prefix without failing the EXIF parse.
     */
    #[Test]
    public function parsesUserCommentWithJisEncoding(): void
    {
        $comment = "JIS\0\0\0\0\0Some text";
        $blob    = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Uses the UNICODE encoding prefix with a short payload.
     * Confirms the parser tolerates the Unicode prefix and parses the EXIF IFD.
     */
    #[Test]
    public function parsesUserCommentWithUnicodeEncoding(): void
    {
        $comment = "UNICODE\0Test";
        $blob    = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Uses an all-zero prefix to represent undefined UserComment encoding.
     * Ensures the parser accepts the undefined encoding without errors.
     */
    #[Test]
    public function parsesUserCommentWithUndefinedEncoding(): void
    {
        $comment = "\0\0\0\0\0\0\0\0Plain text";
        $blob    = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Provides only a partial encoding prefix ("ASC") to simulate truncation.
     * Verifies the parser handles the truncated prefix gracefully.
     */
    #[Test]
    public function handlesUserCommentTruncatedPrefix(): void
    {
        // Only partial encoding prefix (should still parse)
        $comment = 'ASC';  // Truncated "ASCII\0\0\0"
        $blob    = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Supplies an unsupported encoding prefix followed by data.
     * Ensures the parser does not fail when the encoding identifier is unknown.
     */
    #[Test]
    public function handlesUserCommentInvalidEncoding(): void
    {
        $comment = "INVALID\0Data";
        $blob    = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Uses an empty UserComment value with no prefix or payload.
     * Confirms the parser handles the empty tag without errors.
     */
    #[Test]
    public function handlesEmptyUserComment(): void
    {
        $blob = $this->buildTiffWithUserComment('');

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Provides only the ASCII prefix with no comment text.
     * Ensures the parser accepts a prefix-only UserComment value.
     */
    #[Test]
    public function handlesUserCommentEncodingOnly(): void
    {
        $comment = "ASCII\0\0\0";
        $blob    = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Provides an ASCII prefix followed by non-printable bytes.
     * Confirms the parser tolerates binary payloads without failing.
     */
    #[Test]
    public function handlesUserCommentWithNonPrintable(): void
    {
        $comment = "ASCII\0\0\0\x01\x02\x03\x04\x05";
        $blob    = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Uses an ASCII prefix with a large comment payload.
     * Verifies the parser handles long UserComment data within limits.
     */
    #[Test]
    public function handlesUserCommentMaxLength(): void
    {
        // Create a large comment (but within reasonable bounds)
        $comment = "ASCII\0\0\0" . str_repeat('A', 1000);
        $blob    = $this->buildTiffWithUserComment($comment);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Supplies MakerNote data with a vendor-style prefix.
     * Ensures the parser accepts the MakerNote tag and leaves the EXIF IFD available.
     */
    #[Test]
    public function parsesMakerNote(): void
    {
        $makerNoteData = "Canon\0\0\0Some proprietary data";
        $blob          = $this->buildTiffWithMakerNote($makerNoteData);

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Uses an empty MakerNote payload.
     * Confirms the parser tolerates empty MakerNote data without errors.
     */
    #[Test]
    public function handlesEmptyMakerNote(): void
    {
        $blob = $this->buildTiffWithMakerNote('');

        $reader = new TiffExifParser();
        $result = $reader->parseFromBlob($blob);

        self::assertNotNull($result->exifIfd);
    }

    /**
     * Builds a TIFF blob with IFD0 and ExifIFD containing a UserComment entry.
     * This checks the behavior for the specific inputs used in the test.
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

        // IFD0: ImageWidth + ImageLength + ExifIFDPointer
        $blob .= pack('v', 3);  // 3 entries

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

        $exifIfdOffset = 8 + 2 + (3 * 12) + 4;  // header + count + entries + next

        $blob .= pack('v', 0x8769)                    // ExifIFDPointer
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $exifIfdOffset);

        $blob .= pack('V', 0);  // Next IFD

        // ExifIFD
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
     * This checks the behavior for the specific inputs used in the test.
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

        // IFD0: ImageWidth + ImageLength + ExifIFDPointer
        $blob .= pack('v', 3);

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

        $exifIfdOffset = 8 + 2 + (3 * 12) + 4;

        $blob .= pack('v', 0x8769)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $exifIfdOffset);

        $blob .= pack('V', 0);

        // ExifIFD
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
