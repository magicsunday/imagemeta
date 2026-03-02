<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Model\Microsoft\MicrosoftTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function iconv;

/**
 * Exercises ParsedExif handling of Windows XP tags (XPTitle, XPComment, XPAuthor, XPKeywords, XPSubject).
 * These tags store UCS-2 (UTF-16LE) encoded text as BYTE arrays without a BOM.
 * The suite verifies correct UTF-16LE to UTF-8 decoding through the ParsedExif accessors.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
final class ParsedExifWindowsXpTagsTest extends TestCase
{
    /**
     * Decodes an ASCII string stored as UTF-16LE with a NUL terminator.
     */
    #[Test]
    public function xpTitleDecodesAsciiUtf16Le(): void
    {
        $parsedExif = $this->parsedExifFromIfd0Entries([
            MicrosoftTag::XP_TITLE => new IfdEntry(MicrosoftTag::XP_TITLE, 1, 12, $this->toUtf16Le('Hello') . "\x00\x00"),
        ]);

        self::assertSame('Hello', $parsedExif->xpTitle());
    }

    /**
     * Decodes non-ASCII characters (German umlauts) stored as UTF-16LE.
     */
    #[Test]
    public function xpTitleDecodesNonAsciiUtf16Le(): void
    {
        $text       = 'Ünïcödé';
        $parsedExif = $this->parsedExifFromIfd0Entries([
            MicrosoftTag::XP_TITLE => new IfdEntry(MicrosoftTag::XP_TITLE, 1, 16, $this->toUtf16Le($text) . "\x00\x00"),
        ]);

        self::assertSame($text, $parsedExif->xpTitle());
    }

    /**
     * Returns null when the XP_COMMENT tag is missing from IFD0.
     */
    #[Test]
    public function xpCommentReturnsNullWhenMissing(): void
    {
        $parsedExif = $this->parsedExifFromIfd0Entries([]);

        self::assertNull($parsedExif->xpComment());
    }

    /**
     * Strips trailing NUL terminator from UTF-16LE keywords.
     */
    #[Test]
    public function xpKeywordsDecodesWithNullTerminator(): void
    {
        $text       = 'sunset;beach';
        $parsedExif = $this->parsedExifFromIfd0Entries([
            MicrosoftTag::XP_KEYWORDS => new IfdEntry(MicrosoftTag::XP_KEYWORDS, 1, 26, $this->toUtf16Le($text) . "\x00\x00"),
        ]);

        self::assertSame($text, $parsedExif->xpKeywords());
    }

    /**
     * Returns null for an all-NUL payload (empty string encoded as UTF-16LE).
     */
    #[Test]
    public function xpAuthorReturnsNullForEmptyPayload(): void
    {
        $parsedExif = $this->parsedExifFromIfd0Entries([
            MicrosoftTag::XP_AUTHOR => new IfdEntry(MicrosoftTag::XP_AUTHOR, 1, 2, "\x00\x00"),
        ]);

        self::assertNull($parsedExif->xpAuthor());
    }

    /**
     * All five XP tags decode correctly when present simultaneously.
     */
    #[Test]
    public function allFiveXpTagsDecodeCorrectly(): void
    {
        $parsedExif = $this->parsedExifFromIfd0Entries([
            MicrosoftTag::XP_TITLE    => new IfdEntry(MicrosoftTag::XP_TITLE, 1, 12, $this->toUtf16Le('Title') . "\x00\x00"),
            MicrosoftTag::XP_COMMENT  => new IfdEntry(MicrosoftTag::XP_COMMENT, 1, 16, $this->toUtf16Le('Comment') . "\x00\x00"),
            MicrosoftTag::XP_AUTHOR   => new IfdEntry(MicrosoftTag::XP_AUTHOR, 1, 14, $this->toUtf16Le('Author') . "\x00\x00"),
            MicrosoftTag::XP_KEYWORDS => new IfdEntry(MicrosoftTag::XP_KEYWORDS, 1, 18, $this->toUtf16Le('Keywords') . "\x00\x00"),
            MicrosoftTag::XP_SUBJECT  => new IfdEntry(MicrosoftTag::XP_SUBJECT, 1, 16, $this->toUtf16Le('Subject') . "\x00\x00"),
        ]);

        self::assertSame('Title', $parsedExif->xpTitle());
        self::assertSame('Comment', $parsedExif->xpComment());
        self::assertSame('Author', $parsedExif->xpAuthor());
        self::assertSame('Keywords', $parsedExif->xpKeywords());
        self::assertSame('Subject', $parsedExif->xpSubject());
    }

    /**
     * Returns null when XP_SUBJECT has an odd-length byte payload.
     */
    #[Test]
    public function xpSubjectReturnsNullForOddLengthPayload(): void
    {
        $parsedExif = $this->parsedExifFromIfd0Entries([
            MicrosoftTag::XP_SUBJECT => new IfdEntry(MicrosoftTag::XP_SUBJECT, 1, 3, 'abc'),
        ]);

        self::assertNull($parsedExif->xpSubject());
    }

    /**
     * Converts a UTF-8 string to UTF-16LE for test fixture construction.
     */
    private function toUtf16Le(string $utf8): string
    {
        $result = iconv('UTF-8', 'UTF-16LE', $utf8);
        self::assertIsString($result);

        return $result;
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     */
    private function parsedExifFromIfd0Entries(array $ifd0Entries): ParsedExif
    {
        return new ParsedExif(new Ifd($ifd0Entries), null, null, null, null);
    }
}
