<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Traits\NormalisesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function chr;
use function fopen;
use function fwrite;
use function hex2bin;
use function iconv;
use function pack;
use function rewind;
use function strlen;
use function substr;

/**
 * Exercises the ISO BMFF extractor against synthetic container layouts.
 */
#[CoversClass(IsoBmffExtractor::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesTrait(NormalisesOffsets::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesClass(IsoBmffDataReferenceMap::class)]
#[UsesClass(IsoBmffUnresolvedItem::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(ConstructionMethod::class)]
final class IsoBmffExtractorTest extends TestCase
{
    /**
     * Ensures EXIF blobs embedded directly in the meta box are returned.
     */
    #[Test]
    public function extractExifFromExifBox(): void
    {
        $exifPayload = "Exif\0\0primary-exif";
        $meta        = $this->fullBox('meta', $this->box('Exif', $exifPayload));
        $ftyp        = $this->box('ftyp', 'isom');
        $data        = $ftyp . $meta;

        $extractor           = $this->createExtractor($data);
        [$exifs, $xmps, $qt] = $extractor->extract();

        self::assertSame(['primary-exif'], $exifs);
        self::assertSame([], $xmps);
        self::assertNull($qt);
    }

    /**
     * Ensures QuickTime meta boxes without a full box header are parsed correctly.
     */
    #[Test]
    public function extractExifFromNonFullMetaBox(): void
    {
        $exifPayload = "Exif\0\0quicktime-exif";
        $meta        = $this->box('meta', $this->box('Exif', $exifPayload));
        $ftyp        = $this->box('ftyp', 'qt  ');
        $data        = $ftyp . $meta;

        $extractor           = $this->createExtractor($data);
        [$exifs, $xmps, $qt] = $extractor->extract();

        self::assertSame(['quicktime-exif'], $exifs);
        self::assertSame([], $xmps);
        self::assertNull($qt);
    }

    /**
     * Ensures short numeric payloads in QuickTime data boxes are tolerated.
     */
    #[Test]
    public function tolerateShortNumericQuickTimePayloads(): void
    {
        $keyName = 'com.apple.quicktime.live-photo.auto';

        $keysPayload = pack('N', 1);
        $keysPayload .= pack('N', 8 + strlen($keyName));
        $keysPayload .= 'mdta';
        $keysPayload .= $keyName;
        $keys = $this->fullBox('keys', $keysPayload);

        $dataPayload = pack('N', 0x16) . pack('N', 0) . "\x01";
        $data        = $this->box('data', $dataPayload);
        $entry       = $this->box(pack('N', 1), $data);
        $ilst        = $this->box('ilst', $entry);

        $meta = $this->fullBox('meta', $keys . $ilst);
        $ftyp = $this->box('ftyp', 'qt  ');

        $extractor       = $this->createExtractor($ftyp . $meta);
        [, , $quickTime] = $extractor->extract();

        self::assertNotNull($quickTime);
        self::assertArrayHasKey($keyName, $quickTime->keys);
        self::assertSame("\x01", $quickTime->keys[$keyName]);
    }

    /**
     * Ensures short int payloads in QuickTime data boxes are tolerated.
     */
    #[Test]
    public function tolerateShortIntegerQuickTimePayloads(): void
    {
        $keyName = 'com.apple.quicktime.live-photo.auto';

        $keysPayload = pack('N', 1);
        $keysPayload .= pack('N', 8 + strlen($keyName));
        $keysPayload .= 'mdta';
        $keysPayload .= $keyName;
        $keys = $this->fullBox('keys', $keysPayload);

        $dataPayload = pack('N', 0x15) . pack('N', 0) . "\x01";
        $data        = $this->box('data', $dataPayload);
        $entry       = $this->box(pack('N', 1), $data);
        $ilst        = $this->box('ilst', $entry);

        $meta = $this->fullBox('meta', $keys . $ilst);
        $ftyp = $this->box('ftyp', 'qt  ');

        $extractor       = $this->createExtractor($ftyp . $meta);
        [, , $quickTime] = $extractor->extract();

        self::assertNotNull($quickTime);
        self::assertArrayHasKey($keyName, $quickTime->keys);
        self::assertSame("\x01", $quickTime->keys[$keyName]);
    }

    /**
     * Verifies fragmented EXIF data referenced via iloc extents is reassembled.
     */
    #[Test]
    public function resolveIlocMultiExtent(): void
    {
        $exifBlob = "Exif\0\0segment-onesegment-two";
        $part1    = substr($exifBlob, 0, 10);
        $part2    = substr($exifBlob, 10);

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $infe        = $this->box('infe', $infePayload);
        $iinfPayload = "\0\0\0\0" . pack('n', 1) . $infe;

        $ilocBuilder = function (int $offset1, int $offset2, int $len1, int $len2): string {
            $payload = "\0\0\0\0"; // version/flags
            $payload .= "\x44"; // offset/length size = 4 bytes each
            $payload .= "\0";   // base offset size = 0
            $payload .= pack('n', 1); // item count
            $payload .= pack('n', 1); // item id
            $payload .= pack('n', 0); // data reference index
            $payload .= pack('n', 2); // extent count
            $payload .= pack('N', $offset1) . pack('N', $len1);
            $payload .= pack('N', $offset2) . pack('N', $len2);

            return $this->box('iloc', $payload);
        };

        $iinf = $this->box('iinf', $iinfPayload);

        // Build once to compute offsets
        $metaPayload = $iinf . $ilocBuilder(0, 0, strlen($part1), strlen($part2));
        $meta        = $this->fullBox('meta', $metaPayload);
        $ftyp        = $this->box('ftyp', 'isom');
        $mdatPayload = $part1 . $part2;
        $mdat        = $this->box('mdat', $mdatPayload);

        $offsetBase = strlen($ftyp) + strlen($meta) + 8; // mdat payload offset
        $iloc       = $ilocBuilder($offsetBase, $offsetBase + strlen($part1), strlen($part1), strlen($part2));
        $meta       = $this->fullBox('meta', $iinf . $iloc);
        $data       = $ftyp . $meta . $mdat;

        $extractor = $this->createExtractor($data);
        [$exifs]   = $extractor->extract();

        self::assertSame(['segment-onesegment-two'], $exifs);
    }

    /**
     * Verifies iloc version 1 parsing handles index_size nibble correctly.
     *
     * Version 1 iloc boxes pack base_offset_size and index_size in a single byte
     * (high and low nibbles respectively). This test ensures the parser reads
     * the nibbles from the same byte rather than consuming an extra byte.
     */
    #[Test]
    public function resolveIlocVersion1(): void
    {
        $exifBlob = "Exif\0\0version-one-data";

        // Build infe for Exif item (version 2 infe with item_ID as 16-bit)
        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $infe        = $this->box('infe', $infePayload);
        $iinfPayload = "\0\0\0\0" . pack('n', 1) . $infe;
        $iinf        = $this->box('iinf', $iinfPayload);

        // Build version 1 iloc box
        $ilocBuilder = function (int $offset, int $length): string {
            $payload = "\x01\0\0\0"; // version=1, flags=0
            $payload .= "\x44";      // offset_size=4, length_size=4
            $payload .= "\x00";      // base_offset_size=0 (high nibble), index_size=0 (low nibble)
            $payload .= pack('n', 1); // item_count = 1

            // Item entry for version 1:
            $payload .= pack('n', 1);    // item_id = 1
            $payload .= pack('n', 0);    // construction_method (high 4 bits) + reserved (v1/v2)
            $payload .= pack('n', 0);    // data_reference_index = 0
            $payload .= pack('n', 1);    // extent_count = 1
            // No extent_index since index_size=0
            $payload .= pack('N', $offset); // extent_offset
            $payload .= pack('N', $length); // extent_length

            return $this->box('iloc', $payload);
        };

        // Calculate layout
        $metaPayload = $iinf . $ilocBuilder(0, strlen($exifBlob));
        $meta        = $this->fullBox('meta', $metaPayload);
        $ftyp        = $this->box('ftyp', 'heic');
        $mdat        = $this->box('mdat', $exifBlob);

        $offsetBase = strlen($ftyp) + strlen($meta) + 8; // mdat payload starts after header
        $iloc       = $ilocBuilder($offsetBase, strlen($exifBlob));
        $meta       = $this->fullBox('meta', $iinf . $iloc);
        $data       = $ftyp . $meta . $mdat;

        $extractor = $this->createExtractor($data);
        [$exifs]   = $extractor->extract();

        self::assertSame(['version-one-data'], $exifs);
    }

    /**
     * Ensures XMP payloads are collected from uuid boxes and item locations.
     */
    #[Test]
    public function extractXmpFromUuidAndItem(): void
    {
        $uuidGuid  = hex2bin('be7acfcb97a942e89c71999491e3afac');
        $uuidXmp   = '<x:xmpmeta xmlns:x="adobe:ns:meta/">uuid</x:xmpmeta>';
        $directXmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/">direct</x:xmpmeta>';
        $itemXmp   = '<x:xmpmeta xmlns:x="adobe:ns:meta/">item</x:xmpmeta>';

        $uuidBox = $this->box('uuid', $uuidGuid . $uuidXmp);

        $infePayload = "\x02\0\0\0" . pack('n', 2) . pack('n', 0) . 'xmp' . "\0" . 'application/rdf+xml' . "\0\0";
        $infe        = $this->box('infe', $infePayload);
        $iinfPayload = "\0\0\0\0" . pack('n', 1) . $infe;
        $iinf        = $this->box('iinf', $iinfPayload);

        $pitmPayload = "\0\0\0\0" . pack('n', 2);
        $pitm        = $this->box('pitm', $pitmPayload);

        $ilocBuilder = function (int $offset, int $length): string {
            $payload = "\0\0\0\0";
            $payload .= "\x44"; // offset/length = 4 bytes
            $payload .= "\0";
            $payload .= pack('n', 1);
            $payload .= pack('n', 2);
            $payload .= pack('n', 0);
            $payload .= pack('n', 1);
            $payload .= pack('N', $offset) . pack('N', $length);

            return $this->box('iloc', $payload);
        };

        $xmpData     = $itemXmp;
        $metaPayload = $pitm . $iinf . $ilocBuilder(0, strlen($xmpData)) . $this->box('XMP ', $directXmp);
        $meta        = $this->fullBox('meta', $metaPayload);
        $ftyp        = $this->box('ftyp', 'isom');
        $mdat        = $this->box('mdat', $xmpData);

        $offsetBase = strlen($ftyp) + strlen($uuidBox) + strlen($meta) + 8;
        $iloc       = $ilocBuilder($offsetBase, strlen($xmpData));
        $meta       = $this->fullBox('meta', $pitm . $iinf . $iloc . $this->box('XMP ', $directXmp));
        $data       = $ftyp . $uuidBox . $meta . $mdat;

        $extractor = $this->createExtractor($data);
        [, $xmps]  = $extractor->extract();

        self::assertSame([$itemXmp, $directXmp, $uuidXmp], $xmps);
    }

    /**
     * Confirms QuickTime identifiers are populated from keys and mdta boxes.
     */
    #[Test]
    public function readContentIdentifierFromKeysOrMdta(): void
    {
        $keysValue = 'id-from-keys';
        $mdtaValue = 'id-from-mdta';

        $keysFile       = $this->createFileWithQuickTimeKeys($keysValue);
        $keysExtractor  = $this->createExtractor($keysFile);
        [, , $keysMeta] = $keysExtractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $keysMeta);
        self::assertSame($keysValue, $keysMeta->contentIdentifier());

        $mdtaFile       = $this->createFileWithMdtaIdentifier($mdtaValue);
        $mdtaExtractor  = $this->createExtractor($mdtaFile);
        [, , $mdtaMeta] = $mdtaExtractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $mdtaMeta);
        self::assertSame($mdtaValue, $mdtaMeta->contentIdentifier());
    }

    /**
     * Verifies UTF-16BE QuickTime data payloads are converted to UTF-8.
     */
    #[Test]
    public function decodeUtf16DataBoxToUtf8(): void
    {
        $value   = 'Identifier UTF16';
        $encoded = iconv('UTF-8', 'UTF-16BE', $value);
        self::assertIsString($encoded);
        $utf16Payload = $encoded . "\0\0";
        $file         = $this->createQuickTimeKeysFileWithData(2, $utf16Payload);
        $extractor    = $this->createExtractor($file);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertSame($value, $qtMeta->contentIdentifier());
    }

    /**
     * Verifies MacRoman QuickTime data payloads are converted to UTF-8.
     */
    #[Test]
    public function decodeMacRomanDataBoxToUtf8(): void
    {
        $value        = 'Café Society';
        $macPayload   = 'Caf' . chr(0x8E) . ' Society' . "\0";
        $file         = $this->createQuickTimeKeysFileWithData(7, $macPayload);
        $extractor    = $this->createExtractor($file);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertSame($value, $qtMeta->contentIdentifier());
    }

    /**
     * Verifies legacy QuickTime four-character metadata tags are accepted.
     */
    #[Test]
    public function readLegacyFourCcTag(): void
    {
        $legacyKey = chr(0xA9) . 'nam';
        $value     = 'Legacy Title';
        $dataBox   = $this->box('data', pack('N', 1) . pack('N', 0) . $value);
        $ilstEntry = $this->box($legacyKey, $dataBox);
        $ilst      = $this->box('ilst', $ilstEntry);
        $meta      = $this->box('meta', "\0\0\0\0" . $ilst);
        $moov      = $this->box('moov', $meta);
        $ftyp      = $this->box('ftyp', 'qt  ');

        $extractor           = $this->createExtractor($ftyp . $moov);
        [, , $quickTimeMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $quickTimeMeta);
        self::assertSame($value, $quickTimeMeta->keys[$legacyKey]);
    }

    /**
     * Verifies integer QuickTime data payloads are decoded as integers.
     */
    #[Test]
    public function decodeInt32DataBoxPayload(): void
    {
        $key          = 'com.apple.quicktime.videoOrientation';
        $payload      = pack('N', 90);
        $file         = $this->createQuickTimeKeysFileWithCustomKey($key, 0x15, $payload);
        $extractor    = $this->createExtractor($file);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertSame(90, $qtMeta->keys[$key]);
    }

    /**
     * Verifies string QuickTime metadata values can be coerced to integers.
     */
    #[Test]
    public function coerceQuickTimeStringValuesToInt(): void
    {
        $key          = 'com.apple.quicktime.videoOrientation';
        $payload      = '180';
        $file         = $this->createQuickTimeKeysFileWithCustomKey($key, 1, $payload);
        $extractor    = $this->createExtractor($file);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertSame(180, $qtMeta->keys[$key]);
    }

    /**
     * Verifies QuickTime string values are coerced into floats when configured.
     */
    #[Test]
    public function coerceQuickTimeStringValuesToFloat(): void
    {
        $key          = 'com.apple.quicktime.location.accuracy.horizontal';
        $payload      = '12.5';
        $file         = $this->createQuickTimeKeysFileWithCustomKey($key, 1, $payload);
        $extractor    = $this->createExtractor($file);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertSame(12.5, $qtMeta->keys[$key]);
    }

    /**
     * Verifies QuickTime string values are coerced into booleans when configured.
     */
    #[Test]
    public function coerceQuickTimeStringValuesToBool(): void
    {
        $key          = 'com.apple.quicktime.isHDRVideo';
        $payload      = 'true';
        $file         = $this->createQuickTimeKeysFileWithCustomKey($key, 1, $payload);
        $extractor    = $this->createExtractor($file);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertTrue($qtMeta->keys[$key]);
    }

    /**
     * Ensures external data references are captured as unresolved items.
     */
    #[Test]
    public function collectsUnresolvedExternalItemReferences(): void
    {
        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0\0\0";
        $infe        = $this->box('infe', $infePayload);
        $iinfPayload = "\0\0\0\0" . pack('n', 1) . $infe;
        $iinf        = $this->box('iinf', $iinfPayload);

        $drefEntry = $this->fullBox('url ', 'file://example');
        $dref      = $this->fullBox('dref', pack('N', 1) . $drefEntry);
        $dinf      = $this->box('dinf', $dref);

        $payload = "\0\0\0\0";
        $payload .= "\x44";
        $payload .= "\0";
        $payload .= pack('n', 1);
        $payload .= pack('n', 1);
        $payload .= pack('n', 1); // data_reference_index = 1
        $payload .= pack('n', 1);
        $payload .= pack('N', 0) . pack('N', 4);
        $iloc = $this->box('iloc', $payload);

        $meta = $this->fullBox('meta', $iinf . $iloc . $dinf);
        $ftyp = $this->box('ftyp', 'isom');
        $data = $ftyp . $meta;

        $extractor                                        = $this->createExtractor($data);
        [$exifs, , , , $dataReferences, $unresolvedItems] = $extractor->extract();

        self::assertSame([], $exifs);
        self::assertInstanceOf(IsoBmffDataReferenceMap::class, $dataReferences);
        $reference = $dataReferences->referenceForIndex(1);
        self::assertNotNull($reference);
        self::assertSame('url ', $reference->type);
        self::assertSame('file://example', $reference->uri);
        self::assertFalse($reference->selfContained);

        self::assertCount(1, $unresolvedItems);
        $unresolved = $unresolvedItems[0];
        /** @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(IsoBmffUnresolvedItem::class, $unresolved);
        self::assertSame(1, $unresolved->itemId);
        self::assertSame(1, $unresolved->dataReferenceIndex);
        self::assertSame(ConstructionMethod::FileOffset, $unresolved->constructionMethod);
        self::assertSame($reference, $unresolved->dataReference);
    }

    /**
     * Verifies invalid extent definitions trigger a parse error.
     */
    #[Test]
    public function invalidBoxSizesThrowParseError(): void
    {
        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        $ilocPayload = "\0\0\0\0";
        $ilocPayload .= "\x44";
        $ilocPayload .= "\0";
        $ilocPayload .= pack('n', 1);
        $ilocPayload .= pack('n', 1);
        $ilocPayload .= pack('n', 0);
        $ilocPayload .= pack('n', 1);
        $ilocPayload .= pack('N', 0x7FFF0000) . pack('N', 8);
        $iloc = $this->box('iloc', $ilocPayload);

        $meta = $this->fullBox('meta', $iinf . $iloc);
        $data = $this->box('ftyp', 'isom') . $meta;

        $extractor = $this->createExtractor($data);

        try {
            $extractor->extract();
            self::fail('Expected ParseError for invalid iloc extent');
        } catch (ParseError $exception) {
            self::assertStringContainsString('iloc', $exception->getMessage());
        }
    }

    /**
     * Confirms item reference entries are mapped into relationships.
     */
    #[Test]
    public function parseIrefRelationships(): void
    {
        // SingleItemTypeReferenceBox is a plain Box, not a FullBox (no version/flags)
        $entryPayload = pack('n', 1) . pack('n', 2) . pack('n', 2) . pack('n', 3);
        $entry        = $this->box('dimg', $entryPayload);
        $iref         = $this->fullBox('iref', $entry);
        $meta         = $this->fullBox('meta', $iref);
        $ftyp         = $this->box('ftyp', 'isom');

        $extractor              = $this->createExtractor($ftyp . $meta);
        [, , , $itemReferences] = $extractor->extract();

        self::assertInstanceOf(IsoBmffItemReferenceMap::class, $itemReferences);
        self::assertSame([1], $itemReferences->fromItemIds());

        $references = $itemReferences->referencesFor(1);
        self::assertCount(2, $references);
        self::assertSame('dimg', $references[0]->relation);
        self::assertSame(2, $references[0]->toItemId);
        self::assertSame(3, $references[1]->toItemId);
    }

    /**
     * Ensures iloc construction_method=1 resolves offsets relative to idat data.
     */
    #[Test]
    public function resolveIlocIdatConstructionMethod(): void
    {
        $exifPayload = "Exif\0\0idat-exif";

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        // iloc v1: offset_size(4)|length_size(4), base_offset_size(0)|index_size(0) in ONE byte
        $payload = "\x44";       // offset_size=4, length_size=4
        $payload .= "\x00";       // base_offset_size=0 (high nibble), index_size=0 (low nibble)
        $payload .= pack('n', 1); // item_count = 1
        $payload .= pack('n', 1); // item_id = 1
        $payload .= pack('n', 0x1000); // construction_method=1 (high 4 bits)
        $payload .= pack('n', 0); // data_reference_index = 0
        $payload .= pack('n', 1); // extent_count = 1
        $payload .= pack('N', 0); // extent_offset = 0
        $payload .= pack('N', strlen($exifPayload)); // extent_length
        $iloc = $this->fullBox('iloc', $payload, 1, 0);

        $idat = $this->box('idat', $exifPayload);
        $meta = $this->fullBox('meta', $iinf . $iloc . $idat);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor                         = $this->createExtractor($ftyp . $meta);
        [$exifs, , , , , $unresolvedItems] = $extractor->extract();

        self::assertSame(['idat-exif'], $exifs);
        self::assertSame([], $unresolvedItems);
    }

    /**
     * Verifies idat-relative iloc extents are bounds-checked.
     */
    #[Test]
    public function rejectIlocIdatExtentOutsidePayload(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iloc extent length exceeds idat payload');

        $exifPayload = "Exif\0\0idat";

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        // iloc v1: base_offset_size and index_size share ONE byte
        $payload = "\x44";       // offset_size=4, length_size=4
        $payload .= "\x00";       // base_offset_size=0, index_size=0
        $payload .= pack('n', 1); // item_count = 1
        $payload .= pack('n', 1); // item_id = 1
        $payload .= pack('n', 0x1000); // construction_method=1
        $payload .= pack('n', 0); // data_reference_index = 0
        $payload .= pack('n', 1); // extent_count = 1
        $payload .= pack('N', 0); // extent_offset = 0
        $payload .= pack('N', strlen($exifPayload) + 1); // extent_length (too large!)
        $iloc = $this->fullBox('iloc', $payload, 1, 0);

        $idat = $this->box('idat', $exifPayload);
        $meta = $this->fullBox('meta', $iinf . $iloc . $idat);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Ensures iloc construction_method=2 resolves via item references and extent indices.
     */
    #[Test]
    public function resolveIlocItemOffsetWithExtentIndex(): void
    {
        $exifPayload = "Exif\0\0item-ref";

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        // SingleItemTypeReferenceBox is a plain Box, not a FullBox
        $irefEntry = $this->box('dimg', pack('n', 1) . pack('n', 1) . pack('n', 2));
        $iref      = $this->fullBox('iref', $irefEntry);

        // iloc v1: base_offset_size and index_size share ONE byte
        $ilocBuilder = function (int $item2Offset, int $item2Length, int $item1Length): string {
            $payload = "\x44"; // offset_size=4, length_size=4
            $payload .= "\x02"; // base_offset_size=0 (high nibble), index_size=2 (low nibble)
            $payload .= pack('n', 2); // item_count = 2

            $payload .= pack('n', 1);
            $payload .= pack('n', 0x2000);
            $payload .= pack('n', 0);
            $payload .= pack('n', 1);
            $payload .= pack('n', 1);
            $payload .= pack('N', 0);
            $payload .= pack('N', $item1Length);

            $payload .= pack('n', 2);
            $payload .= pack('n', 0x0000);
            $payload .= pack('n', 0);
            $payload .= pack('n', 1);
            $payload .= pack('n', 0);
            $payload .= pack('N', $item2Offset);
            $payload .= pack('N', $item2Length);

            return $this->fullBox('iloc', $payload, 1, 0);
        };

        $placeholderIloc = $ilocBuilder(0, strlen($exifPayload), strlen($exifPayload));
        $meta            = $this->fullBox('meta', $iinf . $iref . $placeholderIloc);
        $ftyp            = $this->box('ftyp', 'isom');
        $mdat            = $this->box('mdat', $exifPayload);

        $offsetBase = strlen($ftyp) + strlen($meta) + 8;
        $iloc       = $ilocBuilder($offsetBase, strlen($exifPayload), strlen($exifPayload));
        $meta       = $this->fullBox('meta', $iinf . $iref . $iloc);

        $extractor = $this->createExtractor($ftyp . $meta . $mdat);
        [$exifs]   = $extractor->extract();

        self::assertSame(['item-ref'], $exifs);
    }

    /**
     * Ensures construction_method=2 extents outside referenced items raise ParseError.
     */
    #[Test]
    public function rejectIlocItemOffsetExtentOutsideReference(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iloc extent outside referenced item');

        $exifPayload = "Exif\0\0ref";

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        // SingleItemTypeReferenceBox is a plain Box, not a FullBox
        $irefEntry = $this->box('dimg', pack('n', 1) . pack('n', 1) . pack('n', 2));
        $iref      = $this->fullBox('iref', $irefEntry);

        // iloc v1: base_offset_size and index_size share ONE byte
        $ilocBuilder = function (int $item2Offset, int $item2Length, int $item1Length): string {
            $payload = "\x44"; // offset_size=4, length_size=4
            $payload .= "\x02"; // base_offset_size=0 (high nibble), index_size=2 (low nibble)
            $payload .= pack('n', 2); // item_count = 2

            $payload .= pack('n', 1);
            $payload .= pack('n', 0x2000);
            $payload .= pack('n', 0);
            $payload .= pack('n', 1);
            $payload .= pack('n', 0);
            $payload .= pack('N', 0);
            $payload .= pack('N', $item1Length + 1);

            $payload .= pack('n', 2);
            $payload .= pack('n', 0x0000);
            $payload .= pack('n', 0);
            $payload .= pack('n', 1);
            $payload .= pack('n', 0);
            $payload .= pack('N', $item2Offset);
            $payload .= pack('N', $item2Length);

            return $this->fullBox('iloc', $payload, 1, 0);
        };

        $placeholderIloc = $ilocBuilder(0, strlen($exifPayload), strlen($exifPayload));
        $meta            = $this->fullBox('meta', $iinf . $iref . $placeholderIloc);
        $ftyp            = $this->box('ftyp', 'isom');
        $mdat            = $this->box('mdat', $exifPayload);

        $offsetBase = strlen($ftyp) + strlen($meta) + 8;
        $iloc       = $ilocBuilder($offsetBase, strlen($exifPayload), strlen($exifPayload));
        $meta       = $this->fullBox('meta', $iinf . $iref . $iloc);

        $extractor = $this->createExtractor($ftyp . $meta . $mdat);
        $extractor->extract();
    }

    /**
     * Ensures non-zero data_reference_index entries are tracked as unresolved.
     */
    #[Test]
    public function trackExternalDataReferenceWithoutResolving(): void
    {
        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        // iloc v1: base_offset_size and index_size share ONE byte
        $payload = "\x44";       // offset_size=4, length_size=4
        $payload .= "\x00";       // base_offset_size=0, index_size=0
        $payload .= pack('n', 1); // item_count = 1
        $payload .= pack('n', 1); // item_id = 1
        $payload .= pack('n', 0x0000); // construction_method=0
        $payload .= pack('n', 1); // data_reference_index = 1 (external)
        $payload .= pack('n', 1); // extent_count = 1
        $payload .= pack('N', 0); // extent_offset = 0
        $payload .= pack('N', 4); // extent_length = 4
        $iloc = $this->fullBox('iloc', $payload, 1, 0);

        $drefEntry = $this->fullBox('url ', 'https://example.test/exif');
        $dref      = $this->fullBox('dref', pack('N', 1) . $drefEntry);
        $dinf      = $this->box('dinf', $dref);

        $meta = $this->fullBox('meta', $iinf . $iloc . $dinf);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor                                        = $this->createExtractor($ftyp . $meta);
        [$exifs, , , , $dataReferences, $unresolvedItems] = $extractor->extract();

        self::assertSame([], $exifs);
        self::assertInstanceOf(IsoBmffDataReferenceMap::class, $dataReferences);
        $reference = $dataReferences->referenceForIndex(1);
        self::assertNotNull($reference);
        self::assertSame('url ', $reference->type);
        self::assertSame('https://example.test/exif', $reference->uri);

        self::assertCount(1, $unresolvedItems);
        $unresolved = $unresolvedItems[0];
        self::assertSame(1, $unresolved->itemId);
        self::assertSame(1, $unresolved->dataReferenceIndex);
        self::assertSame(ConstructionMethod::FileOffset, $unresolved->constructionMethod);
        self::assertSame($reference, $unresolved->dataReference);
    }

    /**
     * Verifies iref reference counts exceeding the maximum are rejected.
     */
    #[Test]
    public function rejectExcessiveIrefReferenceCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iref reference count exceeds maximum allowed');

        // SingleItemTypeReferenceBox is a plain Box, not a FullBox
        $entryPayload = pack('n', 1) . pack('n', 10001);
        $entry        = $this->box('dimg', $entryPayload);
        $iref         = $this->fullBox('iref', $entry);
        $meta         = $this->fullBox('meta', $iref);
        $ftyp         = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds a QuickTime structure containing a `keys` metadata entry.
     *
     * @param string $value Identifier value stored under the QuickTime key.
     */
    private function createFileWithQuickTimeKeys(string $value): string
    {
        return $this->createQuickTimeKeysFileWithData(1, $value);
    }

    /**
     * Builds a QuickTime `keys` metadata structure with a custom `data` payload.
     *
     * @param int    $type        Numeric QuickTime data type identifier.
     * @param string $encodedData Raw payload bytes stored inside the `data` box.
     */
    private function createQuickTimeKeysFileWithData(int $type, string $encodedData): string
    {
        return $this->createQuickTimeKeysFileWithCustomKey(
            'com.apple.quicktime.content.identifier',
            $type,
            $encodedData
        );
    }

    /**
     * Builds a QuickTime `keys` metadata structure with a custom `data` payload.
     *
     * @param string $key         QuickTime metadata key identifier.
     * @param int    $type        Numeric QuickTime data type identifier.
     * @param string $encodedData Raw payload bytes stored inside the `data` box.
     */
    private function createQuickTimeKeysFileWithCustomKey(string $key, int $type, string $encodedData): string
    {
        $keysEntry = pack('N', 8 + strlen($key))
            . 'mdta'
            . $key;
        $keys = $this->box('keys', "\0\0\0\0" . pack('N', 1) . $keysEntry);

        $dataBox   = $this->box('data', pack('N', $type) . pack('N', 0) . $encodedData);
        $ilstEntry = $this->box(pack('N', 1), $dataBox);
        $ilst      = $this->box('ilst', $ilstEntry);

        $metaPayload = "\0\0\0\0" . $keys . $ilst;
        $meta        = $this->box('meta', $metaPayload);
        $moov        = $this->box('moov', $this->box('udta', $meta));

        return $this->box('ftyp', 'isom') . $moov;
    }

    /**
     * Builds a QuickTime structure containing an mdta free-form identifier.
     *
     * @param string $value Identifier value encoded within the mdta structure.
     */
    private function createFileWithMdtaIdentifier(string $value): string
    {
        $mean     = $this->box('mean', pack('N', 1) . pack('N', 0) . 'com.apple.quicktime');
        $name     = $this->box('name', pack('N', 1) . pack('N', 0) . 'content.identifier');
        $data     = $this->box('data', pack('N', 1) . pack('N', 0) . $value);
        $freeform = $this->box('----', $mean . $name . $data);
        $ilst     = $this->box('ilst', $freeform);

        $metaPayload = "\0\0\0\0" . $ilst;
        $meta        = $this->box('meta', $metaPayload);
        $moov        = $this->box('moov', $meta);

        return $this->box('ftyp', 'isom') . $moov;
    }

    /**
     * Verifies that excessive iloc item counts trigger a ParseError.
     */
    #[Test]
    public function rejectsExcessiveIlocItemCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iloc item count exceeds maximum allowed');

        // Build an iloc box with itemCount > MAX_ILOC_ITEMS (10000)
        $payload = "\0\0\0\0";           // version 0, flags 0
        $payload .= "\x44";                // offset_size=4, length_size=4
        $payload .= "\0";                  // base_offset_size=0
        $payload .= pack('n', 10001);      // itemCount = 10001 (exceeds limit)

        $iloc = $this->box('iloc', $payload);
        $iinf = $this->fullBox('iinf', pack('n', 0)); // empty iinf
        $meta = $this->fullBox('meta', $iinf . $iloc);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Verifies that excessive iinf entry counts trigger a ParseError.
     */
    #[Test]
    public function rejectsExcessiveIinfEntryCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iinf entry count exceeds maximum allowed');

        // Build an iinf box with entryCount > MAX_IINF_ENTRIES (10000)
        $payload = pack('N', 10001); // version 1 uses 32-bit entry count
        $iinf    = $this->fullBox('iinf', $payload, 1, 0);
        $meta    = $this->fullBox('meta', $iinf);
        $ftyp    = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Verifies that excessive keys entry counts trigger a ParseError.
     */
    #[Test]
    public function rejectsExcessiveKeysEntryCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('keys entry count exceeds maximum allowed');

        // Build a keys box with entryCount > MAX_KEYS_ENTRIES (1000)
        $payload = pack('N', 1001); // entryCount = 1001 (exceeds limit)
        $keys    = $this->fullBox('keys', $payload);
        $meta    = $this->fullBox('meta', $keys);
        $moov    = $this->box('moov', $meta);
        $ftyp    = $this->box('ftyp', 'qt  ');

        $extractor = $this->createExtractor($ftyp . $moov);
        $extractor->extract();
    }

    /**
     * Verifies that excessive stsd entry counts trigger a ParseError.
     */
    #[Test]
    public function rejectsExcessiveStsdEntryCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('stsd entry count exceeds maximum allowed');

        // Build a track with stsd containing entryCount > MAX_STSD_ENTRIES (100)
        $payload = pack('N', 101); // entryCount = 101 (exceeds limit)
        $stsd    = $this->fullBox('stsd', $payload);
        $stbl    = $this->box('stbl', $stsd);
        $minf    = $this->box('minf', $stbl);

        // Create a minimal hdlr box for video handler
        $hdlrPayload = pack('N', 0);      // version/flags
        $hdlrPayload .= "\0\0\0\0";       // pre_defined
        $hdlrPayload .= 'vide';           // handler_type
        $hdlrPayload .= str_repeat("\0", 12); // reserved
        $hdlr = $this->box('hdlr', $hdlrPayload);

        $mdia = $this->box('mdia', $hdlr . $minf);
        $trak = $this->box('trak', $mdia);
        $moov = $this->box('moov', $trak);
        $ftyp = $this->box('ftyp', 'qt  ');

        $extractor = $this->createExtractor($ftyp . $moov);
        $extractor->extract();
    }

    /**
     * Wraps raw bytes in a temporary stream-backed extractor.
     *
     * @param string $data Raw ISO BMFF file contents.
     */
    private function createExtractor(string $data): IsoBmffExtractor
    {
        $fh = fopen('php://temp', 'wb+');
        if ($fh === false) {
            self::fail('Unable to open temporary stream for ISO BMFF test data.');
        }

        fwrite($fh, $data);
        rewind($fh);

        return new IsoBmffExtractor(new Stream($fh, strlen($data)));
    }

    /**
     * Creates a standard ISO BMFF box header around a payload.
     *
     * @param string $type    Four-character box type.
     * @param string $payload Raw box payload.
     *
     * @return string Serialized box bytes containing the header and payload.
     */
    private function box(string $type, string $payload): string
    {
        $size = 8 + strlen($payload);

        return pack('N', $size) . $type . $payload;
    }

    /**
     * Creates a full box including version and flags fields.
     *
     * @param string $type    Four-character box type.
     * @param string $payload Raw box payload excluding version/flags.
     * @param int    $version Box version field.
     * @param int    $flags   Box flags field.
     *
     * @return string Serialized full box bytes with version and flags header.
     */
    private function fullBox(string $type, string $payload, int $version = 0, int $flags = 0): string
    {
        $header = chr($version) . chr(($flags >> 16) & 0xFF) . chr(($flags >> 8) & 0xFF) . chr($flags & 0xFF);

        return $this->box($type, $header . $payload);
    }
}
