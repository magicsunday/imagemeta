<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests;

use MagicSunday\ImageMeta\Convenience\CaptureDateResolver;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;
use function file_put_contents;
use function pack;
use function rename;
use function ltrim;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class CaptureDateResolverTest extends TestCase
{
    private const string EXIF_SIGNATURE = "Exif\0\0";

    #[Test]
    public function returnsCaptureDateFromExif(): void
    {
        $tiff = $this->littleEndianTiff('Vendor', 'Model', 100, 50, '2024:05:05 10:20:30');
        $jpeg = "\xFF\xD8" . $this->segment(0xE1, self::EXIF_SIGNATURE . $tiff) . "\xFF\xD9";

        $path = $this->writeTempFile($jpeg, 'jpg');

        try {
            $metadata = (new MetadataReader())->read($path);
        } finally {
            @unlink($path);
        }

        $captured = CaptureDateResolver::bestCaptureDateTime($metadata);

        self::assertNotNull($captured);
        self::assertSame('2024-05-05 10:20:30', $captured->format('Y-m-d H:i:s'));
    }

    private function littleEndianTiff(string $make, string $model, int $width, int $height, string $dateTimeOriginal): string
    {
        $makeData  = $make . "\0";
        $modelData = $model . "\0";
        $dateData  = $dateTimeOriginal . "\0";

        $ifd0Offset = 8;
        $ifd0Count  = 5;
        $ifd0Size   = 2 + ($ifd0Count * 12) + 4;

        $currentOffset = $ifd0Offset + $ifd0Size;

        $makeOffset = $currentOffset;
        $currentOffset += strlen($makeData);

        $modelOffset = $currentOffset;
        $currentOffset += strlen($modelData);

        $exifIfdOffset = $currentOffset;
        $exifIfdCount  = 1;
        $exifIfdSize   = 2 + ($exifIfdCount * 12) + 4;

        $dateOffset = $exifIfdOffset + $exifIfdSize;

        $ifd0 = pack('v', $ifd0Count)
            . pack('v', ExifTag::MAKE) . pack('v', 2) . pack('V', strlen($makeData)) . pack('V', $makeOffset)
            . pack('v', ExifTag::MODEL) . pack('v', 2) . pack('V', strlen($modelData)) . pack('V', $modelOffset)
            . pack('v', ExifTag::IMAGE_WIDTH) . pack('v', 4) . pack('V', 1) . pack('V', $width)
            . pack('v', ExifTag::IMAGE_HEIGHT) . pack('v', 4) . pack('V', 1) . pack('V', $height)
            . pack('v', ExifTag::EXIF_IFD_POINTER) . pack('v', 4) . pack('V', 1) . pack('V', $exifIfdOffset)
            . pack('V', 0);

        $exifIfd = pack('v', $exifIfdCount)
            . pack('v', ExifTag::DATETIME_ORIGINAL) . pack('v', 2) . pack('V', strlen($dateData)) . pack('V', $dateOffset)
            . pack('V', 0);

        return 'II'
            . pack('v', 0x2A)
            . pack('V', $ifd0Offset)
            . $ifd0
            . $makeData
            . $modelData
            . $exifIfd
            . $dateData;
    }

    private function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    private function writeTempFile(string $payload, ?string $extension = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'meta');
        if ($path === false) {
            self::fail('Unable to allocate temporary file');
        }

        file_put_contents($path, $payload);

        if ($extension !== null) {
            $suffix = ltrim($extension, '.');
            $target = $path . '.' . $suffix;
            if (!rename($path, $target)) {
                @unlink($path);
                self::fail('Unable to rename temporary file');
            }

            $path = $target;
        }

        return $path;
    }
}
