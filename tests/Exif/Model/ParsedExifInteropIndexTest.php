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
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validates InteroperabilityIndex enforcement per EXIF 3.0 §4.6.8.1.1.
 * The tag must be ASCII with exactly 4 bytes (including NUL terminator).
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
final class ParsedExifInteropIndexTest extends TestCase
{
    /**
     * Returns the interop index when the entry is valid ASCII[4].
     */
    #[Test]
    public function returnsValidInteropIndex(): void
    {
        self::assertSame('R98', $this->parseInteropIndex(TiffConst::TYPE_ASCII, 4, "R98\0"));
    }

    /**
     * Rejects an interop index entry with wrong count (3 instead of 4).
     */
    #[Test]
    public function rejectsInteropIndexWithWrongCount(): void
    {
        self::assertNull($this->parseInteropIndex(TiffConst::TYPE_ASCII, 3, 'R98'));
    }

    /**
     * Rejects an interop index entry with wrong type (UNDEFINED instead of ASCII).
     */
    #[Test]
    public function rejectsInteropIndexWithWrongType(): void
    {
        self::assertNull($this->parseInteropIndex(TiffConst::TYPE_UNDEFINED, 4, "R98\0"));
    }

    private function parseInteropIndex(int $type, int $count, string $value): ?string
    {
        $interopIfd = new Ifd([
            ExifTag::INTEROPERABILITY_INDEX => new IfdEntry(
                ExifTag::INTEROPERABILITY_INDEX,
                $type,
                $count,
                $value,
            ),
        ]);

        return (new ParsedExif(new Ifd([]), null, null, $interopIfd, null))
            ->interopIndex();
    }
}
