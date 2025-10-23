<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MetadataReader;

use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage for the convenience metadata reader.
 *
 * @covers \MagicSunday\ImageMeta\MetadataReader
 */
final class MetadataReaderTest extends TestCase
{
    private const EXIF_SIGNATURE = "Exif\0\0";
    private const XMP_SIGNATURE  = "http://ns.adobe.com/xap/1.0/\0";

    private const int MARKER_APP1 = 0xE1;

    /**
     * Ensures JPEG detection extracts EXIF and XMP payloads with parsed documents.
     */
    #[Test]
    public function testReadJpegPopulatesMetadata(): void
    {
        $tiff = self::littleEndianEmptyTiff();
        $xmp  = '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" dc:title="Synthetic" />'
            . '</rdf:RDF>'
            . '</x:xmpmeta>';

        $jpeg = "\xFF\xD8"
            . self::segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $tiff)
            . self::segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmp)
            . "\xFF\xD9";

        $path = $this->writeTempFile($jpeg);

        try {
            $metadata = (new MetadataReader())->read($path);
        } finally {
            @unlink($path);
        }

        self::assertInstanceOf(Metadata::class, $metadata);
        self::assertSame([$tiff], $metadata->exifBlobs);
        self::assertSame([$xmp], $metadata->xmpBlobs);
        self::assertNull($metadata->quickTime);
        self::assertInstanceOf(ExifDocument::class, $metadata->exifDoc);
        self::assertInstanceOf(XmpDocument::class, $metadata->xmpDoc);
    }

    /**
     * Ensures ISO BMFF detection populates EXIF/XMP blobs and QuickTime metadata.
     */
    #[Test]
    public function testReadIsoBmffPopulatesMetadata(): void
    {
        $tiff = self::littleEndianEmptyTiff();
        $xmp  = '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" dc:creator="Agent" />'
            . '</rdf:RDF>'
            . '</x:xmpmeta>';
        $identifier = 'qt-meta-identifier';

        $ftyp       = self::box('ftyp', 'isom');
        $meta       = self::fullBox('meta', self::box('Exif', self::EXIF_SIGNATURE . $tiff) . self::box('XMP ', $xmp));
        $moov       = self::quickTimeMoov($identifier);
        $isoPayload = $ftyp . $meta . $moov;

        $path = $this->writeTempFile($isoPayload);

        try {
            $metadata = (new MetadataReader())->read($path);
        } finally {
            @unlink($path);
        }

        self::assertInstanceOf(Metadata::class, $metadata);
        self::assertSame([$tiff], $metadata->exifBlobs);
        self::assertSame([$xmp], $metadata->xmpBlobs);
        self::assertInstanceOf(QuickTimeMeta::class, $metadata->quickTime);
        self::assertSame($identifier, $metadata->quickTime->contentIdentifier());
        self::assertInstanceOf(ExifDocument::class, $metadata->exifDoc);
        self::assertInstanceOf(XmpDocument::class, $metadata->xmpDoc);
    }

    /**
     * Writes the provided binary payload to a temporary file and returns its path.
     */
    private function writeTempFile(string $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'meta');
        if ($path === false) {
            self::fail('Unable to allocate temporary file');
        }

        file_put_contents($path, $payload);

        return $path;
    }

    /**
     * Builds a minimal little-endian TIFF header with no directory entries.
     */
    private static function littleEndianEmptyTiff(): string
    {
        return 'II' . pack('v', 0x2A) . pack('V', 8) . pack('v', 0) . pack('V', 0);
    }

    /**
     * Wraps a payload with a JPEG marker and its big-endian length field.
     */
    private static function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    /**
     * Constructs a standard ISO BMFF box header around the provided payload.
     */
    private static function box(string $type, string $payload): string
    {
        $size = 8 + strlen($payload);

        return pack('N', $size) . $type . $payload;
    }

    /**
     * Constructs a full box (including version and flags) around a payload.
     */
    private static function fullBox(string $type, string $payload, int $version = 0, int $flags = 0): string
    {
        $header = chr($version)
            . chr(($flags >> 16) & 0xFF)
            . chr(($flags >> 8) & 0xFF)
            . chr($flags & 0xFF);

        return self::box($type, $header . $payload);
    }

    /**
     * Builds a QuickTime moov/udta/meta structure containing a content identifier.
     */
    private static function quickTimeMoov(string $value): string
    {
        $keysEntry = pack('N', 8 + strlen('com.apple.quicktime.content.identifier'))
            . 'mdta'
            . 'com.apple.quicktime.content.identifier';
        $keys = self::box('keys', "\0\0\0\0" . pack('N', 1) . $keysEntry);

        $dataBox   = self::box('data', pack('N', 1) . pack('N', 0) . $value);
        $ilstEntry = self::box(pack('N', 1), $dataBox);
        $ilst      = self::box('ilst', $ilstEntry);

        $metaPayload = "\0\0\0\0" . $keys . $ilst;
        $meta        = self::box('meta', $metaPayload);
        $udta        = self::box('udta', $meta);

        return self::box('moov', $udta);
    }
}
