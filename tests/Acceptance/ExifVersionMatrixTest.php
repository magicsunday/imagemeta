<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Acceptance;

use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function assert;
use function chr;
use function file_put_contents;
use function pack;
use function rename;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class ExifVersionMatrixTest extends TestCase
{
    #[Test]
    #[DataProvider('provideExifVersions')]
    public function readsExifVersionMatrix(string $raw, string $expectedVersion, string $expectedProfile): void
    {
        $jpeg = $this->jpegWithExifVersion($raw);
        $path = $this->writeTempFile($jpeg, 'jpg');

        try {
            $structured = (new MetadataReader())->read($path)->structured();
        } finally {
            @unlink($path);
        }

        self::assertSame($expectedVersion, $structured->technical->standards->exifVersion);
        self::assertSame($expectedProfile, $structured->technical->standards->profile);
    }

    /**
     * @return iterable<string, array{string,string,string}>
     */
    public static function provideExifVersions(): iterable
    {
        yield '1.0' => ['0100', '1.00', '1.0'];
        yield '1.1' => ['0110', '1.10', '1.1'];
        yield '2.1' => ['0210', '2.10', '2.1'];
        yield '2.2' => ['0220', '2.20', '2.2'];
        yield '2.21' => ['0221', '2.21', '2.21'];
        yield '2.3' => ['0230', '2.30', '2.3'];
        yield '2.31' => ['0231', '2.31', '2.31'];
        yield '2.32' => ['0232', '2.32', '2.32'];
        yield '3.0' => ['0300', '3.00', '3.0'];
    }

    private function jpegWithExifVersion(string $rawVersion): string
    {
        $tiff = $this->littleEndianExifTiff($rawVersion);

        return "\xFF\xD8"
            . $this->segment(0xE1, "Exif\0\0" . $tiff)
            . "\xFF\xD9";
    }

    private function littleEndianExifTiff(string $rawVersion): string
    {
        $ifd0Offset    = 8;
        $exifIfdOffset = $ifd0Offset + 2 + 12 + 4;

        $ifd0 = pack('v', 1)
            . pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', 4)
            . pack('V', 1)
            . pack('V', $exifIfdOffset)
            . pack('V', 0);

        $value = substr($rawVersion . "\0\0\0\0", 0, 4);

        $exifIfd = pack('v', 1)
            . pack('v', ExifTag::EXIF_VERSION)
            . pack('v', 7)
            . pack('V', 4)
            . $value
            . pack('V', 0);

        return 'II'
            . pack('v', 0x2A)
            . pack('V', $ifd0Offset)
            . $ifd0
            . $exifIfd;
    }

    private function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    private function writeTempFile(string $payload, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'meta');
        assert($path !== false, 'Unable to allocate temporary file');

        file_put_contents($path, $payload);

        $target = $path . '.' . $extension;
        if (!@rename($path, $target)) {
            @unlink($path);
            self::fail('Unable to finalise temporary JPEG');
        }

        return $target;
    }
}
