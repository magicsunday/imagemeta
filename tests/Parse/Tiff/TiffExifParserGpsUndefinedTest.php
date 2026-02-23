<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function str_pad;
use function strlen;
use function substr;

/**
 * Verifies GPSProcessingMethod and GPSAreaInformation enforce UNDEFINED type per EXIF 3.0.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserGpsUndefinedTest extends TestCase
{
    /**
     * Accepts GPS UNDEFINED tags encoded with TIFF type UNDEFINED.
     */
    #[Test]
    #[DataProvider('provideGpsUndefinedTags')]
    public function acceptsGpsUndefinedTagsWithCorrectType(int $tag, string $name, string $resultKey): void
    {
        $payload = "ASCII\0\0\0GPS test";

        $result = (new TiffExifParser())->parseFromBlob(
            $this->buildClassicTiffWithSingleGpsEntry(
                $tag,
                TiffConst::TYPE_UNDEFINED,
                strlen($payload),
                $payload,
            ),
        );

        self::assertSame('GPS test', $result->gps()[$resultKey]);
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: string}>
     */
    public static function provideGpsUndefinedTags(): iterable
    {
        yield 'GPSProcessingMethod' => [ExifTag::GPS_PROCESSING_METHOD, 'GPSProcessingMethod', 'processing_method'];
        yield 'GPSAreaInformation' => [ExifTag::GPS_AREA_INFORMATION, 'GPSAreaInformation', 'area_information'];
    }

    private function classicIfd0WithGpsPointerLength(): int
    {
        return 2 + (3 * 12) + 4;
    }

    private function buildClassicIfd0WithGpsPointer(int $gpsIfdOffset): string
    {
        return pack('v', 3)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::GPS_IFD_POINTER)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $gpsIfdOffset)
            . pack('V', 0);
    }

    private function buildClassicTiffWithSingleGpsEntry(int $tag, int $type, int $count, string $valueBytes): string
    {
        $header = Endian::Little->value
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8);

        $ifd0Length   = $this->classicIfd0WithGpsPointerLength();
        $gpsIfdOffset = strlen($header) + $ifd0Length;
        $ifd0         = $this->buildClassicIfd0WithGpsPointer($gpsIfdOffset);

        $componentSize = $this->bytesPerComponent($type);
        $dataSize      = $componentSize * $count;
        $dataBytes     = strlen($valueBytes) >= $dataSize
            ? substr($valueBytes, 0, $dataSize)
            : str_pad($valueBytes, $dataSize, "\0");

        $gpsIfdLength = 2 + 12 + 4;
        $dataOffset   = strlen($header . $ifd0) + $gpsIfdLength;

        $entry = pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count);

        if ($dataSize > 4) {
            $entry .= pack('V', $dataOffset);
            $payload = $dataBytes;
        } else {
            $entry .= str_pad($dataBytes, 4, "\0");
            $payload = '';
        }

        $gpsIfd = pack('v', 1)
            . $entry
            . pack('V', 0);

        return $header . $ifd0 . $gpsIfd . $payload;
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
