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
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;
use function str_pad;
use function substr;

#[CoversClass(TiffExifReader::class)]
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
final class TiffExifReaderFixedLengthTest extends TestCase
{
    #[Test]
    #[DataProvider('validFixedLengthTagProvider')]
    public function acceptsFixedLengthTagsWithValidCounts(
        int $tag,
        int $type,
        int $count,
        string $valueBytes,
    ): void {
        $blob = $this->buildClassicTiffWithEntry($tag, $type, $count, $valueBytes);

        $reader = new TiffExifReader();

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
                '0300',
            ],
            'FlashpixVersion count 4' => [
                ExifTag::FLASHPIX_VERSION,
                TiffConst::TYPE_ASCII,
                4,
                '0100',
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

            $blob .= pack('v', $tag)
                . pack('v', $type)
                . pack('V', $count)
                . $inlineBytes
                . pack('V', 0);

            return $blob;
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
            default => 1,
        };
    }
}
