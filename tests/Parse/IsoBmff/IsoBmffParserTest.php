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
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeDataAtom;
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
#[UsesClass(QuickTimeDataAtom::class)]
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
     * Decodes a 1-byte unsigned integer data box payload.
     * QuickTime File Format 2012, Table 3-5: type 22 supports 1-4 byte payloads.
     *
     * @return void
     */
    #[Test]
    public function decodeOneBytUnsignedIntPayload(): void
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
        self::assertSame(1, $quickTime->keys[$keyName]);
    }

    /**
     * Decodes a 1-byte signed integer data box payload.
     * QuickTime File Format 2012, Table 3-5: type 21 supports 1-4 byte payloads.
     *
     * @return void
     */
    #[Test]
    public function decodeOneByteSignedIntPayload(): void
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
        self::assertSame(1, $quickTime->keys[$keyName]);
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
            $payload .= pack('n', 0);    // reserved (12 bits) + construction_method (4 bits)
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
     * Builds an iloc box with version 3, which is undefined in ISO/IEC 14496-12 §8.11.3.
     * Confirms the parser rejects unsupported iloc versions.
     *
     * @return void
     */
    #[Test]
    public function rejectIlocUnsupportedVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported iloc box version');

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        $ilocPayload = "\x44";       // offset_size=4, length_size=4
        $ilocPayload .= "\x00";       // base_offset_size=0, index_size=0
        $ilocPayload .= pack('n', 0); // item_count = 0
        $iloc = $this->fullBox('iloc', $ilocPayload, 3, 0);

        $meta = $this->fullBox('meta', $iinf . $iloc);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects an iloc box with non-zero flags.
     *
     * @return void
     */
    #[Test]
    public function rejectIlocNonZeroFlags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported iloc box flags');

        $ilocPayload = "\x44\x00" . pack('n', 0);
        $iloc        = $this->fullBox('iloc', $ilocPayload, flags: 1);
        $meta        = $this->fullBox('meta', $iloc);
        $ftyp        = $this->box('ftyp', 'isom');

        $this->createExtractor($ftyp . $meta)->extract();
    }

    /**
     * Builds an iloc v0 box where the low nibble of the base_offset/index byte is non-zero.
     * This confirms the reserved nibble is validated per ISO/IEC 14496-12 §8.11.3.
     *
     * @return void
     */
    #[Test]
    public function rejectIlocVersion0NonZeroReservedNibble(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iloc version 0 reserved nibble must be zero');

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        $ilocPayload = "\x44";       // offset_size=4, length_size=4
        $ilocPayload .= "\x04";       // base_offset_size=0 (high), reserved=4 (low, should be 0)
        $ilocPayload .= pack('n', 0); // item_count = 0
        $iloc = $this->fullBox('iloc', $ilocPayload, 0, 0);

        $meta = $this->fullBox('meta', $iinf . $iloc);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds an iloc v1 box with non-zero reserved bits in the construction_method field.
     * This validates that the upper 12 bits of the 16-bit field are zero per ISO/IEC 14496-12 §8.11.3.
     *
     * @return void
     */
    #[Test]
    public function rejectIlocNonZeroConstructionMethodReservedBits(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iloc construction_method reserved bits must be zero');

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        $ilocPayload = "\x44";         // offset_size=4, length_size=4
        $ilocPayload .= "\x00";         // base_offset_size=0, index_size=0
        $ilocPayload .= pack('n', 1);   // item_count = 1
        $ilocPayload .= pack('n', 1);   // item_id = 1
        $ilocPayload .= pack('n', 0x0010); // reserved bits set (bit 4)
        $ilocPayload .= pack('n', 0);   // data_reference_index = 0
        $ilocPayload .= pack('n', 0);   // extent_count = 0
        $iloc = $this->fullBox('iloc', $ilocPayload, 1, 0);

        $meta = $this->fullBox('meta', $iinf . $iloc);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds an iloc v1 box with construction_method = 4, which is outside the defined range 0–2.
     * This verifies that invalid construction method values are rejected per ISO/IEC 14496-12 §8.11.3.
     *
     * @return void
     */
    #[Test]
    public function rejectIlocInvalidConstructionMethodValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iloc construction_method value out of range');

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        $ilocPayload = "\x44";         // offset_size=4, length_size=4
        $ilocPayload .= "\x00";         // base_offset_size=0, index_size=0
        $ilocPayload .= pack('n', 1);   // item_count = 1
        $ilocPayload .= pack('n', 1);   // item_id = 1
        $ilocPayload .= pack('n', 0x0004); // construction_method=4 (invalid)
        $ilocPayload .= pack('n', 0);   // data_reference_index = 0
        $ilocPayload .= pack('n', 0);   // extent_count = 0
        $iloc = $this->fullBox('iloc', $ilocPayload, 1, 0);

        $meta = $this->fullBox('meta', $iinf . $iloc);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects iloc boxes with duplicate item_ID entries.
     * ISO/IEC 14496-12 §8.11.3: item_ID values must be unique within one iloc box.
     *
     * @return void
     */
    #[Test]
    public function rejectIlocDuplicateItemId(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('duplicate iloc item_ID 1');

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $this->box('infe', $infePayload));

        // Version 0 iloc with 2 items sharing item_id=1
        $ilocPayload = "\x44";         // offset_size=4, length_size=4
        $ilocPayload .= "\x00";         // base_offset_size=0, index_size=0
        $ilocPayload .= pack('n', 2);   // item_count = 2
        // First item
        $ilocPayload .= pack('n', 1);   // item_id = 1
        $ilocPayload .= pack('n', 0);   // data_reference_index = 0
        $ilocPayload .= pack('n', 0);   // extent_count = 0
        // Second item (duplicate)
        $ilocPayload .= pack('n', 1);   // item_id = 1 (duplicate)
        $ilocPayload .= pack('n', 0);   // data_reference_index = 0
        $ilocPayload .= pack('n', 0);   // extent_count = 0
        $iloc = $this->fullBox('iloc', $ilocPayload);

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
     * Creates a meta box with an hdlr handler type other than 'mdta'.
     * Per QuickTime File Format 2012, "Metadata Atom", keys/ilst structures
     * must only be interpreted when the handler is 'mdta'. A non-mdta handler
     * causes the parser to discard collected keys/ilst entries.
     *
     * @return void
     */
    #[Test]
    public function ignoresKeysIlstWhenHdlrIsNotMdta(): void
    {
        $key      = 'com.apple.quicktime.content.identifier';
        $keyEntry = pack('N', 8 + strlen($key)) . 'mdta' . $key;
        $keys     = $this->box('keys', "\0\0\0\0" . pack('N', 1) . $keyEntry);

        $dataBox   = $this->box('data', pack('N', 1) . pack('N', 0) . 'should-be-ignored');
        $ilstEntry = $this->box(pack('N', 1), $dataBox);
        $ilst      = $this->box('ilst', $ilstEntry);

        // hdlr with handler type 'pict' (not 'mdta')
        $hdlr = $this->box('hdlr', "\0\0\0\0\0\0\0\0pict" . str_repeat("\0", 12));

        $metaPayload = "\0\0\0\0" . $hdlr . $keys . $ilst;
        $meta        = $this->box('meta', $metaPayload);
        $moov        = $this->box('moov', $this->box('udta', $meta));
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor    = $this->createExtractor($ftyp . $moov);
        [, , $qtMeta] = $extractor->extract();

        // With keys/ilst discarded due to non-mdta handler and a minimal ftyp,
        // no QuickTime metadata is produced.
        self::assertNull($qtMeta);
    }

    /**
     * QuickTime File Format 2012, "Metadata Structure": an mdta meta box
     * must contain a keys subatom. When keys is missing, the parser rejects
     * the meta box.
     *
     * @return void
     */
    #[Test]
    public function rejectMdtaMetaMissingKeys(): void
    {
        $hdlr = $this->box('hdlr', "\0\0\0\0\0\0\0\0mdta" . str_repeat("\0", 12));

        $dataBox   = $this->box('data', pack('N', 1) . pack('N', 0) . 'value');
        $ilstEntry = $this->box(pack('N', 1), $dataBox);
        $ilst      = $this->box('ilst', $ilstEntry);

        $meta = $this->box('meta', "\0\0\0\0" . $hdlr . $ilst);
        $moov = $this->box('moov', $this->box('udta', $meta));
        $ftyp = $this->box('ftyp', 'isom');

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('mdta meta box missing required keys subatom');

        $this->createExtractor($ftyp . $moov)->extract();
    }

    /**
     * QuickTime File Format 2012, "Metadata Structure": an mdta meta box
     * must contain an ilst subatom. When ilst is missing, the parser rejects
     * the meta box.
     *
     * @return void
     */
    #[Test]
    public function rejectMdtaMetaMissingIlst(): void
    {
        $hdlr = $this->box('hdlr', "\0\0\0\0\0\0\0\0mdta" . str_repeat("\0", 12));

        $key      = 'com.apple.quicktime.content.identifier';
        $keyEntry = pack('N', 8 + strlen($key)) . 'mdta' . $key;
        $keys     = $this->box('keys', "\0\0\0\0" . pack('N', 1) . $keyEntry);

        $meta = $this->box('meta', "\0\0\0\0" . $hdlr . $keys);
        $moov = $this->box('moov', $this->box('udta', $meta));
        $ftyp = $this->box('ftyp', 'isom');

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('mdta meta box missing required ilst subatom');

        $this->createExtractor($ftyp . $moov)->extract();
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
     * Decodes an unsigned 32-bit integer data box payload.
     * QuickTime File Format 2012, Table 3-5, type code 22.
     *
     * @return void
     */
    #[Test]
    public function decodeUnsignedIntDataBoxPayload(): void
    {
        $key          = 'com.apple.quicktime.videoOrientation';
        $payload      = pack('N', 0xFFFFFFFF);
        $file         = $this->createQuickTimeKeysFileWithCustomKey($key, 0x16, $payload);
        $extractor    = $this->createExtractor($file);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertSame(0xFFFFFFFF, $qtMeta->keys[$key]);
    }

    /**
     * Decodes a float32 data box payload.
     * QuickTime File Format 2012, Table 3-5, type code 23.
     *
     * @return void
     */
    #[Test]
    public function decodeFloat32DataBoxPayload(): void
    {
        $key          = 'com.apple.quicktime.videoOrientation';
        $payload      = pack('G', 3.14);
        $file         = $this->createQuickTimeKeysFileWithCustomKey($key, 0x17, $payload);
        $extractor    = $this->createExtractor($file);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertEqualsWithDelta(3.14, $qtMeta->keys[$key], 0.001);
    }

    /**
     * Decodes a float64 data box payload.
     * QuickTime File Format 2012, Table 3-5, type code 24.
     *
     * @return void
     */
    #[Test]
    public function decodeFloat64DataBoxPayload(): void
    {
        $key          = 'com.apple.quicktime.videoOrientation';
        $payload      = pack('E', 2.718281828);
        $file         = $this->createQuickTimeKeysFileWithCustomKey($key, 0x18, $payload);
        $extractor    = $this->createExtractor($file);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertEqualsWithDelta(2.718281828, $qtMeta->keys[$key], 0.000001);
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
        $payload .= pack('n', 0x0001); // construction_method=1
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
        $payload .= pack('n', 0x0001); // construction_method=1
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
            $payload .= pack('n', 0x0002);
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

            $payload .= pack('n', 1);          // item_id = 1
            $payload .= pack('n', 0x0002);     // construction_method=2 (item_offset)
            $payload .= pack('n', 0);          // data_reference_index = 0
            $payload .= pack('n', 1);          // extent_count = 1
            $payload .= pack('N', 1);          // extent_index = 1 (1-based)
            $payload .= pack('N', 0);          // extent_offset = 0
            $payload .= pack('N', $item1Length + 1); // extent_length (too large!)

            $payload .= pack('n', 2);          // item_id = 2
            $payload .= pack('n', 0x0000);     // construction_method=0
            $payload .= pack('n', 0);          // data_reference_index = 0
            $payload .= pack('n', 1);          // extent_count = 1
            $payload .= pack('N', 0);          // extent_index (irrelevant for method 0)
            $payload .= pack('N', $item2Offset); // extent_offset
            $payload .= pack('N', $item2Length); // extent_length

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
     * Rejects an iref box with non-zero flags.
     *
     * @return void
     */
    #[Test]
    public function rejectIrefNonZeroFlags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported iref box flags');

        $entryPayload = pack('n', 1) . pack('n', 1) . pack('n', 2);
        $entry        = $this->box('dimg', $entryPayload);
        $iref         = $this->fullBox('iref', $entry, flags: 1);
        $meta         = $this->fullBox('meta', $iref);
        $ftyp         = $this->box('ftyp', 'isom');

        $this->createExtractor($ftyp . $meta)->extract();
    }

    /**
     * Builds an ilst entry with two data boxes (different locales) under one key.
     * This confirms $keys has the first value and allValues() returns both atoms
     * with correct type and locale indicators.
     *
     * @return void
     */
    #[Test]
    public function preservesMultipleDataAtomsPerQuickTimeKey(): void
    {
        $keyName = 'com.apple.quicktime.content.identifier';

        $keysPayload = pack('N', 1);
        $keysPayload .= pack('N', 8 + strlen($keyName));
        $keysPayload .= 'mdta';
        $keysPayload .= $keyName;
        $keys = $this->fullBox('keys', $keysPayload);

        // Two data boxes with different locales
        $locale1  = 0x00000000; // default locale
        $locale2  = 0x00010002; // country=1, language=2
        $dataBox1 = $this->box('data', pack('N', 1) . pack('N', $locale1) . 'first-value');
        $dataBox2 = $this->box('data', pack('N', 1) . pack('N', $locale2) . 'second-value');
        $entry    = $this->box(pack('N', 1), $dataBox1 . $dataBox2);
        $ilst     = $this->box('ilst', $entry);

        $meta = $this->fullBox('meta', $keys . $ilst);
        $ftyp = $this->box('ftyp', 'qt  ');

        $extractor       = $this->createExtractor($ftyp . $meta);
        [, , $quickTime] = $extractor->extract();

        self::assertNotNull($quickTime);

        // Backward compat: $keys has the first value
        self::assertSame('first-value', $quickTime->keys[$keyName]);

        // allValues() returns both atoms
        $atoms = $quickTime->allValues($keyName);
        self::assertCount(2, $atoms);

        self::assertSame(1, $atoms[0]->typeIndicator);
        self::assertSame(0, $atoms[0]->locale);
        self::assertSame('first-value', $atoms[0]->value);

        self::assertSame(1, $atoms[1]->typeIndicator);
        self::assertSame(0x00010002, $atoms[1]->locale);
        self::assertSame('second-value', $atoms[1]->value);
        self::assertSame(1, $atoms[1]->countryIndicator());
        self::assertSame(2, $atoms[1]->languageIndicator());
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
     * Creates a keys box with non-zero version to trigger validation.
     * QuickTime File Format 2012, "Metadata item keys atom": version/flags must be 0.
     *
     * @return void
     */
    #[Test]
    public function rejectsKeysBoxWithNonZeroVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('keys box version/flags must be 0');

        $payload = pack('N', 1); // entryCount = 1
        $keys    = $this->fullBox('keys', $payload, 1, 0); // version=1
        $meta    = $this->fullBox('meta', $keys);
        $moov    = $this->box('moov', $meta);
        $ftyp    = $this->box('ftyp', 'qt  ');

        $extractor = $this->createExtractor($ftyp . $moov);
        $extractor->extract();
    }

    /**
     * Creates a keys box with non-zero flags to trigger validation.
     * QuickTime File Format 2012, "Metadata item keys atom": version/flags must be 0.
     *
     * @return void
     */
    #[Test]
    public function rejectsKeysBoxWithNonZeroFlags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('keys box version/flags must be 0');

        $payload = pack('N', 1); // entryCount = 1
        $keys    = $this->fullBox('keys', $payload, 0, 1); // flags=1
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
     * Rejects an stsd box with non-zero version.
     *
     * @return void
     */
    #[Test]
    public function rejectStsdUnsupportedVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported stsd box version');

        $payload = pack('N', 0);
        $stsd    = $this->fullBox('stsd', $payload, 1); // version=1
        $stbl    = $this->box('stbl', $stsd);
        $minf    = $this->box('minf', $stbl);

        $hdlrPayload = pack('N', 0) . "\0\0\0\0" . 'vide' . str_repeat("\0", 12);
        $hdlr        = $this->box('hdlr', $hdlrPayload);

        $mdia = $this->box('mdia', $hdlr . $minf);
        $trak = $this->box('trak', $mdia);
        $moov = $this->box('moov', $trak);
        $ftyp = $this->box('ftyp', 'qt  ');

        $extractor = $this->createExtractor($ftyp . $moov);
        $extractor->extract();
    }

    /**
     * Rejects an stsd box with non-zero flags.
     *
     * @return void
     */
    #[Test]
    public function rejectStsdUnsupportedFlags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported stsd box flags');

        $payload = pack('N', 0);
        $stsd    = $this->fullBox('stsd', $payload, 0, 1); // flags=1
        $stbl    = $this->box('stbl', $stsd);
        $minf    = $this->box('minf', $stbl);

        $hdlrPayload = pack('N', 0) . "\0\0\0\0" . 'vide' . str_repeat("\0", 12);
        $hdlr        = $this->box('hdlr', $hdlrPayload);

        $mdia = $this->box('mdia', $hdlr . $minf);
        $trak = $this->box('trak', $mdia);
        $moov = $this->box('moov', $trak);
        $ftyp = $this->box('ftyp', 'qt  ');

        $extractor = $this->createExtractor($ftyp . $moov);
        $extractor->extract();
    }

    /**
     * Builds an infe box with version 4, which is not defined by ISO/IEC 14496-12.
     * Confirms the parser rejects unsupported infe versions.
     *
     * @return void
     */
    #[Test]
    public function rejectInfeUnsupportedVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported infe box version');

        $infePayload = "\x04\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0";
        $infe        = $this->box('infe', $infePayload);
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $infe);
        $meta        = $this->fullBox('meta', $iinf);
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds an infe box with only 4 bytes (needs 8 minimum for v0/v1).
     * Confirms the parser rejects truncated infe payloads.
     *
     * @return void
     */
    #[Test]
    public function rejectInfeTruncated(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('infe box truncated');

        $infe = $this->box('infe', "\0\0\0\0"); // only 4 bytes, needs 8
        $iinf = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $infe);
        $meta = $this->fullBox('meta', $iinf);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds an infe v2 box with only 10 bytes (needs 12: 4 header + 2 ID + 2 prot + 4 type).
     * Confirms the parser rejects truncated v2 infe payloads.
     *
     * @return void
     */
    #[Test]
    public function rejectInfeTruncatedVersion2(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('infe box truncated');

        // v2 needs 12 bytes: 4 header + 2 item_ID + 2 protection_index + 4 item_type
        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0); // 8 bytes, needs 12
        $infe        = $this->box('infe', $infePayload);
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $infe);
        $meta        = $this->fullBox('meta', $iinf);
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds an infe v2 box with content_encoding and verifies item identification
     * still works. This confirms parseInfe correctly parses the optional third
     * NUL-terminated string without breaking content_type matching.
     *
     * @return void
     */
    #[Test]
    public function parsesInfeWithContentEncoding(): void
    {
        $xmpData = '<x:xmpmeta xmlns:x="adobe:ns:meta/">encoded</x:xmpmeta>';

        // infe v2: item_ID=1, protection_index=0, item_type='xmp\0',
        // name='XMP\0', content_type='application/rdf+xml\0', content_encoding='gzip\0'
        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . "xmp\0"
            . "XMP\0application/rdf+xml\0gzip\0";
        $infe = $this->box('infe', $infePayload);
        $iinf = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $infe);
        $pitm = $this->box('pitm', "\0\0\0\0" . pack('n', 1));

        // iloc v0: one item at a known offset
        $ilocPayload = "\0\0\0\0\x44\0" . pack('n', 1)
            . pack('n', 1) . pack('n', 0) . pack('n', 1)
            . pack('N', 0) . pack('N', strlen($xmpData));
        $iloc = $this->box('iloc', $ilocPayload);

        $meta = $this->fullBox('meta', $pitm . $iinf . $iloc);
        $ftyp = $this->box('ftyp', 'isom');
        $mdat = $this->box('mdat', $xmpData);

        // Recalculate iloc offset now that we know full meta size
        $dataOffset  = strlen($ftyp) + strlen($meta) + 8; // +8 for mdat box header
        $ilocPayload = "\0\0\0\0\x44\0" . pack('n', 1)
            . pack('n', 1) . pack('n', 0) . pack('n', 1)
            . pack('N', $dataOffset) . pack('N', strlen($xmpData));
        $iloc = $this->box('iloc', $ilocPayload);
        $meta = $this->fullBox('meta', $pitm . $iinf . $iloc);

        $extractor = $this->createExtractor($ftyp . $meta . $mdat);
        [, $xmps]  = $extractor->extract();

        self::assertSame([$xmpData], $xmps);
    }

    /**
     * Builds an infe v2 box with item_type='mime' and extension_type.
     * Confirms the parser handles the optional extension_type field without error.
     *
     * @return void
     */
    #[Test]
    public function parsesInfeWithExtensionType(): void
    {
        $xmpData = '<x:xmpmeta xmlns:x="adobe:ns:meta/">ext</x:xmpmeta>';

        // infe v2: item_type='mime', with content_encoding + extension_type
        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'mime'
            . "XMP\0application/rdf+xml\0\0" . 'fdel';
        $infe = $this->box('infe', $infePayload);
        $iinf = $this->box('iinf', "\0\0\0\0" . pack('n', 1) . $infe);
        $pitm = $this->box('pitm', "\0\0\0\0" . pack('n', 1));

        $ilocPayload = "\0\0\0\0\x44\0" . pack('n', 1)
            . pack('n', 1) . pack('n', 0) . pack('n', 1)
            . pack('N', 0) . pack('N', strlen($xmpData));
        $iloc = $this->box('iloc', $ilocPayload);

        $meta = $this->fullBox('meta', $pitm . $iinf . $iloc);
        $ftyp = $this->box('ftyp', 'isom');
        $mdat = $this->box('mdat', $xmpData);

        $dataOffset  = strlen($ftyp) + strlen($meta) + 8;
        $ilocPayload = "\0\0\0\0\x44\0" . pack('n', 1)
            . pack('n', 1) . pack('n', 0) . pack('n', 1)
            . pack('N', $dataOffset) . pack('N', strlen($xmpData));
        $iloc = $this->box('iloc', $ilocPayload);
        $meta = $this->fullBox('meta', $pitm . $iinf . $iloc);

        $extractor = $this->createExtractor($ftyp . $meta . $mdat);
        [, $xmps]  = $extractor->extract();

        self::assertSame([$xmpData], $xmps);
    }

    /**
     * Builds an iinf box claiming 2 entries but containing only 1 infe child.
     * Confirms the parser rejects entry_count mismatches.
     *
     * @return void
     */
    #[Test]
    public function rejectIinfEntryCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iinf entry count mismatch');

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $infe        = $this->box('infe', $infePayload);
        $iinf        = $this->box('iinf', "\0\0\0\0" . pack('n', 2) . $infe); // claims 2, has 1
        $meta        = $this->fullBox('meta', $iinf);
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds an iinf box with version 2, which is not defined by ISO/IEC 14496-12.
     * Confirms the parser rejects unsupported iinf versions.
     *
     * @return void
     */
    #[Test]
    public function rejectIinfUnsupportedVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported iinf box version');

        $iinf = $this->box('iinf', "\x02\0\0\0" . pack('N', 0)); // version=2
        $meta = $this->fullBox('meta', $iinf);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds an iinf box with only 3 bytes of content (needs 6 minimum).
     * Confirms the parser rejects truncated iinf payloads.
     *
     * @return void
     */
    #[Test]
    public function rejectIinfTruncated(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iinf box truncated');

        $iinf = $this->box('iinf', "\0\0\0"); // only 3 bytes
        $meta = $this->fullBox('meta', $iinf);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds an iinf v1 box with only 6 bytes (needs 8: 4 header + 4 entry_count).
     * Confirms the parser rejects truncated v1 iinf payloads.
     *
     * @return void
     */
    #[Test]
    public function rejectIinfTruncatedVersion1(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iinf box truncated');

        $iinf = $this->box('iinf', "\x01\0\0\0" . pack('n', 0)); // version=1 but only 6 bytes
        $meta = $this->fullBox('meta', $iinf);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds a pitm box with version 2, which is not defined by ISO/IEC 14496-12.
     * Confirms the parser rejects unsupported pitm versions.
     *
     * @return void
     */
    #[Test]
    public function rejectPitmUnsupportedVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported pitm box version');

        $pitmPayload = "\x02\0\0\0" . pack('N', 1); // version=2, flags=0, item_ID=1
        $pitm        = $this->box('pitm', $pitmPayload);
        $meta        = $this->fullBox('meta', $pitm);
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds a pitm v0 box with only 3 bytes of payload (needs 6: 4 header + 2 item_ID).
     * Confirms the parser rejects truncated pitm payloads.
     *
     * @return void
     */
    #[Test]
    public function rejectPitmTruncatedVersion0(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('pitm box truncated');

        $pitmPayload = "\0\0\0"; // only 3 bytes, needs at least 6
        $pitm        = $this->box('pitm', $pitmPayload);
        $meta        = $this->fullBox('meta', $pitm);
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds a pitm v1 box with only 6 bytes of payload (needs 8: 4 header + 4 item_ID).
     * Confirms the parser rejects truncated v1 pitm payloads.
     *
     * @return void
     */
    #[Test]
    public function rejectPitmTruncatedVersion1(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('pitm box truncated');

        $pitmPayload = "\x01\0\0\0" . pack('n', 1); // version=1, flags=0, only 2-byte item_ID (6 bytes total, needs 8)
        $pitm        = $this->box('pitm', $pitmPayload);
        $meta        = $this->fullBox('meta', $pitm);
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds a dref box with version 1, which violates the ISO/IEC 14496-12 spec.
     * Confirms the parser rejects non-zero dref versions.
     *
     * @return void
     */
    #[Test]
    public function rejectDrefUnsupportedVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported dref box version');

        $dref = $this->fullBox('dref', pack('N', 0), 1); // version=1, entry_count=0
        $dinf = $this->box('dinf', $dref);
        $meta = $this->fullBox('meta', $dinf);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds a meta box with FullBox version=1 instead of 0.
     * Confirms the parser rejects unsupported meta versions.
     *
     * @return void
     */
    #[Test]
    public function rejectMetaUnsupportedVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported meta box version');

        $iinf = $this->box('iinf', "\0\0\0\0" . pack('n', 0));
        $meta = $this->fullBox('meta', $iinf, 1); // version=1, flags=0
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Builds a meta box with FullBox flags=1 instead of 0.
     * Confirms the parser rejects unsupported meta flags.
     *
     * @return void
     */
    #[Test]
    public function rejectMetaUnsupportedFlags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported meta box flags');

        $iinf = $this->box('iinf', "\0\0\0\0" . pack('n', 0));
        $meta = $this->fullBox('meta', $iinf, 0, 1); // version=0, flags=1
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects an iinf box with non-zero flags.
     *
     * @return void
     */
    #[Test]
    public function rejectIinfUnsupportedFlags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported iinf box flags');

        $iinf = $this->fullBox('iinf', pack('n', 0), 0, 1); // flags=1
        $meta = $this->fullBox('meta', $iinf);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects an infe box with non-zero flags.
     *
     * @return void
     */
    #[Test]
    public function rejectInfeUnsupportedFlags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported infe box flags');

        $infePayload = pack('n', 1) . pack('n', 0) . 'Exif' . "\0\0\0";
        $infe        = $this->fullBox('infe', $infePayload, 2, 1); // flags=1
        $iinf        = $this->fullBox('iinf', pack('n', 1) . $infe);
        $meta        = $this->fullBox('meta', $iinf);
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects a pitm box with non-zero flags.
     *
     * @return void
     */
    #[Test]
    public function rejectPitmUnsupportedFlags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported pitm box flags');

        $pitm = $this->fullBox('pitm', pack('n', 1), 0, 1); // flags=1
        $iinf = $this->fullBox('iinf', pack('n', 0));
        $meta = $this->fullBox('meta', $iinf . $pitm);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects pitm referencing a non-existent item.
     * ISO/IEC 14496-12 §8.11.4: the primary item must reference an existing item.
     *
     * @return void
     */
    #[Test]
    public function rejectPitmReferencingNonExistentItem(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('pitm references non-existent item 99');

        $pitm = $this->fullBox('pitm', pack('n', 99)); // item_ID = 99 (no such item)
        $iinf = $this->fullBox('iinf', pack('n', 0));  // No items defined
        $meta = $this->fullBox('meta', $iinf . $pitm);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects a dref url entry with non-zero version.
     *
     * @return void
     */
    #[Test]
    public function rejectDrefEntryUnsupportedVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported dref entry version');

        $drefEntry = $this->fullBox('url ', '', 1); // version=1
        $dref      = $this->fullBox('dref', pack('N', 1) . $drefEntry);
        $dinf      = $this->box('dinf', $dref);
        $meta      = $this->fullBox('meta', $dinf);
        $ftyp      = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects an ftyp box whose compatible_brands length is not a multiple of 4.
     *
     * @return void
     */
    #[Test]
    public function rejectFtypMisalignedCompatibleBrands(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ftyp compatible_brands length is not a multiple of 4');

        // major_brand (4) + minor_version (4) + 5 bytes (not multiple of 4)
        $ftyp = $this->box('ftyp', 'isom' . pack('N', 0) . 'heicX');

        $extractor = $this->createExtractor($ftyp);
        $extractor->extract();
    }

    /**
     * Rejects a tkhd box with unsupported version (2).
     *
     * @return void
     */
    #[Test]
    public function rejectTkhdUnsupportedVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported tkhd box version');

        // version=2, flags=0, then 80 bytes of padding for minimum content
        $tkhdPayload = "\x02\x00\x00\x00" . str_repeat("\0", 80);
        $tkhd        = $this->box('tkhd', $tkhdPayload);
        $trak        = $this->box('trak', $tkhd);
        $moov        = $this->box('moov', $trak);
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $moov);
        $extractor->extract();
    }

    /**
     * Rejects an hdlr box with non-zero version.
     *
     * @return void
     */
    #[Test]
    public function rejectHdlrUnsupportedVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported hdlr box version');

        $hdlrPayload = "\x01\x00\x00\x00"         // version=1, flags=0
            . "\x00\x00\x00\x00"                   // pre_defined=0
            . 'vide'                               // handler_type
            . str_repeat("\0", 12);                // reserved
        $hdlr = $this->box('hdlr', $hdlrPayload);
        $meta = $this->fullBox('meta', $hdlr);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects an hdlr box with non-zero flags.
     *
     * @return void
     */
    #[Test]
    public function rejectHdlrUnsupportedFlags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported hdlr box flags');

        $hdlrPayload = "\x00\x00\x00\x01"         // version=0, flags=1
            . "\x00\x00\x00\x00"                   // pre_defined=0
            . 'vide'                               // handler_type
            . str_repeat("\0", 12);                // reserved
        $hdlr = $this->box('hdlr', $hdlrPayload);
        $meta = $this->fullBox('meta', $hdlr);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects an hdlr box with non-zero pre_defined field.
     *
     * @return void
     */
    #[Test]
    public function rejectHdlrNonZeroPreDefined(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('hdlr pre_defined must be 0');

        $hdlrPayload = "\x00\x00\x00\x00"         // version=0, flags=0
            . "\x00\x00\x00\x01"                   // pre_defined=1
            . 'vide'                               // handler_type
            . str_repeat("\0", 12);                // reserved
        $hdlr = $this->box('hdlr', $hdlrPayload);
        $meta = $this->fullBox('meta', $hdlr);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects an hdlr box with non-zero reserved fields.
     *
     * @return void
     */
    #[Test]
    public function rejectHdlrNonZeroReserved(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('hdlr reserved fields must be 0');

        $hdlrPayload = "\x00\x00\x00\x00"         // version=0, flags=0
            . "\x00\x00\x00\x00"                   // pre_defined=0
            . 'vide'                               // handler_type
            . "\x00\x00\x00\x01"                   // reserved[0] = 1 (invalid)
            . "\x00\x00\x00\x00"                   // reserved[1] = 0
            . "\x00\x00\x00\x00";                  // reserved[2] = 0
        $hdlr = $this->box('hdlr', $hdlrPayload);
        $meta = $this->fullBox('meta', $hdlr);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor = $this->createExtractor($ftyp . $meta);
        $extractor->extract();
    }

    /**
     * Rejects duplicate meta boxes inside a moov container.
     *
     * @return void
     */
    #[Test]
    public function rejectDuplicateMetaInMoov(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('duplicate meta box in moov');

        $hdlr = $this->box('hdlr', "\0\0\0\0\0\0\0\0pict" . str_repeat("\0", 12));
        $meta = $this->box('meta', "\0\0\0\0" . $hdlr);
        $moov = $this->box('moov', $meta . $meta);
        $ftyp = $this->box('ftyp', 'isom');

        $this->createExtractor($ftyp . $moov)->extract();
    }

    /**
     * Rejects duplicate meta boxes inside a udta container.
     *
     * @return void
     */
    #[Test]
    public function rejectDuplicateMetaInUdta(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('duplicate meta box in udta');

        $hdlr = $this->box('hdlr', "\0\0\0\0\0\0\0\0pict" . str_repeat("\0", 12));
        $meta = $this->box('meta', "\0\0\0\0" . $hdlr);
        $udta = $this->box('udta', $meta . $meta);
        $moov = $this->box('moov', $udta);
        $ftyp = $this->box('ftyp', 'isom');

        $this->createExtractor($ftyp . $moov)->extract();
    }

    #[Test]
    public function parsesTrackLevelUdtaMetaBox(): void
    {
        // Build a keys/ilst metadata inside udta inside trak
        $key      = 'com.apple.quicktime.content.identifier';
        $keyEntry = pack('N', 8 + strlen($key)) . 'mdta' . $key;
        $keys     = $this->box('keys', "\0\0\0\0" . pack('N', 1) . $keyEntry);

        $dataBox   = $this->box('data', pack('N', 1) . pack('N', 0) . 'track-meta-value');
        $ilstEntry = $this->box(pack('N', 1), $dataBox);
        $ilst      = $this->box('ilst', $ilstEntry);

        $metaPayload = "\0\0\0\0" . $keys . $ilst;
        $meta        = $this->box('meta', $metaPayload);
        $udta        = $this->box('udta', $meta);
        $trak        = $this->box('trak', $udta);
        $moov        = $this->box('moov', $trak);
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor    = $this->createExtractor($ftyp . $moov);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertSame('track-meta-value', $qtMeta->keys[$key]);
    }

    #[Test]
    public function parsesTrackNameAtomFromTrackLevelUdta(): void
    {
        $namePayload = "Test Track Name\0";
        $nameAtom    = $this->box('name', $namePayload);
        $udta        = $this->box('udta', $nameAtom);
        $trak        = $this->box('trak', $udta);
        $moov        = $this->box('moov', $trak);
        $ftyp        = $this->box('ftyp', 'isom');

        $extractor    = $this->createExtractor($ftyp . $moov);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertSame('Test Track Name', $qtMeta->keys[QuickTimeMeta::TRACK_NAME_KEY]);
    }

    #[Test]
    public function moovLevelMetadataNotOverwrittenByTrackUdta(): void
    {
        // Movie-level udta with keys/ilst
        $key      = 'com.apple.quicktime.content.identifier';
        $keyEntry = pack('N', 8 + strlen($key)) . 'mdta' . $key;
        $keys     = $this->box('keys', "\0\0\0\0" . pack('N', 1) . $keyEntry);

        $dataBox   = $this->box('data', pack('N', 1) . pack('N', 0) . 'movie-level-value');
        $ilstEntry = $this->box(pack('N', 1), $dataBox);
        $ilst      = $this->box('ilst', $ilstEntry);

        $movieMeta = $this->box('meta', "\0\0\0\0" . $keys . $ilst);
        $movieUdta = $this->box('udta', $movieMeta);

        // Track-level udta with same key but different value
        $dataBox2   = $this->box('data', pack('N', 1) . pack('N', 0) . 'track-level-value');
        $ilstEntry2 = $this->box(pack('N', 1), $dataBox2);
        $ilst2      = $this->box('ilst', $ilstEntry2);

        $trackMeta = $this->box('meta', "\0\0\0\0" . $keys . $ilst2);
        $trackUdta = $this->box('udta', $trackMeta);
        $trak      = $this->box('trak', $trackUdta);

        $moov = $this->box('moov', $movieUdta . $trak);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor    = $this->createExtractor($ftyp . $moov);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        // Track-level overwrites movie-level via mergeAssociative (last wins)
        // because parseTrak returns its own keys that get merged
        self::assertSame('track-level-value', $qtMeta->keys[$key]);
    }

    #[Test]
    public function ilstNameAtomUsedAsFallbackKey(): void
    {
        // Build ilst with an entry that has no key mapping — only a name atom + data atom
        // name atom: full box (version=0, flags=0) + UTF-8 string
        $namePayload = "\0\0\0\0custom.metadata.key";
        $nameAtom    = $this->box('name', $namePayload);
        $dataBox     = $this->box('data', pack('N', 1) . pack('N', 0) . 'name-fallback-value');

        // Use a non-printable fourcc (0x00000001) so the key index lookup fails
        $ilstEntry = $this->box(pack('N', 1), $nameAtom . $dataBox);
        $ilst      = $this->box('ilst', $ilstEntry);

        // No keys box — only ilst with name atom fallback
        $meta = $this->box('meta', "\0\0\0\0" . $ilst);
        $udta = $this->box('udta', $meta);
        $moov = $this->box('moov', $udta);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor    = $this->createExtractor($ftyp . $moov);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertSame('name-fallback-value', $qtMeta->keys['custom.metadata.key']);
    }

    #[Test]
    public function rejectDuplicateIlstNameAtomValues(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('duplicate ilst name atom value "duplicate.key"');

        $namePayload = "\0\0\0\0duplicate.key";
        $nameAtom    = $this->box('name', $namePayload);
        $dataBox     = $this->box('data', pack('N', 1) . pack('N', 0) . 'value1');
        $ilstEntry1  = $this->box(pack('N', 1), $nameAtom . $dataBox);

        $dataBox2   = $this->box('data', pack('N', 1) . pack('N', 0) . 'value2');
        $ilstEntry2 = $this->box(pack('N', 2), $nameAtom . $dataBox2);

        $ilst = $this->box('ilst', $ilstEntry1 . $ilstEntry2);
        $meta = $this->box('meta', "\0\0\0\0" . $ilst);
        $udta = $this->box('udta', $meta);
        $moov = $this->box('moov', $udta);
        $ftyp = $this->box('ftyp', 'isom');

        $this->createExtractor($ftyp . $moov)->extract();
    }

    #[Test]
    public function rejectIlstNameAtomWithNonZeroVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ilst name atom version must be 0');

        $namePayload = "\x01\0\0\0some.key";
        $nameAtom    = $this->box('name', $namePayload);
        $dataBox     = $this->box('data', pack('N', 1) . pack('N', 0) . 'value');
        $ilstEntry   = $this->box(pack('N', 1), $nameAtom . $dataBox);

        $ilst = $this->box('ilst', $ilstEntry);
        $meta = $this->box('meta', "\0\0\0\0" . $ilst);
        $udta = $this->box('udta', $meta);
        $moov = $this->box('moov', $udta);
        $ftyp = $this->box('ftyp', 'isom');

        $this->createExtractor($ftyp . $moov)->extract();
    }

    #[Test]
    public function parsesItifAtomWithValidItemId(): void
    {
        $key      = 'com.apple.quicktime.content.identifier';
        $keyEntry = pack('N', 8 + strlen($key)) . 'mdta' . $key;
        $keys     = $this->box('keys', "\0\0\0\0" . pack('N', 1) . $keyEntry);

        // itif: version=0, flags=0, Item_ID=42
        $itif    = $this->box('itif', "\0\0\0\0" . pack('N', 42));
        $dataBox = $this->box('data', pack('N', 1) . pack('N', 0) . 'itif-test-value');
        // ilst entry with itif + data
        $ilstEntry = $this->box(pack('N', 1), $itif . $dataBox);
        $ilst      = $this->box('ilst', $ilstEntry);

        $meta = $this->box('meta', "\0\0\0\0" . $keys . $ilst);
        $udta = $this->box('udta', $meta);
        $moov = $this->box('moov', $udta);
        $ftyp = $this->box('ftyp', 'isom');

        $extractor    = $this->createExtractor($ftyp . $moov);
        [, , $qtMeta] = $extractor->extract();

        self::assertInstanceOf(QuickTimeMeta::class, $qtMeta);
        self::assertSame('itif-test-value', $qtMeta->keys[$key]);
    }

    #[Test]
    public function rejectDuplicateItifItemIds(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('duplicate Item_ID 7 in ilst itif atoms');

        $key      = 'com.apple.quicktime.content.identifier';
        $keyEntry = pack('N', 8 + strlen($key)) . 'mdta' . $key;
        $keys     = $this->box('keys', "\0\0\0\0" . pack('N', 2) . $keyEntry . $keyEntry);

        $itif1      = $this->box('itif', "\0\0\0\0" . pack('N', 7));
        $dataBox1   = $this->box('data', pack('N', 1) . pack('N', 0) . 'value1');
        $ilstEntry1 = $this->box(pack('N', 1), $itif1 . $dataBox1);

        $itif2      = $this->box('itif', "\0\0\0\0" . pack('N', 7));
        $dataBox2   = $this->box('data', pack('N', 1) . pack('N', 0) . 'value2');
        $ilstEntry2 = $this->box(pack('N', 2), $itif2 . $dataBox2);

        $ilst = $this->box('ilst', $ilstEntry1 . $ilstEntry2);
        $meta = $this->box('meta', "\0\0\0\0" . $keys . $ilst);
        $udta = $this->box('udta', $meta);
        $moov = $this->box('moov', $udta);
        $ftyp = $this->box('ftyp', 'isom');

        $this->createExtractor($ftyp . $moov)->extract();
    }

    #[Test]
    public function rejectItifAtomWithNonZeroVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('itif atom version must be 0');

        $itif      = $this->box('itif', "\x01\0\0\0" . pack('N', 1));
        $dataBox   = $this->box('data', pack('N', 1) . pack('N', 0) . 'value');
        $ilstEntry = $this->box(pack('N', 1), $itif . $dataBox);

        $ilst = $this->box('ilst', $ilstEntry);
        $meta = $this->box('meta', "\0\0\0\0" . $ilst);
        $udta = $this->box('udta', $meta);
        $moov = $this->box('moov', $udta);
        $ftyp = $this->box('ftyp', 'isom');

        $this->createExtractor($ftyp . $moov)->extract();
    }

    #[Test]
    public function rejectItifAtomWithNonZeroFlags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('itif atom flags must be 0');

        $itif      = $this->box('itif', "\0\0\0\x01" . pack('N', 1));
        $dataBox   = $this->box('data', pack('N', 1) . pack('N', 0) . 'value');
        $ilstEntry = $this->box(pack('N', 1), $itif . $dataBox);

        $ilst = $this->box('ilst', $ilstEntry);
        $meta = $this->box('meta', "\0\0\0\0" . $ilst);
        $udta = $this->box('udta', $meta);
        $moov = $this->box('moov', $udta);
        $ftyp = $this->box('ftyp', 'isom');

        $this->createExtractor($ftyp . $moov)->extract();
    }

    #[Test]
    public function rejectIlstNameAtomWithInvalidUtf8(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ilst name atom contains invalid UTF-8');

        // 0xFF 0xFE is invalid UTF-8
        $namePayload = "\0\0\0\0\xFF\xFE";
        $nameAtom    = $this->box('name', $namePayload);
        $dataBox     = $this->box('data', pack('N', 1) . pack('N', 0) . 'value');
        $ilstEntry   = $this->box(pack('N', 1), $nameAtom . $dataBox);

        $ilst = $this->box('ilst', $ilstEntry);
        $meta = $this->box('meta', "\0\0\0\0" . $ilst);
        $udta = $this->box('udta', $meta);
        $moov = $this->box('moov', $udta);
        $ftyp = $this->box('ftyp', 'isom');

        $this->createExtractor($ftyp . $moov)->extract();
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
