<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MetadataReader;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;
use function file_put_contents;
use function pack;
use function sha1;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Integration coverage for the convenience metadata reader.
 *
 * @covers \MagicSunday\ImageMeta\MetadataReader
 */
final class MetadataReaderTest extends TestCase
{
    private const string EXIF_SIGNATURE = "Exif\0\0";

    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    private const int MARKER_APP1 = 0xE1;

    /**
     * Ensures JPEG detection extracts EXIF and XMP payloads with parsed documents.
     */
    #[Test]
    public function testReadJpegPopulatesMetadata(): void
    {
        $makerNote = 'synthetic-nikon-maker-note';
        $tiff      = $this->littleEndianTiffWithMakerNote('Nikon Corporation', 'Z 9', $makerNote);
        $xmp       = '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" dc:title="Synthetic" />'
            . '</rdf:RDF>'
            . '</x:xmpmeta>';

        $jpeg = "\xFF\xD8"
            . $this->segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $tiff)
            . $this->segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmp)
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
        self::assertInstanceOf(MakerNotesMetadata::class, $metadata->makerNotes);
        self::assertSame('Nikon', $metadata->makerNotes->vendor());
        self::assertSame(strlen($makerNote), $metadata->makerNotes->length());
        self::assertSame(sha1($makerNote), $metadata->makerNotes->sha1());
        self::assertNull($metadata->iccProfile);
        self::assertSame([], $metadata->iccSegments);
    }

    /**
     * Ensures ISO BMFF detection populates EXIF/XMP blobs and QuickTime metadata.
     */
    #[Test]
    public function testReadIsoBmffPopulatesMetadata(): void
    {
        $makerNote = 'synthetic-sony-maker-note';
        $tiff      = $this->littleEndianTiffWithMakerNote('Sony Corporation', 'ILCE-1', $makerNote);
        $xmp       = '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" dc:creator="Agent" />'
            . '</rdf:RDF>'
            . '</x:xmpmeta>';
        $identifier = 'qt-meta-identifier';

        $ftyp       = $this->box('ftyp', 'isom');
        $meta       = $this->fullBox('meta', $this->box('Exif', self::EXIF_SIGNATURE . $tiff) . $this->box('XMP ', $xmp));
        $moov       = $this->quickTimeMoov($identifier);
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
        self::assertInstanceOf(MakerNotesMetadata::class, $metadata->makerNotes);
        self::assertSame('Sony', $metadata->makerNotes->vendor());
        self::assertSame(strlen($makerNote), $metadata->makerNotes->length());
        self::assertSame(sha1($makerNote), $metadata->makerNotes->sha1());
        self::assertNull($metadata->iccProfile);
        self::assertSame([], $metadata->iccSegments);
    }

    /**
     * Writes the provided binary payload to a temporary file and returns its path.
     *
     * @param string $payload Binary payload to persist on disk.
     *
     * @return string Absolute path to the temporary file containing the payload.
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
     * Builds a minimal little-endian TIFF containing make/model strings and maker notes.
     */
    private function littleEndianTiffWithMakerNote(string $make, string $model, string $makerNote): string
    {
        $makeData   = $make . "\0";
        $modelData  = $model . "\0";
        $ifd0Offset = 8;
        $ifd0Count  = 3;
        $ifd0Size   = 2 + ($ifd0Count * 12) + 4;

        $currentOffset = $ifd0Offset + $ifd0Size;

        $makeOffset = $currentOffset;
        $currentOffset += strlen($makeData);

        $modelOffset = $currentOffset;
        $currentOffset += strlen($modelData);

        $exifIfdOffset = $currentOffset;
        $exifIfdCount  = 1;
        $exifIfdSize   = 2 + 12 + 4;

        $makerNoteOffset = $exifIfdOffset + $exifIfdSize;

        $ifd0 = pack('v', $ifd0Count)
            . pack('v', ExifTag::MAKE)
            . pack('v', 2)
            . pack('V', strlen($makeData))
            . pack('V', $makeOffset)
            . pack('v', ExifTag::MODEL)
            . pack('v', 2)
            . pack('V', strlen($modelData))
            . pack('V', $modelOffset)
            . pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', 4)
            . pack('V', 1)
            . pack('V', $exifIfdOffset)
            . pack('V', 0);

        $exifIfd = pack('v', $exifIfdCount)
            . pack('v', ExifTag::MAKER_NOTE)
            . pack('v', 7)
            . pack('V', strlen($makerNote))
            . pack('V', $makerNoteOffset)
            . pack('V', 0);

        return 'II'
            . pack('v', 0x2A)
            . pack('V', $ifd0Offset)
            . $ifd0
            . $makeData
            . $modelData
            . $exifIfd
            . $makerNote;
    }

    /**
     * Wraps a payload with a JPEG marker and its big-endian length field.
     *
     * @param int    $marker  Marker identifier without the 0xFF prefix.
     * @param string $payload Binary segment payload.
     *
     * @return string Serialized JPEG segment.
     */
    private function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    /**
     * Constructs a standard ISO BMFF box header around the provided payload.
     *
     * @param string $type    Four-character box type.
     * @param string $payload Box payload data.
     *
     * @return string Serialized box bytes.
     */
    private function box(string $type, string $payload): string
    {
        $size = 8 + strlen($payload);

        return pack('N', $size) . $type . $payload;
    }

    /**
     * Constructs a full box (including version and flags) around a payload.
     *
     * @param string $type    Four-character box type.
     * @param string $payload Box payload data.
     * @param int    $version Version byte to prepend to the payload.
     * @param int    $flags   Three-byte flag field to prepend to the payload.
     *
     * @return string Serialized full box bytes.
     */
    private function fullBox(string $type, string $payload, int $version = 0, int $flags = 0): string
    {
        $header = chr($version)
            . chr(($flags >> 16) & 0xFF)
            . chr(($flags >> 8) & 0xFF)
            . chr($flags & 0xFF);

        return $this->box($type, $header . $payload);
    }

    /**
     * Builds a QuickTime moov/udta/meta structure containing a content identifier.
     *
     * @param string $value Content identifier to store inside the structure.
     *
     * @return string Serialized QuickTime `moov` box structure.
     */
    private function quickTimeMoov(string $value): string
    {
        $keysEntry = pack('N', 8 + strlen('com.apple.quicktime.content.identifier'))
            . 'mdta'
            . 'com.apple.quicktime.content.identifier';
        $keys = $this->box('keys', "\0\0\0\0" . pack('N', 1) . $keysEntry);

        $dataBox   = $this->box('data', pack('N', 1) . pack('N', 0) . $value);
        $ilstEntry = $this->box(pack('N', 1), $dataBox);
        $ilst      = $this->box('ilst', $ilstEntry);

        $metaPayload = "\0\0\0\0" . $keys . $ilst;
        $meta        = $this->box('meta', $metaPayload);
        $udta        = $this->box('udta', $meta);

        return $this->box('moov', $udta);
    }
}
