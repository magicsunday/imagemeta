<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Metadata;

use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test case for the aggregated metadata container model.
 *
 * @covers \MagicSunday\ImageMeta\Model\Metadata
 */
#[CoversClass(Metadata::class)]
final class MetadataTest extends TestCase
{
    /**
     * Ensures that every provided metadata component is exposed unchanged.
     */
    #[Test]
    public function storesProvidedMetadataComponents(): void
    {
        $exifBlobs = [
            'primary-exif-blob',
            'alternate-exif-blob',
        ];

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.content.identifier' => 'movie-123',
        ]);

        $ifd0 = new Ifd([
            ExifTag::MAKE  => new IfdEntry(ExifTag::MAKE, 2, 1, 'Canon'),
            ExifTag::MODEL => new IfdEntry(ExifTag::MODEL, 2, 1, 'EOS R5'),
        ]);
        $exifDoc  = new ExifDocument($ifd0, null, null, null, null);
        $xmpBlobs = [
            '<x:xmpmeta>\n  <!-- primary -->\n</x:xmpmeta>',
            '<x:xmpmeta>\n  <!-- secondary -->\n</x:xmpmeta>',
        ];
        $xmpDoc = new XmpDocument([
            '{http://ns.adobe.com/photoshop/1.0/}DateCreated' => '2024-05-01',
        ]);

        $metadata = new Metadata($exifBlobs, $quickTime, $exifDoc, $xmpBlobs, $xmpDoc);

        self::assertSame($exifBlobs, $metadata->exifBlobs);
        self::assertSame($quickTime, $metadata->quickTime);
        self::assertSame($exifDoc, $metadata->exifDoc);
        self::assertSame($xmpBlobs, $metadata->xmpBlobs);
        self::assertSame($xmpDoc, $metadata->xmpDoc);
    }

    /**
     * Ensures the optional metadata components default to null or empty values.
     */
    #[Test]
    public function appliesNullAndEmptyDefaults(): void
    {
        $metadata = new Metadata([], null);

        self::assertSame([], $metadata->exifBlobs);
        self::assertNull($metadata->quickTime);
        self::assertNull($metadata->exifDoc);
        self::assertSame([], $metadata->xmpBlobs);
        self::assertNull($metadata->xmpDoc);
    }
}
