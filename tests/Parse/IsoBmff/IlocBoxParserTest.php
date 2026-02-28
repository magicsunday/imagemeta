<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;
use MagicSunday\ImageMeta\Parse\IsoBmff\IlocBoxParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\ItemLocationResolver;
use MagicSunday\ImageMeta\Tests\Helpers\IsoBmffBoxTrait;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function chr;
use function pack;
use function strlen;

/**
 * Tests for IlocBoxParser covering iloc version 0/1/2 parsing, pitm parsing,
 * iinf/infe parsing, iref parsing, and dinf/dref parsing.
 * Validates both successful extraction and error paths for malformed data.
 */
#[CoversClass(IlocBoxParser::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(BoxNavigator::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(ConstructionMethod::class)]
#[UsesClass(IsoBmffItemReference::class)]
#[UsesClass(IsoBmffDataReference::class)]
#[UsesClass(ItemLocationResolver::class)]
#[UsesTrait(NormalizesOffsets::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
#[UsesTrait(IsoBmffBoxTrait::class)]
final class IlocBoxParserTest extends TestCase
{
    use IsoBmffBoxTrait;

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Creates a BoxNavigator and IlocBoxParser wrapping the given binary data.
     * Returns the parser plus a BoxDescriptor for the content region.
     *
     * @return array{0: IlocBoxParser, 1: BoxDescriptor}
     */
    private function createParserWithDescriptor(string $content, string $type = 'iloc'): array
    {
        $contentLength = strlen($content);
        $stream        = $this->createIsoBmffTempStream($content);
        $navigator     = new BoxNavigator($stream);
        $parser        = new IlocBoxParser($navigator);
        $window        = $stream->window(0, $contentLength);

        $descriptor = new BoxDescriptor(
            type: $type,
            size: 8 + $contentLength,
            offset: 0,
            contentOffset: 0,
            contentSize: $contentLength,
            window: $window,
            userType: null,
        );

        return [$parser, $descriptor];
    }

    /**
     * Appends iloc extents with 32-bit offset/length fields.
     *
     * @param list<array{offset: int, length: int}> $extents
     */
    private function appendIlocExtents(string $data, array $extents): string
    {
        $data .= pack('n', count($extents));

        foreach ($extents as $extent) {
            $data .= pack('N', $extent['offset']);
            $data .= pack('N', $extent['length']);
        }

        return $data;
    }

    /**
     * Appends one iloc item using the requested item ID width.
     *
     * @param array{itemId: int, dataRefIndex: int, baseOffset: int, extents: list<array{offset: int, length: int}>, constructionMethod?: int} $item
     */
    private function appendIlocItem(
        string $data,
        array $item,
        string $itemIdFormat,
        bool $withConstructionMethod,
    ): string {
        $data .= pack($itemIdFormat, $item['itemId']);

        if ($withConstructionMethod) {
            self::assertArrayHasKey('constructionMethod', $item);
            $constructionMethod = $item['constructionMethod'];
            // 12-bit reserved + 4-bit construction_method
            $data .= pack('n', $constructionMethod & 0x0F);
        }

        $data .= pack('n', $item['dataRefIndex']);
        $data .= pack('N', $item['baseOffset']);

        return $this->appendIlocExtents($data, $item['extents']);
    }

    /**
     * Creates the iloc binary payload for version 0 with 4-byte offsets and 4-byte lengths.
     *
     * @param list<array{itemId: int, dataRefIndex: int, baseOffset: int, extents: list<array{offset: int, length: int}>}> $items
     */
    private function buildIlocV0Payload(array $items): string
    {
        // version=0, flags=0
        $data = chr(0) . chr(0) . chr(0) . chr(0);
        // offset_size=4, length_size=4 (packed as high/low nibble)
        $data .= chr((4 << 4) | 4);
        // base_offset_size=4, reserved=0
        $data .= chr((4 << 4) | 0);
        // item_count (16-bit for v0)
        $data .= pack('n', count($items));

        foreach ($items as $item) {
            $data = $this->appendIlocItem($data, $item, 'n', false);
        }

        return $data;
    }

    /**
     * Creates the iloc binary payload for version 1 with construction method.
     *
     * @param list<array{itemId: int, constructionMethod: int, dataRefIndex: int, baseOffset: int, extents: list<array{offset: int, length: int}>}> $items
     */
    private function buildIlocV1Payload(array $items): string
    {
        // version=1, flags=0
        $data = chr(1) . chr(0) . chr(0) . chr(0);
        // offset_size=4, length_size=4
        $data .= chr((4 << 4) | 4);
        // base_offset_size=4, index_size=0
        $data .= chr((4 << 4) | 0);
        // item_count (16-bit for v1)
        $data .= pack('n', count($items));

        foreach ($items as $item) {
            $data = $this->appendIlocItem($data, $item, 'n', true);
        }

        return $data;
    }

    /**
     * Creates the iloc binary payload for version 2 with 32-bit item IDs.
     *
     * @param list<array{itemId: int, constructionMethod: int, dataRefIndex: int, baseOffset: int, extents: list<array{offset: int, length: int}>}> $items
     */
    private function buildIlocV2Payload(array $items): string
    {
        // version=2, flags=0
        $data = chr(2) . chr(0) . chr(0) . chr(0);
        // offset_size=4, length_size=4
        $data .= chr((4 << 4) | 4);
        // base_offset_size=4, index_size=0
        $data .= chr((4 << 4) | 0);
        // item_count (32-bit for v2)
        $data .= pack('N', count($items));

        foreach ($items as $item) {
            $data = $this->appendIlocItem($data, $item, 'N', true);
        }

        return $data;
    }

    // =========================================================================
    // parseIloc — version 0 positive test
    // =========================================================================

    /**
     * Parses a valid iloc v0 box with a single item having one extent.
     */
    #[Test]
    public function parseIlocVersion0SingleItem(): void
    {
        $payload = $this->buildIlocV0Payload([
            [
                'itemId'       => 1,
                'dataRefIndex' => 0,
                'baseOffset'   => 100,
                'extents'      => [['offset' => 200, 'length' => 50]],
            ],
        ]);

        [$parser, $descriptor] = $this->createParserWithDescriptor($payload);
        $locations             = $parser->parseIloc($descriptor);

        self::assertArrayHasKey(1, $locations);
        self::assertSame(0, $locations[1]['dataReferenceIndex']);
        self::assertSame(ConstructionMethod::FileOffset, $locations[1]['constructionMethod']);
        self::assertSame(100, $locations[1]['baseOffset']);
        self::assertCount(1, $locations[1]['extents']);
        self::assertSame(200, $locations[1]['extents'][0]['offset']);
        self::assertSame(50, $locations[1]['extents'][0]['length']);
        self::assertNull($locations[1]['extents'][0]['index']);
    }

    /**
     * Parses a valid iloc v0 box with multiple items and extents.
     */
    #[Test]
    public function parseIlocVersion0MultipleItems(): void
    {
        $payload = $this->buildIlocV0Payload([
            [
                'itemId'       => 1,
                'dataRefIndex' => 0,
                'baseOffset'   => 0,
                'extents'      => [
                    ['offset' => 10, 'length' => 20],
                    ['offset' => 30, 'length' => 40],
                ],
            ],
            [
                'itemId'       => 2,
                'dataRefIndex' => 1,
                'baseOffset'   => 500,
                'extents'      => [['offset' => 0, 'length' => 100]],
            ],
        ]);

        [$parser, $descriptor] = $this->createParserWithDescriptor($payload);
        $locations             = $parser->parseIloc($descriptor);

        self::assertCount(2, $locations);
        self::assertArrayHasKey(1, $locations);
        self::assertArrayHasKey(2, $locations);
        self::assertCount(2, $locations[1]['extents']);
        self::assertCount(1, $locations[2]['extents']);
    }

    // =========================================================================
    // parseIloc — version 1 positive test
    // =========================================================================

    /**
     * Parses a valid iloc v1 box with idat construction method.
     */
    #[Test]
    public function parseIlocVersion1WithIdatConstruction(): void
    {
        $payload = $this->buildIlocV1Payload([
            [
                'itemId'             => 5,
                'constructionMethod' => 1,
                'dataRefIndex'       => 0,
                'baseOffset'         => 0,
                'extents'            => [['offset' => 0, 'length' => 128]],
            ],
        ]);

        [$parser, $descriptor] = $this->createParserWithDescriptor($payload);
        $locations             = $parser->parseIloc($descriptor);

        self::assertArrayHasKey(5, $locations);
        self::assertSame(ConstructionMethod::IdatOffset, $locations[5]['constructionMethod']);
    }

    // =========================================================================
    // parseIloc — version 2 positive test
    // =========================================================================

    /**
     * Parses a valid iloc v2 box using 32-bit item IDs.
     */
    #[Test]
    public function parseIlocVersion2With32BitIds(): void
    {
        $payload = $this->buildIlocV2Payload([
            [
                'itemId'             => 70000,
                'constructionMethod' => 0,
                'dataRefIndex'       => 0,
                'baseOffset'         => 0,
                'extents'            => [['offset' => 0, 'length' => 256]],
            ],
        ]);

        [$parser, $descriptor] = $this->createParserWithDescriptor($payload);
        $locations             = $parser->parseIloc($descriptor);

        self::assertArrayHasKey(70000, $locations);
        self::assertSame(ConstructionMethod::FileOffset, $locations[70000]['constructionMethod']);
    }

    // =========================================================================
    // parseIloc — negative tests
    // =========================================================================

    /**
     * Returns empty for unsupported iloc version (v3).
     */
    #[Test]
    public function parseIlocReturnsEmptyForUnsupportedVersion(): void
    {
        // version=3, flags=0
        $data = chr(3) . chr(0) . chr(0) . chr(0)
            . chr((4 << 4) | 4) . chr((4 << 4) | 0)
            . pack('n', 0);

        [$parser, $descriptor] = $this->createParserWithDescriptor($data);
        $locations             = $parser->parseIloc($descriptor);

        self::assertSame([], $locations);
    }

    /**
     * Returns empty for non-zero flags in iloc.
     */
    #[Test]
    public function parseIlocReturnsEmptyForNonZeroFlags(): void
    {
        // version=0, flags=1
        $data = chr(0) . chr(0) . chr(0) . chr(1)
            . chr((4 << 4) | 4) . chr((4 << 4) | 0)
            . pack('n', 0);

        [$parser, $descriptor] = $this->createParserWithDescriptor($data);
        $locations             = $parser->parseIloc($descriptor);

        self::assertSame([], $locations);
    }

    /**
     * Rejects iloc v0 with non-zero reserved nibble.
     */
    #[Test]
    public function parseIlocRejectsV0WithNonZeroReservedNibble(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iloc version 0 reserved nibble must be zero');

        // version=0, flags=0
        $data = chr(0) . chr(0) . chr(0) . chr(0)
            // offset_size=4, length_size=4
            . chr((4 << 4) | 4)
            // base_offset_size=4, reserved=4 (should be 0 for v0)
            . chr((4 << 4) | 4)
            . pack('n', 0);

        [$parser, $descriptor] = $this->createParserWithDescriptor($data);
        $parser->parseIloc($descriptor);
    }

    /**
     * Rejects iloc with an invalid size nibble value.
     */
    #[Test]
    public function parseIlocRejectsInvalidSizeNibble(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('invalid length field size');

        // version=0, flags=0
        $data = chr(0) . chr(0) . chr(0) . chr(0)
            // offset_size=3 (invalid nibble), length_size=4
            . chr((3 << 4) | 4)
            . chr((4 << 4) | 0)
            . pack('n', 0);

        [$parser, $descriptor] = $this->createParserWithDescriptor($data);
        $parser->parseIloc($descriptor);
    }

    /**
     * Rejects duplicate item IDs within an iloc box.
     */
    #[Test]
    public function parseIlocRejectsDuplicateItemIds(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('duplicate iloc item_ID 1');

        $payload = $this->buildIlocV0Payload([
            [
                'itemId'       => 1,
                'dataRefIndex' => 0,
                'baseOffset'   => 0,
                'extents'      => [['offset' => 0, 'length' => 10]],
            ],
            [
                'itemId'       => 1,
                'dataRefIndex' => 0,
                'baseOffset'   => 0,
                'extents'      => [['offset' => 10, 'length' => 20]],
            ],
        ]);

        [$parser, $descriptor] = $this->createParserWithDescriptor($payload);
        $parser->parseIloc($descriptor);
    }

    /**
     * Rejects iloc with trailing bytes after declared items.
     */
    #[Test]
    public function parseIlocRejectsTrailingBytes(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iloc payload has trailing bytes after declared items');

        $payload = $this->buildIlocV0Payload([
            [
                'itemId'       => 1,
                'dataRefIndex' => 0,
                'baseOffset'   => 0,
                'extents'      => [['offset' => 0, 'length' => 10]],
            ],
        ]) . "\x00\x00";

        [$parser, $descriptor] = $this->createParserWithDescriptor($payload);
        $parser->parseIloc($descriptor);
    }

    // =========================================================================
    // parsePitm — positive and negative tests
    // =========================================================================

    /**
     * Parses a valid pitm v0 box returning a 16-bit primary item ID.
     */
    #[Test]
    public function parsePitmVersion0(): void
    {
        // version=0, flags=0, item_ID=42
        $content = chr(0) . chr(0) . chr(0) . chr(0) . pack('n', 42);

        [$parser, $descriptor] = $this->createParserWithDescriptor($content, 'pitm');

        self::assertSame(42, $parser->parsePitm($descriptor));
    }

    /**
     * Parses a valid pitm v1 box returning a 32-bit primary item ID.
     */
    #[Test]
    public function parsePitmVersion1(): void
    {
        // version=1, flags=0, item_ID=100000
        $content = chr(1) . chr(0) . chr(0) . chr(0) . pack('N', 100000);

        [$parser, $descriptor] = $this->createParserWithDescriptor($content, 'pitm');

        self::assertSame(100000, $parser->parsePitm($descriptor));
    }

    /**
     * Returns null for unsupported pitm version.
     */
    #[Test]
    public function parsePitmReturnsNullForUnsupportedVersion(): void
    {
        // version=2, flags=0, item_ID=1
        $content = chr(2) . chr(0) . chr(0) . chr(0) . pack('n', 1);

        [$parser, $descriptor] = $this->createParserWithDescriptor($content, 'pitm');

        self::assertNull($parser->parsePitm($descriptor));
    }

    /**
     * Rejects a truncated pitm box.
     */
    #[Test]
    public function parsePitmRejectsTruncated(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('pitm box truncated');

        // Only 5 bytes (needs at least 6)
        $content = chr(0) . chr(0) . chr(0) . chr(0) . chr(0);

        [$parser, $descriptor] = $this->createParserWithDescriptor($content, 'pitm');

        $parser->parsePitm($descriptor);
    }

    // =========================================================================
    // parseIinf — positive test
    // =========================================================================

    /**
     * Parses a valid iinf v0 box with one infe v2 entry.
     */
    #[Test]
    public function parseIinfVersion0WithOneEntry(): void
    {
        // infe v2: version=2, flags=0, item_ID=1 (16-bit), protection_index=0, item_type='Exif', name='exif\0'
        $infePayload = chr(2) . chr(0) . chr(0) . chr(0)
            . pack('n', 1)
            . pack('n', 0)
            . 'Exif'
            . "exif\0";

        $infeBox = $this->box('infe', $infePayload);

        // iinf v0: version=0, flags=0, entry_count=1
        $iinfPayload = chr(0) . chr(0) . chr(0) . chr(0)
            . pack('n', 1)
            . $infeBox;

        [$parser, $descriptor] = $this->createParserWithDescriptor($iinfPayload, 'iinf');

        $items = $parser->parseIinf($descriptor);

        self::assertCount(1, $items);
        self::assertSame(1, $items[0]['id']);
        self::assertSame('Exif', $items[0]['itemType']);
        self::assertSame('exif', $items[0]['name']);
    }

    // =========================================================================
    // parseIinf — negative test
    // =========================================================================

    /**
     * Rejects a truncated iinf box.
     */
    #[Test]
    public function parseIinfRejectsTruncated(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iinf box truncated');

        // Only 5 bytes (needs at least 6)
        $content = chr(0) . chr(0) . chr(0) . chr(0) . chr(0);

        [$parser, $descriptor] = $this->createParserWithDescriptor($content, 'iinf');

        $parser->parseIinf($descriptor);
    }

    /**
     * Rejects iinf with unsupported version.
     */
    #[Test]
    public function parseIinfRejectsUnsupportedVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported iinf box version');

        $content = chr(2) . chr(0) . chr(0) . chr(0) . pack('n', 0);

        [$parser, $descriptor] = $this->createParserWithDescriptor($content, 'iinf');

        $parser->parseIinf($descriptor);
    }

    // =========================================================================
    // parseIref — positive test
    // =========================================================================

    /**
     * Parses a valid iref v0 box with one reference entry.
     */
    #[Test]
    public function parseIrefVersion0SingleReference(): void
    {
        // Reference entry: fromItemId=1, referenceCount=1, toItemId=2
        $entryPayload = pack('n', 1) . pack('n', 1) . pack('n', 2);
        $entryBox     = $this->box('cdsc', $entryPayload);

        // iref v0: version=0, flags=0
        $irefPayload = chr(0) . chr(0) . chr(0) . chr(0) . $entryBox;

        [$parser, $descriptor] = $this->createParserWithDescriptor($irefPayload, 'iref');

        $references = $parser->parseIref($descriptor);

        self::assertArrayHasKey(1, $references);
        self::assertCount(1, $references[1]);
        self::assertSame('cdsc', $references[1][0]->relation);
        self::assertSame(2, $references[1][0]->toItemId);
    }

    // =========================================================================
    // parseIref — negative test
    // =========================================================================

    /**
     * Rejects a truncated iref box.
     */
    #[Test]
    public function parseIrefRejectsTruncated(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('iref box truncated');

        // Only 3 bytes
        $content = chr(0) . chr(0) . chr(0);

        [$parser, $descriptor] = $this->createParserWithDescriptor($content, 'iref');

        $parser->parseIref($descriptor);
    }

    /**
     * Returns empty for unsupported iref version.
     */
    #[Test]
    public function parseIrefReturnsEmptyForUnsupportedVersion(): void
    {
        // version=2, flags=0
        $content = chr(2) . chr(0) . chr(0) . chr(0);

        [$parser, $descriptor] = $this->createParserWithDescriptor($content, 'iref');

        self::assertSame([], $parser->parseIref($descriptor));
    }

    // =========================================================================
    // parseDinf — positive test
    // =========================================================================

    /**
     * Parses a valid dinf box with a self-contained url reference.
     */
    #[Test]
    public function parseDinfWithSelfContainedUrl(): void
    {
        // url entry: version=0, flags=1 (self-contained)
        $urlPayload = chr(0) . chr(0) . chr(0) . chr(1);
        $urlBox     = $this->box('url ', $urlPayload);

        // dref: version=0, flags=0, entry_count=1
        $drefPayload = chr(0) . chr(0) . chr(0) . chr(0) . pack('N', 1) . $urlBox;
        $drefBox     = $this->box('dref', $drefPayload);

        $dinfPayload = $drefBox;

        [$parser, $descriptor] = $this->createParserWithDescriptor($dinfPayload, 'dinf');

        $references = $parser->parseDinf($descriptor);

        self::assertArrayHasKey(1, $references);
        self::assertTrue($references[1]->selfContained);
        self::assertSame('url ', $references[1]->type);
    }

    // =========================================================================
    // parseDinf — negative test
    // =========================================================================

    /**
     * Rejects a dinf box missing a dref child.
     */
    #[Test]
    public function parseDinfRejectsMissingDref(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('dinf must contain exactly one dref box');

        // dinf with a non-dref child
        $fakeChild   = $this->box('fake', 'data');
        $dinfPayload = $fakeChild;

        [$parser, $descriptor] = $this->createParserWithDescriptor($dinfPayload, 'dinf');

        $parser->parseDinf($descriptor);
    }
}
