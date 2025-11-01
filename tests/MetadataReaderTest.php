<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests;

use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File as FileValue;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;
use function file_put_contents;
use function md5;
use function pack;
use function ltrim;
use function rename;
use function sha1;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class MetadataReaderTest extends TestCase
{
    private const string EXIF_SIGNATURE = "Exif\0\0";

    private const int MARKER_APP1 = 0xE1;

    private const int MARKER_SOF0 = 0xC0;

    #[Test]
    public function readJpegExtractsExifMetadata(): void
    {
        $tiff = $this->littleEndianTiff(
            make: 'MagicSunday',
            model: 'ImageMeta',
            width: 512,
            height: 256,
            dateTimeOriginal: '2024:03:01 12:34:56',
        );

        $jpeg = "\xFF\xD8"
            . $this->segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $tiff)
            . $this->segment(self::MARKER_SOF0, $this->baselineSofPayload(512, 256))
            . "\xFF\xD9";

        $path = $this->writeTempFile($jpeg, 'jpg');

        try {
            $metadata = (new MetadataReader())->read($path);
        } finally {
            @unlink($path);
        }

        self::assertSame([$tiff], $metadata->exifBlobs);
        self::assertInstanceOf(ParsedExif::class, $metadata->exifDoc);
        self::assertSame(8, $metadata->jpegBitsPerSample);
        self::assertSame(256, $metadata->jpegFrameHeight);
        self::assertSame(512, $metadata->jpegFrameWidth);

        $structured = $metadata->structured();

        self::assertInstanceOf(FileValue::class, $structured->file);
        self::assertInstanceOf(Container::class, $structured->container);
        self::assertInstanceOf(Image::class, $structured->image);
        self::assertInstanceOf(Exposure::class, $structured->exposure);
        self::assertInstanceOf(Gps::class, $structured->gps);

        self::assertSame('JPEG', $structured->container->format);
        self::assertSame(512, $structured->image->width);
        self::assertSame(256, $structured->image->height);
    }

    #[Test]
    public function readJpegWithDigestsCalculatesChecksums(): void
    {
        $tiff = $this->littleEndianTiff('Vendor', 'Model', 128, 64, '2024:01:01 00:00:01');
        $jpeg = "\xFF\xD8"
            . $this->segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $tiff)
            . $this->segment(self::MARKER_SOF0, $this->baselineSofPayload(128, 64))
            . "\xFF\xD9";

        $path = $this->writeTempFile($jpeg, 'jpeg');

        try {
            $metadata = (new MetadataReader())->read($path, true);
        } finally {
            @unlink($path);
        }

        self::assertSame(sha1($jpeg), $metadata->digestSha1);
        self::assertSame(md5($jpeg), $metadata->digestMd5);

        $structured = $metadata->structured();
        self::assertSame(sha1($jpeg), $structured->file->digestSha1);
        self::assertSame(md5($jpeg), $structured->file->digestMd5);
    }

    #[Test]
    public function unsupportedContainerThrowsParseError(): void
    {
        $this->expectExceptionMessage('Only JPEG containers are supported by the core reader.');

        $path = $this->writeTempFile('not a jpeg');

        try {
            (new MetadataReader())->read($path);
        } finally {
            @unlink($path);
        }
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

    private function baselineSofPayload(int $width, int $height): string
    {
        return chr(8)
            . pack('n', $height)
            . pack('n', $width)
            . chr(3)
            . chr(1) . chr(0x11) . chr(0)
            . chr(2) . chr(0x11) . chr(1)
            . chr(3) . chr(0x11) . chr(1);
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
