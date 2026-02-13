<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function iconv;
use function pack;
use function strlen;

/**
 * Exercises the 8-byte character code area enforcement in ParsedExif UserComment.
 * It validates that EXIF 3.0 §4.6.4 is enforced for UNDEFINED text fields.
 * The suite covers missing prefixes, short payloads, and unrecognised encodings.
 * This ensures non-conformant UserComment values are rejected.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifUserCommentPrefixTest extends TestCase
{
    /**
     * Supplies a UserComment shorter than the 8-byte character code area.
     * Confirms that payloads below the minimum length are rejected.
     *
     * @return void
     */
    #[Test]
    public function rejectsUserCommentShorterThanEightBytes(): void
    {
        $parsedExif = $this->parsedExifWithUserComment('Hello');

        self::assertNull($parsedExif->userComment());
        self::assertNull($parsedExif->userCommentEncoding());
    }

    /**
     * Supplies a UserComment with an unrecognised 8-byte prefix.
     * Confirms that an unknown encoding identifier is rejected.
     *
     * @return void
     */
    #[Test]
    public function rejectsUserCommentWithUnknownPrefix(): void
    {
        $raw = "INVALID\0Some text here";

        $parsedExif = $this->parsedExifWithUserComment($raw);

        self::assertNull($parsedExif->userComment());
        self::assertNull($parsedExif->userCommentEncoding());
    }

    /**
     * Supplies a UserComment with only 8 null bytes and no content.
     * Confirms that an empty UNDEFINED-prefix comment returns null.
     *
     * @return void
     */
    #[Test]
    public function returnsNullForUndefinedPrefixWithoutContent(): void
    {
        $raw = "\0\0\0\0\0\0\0\0";

        $parsedExif = $this->parsedExifWithUserComment($raw);

        self::assertNull($parsedExif->userComment());
    }

    /**
     * Supplies a valid ASCII-prefixed UserComment.
     * Confirms the comment text is extracted correctly.
     *
     * @return void
     */
    #[Test]
    public function acceptsValidAsciiPrefixedComment(): void
    {
        $raw = "ASCII\0\0\0Hello World";

        $parsedExif = $this->parsedExifWithUserComment($raw);

        self::assertSame('Hello World', $parsedExif->userComment());
        self::assertSame('ASCII', $parsedExif->userCommentEncoding());
    }

    /**
     * Supplies a valid UNDEFINED (all-NULL) prefix with text content.
     * Confirms the comment text is extracted via the UNDEFINED path.
     *
     * @return void
     */
    #[Test]
    public function acceptsUndefinedPrefixWithContent(): void
    {
        $raw = "\0\0\0\0\0\0\0\0Some content";

        $parsedExif = $this->parsedExifWithUserComment($raw);

        self::assertSame('Some content', $parsedExif->userComment());
        self::assertSame('UNDEFINED', $parsedExif->userCommentEncoding());
    }

    /**
     * Supplies a UNICODE-prefixed UTF-8 UserComment payload.
     * Confirms EXIF 3.0 UTF-8 semantics are applied for the UNICODE marker.
     *
     * @return void
     */
    #[Test]
    public function acceptsUnicodePrefixWithUtf8Content(): void
    {
        $raw = "UNICODE\0測位方式";

        $parsedExif = $this->parsedExifWithUserComment($raw);

        self::assertSame('測位方式', $parsedExif->userComment());
        self::assertSame('UNICODE', $parsedExif->userCommentEncoding());
    }

    /**
     * Supplies a malformed UTF-8 payload with UNICODE marker.
     * Confirms invalid UTF-8 is rejected under EXIF 3.0 semantics.
     *
     * @return void
     */
    #[Test]
    public function rejectsUnicodePrefixWithMalformedUtf8Content(): void
    {
        $raw = "UNICODE\0\xC3\x28";

        $parsedExif = $this->parsedExifWithUserComment($raw);

        self::assertNull($parsedExif->userComment());
        self::assertSame('UNICODE', $parsedExif->userCommentEncoding());
    }

    /**
     * Supplies a legacy UTF-16BE-with-BOM payload under UNICODE marker.
     * Confirms compatibility fallback keeps existing EXIF 2.x ecosystem behavior.
     *
     * @return void
     */
    #[Test]
    public function acceptsUnicodePrefixWithLegacyUtf16BomContent(): void
    {
        $raw = "UNICODE\0\xFE\xFF" . pack('n*', 0x6E2C, 0x4F4D, 0x65B9, 0x5F0F);

        $parsedExif = $this->parsedExifWithUserComment($raw);

        self::assertSame('測位方式', $parsedExif->userComment());
        self::assertSame('UNICODE', $parsedExif->userCommentEncoding());
    }

    /**
     * Supplies a JIS-prefixed comment encoded as ISO-2022-JP.
     * Confirms JIS-marker decoding follows EXIF-aligned JIS strategy.
     *
     * @return void
     */
    #[Test]
    public function acceptsJisPrefixWithIso2022JpContent(): void
    {
        $jisContent = iconv('UTF-8', 'ISO-2022-JP', '東京');
        self::assertIsString($jisContent);

        $raw = "JIS\0\0\0\0\0" . $jisContent;

        $parsedExif = $this->parsedExifWithUserComment($raw);

        self::assertSame('東京', $parsedExif->userComment());
        self::assertSame('JIS', $parsedExif->userCommentEncoding());
    }

    /**
     * Supplies Shift-JIS bytes under a JIS marker.
     * Confirms non-JIS payloads are rejected without silent Shift-JIS fallback.
     *
     * @return void
     */
    #[Test]
    public function rejectsJisPrefixWithShiftJisContent(): void
    {
        $raw = "JIS\0\0\0\0\0" . pack('C*', 0x93, 0x8C, 0x8B, 0x9E);

        $parsedExif = $this->parsedExifWithUserComment($raw);

        self::assertNull($parsedExif->userComment());
        self::assertSame('JIS', $parsedExif->userCommentEncoding());
    }

    private function parsedExifWithUserComment(string $raw): ParsedExif
    {
        $exifIfd = new Ifd([
            ExifTag::USER_COMMENT => new IfdEntry(
                ExifTag::USER_COMMENT,
                7,
                strlen($raw),
                $raw,
            ),
        ]);

        return new ParsedExif(new Ifd([]), $exifIfd, null, null, null);
    }
}
