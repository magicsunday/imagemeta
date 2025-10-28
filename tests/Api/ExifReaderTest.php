<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Api;

use MagicSunday\ImageMeta\Api\ExifReader;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExifReaderTest extends TestCase
{
    #[Test]
    public function readsExifMetadataFromJpeg(): void
    {
        $reader = new ExifReader();
        $path   = dirname(__DIR__, 2) . '/test-images/Images/gps_exif_example.jpg';

        $document = $reader->read($path);

        self::assertTrue($document->hasData());
        self::assertSame('Canon', $document->camera()->make);
        self::assertSame('Canon PowerShot SX130 IS', $document->camera()->model);

        $exposure = $document->exposure();
        self::assertSame(80, $exposure->iso);
        self::assertEqualsWithDelta(0.01, $exposure->exposureTimeSec, 1e-5);
        self::assertEqualsWithDelta(3.4, $exposure->fNumber, 1e-2);

        $lens = $document->lens();
        self::assertEqualsWithDelta(5.0, $lens->focalLength ?? 0.0, 1e-6);

        $image = $document->image();
        self::assertSame(4000, $image->width);
        self::assertSame(3000, $image->height);
        self::assertSame(Orientation::TOP_LEFT, $image->orientation);
        self::assertSame(ColorSpace::SRGB, $image->colorSpace);

        $gps = $document->gps();
        self::assertNotNull($gps->latitude);
        self::assertEqualsWithDelta(41.888948, $gps->latitude->toFloat() ?? 0.0, 1e-6);
        self::assertNotNull($gps->longitude);
        self::assertEqualsWithDelta(-87.624494, $gps->longitude->toFloat() ?? 0.0, 1e-6);

        $preview = $document->preview();
        self::assertTrue($preview->hasThumbnail);
        self::assertNull($preview->hasPreview);
        self::assertNull($preview->previewEncoding);
        self::assertNull($preview->previewMimeType);
        self::assertNull($preview->previewBitDepth);
        self::assertNull($preview->previewColorSpace);
        self::assertNull($preview->previewOffset);
        self::assertNull($preview->previewLength);
    }

    #[Test]
    public function returnsEmptyDocumentWhenExifIsMissing(): void
    {
        $reader = new ExifReader();

        $image = imagecreatetruecolor(1, 1);
        $white = imagecolorallocate($image, 255, 255, 255);
        self::assertNotFalse($white);
        imagefill($image, 0, 0, $white);

        $path = tempnam(sys_get_temp_dir(), 'exif');
        self::assertIsString($path);
        imagejpeg($image, $path);
        imagedestroy($image);

        try {
            $document = $reader->read($path);

            self::assertFalse($document->hasData());
            self::assertNull($document->camera()->make);
            self::assertNull($document->lens()->focalLength);
            self::assertNull($document->gps()->latitude);
        } finally {
            @unlink($path);
        }
    }
}
