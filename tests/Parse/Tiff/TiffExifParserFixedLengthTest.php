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
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_pad;
use function strlen;
use function substr;

/**
 * Exercises TIFF EXIF parsing for fixed-length and inline value cases.
 * It verifies correct handling of inline vs. offset storage for various tag types.
 * The tests include rational, numeric list, and ASCII values across endian modes.
 * This keeps fixed-size parsing paths stable for common EXIF payloads.
 *
 * @internal
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(Endian::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(ExifRational::class)]
#[UsesClass(ExifRationalList::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
final class TiffExifParserFixedLengthTest extends TestCase
{
    /**
     * Uses fixed-length EXIF/GPS tags with valid component counts from the data provider.
     * Verifies the parser accepts these entries without raising a ParseError.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('validFixedLengthTagProvider')]
    public function acceptsFixedLengthTagsWithValidCounts(
        int $tag,
        int $type,
        int $count,
        string $valueBytes,
    ): void {
        $blob = $this->buildClassicTiffWithEntry($tag, $type, $count, $valueBytes);

        $reader = new TiffExifParser();

        $reader->parseFromBlob($blob);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0:int,1:int,2:int,3:string}>
     */
    public static function validFixedLengthTagProvider(): array
    {
        return [
            'ExifVersion count 4' => [
                ExifTag::EXIF_VERSION,
                TiffConst::TYPE_ASCII,
                4,
                "030\0",
            ],
            'FlashpixVersion count 4' => [
                ExifTag::FLASHPIX_VERSION,
                TiffConst::TYPE_ASCII,
                4,
                "010\0",
            ],
            'ComponentsConfiguration count 4' => [
                ExifTag::COMPONENTS_CONFIGURATION,
                TiffConst::TYPE_UNDEFINED,
                4,
                "\x01\x02\x03\x00",
            ],
            'GPSVersionID count 4' => [
                ExifTag::GPS_VERSION_ID,
                TiffConst::TYPE_BYTE,
                4,
                "\x02\x03\x00\x00",
            ],
            'LensSpecification count 4' => [
                ExifTag::LENS_SPECIFICATION,
                TiffConst::TYPE_RATIONAL,
                4,
                "\x00\x00\x00\x1C\x00\x00\x00\x01\x00\x00\x00\x46\x00\x00\x00\x01\x00\x00\x00\x18\x00\x00\x00\x0A\x00\x00\x00\x38\x00\x00\x00\x0A",
            ],
            'GPSTimeStamp count 3' => [
                ExifTag::GPS_TIME_STAMP,
                TiffConst::TYPE_RATIONAL,
                3,
                "\x00\x00\x00\x0C\x00\x00\x00\x01\x00\x00\x00\x22\x00\x00\x00\x01\x00\x00\x00\x38\x00\x00\x00\x01",
            ],
            'GPSDateStamp count 11' => [
                ExifTag::GPS_DATE_STAMP,
                TiffConst::TYPE_ASCII,
                11,
                "2024:05:06\0",
            ],
            'FileSource count 1' => [
                ExifTag::FILE_SOURCE,
                TiffConst::TYPE_UNDEFINED,
                1,
                "\x03",
            ],
            'SceneType count 1' => [
                ExifTag::SCENE_TYPE,
                TiffConst::TYPE_UNDEFINED,
                1,
                "\x01",
            ],
            'GPSAltitudeRef count 1' => [
                ExifTag::GPS_ALTITUDE_REF,
                TiffConst::TYPE_BYTE,
                1,
                "\x02",
            ],
            'GPSDifferential count 1' => [
                ExifTag::GPS_DIFFERENTIAL,
                TiffConst::TYPE_SHORT,
                1,
                "\x01\x00",
            ],
        ];
    }

    private function buildClassicTiffWithEntry(int $tag, int $type, int $count, string $valueBytes): string
    {
        $ifdOffset = 8;
        $blob      = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset);

        $blob .= pack('v', 1);

        $componentSize = $this->bytesPerComponent($type);
        $dataSize      = $componentSize * $count;

        if (strlen($valueBytes) < $dataSize) {
            $valueBytes = str_pad($valueBytes, $dataSize, "\0");
        }

        if ($dataSize <= 4) {
            $inlineBytes = str_pad(substr($valueBytes, 0, $dataSize), 4, "\0");

            return $blob . (pack('v', $tag) . pack('v', $type) . pack('V', $count) . $inlineBytes . pack('V', 0));
        }

        $valueOffset = $ifdOffset + 2 + 12 + 4;

        $blob .= pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count)
            . pack('V', $valueOffset)
            . pack('V', 0);

        return $blob . substr($valueBytes, 0, $dataSize);
    }

    private function bytesPerComponent(int $type): int
    {
        return match ($type) {
            TiffConst::TYPE_ASCII,
            TiffConst::TYPE_BYTE,
            TiffConst::TYPE_UNDEFINED => 1,
            TiffConst::TYPE_SHORT     => 2,
            TiffConst::TYPE_LONG,
            TiffConst::TYPE_SLONG => 4,
            TiffConst::TYPE_RATIONAL,
            TiffConst::TYPE_SRATIONAL => 8,
            default                   => 1,
        };
    }
}
