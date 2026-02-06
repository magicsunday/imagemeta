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
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParser;
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
 * Exercises ISO BMFF parsing with synthetic container layouts and box hierarchies.
 * It verifies EXIF and XMP extraction paths as well as QuickTime metadata key capture.
 * The tests cover iloc item resolution, construction methods, and data reference handling.
 * Error cases ensure malformed boxes and invalid sizes raise ParseError without crashes.
 */
#[CoversClass(IsoBmffParser::class)]
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
final class IsoBmffParserTest extends TestCase
{
    /**
     * Extracts EXIF data from a dedicated Exif box inside a full meta box.
     * This verifies the extractor returns the EXIF payload and leaves XMP/QuickTime empty.
     *
     * @return void
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
     * Parses EXIF from a non-full meta box in a QuickTime-branded file.
     * This confirms EXIF extraction works for QuickTime-style meta boxes.
     *
     * @return void
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
     * Provides a short numeric data payload for a QuickTime metadata key.
     * This ensures the parser tolerates truncated numeric payloads without failing.
     *
     * @return void
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
     * Provides a short integer data payload for a QuickTime metadata key.
     * This confirms short integer payloads are preserved instead of throwing.
     *
     * @return void
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
     * Resolves iloc items that are split across multiple extents.
     * This verifies the extractor concatenates extents to reassemble the EXIF blob.
     *
     * @return void
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
     * Builds a version 1 iloc box with 16-bit item IDs and resolves the EXIF item.
     * This confirms iloc v1 parsing and item reconstruction produce the expected EXIF payload.
     *
     * @return void
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
     * Builds a version 2 iloc box and resolves the EXIF item using 32-bit item IDs.
     * This ensures iloc v2 does not depend on flags to determine item_ID width.
     *
     * @return void
     */
    #[Test]
    public function resolveIlocVersion2Uses32BitItemId(): void
    {
        $exifBlob = "Exif\0\0version-two-data";

        // Build infe for Exif item (version 2 infe with item_ID as 16-bit)
        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $infe        = $this->box('infe', $infePayload);
        $iinfPayload = "\0\0\0\0" . pack('n', 1) . $infe;
        $iinf        = $this->box('iinf', $iinfPayload);

        // Build version 2 iloc box (item_count/item_ID are 32-bit)
        $ilocBuilder = function (int $offset, int $length): string {
            $payload = "\x44";       // offset_size=4, length_size=4
            $payload .= "\x00";       // base_offset_size=0 (high nibble), index_size=0 (low nibble)
            $payload .= pack('N', 1); // item_count = 1

            $payload .= pack('N', 1); // item_id = 1 (32-bit)
            $payload .= pack('n', 0); // construction_method (v2)
            $payload .= pack('n', 0); // data_reference_index = 0
            $payload .= pack('n', 1); // extent_count = 1
            $payload .= pack('N', $offset); // extent_offset
            $payload .= pack('N', $length); // extent_length

            return $this->fullBox('iloc', $payload, 2, 0);
        };

        $metaPayload = $iinf . $ilocBuilder(0, strlen($exifBlob));
        $meta        = $this->fullBox('meta', $metaPayload);
        $ftyp        = $this->box('ftyp', 'heic');
        $mdat        = $this->box('mdat', $exifBlob);

        $offsetBase = strlen($ftyp) + strlen($meta) + 8;
        $iloc       = $ilocBuilder($offsetBase, strlen($exifBlob));
        $meta       = $this->fullBox('meta', $iinf . $iloc);
        $data       = $ftyp . $meta . $mdat;

        $extractor = $this->createExtractor($data);
        [$exifs]   = $extractor->extract();

        self::assertSame(['version-two-data'], $exifs);
    }

    /**
     * Rejects iloc boxes that use a non-conformant offset_size nibble.
     * This ensures size nibbles are limited to 0, 4, or 8 bytes.
     *
     * @return void
     */
    #[Test]
    public function rejectIlocOffsetSizeNibbleOfOne(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('invalid length field size');

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        $ilocPayload = "\x14";       // offset_size=1 (invalid), length_size=4
        $ilocPayload .= "\x00";       // base_offset_size=0, index_size=0
        $ilocPayload .= pack('n', 0); // item_count = 0
        $iloc = $this->fullBox('iloc', $ilocPayload);

        $meta = $this->fullBox('meta', $iinf . $iloc);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects iloc boxes that use a non-conformant index_size nibble.
     * This ensures size nibbles are limited to 0, 4, or 8 bytes.
     *
     * @return void
     */
    #[Test]
    public function rejectIlocIndexSizeNibbleOfTwo(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('invalid length field size');

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        $ilocPayload = "\x44";       // offset_size=4, length_size=4
        $ilocPayload .= "\x02";       // base_offset_size=0 (high), index_size=2 (invalid)
        $ilocPayload .= pack('n', 0); // item_count = 0
        $iloc = $this->fullBox('iloc', $ilocPayload, 1, 0);

        $meta = $this->fullBox('meta', $iinf . $iloc);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Extracts XMP from three sources: uuid box, item-based XMP, and direct XMP box.
     * This verifies all XMP sources are collected and returned in the expected order.
     *
     * @return void
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
     * Reads content identifiers from both QuickTime keys and mdta free-form boxes.
     * This confirms either metadata path can populate QuickTimeMeta::contentIdentifier().
     *
     * @return void
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
     * Encodes a UTF-16BE data box and decodes it back to UTF-8.
     * This verifies Unicode content identifiers are normalized correctly.
     *
     * @return void
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
     * Uses a MacRoman data payload to represent accented text.
     * This confirms legacy encodings are converted to UTF-8 strings.
     *
     * @return void
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
     * Reads a legacy four-character code key from an ilst entry.
     * This verifies that non-mdta keys are still captured in QuickTime metadata.
     *
     * @return void
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
     * Creates a udta box whose child list ends with the optional 4-byte zero terminator.
     * Per QuickTime File Format 2012 §2 "User Data Atoms", readers must tolerate this
     * trailing terminator without raising an alignment error.
     *
     * @return void
     */
    #[Test]
    public function toleratesUdtaTrailingZeroTerminator(): void
    {
        $key      = 'com.apple.quicktime.content.identifier';
        $keyEntry = pack('N', 8 + strlen($key)) . 'mdta' . $key;
        $keys     = $this->box('keys', "\0\0\0\0" . pack('N', 1) . $keyEntry);

        $dataBox   = $this->box('data', pack('N', 1) . pack('N', 0) . 'udta-terminator-value');
        $ilstEntry = $this->box(pack('N', 1), $dataBox);
        $ilst      = $this->box('ilst', $ilstEntry);

        $metaPayload = "\0\0\0\0" . $keys . $ilst;
        $meta        = $this->box('meta', $metaPayload);

        // Append 4-byte zero terminator inside udta
        $udtaPayload = $meta . "\0\0\0\0";
        $udta        = $this->box('udta', $udtaPayload);
        $moov        = $this->box('moov', $udta);
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor    = $this->createExtractor($ftyp . $moov);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertSame('udta-terminator-value', $qtMeta->keys[$key]);
    }

    /**
     * Builds a keys atom with entries from different namespaces (mdta and a custom one).
     * Verifies the parser preserves the namespace: mdta keys are stored directly while
     * non-mdta keys are prefixed with the 4-byte namespace per QuickTime File Format 2012.
     *
     * @return void
     */
    #[Test]
    public function preservesKeyNamespaceForNonMdtaKeys(): void
    {
        $mdtaKey   = 'com.apple.quicktime.content.identifier';
        $customKey = 'custom.vendor.key';

        // Build two key entries: one mdta, one with custom 'cust' namespace
        $mdtaEntry = pack('N', 8 + strlen($mdtaKey)) . 'mdta' . $mdtaKey;
        $custEntry = pack('N', 8 + strlen($customKey)) . 'cust' . $customKey;
        $keys      = $this->box('keys', "\0\0\0\0" . pack('N', 2) . $mdtaEntry . $custEntry);

        // Build ilst with two data entries mapped by index
        $dataBox1   = $this->box('data', pack('N', 1) . pack('N', 0) . 'mdta-value');
        $dataBox2   = $this->box('data', pack('N', 1) . pack('N', 0) . 'cust-value');
        $ilstEntry1 = $this->box(pack('N', 1), $dataBox1);
        $ilstEntry2 = $this->box(pack('N', 2), $dataBox2);
        $ilst       = $this->box('ilst', $ilstEntry1 . $ilstEntry2);

        $meta = $this->box('meta', "\0\0\0\0" . $keys . $ilst);
        $moov = $this->box('moov', $this->box('udta', $meta));
        $ftyp = $this->box('ftyp', 'isom');

        $extractor    = $this->createExtractor($ftyp . $moov);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);

        // mdta namespace key is stored directly by name
        self::assertSame('mdta-value', $qtMeta->keys[$mdtaKey]);

        // Non-mdta namespace key is prefixed with namespace
        $prefixedKey = 'cust:' . $customKey;
        self::assertArrayHasKey($prefixedKey, $qtMeta->keys);
        self::assertSame('cust-value', $qtMeta->keys[$prefixedKey]);
    }

    /**
     * Uses a data box with integer type and 32-bit payload.
     * This confirms numeric QuickTime values are decoded to integers.
     *
     * @return void
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
     * Provides a numeric string value for a QuickTime key.
     * This ensures numeric strings are coerced to integer values.
     *
     * @return void
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
     * Provides a decimal string value for a QuickTime key.
     * This confirms numeric strings are coerced to floats when appropriate.
     *
     * @return void
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
     * Provides a boolean-like string for a QuickTime key.
     * This verifies string values are coerced to booleans when applicable.
     *
     * @return void
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
     * Provides a data box whose type indicator byte (bits 24–31) is non-zero.
     * Per QuickTime File Format 2012, "Type Indicator" (p. 139), the indicator
     * byte must be 0; a non-zero value must trigger a ParseError.
     *
     * @return void
     */
    #[Test]
    public function rejectsNonZeroDataBoxTypeIndicatorByte(): void
    {
        // Indicator byte = 0x01, well-known type bits = 0x000001 (UTF-8)
        $invalidType = 0x01000001;
        $file        = $this->createQuickTimeKeysFileWithCustomKey(
            'com.apple.quicktime.content.identifier',
            $invalidType,
            'test-value',
        );

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('data box type indicator byte must be 0');

        $this->createExtractor($file)->extract();
    }

    /**
     * Uses a non-zero data_reference_index to create an unresolved iloc item.
     * This confirms external data references are recorded but not resolved.
     *
     * @return void
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
     * Builds an iloc extent with an invalid large offset to trigger validation.
     * This asserts a ParseError is thrown when box sizes are inconsistent.
     *
     * @return void
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
     * Parses an iref box with dimg relationships for a single item.
     * This verifies the item reference map captures multiple outgoing references.
     *
     * @return void
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
     * Resolves iloc items stored in the idat box using construction method 1.
     * This confirms idat-based extents are read and produce EXIF output.
     *
     * @return void
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
     * Sets iloc extent length larger than the idat payload.
     * This asserts a ParseError is thrown when idat extents exceed bounds.
     *
     * @return void
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
     * Builds iloc entries with index_size to reference another item.
     * This verifies extent offsets are resolved relative to referenced items.
     *
     * @return void
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
            $payload .= "\x04"; // base_offset_size=0 (high nibble), index_size=4 (low nibble)
            $payload .= pack('n', 2); // item_count = 2

            $payload .= pack('n', 1);
            $payload .= pack('n', 0x2000);
            $payload .= pack('n', 0);
            $payload .= pack('n', 1);
            $payload .= pack('N', 1);
            $payload .= pack('N', 0);
            $payload .= pack('N', $item1Length);

            $payload .= pack('n', 2);
            $payload .= pack('n', 0x0000);
            $payload .= pack('n', 0);
            $payload .= pack('n', 1);
            $payload .= pack('N', 0);
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
     * Uses an extent that exceeds the referenced item length.
     * This asserts a ParseError is thrown for invalid item offset extents.
     *
     * @return void
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
            $payload .= "\x04"; // base_offset_size=0 (high nibble), index_size=4 (low nibble)
            $payload .= pack('n', 2); // item_count = 2

            $payload .= pack('n', 1);
            $payload .= pack('n', 0x2000);
            $payload .= pack('n', 0);
            $payload .= pack('n', 1);
            $payload .= pack('N', 0);
            $payload .= pack('N', 0);
            $payload .= pack('N', $item1Length + 1);

            $payload .= pack('n', 2);
            $payload .= pack('n', 0x0000);
            $payload .= pack('n', 0);
            $payload .= pack('n', 1);
            $payload .= pack('N', 0);
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
     * Sets a non-zero data reference index to point at an external URL.
     * This confirms external references are tracked while EXIF remains unresolved.
     *
     * @return void
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
     * Creates an iref entry with a reference count above the configured maximum.
     * This ensures a ParseError is raised to prevent pathological allocations.
     *
     * @return void
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
     * This helper is used to populate the content identifier from mdta keys.
     *
     * @param string $value Identifier value stored under the QuickTime key.
     */
    private function createFileWithQuickTimeKeys(string $value): string
    {
        return $this->createQuickTimeKeysFileWithData(1, $value);
    }

    /**
     * Builds a QuickTime `keys` metadata structure with a custom `data` payload.
     * This helper lets tests vary the data type and encoding independently.
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
     * This helper is shared by tests that exercise different key/type combinations.
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
     * This helper is used to test the alternative content.identifier path.
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
     * Creates an iloc box with more items than the configured maximum.
     * This asserts the parser rejects excessive item counts early.
     *
     * @return void
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
     * Creates an iinf box with an entry count above the configured maximum.
     * This ensures the parser aborts before allocating oversized tables.
     *
     * @return void
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
     * Creates a keys box with too many entries for the configured limit.
     * This verifies that metadata key parsing enforces size caps.
     *
     * @return void
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
     * Creates an stsd box with an entry count above the configured maximum.
     * This confirms the parser rejects malformed track tables.
     *
     * @return void
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
     * This helper keeps byte-length bookkeeping aligned with the payload.
     *
     * @param string $data Raw ISO BMFF file contents.
     */
    private function createExtractor(string $data): IsoBmffParser
    {
        $fh = fopen('php://temp', 'wb+');
        if ($fh === false) {
            self::fail('Unable to open temporary stream for ISO BMFF test data.');
        }

        fwrite($fh, $data);
        rewind($fh);

        return new IsoBmffParser(new Stream($fh, strlen($data)));
    }

    /**
     * Creates a standard ISO BMFF box header around a payload.
     * This helper computes the size field and prefixes the box type.
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
     * This helper is used to build full boxes like meta, iinf, and iloc.
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
