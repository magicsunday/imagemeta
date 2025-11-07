<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\imagemeta\tests;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Curate\Exif\ValueFactory;
use MagicSunday\ImageMeta\Curate\ExifAssembler;
use MagicSunday\ImageMeta\Curate\StructuredMetadata;
use MagicSunday\ImageMeta\Detect\FormatDetector;
use MagicSunday\ImageMeta\Exif\Support\EnumFromIntStringNullable;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMerger;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use MagicSunday\ImageMeta\MakerNotes\CanonDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\NikonDecoder;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\MakerNotes\RegistryFactory;
use MagicSunday\ImageMeta\MakerNotes\SonyDecoder;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\StructuredMetadataCache;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifReader;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use MagicSunday\ImageMeta\Value\Audio;
use MagicSunday\ImageMeta\Value\AudioClips;
use MagicSunday\ImageMeta\Value\Author;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\ColorProfile;
use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Device;
use MagicSunday\ImageMeta\Value\ExifFlash;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\File as FileValue;
use MagicSunday\ImageMeta\Value\FlashPix;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Integrity;
use MagicSunday\ImageMeta\Value\Interop;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\Lens;
use MagicSunday\ImageMeta\Value\Motion;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\RelatedAssets;
use MagicSunday\ImageMeta\Value\Rights;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Standards;
use MagicSunday\ImageMeta\Value\Temporal;
use MagicSunday\ImageMeta\Value\Thumbnail;
use MagicSunday\ImageMeta\Value\TiffData;
use MagicSunday\ImageMeta\Value\Video;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use MagicSunday\ImageMeta\Value\Xmp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function chr;
use function count;
use function file_put_contents;
use function ltrim;
use function md5;
use function pack;
use function rename;
use function sha1;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Integration coverage for the convenience metadata reader.
 */
#[CoversClass(MetadataReader::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(UInt64::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(ExifAssembler::class)]
#[UsesClass(FormatDetector::class)]
#[UsesClass(ValueFactory::class)]
#[UsesClass(StructuredMetadata::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(AppleMakerNotesMerger::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(SemanticStyle::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryFactory::class)]
#[UsesClass(SonyDecoder::class)]
#[UsesClass(CanonDecoder::class)]
#[UsesClass(NikonDecoder::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(StructuredMetadataCache::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesClass(IsoBmffExtractor::class)]
#[UsesClass(JpegExtractor::class)]
#[UsesClass(TiffExifReader::class)]
#[UsesClass(XmpParser::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
#[UsesClass(Audio::class)]
#[UsesClass(AudioClips::class)]
#[UsesClass(Author::class)]
#[UsesClass(Camera::class)]
#[UsesClass(Capture::class)]
#[UsesClass(ColorProfile::class)]
#[UsesClass(CompositeImageInfo::class)]
#[UsesClass(Container::class)]
#[UsesClass(Derived::class)]
#[UsesClass(Device::class)]
#[UsesClass(ExifFlash::class)]
#[UsesClass(Exposure::class)]
#[UsesClass(FileValue::class)]
#[UsesClass(FlashPix::class)]
#[UsesClass(Focus::class)]
#[UsesClass(Gps::class)]
#[UsesClass(Image::class)]
#[UsesClass(Integrity::class)]
#[UsesClass(Interop::class)]
#[UsesClass(Keywords::class)]
#[UsesClass(Lens::class)]
#[UsesClass(Motion::class)]
#[UsesClass(MultiPicture::class)]
#[UsesClass(Thumbnail::class)]
#[UsesClass(ProcessingSettings::class)]
#[UsesClass(Regions::class)]
#[UsesClass(RelatedAssets::class)]
#[UsesClass(Rights::class)]
#[UsesClass(Scene::class)]
#[UsesClass(Sensor::class)]
#[UsesClass(Standards::class)]
#[UsesClass(Temporal::class)]
#[UsesClass(TiffData::class)]
#[UsesClass(Video::class)]
#[UsesClass(WhiteBalanceDetails::class)]
#[UsesClass(Xmp::class)]
final class MetadataReaderTest extends TestCase
{
    private const string EXIF_SIGNATURE = "Exif\0\0";

    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    private const int MARKER_APP1 = 0xE1;

    /**
     * Ensures JPEG detection extracts EXIF and XMP payloads with parsed documents.
     */
    #[Test]
    public function readJpegPopulatesMetadata(): void
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

        $path = $this->writeTempFile($jpeg, 'jpg');

        try {
            $metadata = (new MetadataReader())->read($path);
        } finally {
            @unlink($path);
        }

        self::assertSame([$tiff], $metadata->exifBlobs);
        self::assertSame([$xmp], $metadata->xmpBlobs);
        self::assertNull($metadata->quickTime);
        self::assertInstanceOf(ParsedExif::class, $metadata->exifDoc);
        self::assertInstanceOf(XmpDocument::class, $metadata->xmpDoc);
        self::assertInstanceOf(MakerNotesRecord::class, $metadata->makerNotes);
        self::assertSame('Nikon', $metadata->makerNotes->vendor);
        self::assertSame(strlen($makerNote), $metadata->makerNotes->length);
        self::assertSame(sha1($makerNote), $metadata->makerNotes->sha1);
        self::assertNull($metadata->iccProfile);
        self::assertSame([], $metadata->iccSegments);
        self::assertSame([], $metadata->flashPixStreams);
        self::assertSame('image/jpeg', $metadata->mimeType);
        self::assertSame(strlen($jpeg), $metadata->fileSize);
        self::assertSame('jpg', $metadata->extension);
        self::assertNull($metadata->digestSha1);
        self::assertNull($metadata->digestMd5);

        $structured = $metadata->structured();

        /** @var array<string, callable(): mixed> $componentAccessors */
        $componentAccessors = [
            'file'      => static fn (): FileValue => $structured->file,
            'container' => static fn (): Container => $structured->container,
            'camera'    => static fn (): Camera => $structured->camera,
            'lens'      => static fn (): Lens => $structured->lens,
            'derived'   => static fn (): Derived => $structured->derived,
            'exposure'  => static fn (): Exposure => $structured->exposure,
            'thumbnail' => static fn (): Thumbnail => $structured->thumbnail,
            'rights'    => static fn (): Rights => $structured->rights,
        ];

        $expectedClasses = [
            'file'      => FileValue::class,
            'container' => Container::class,
            'camera'    => Camera::class,
            'lens'      => Lens::class,
            'derived'   => Derived::class,
            'exposure'  => Exposure::class,
            'thumbnail' => Thumbnail::class,
            'rights'    => Rights::class,
        ];

        foreach ($componentAccessors as $name => $accessor) {
            $value = $accessor();
            self::assertInstanceOf($expectedClasses[$name], $value);
        }

        self::assertSame('image/jpeg', $structured->file->mimeType);
        self::assertSame(strlen($jpeg), $structured->file->fileSize);
        self::assertSame('jpg', $structured->file->extension);
        self::assertNull($structured->file->digestSha1);
        self::assertNull($structured->file->digestMd5);
    }

    /**
     * Ensures optional digest calculation provides SHA-1 and MD5 for JPEG payloads.
     */
    #[Test]
    public function readJpegWithDigestsPopulatesChecksums(): void
    {
        $makerNote = 'digest-maker-note';
        $tiff      = $this->littleEndianTiffWithMakerNote('Canon', 'EOS R6', $makerNote);

        $jpeg = "\xFF\xD8"
            . $this->segment(self::MARKER_APP1, self::EXIF_SIGNATURE . $tiff)
            . "\xFF\xD9";

        $path = $this->writeTempFile($jpeg, 'jpeg');

        try {
            $metadata = (new MetadataReader())->read($path, true);
        } finally {
            @unlink($path);
        }

        $expectedSha1 = sha1($jpeg);
        $expectedMd5  = md5($jpeg);

        self::assertSame($expectedSha1, $metadata->digestSha1);
        self::assertSame($expectedMd5, $metadata->digestMd5);

        $structured = $metadata->structured();
        self::assertSame($expectedSha1, $structured->file->digestSha1);
        self::assertSame($expectedMd5, $structured->file->digestMd5);
    }

    /**
     * Ensures the structured image aggregate falls back to the SOF precision when EXIF lacks the tag.
     */
    #[Test]
    public function structuredImageBitsPerSampleFallbacksToFramePrecision(): void
    {
        $sofPayload = $this->buildBaselineStartOfFramePayload(8, 672, 448);

        $jpeg = "\xFF\xD8"
            . $this->segment(0xC0, $sofPayload)
            . "\xFF\xD9";

        $path = $this->writeTempFile($jpeg, 'jpg');

        try {
            $metadata = (new MetadataReader())->read($path);
        } finally {
            @unlink($path);
        }

        self::assertSame(8, $metadata->jpegBitsPerSample);

        $image = $metadata->structured()->image;

        self::assertSame(8, $image->bitsPerSample);
        self::assertSame(448, $image->width);
        self::assertSame(672, $image->height);
    }

    /**
     * Ensures ISO BMFF detection populates EXIF/XMP blobs and QuickTime metadata.
     */
    #[Test]
    public function readIsoBmffPopulatesMetadata(): void
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

        self::assertSame([$tiff], $metadata->exifBlobs);
        self::assertSame([$xmp], $metadata->xmpBlobs);
        self::assertInstanceOf(QuickTimeMeta::class, $metadata->quickTime);
        self::assertSame($identifier, $metadata->quickTime->contentIdentifier());
        self::assertInstanceOf(ParsedExif::class, $metadata->exifDoc);
        self::assertInstanceOf(XmpDocument::class, $metadata->xmpDoc);
        self::assertInstanceOf(MakerNotesRecord::class, $metadata->makerNotes);
        self::assertSame('Sony', $metadata->makerNotes->vendor);
        self::assertSame(strlen($makerNote), $metadata->makerNotes->length);
        self::assertSame(sha1($makerNote), $metadata->makerNotes->sha1);
        self::assertNull($metadata->iccProfile);
        self::assertSame([], $metadata->iccSegments);
    }

    #[Test]
    public function deduplicatesXmpPacketsByHash(): void
    {
        $xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" /></x:xmpmeta>';

        $jpeg = "\xFF\xD8"
            . $this->segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmp)
            . $this->segment(self::MARKER_APP1, self::XMP_SIGNATURE . $xmp)
            . "\xFF\xD9";

        $path = $this->writeTempFile($jpeg);

        try {
            $metadata = (new MetadataReader())->read($path);
        } finally {
            @unlink($path);
        }

        self::assertCount(1, $metadata->xmpBlobs);
        self::assertSame($xmp, $metadata->xmpBlobs[0]);
    }

    /**
     * Writes the provided binary payload to a temporary file and returns its path.
     *
     * @param string $payload Binary payload to persist on disk.
     *
     * @return string Absolute path to the temporary file containing the payload.
     */
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
     * Builds a baseline start of frame payload with three colour components.
     *
     * @param int $precision Sample precision reported by the SOF marker.
     * @param int $height    Frame height in image lines.
     * @param int $width     Frame width in samples per line.
     *
     * @return string Serialized SOF payload excluding marker and length fields.
     */
    private function buildBaselineStartOfFramePayload(int $precision, int $height, int $width): string
    {
        $components = [
            [1, 0x22, 0],
            [2, 0x11, 1],
            [3, 0x11, 1],
        ];

        $payload = pack('CnnC', $precision, $height, $width, count($components));

        foreach ($components as [$id, $sampling, $table]) {
            $payload .= pack('CCC', $id, $sampling, $table);
        }

        return $payload;
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
