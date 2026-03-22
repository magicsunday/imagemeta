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
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;
use MagicSunday\ImageMeta\Parse\IsoBmff\FullBoxHeader;
use MagicSunday\ImageMeta\Tests\Helpers\IsoBmffBoxTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;
use function pack;
use function strlen;

/**
 * Tests the BoxNavigator class for ISO BMFF box iteration, header reading,
 * integer decoding, and fourcc validation.
 * Covers positive iteration of child boxes, negative error paths for invalid
 * sizes and misaligned children, nested box reading, and utility methods.
 */
#[CoversClass(BoxNavigator::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesClass(FullBoxHeader::class)]
#[UsesTrait(NormalizesOffsets::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
#[UsesClass(UInt64::class)]
final class BoxNavigatorTest extends TestCase
{
    use IsoBmffBoxTrait;

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Creates a BoxNavigator wrapping the given binary data and returns it
     * together with a BoxDescriptor covering the entire data as a container.
     *
     * @return array{0: BoxNavigator, 1: BoxDescriptor}
     */
    private function createNavigatorWithContainer(string $data): array
    {
        $contentSize = strlen($data);
        $stream      = $this->createIsoBmffTempStream($data);
        $navigator   = new BoxNavigator($stream);
        $window      = $stream->window(0, $contentSize);

        $container = new BoxDescriptor(
            type: 'root',
            size: $contentSize,
            offset: 0,
            contentOffset: 0,
            contentSize: $contentSize,
            window: $window,
            userType: null,
        );

        return [$navigator, $container];
    }

    /**
     * Creates a BoxNavigator from raw binary data.
     */
    private function createNavigator(string $data): BoxNavigator
    {
        return new BoxNavigator($this->createIsoBmffTempStream($data));
    }

    /**
     * Creates a navigator backed by a single-byte dummy stream.
     */
    private function createDummyNavigator(): BoxNavigator
    {
        return $this->createNavigator('X');
    }

    /**
     * Materializes child descriptors for easier assertions.
     *
     * @return list<BoxDescriptor>
     */
    private function walkChildrenToList(
        BoxNavigator $navigator,
        BoxDescriptor $container,
        int $offset = 0,
        bool $allowTrailingTerminator = false,
    ): array {
        $children = [];

        foreach ($navigator->walkChildren($container, $offset, $allowTrailingTerminator) as $box) {
            $children[] = $box;
        }

        return $children;
    }

    /**
     * Materializes child types for simpler order assertions.
     *
     * @return list<string>
     */
    private function walkChildTypes(
        BoxNavigator $navigator,
        BoxDescriptor $container,
        int $offset = 0,
        bool $allowTrailingTerminator = false,
    ): array {
        return array_map(
            static fn (BoxDescriptor $box): string => $box->type,
            $this->walkChildrenToList($navigator, $container, $offset, $allowTrailingTerminator),
        );
    }

    // =========================================================================
    // walkChildren — positive tests
    // =========================================================================

    /**
     * Iterates two adjacent child boxes and verifies their types and sizes.
     * This confirms walkChildren yields BoxDescriptor instances in order.
     */
    #[Test]
    public function walkChildrenYieldsTwoAdjacentBoxes(): void
    {
        $child1 = $this->box('abcd', 'HELLO');
        $child2 = $this->box('efgh', 'WORLD!!');
        $data   = $child1 . $child2;

        [$navigator, $container] = $this->createNavigatorWithContainer($data);

        self::assertSame(['abcd', 'efgh'], $this->walkChildTypes($navigator, $container));
    }

    /**
     * Iterates a single child box and checks that all descriptor fields are correct.
     */
    #[Test]
    public function walkChildrenReturnsSingleChildWithCorrectDescriptor(): void
    {
        $payload = 'DATA';
        $child   = $this->box('test', $payload);

        [$navigator, $container] = $this->createNavigatorWithContainer($child);

        $children = $this->walkChildrenToList($navigator, $container);

        self::assertCount(1, $children);
        self::assertSame('test', $children[0]->type);
        self::assertSame(12, $children[0]->size);
        self::assertSame(0, $children[0]->offset);
        self::assertSame(8, $children[0]->contentOffset);
        self::assertSame(4, $children[0]->contentSize);
    }

    /**
     * Iterates children starting at a non-zero offset, skipping leading bytes.
     */
    #[Test]
    public function walkChildrenWithOffset(): void
    {
        $prefix = pack('N', 0);
        $child  = $this->box('skip', 'OK');
        $data   = $prefix . $child;

        [$navigator, $container] = $this->createNavigatorWithContainer($data);

        self::assertSame(['skip'], $this->walkChildTypes($navigator, $container, 4));
    }

    /**
     * Verifies nested boxes can be walked by using a child's descriptor as a container.
     */
    #[Test]
    public function walkChildrenHandlesNestedBoxes(): void
    {
        $inner = $this->box('innr', 'AB');
        $outer = $this->box('outr', $inner);
        $data  = $outer;

        [$navigator, $container] = $this->createNavigatorWithContainer($data);

        $outerBoxes = $this->walkChildrenToList($navigator, $container);

        self::assertCount(1, $outerBoxes);
        self::assertSame('outr', $outerBoxes[0]->type);

        $innerBoxes = $this->walkChildrenToList($navigator, $outerBoxes[0]);

        self::assertCount(1, $innerBoxes);
        self::assertSame('innr', $innerBoxes[0]->type);
    }

    /**
     * Tolerates a trailing 4-byte zero terminator when flag is enabled.
     */
    #[Test]
    public function walkChildrenAllowsTrailingTerminator(): void
    {
        $child      = $this->box('term', 'XY');
        $terminator = pack('N', 0);
        $data       = $child . $terminator;

        [$navigator, $container] = $this->createNavigatorWithContainer($data);

        self::assertSame(['term'], $this->walkChildTypes($navigator, $container, allowTrailingTerminator: true));
    }

    // =========================================================================
    // walkChildren — negative tests
    // =========================================================================

    /**
     * Rejects a negative child offset.
     */
    #[Test]
    public function walkChildrenRejectsNegativeOffset(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('child offset outside container');

        $child                   = $this->box('test', 'X');
        [$navigator, $container] = $this->createNavigatorWithContainer($child);

        iterator_to_array($navigator->walkChildren($container, -1));
    }

    /**
     * Rejects a child offset exceeding the container content size.
     */
    #[Test]
    public function walkChildrenRejectsExcessiveOffset(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('child offset outside container');

        $child                   = $this->box('test', 'X');
        [$navigator, $container] = $this->createNavigatorWithContainer($child);

        iterator_to_array($navigator->walkChildren($container, $container->contentSize + 1));
    }

    /**
     * Rejects misaligned children where remaining bytes do not match parent bounds.
     */
    #[Test]
    public function walkChildrenRejectsMisalignedChildren(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('child boxes do not align with parent');

        $child = $this->box('test', 'X');
        $data  = $child . "\xFF\xFF";

        [$navigator, $container] = $this->createNavigatorWithContainer($data);

        iterator_to_array($navigator->walkChildren($container));
    }

    /**
     * Rejects a trailing 4-byte non-zero terminator even when flag is enabled.
     */
    #[Test]
    public function walkChildrenRejectsNonZeroTrailingTerminator(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('child boxes do not align with parent');

        $child      = $this->box('term', 'XY');
        $terminator = pack('N', 42);
        $data       = $child . $terminator;

        [$navigator, $container] = $this->createNavigatorWithContainer($data);

        iterator_to_array($navigator->walkChildren($container, allowTrailingTerminator: true));
    }

    // =========================================================================
    // readBoxAt — positive tests
    // =========================================================================

    /**
     * Reads a box at offset 0 and verifies all descriptor fields.
     */
    #[Test]
    public function readBoxAtReturnsCorrectDescriptor(): void
    {
        $payload   = 'ABCDEF';
        $boxBytes  = $this->box('test', $payload);
        $navigator = $this->createNavigator($boxBytes);

        $box = $navigator->readBoxAt(0, strlen($boxBytes));

        self::assertSame('test', $box->type);
        self::assertSame(14, $box->size);
        self::assertSame(0, $box->offset);
        self::assertSame(8, $box->contentOffset);
        self::assertSame(6, $box->contentSize);
        self::assertNull($box->userType);
    }

    /**
     * Reads a box with extended size (size==1 + 64-bit largesize).
     */
    #[Test]
    public function readBoxAtHandlesExtendedSize(): void
    {
        $payload   = 'ABCDEF';
        $totalSize = 16 + strlen($payload);
        $boxBytes  = pack('N', 1) . 'extd' . pack('N2', 0, $totalSize) . $payload;

        $navigator = $this->createNavigator($boxBytes);
        $box       = $navigator->readBoxAt(0, strlen($boxBytes));

        self::assertSame('extd', $box->type);
        self::assertSame($totalSize, $box->size);
        self::assertSame(16, $box->contentOffset);
        self::assertSame(strlen($payload), $box->contentSize);
    }

    /**
     * Reads a size==0 box at top level (implicit size to end of container).
     */
    #[Test]
    public function readBoxAtHandlesImplicitSize(): void
    {
        $payload  = 'IMPLICIT';
        $boxBytes = pack('N', 0) . 'impl' . $payload;

        $navigator = $this->createNavigator($boxBytes);
        $box       = $navigator->readBoxAt(0, strlen($boxBytes), true);

        self::assertSame('impl', $box->type);
        self::assertSame(strlen($boxBytes), $box->size);
        self::assertSame(8, $box->contentOffset);
        self::assertSame(strlen($payload), $box->contentSize);
    }

    // =========================================================================
    // readBoxAt — negative tests
    // =========================================================================

    /**
     * Rejects reading a box at a negative offset.
     */
    #[Test]
    public function readBoxAtRejectsNegativeOffset(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('box offset outside container');

        $navigator = $this->createNavigator($this->box('test', 'X'));
        $navigator->readBoxAt(-1, 100);
    }

    /**
     * Rejects reading a box at an offset beyond the limit.
     */
    #[Test]
    public function readBoxAtRejectsOffsetBeyondLimit(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('box offset outside container');

        $navigator = $this->createNavigator($this->box('test', 'X'));
        $navigator->readBoxAt(100, 50);
    }

    /**
     * Rejects a box where size==0 when not at top level.
     */
    #[Test]
    public function readBoxAtRejectsSizeZeroWithoutImplicit(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('nested box size==0 is only valid at top level');

        $boxBytes  = pack('N', 0) . 'testPAYLOAD';
        $navigator = $this->createNavigator($boxBytes);
        $navigator->readBoxAt(0, strlen($boxBytes));
    }

    /**
     * Rejects a box with size smaller than the header length.
     */
    #[Test]
    public function readBoxAtRejectsInvalidSize(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('invalid box size for test');

        $boxBytes  = pack('N', 4) . 'test';
        $navigator = $this->createNavigator($boxBytes);
        $navigator->readBoxAt(0, strlen($boxBytes));
    }

    /**
     * Rejects a box that exceeds container bounds.
     */
    #[Test]
    public function readBoxAtRejectsBoxExceedingContainer(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('box test exceeds container bounds');

        $boxBytes  = pack('N', 100) . 'testAB';
        $navigator = $this->createNavigator($boxBytes);
        $navigator->readBoxAt(0, strlen($boxBytes));
    }

    // =========================================================================
    // readUInt — positive and negative tests
    // =========================================================================

    /**
     * Reads a 2-byte unsigned integer from a stream window.
     */
    #[Test]
    public function readUIntReads16BitValue(): void
    {
        $data                    = pack('n', 0x1234);
        [$navigator, $container] = $this->createNavigatorWithContainer($data);

        $container->window->seek(0);
        $value = $navigator->readUInt($container->window, 2);

        self::assertSame(0x1234, $value);
    }

    /**
     * Returns 0 for a zero-byte read.
     */
    #[Test]
    public function readUIntReturnsZeroForZeroBytes(): void
    {
        $data                    = 'X';
        [$navigator, $container] = $this->createNavigatorWithContainer($data);

        $value = $navigator->readUInt($container->window, 0);

        self::assertSame(0, $value);
    }

    /**
     * Rejects unsupported integer sizes.
     */
    #[Test]
    public function readUIntRejectsUnsupportedSize(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported integer size 5');

        $data                    = 'ABCDE';
        [$navigator, $container] = $this->createNavigatorWithContainer($data);

        $container->window->seek(0);
        $navigator->readUInt($container->window, 5);
    }

    // =========================================================================
    // isPrintableFourcc — tests
    // =========================================================================

    /**
     * Accepts standard printable ASCII fourcc codes.
     */
    #[Test]
    public function isPrintableFourccAcceptsPrintableAscii(): void
    {
        $navigator = $this->createDummyNavigator();

        self::assertTrue($navigator->isPrintableFourcc('ftyp'));
        self::assertTrue($navigator->isPrintableFourcc('moov'));
        self::assertTrue($navigator->isPrintableFourcc('test'));
    }

    /**
     * Accepts copyright-prefix fourcc codes starting with 0xA9.
     */
    #[Test]
    public function isPrintableFourccAcceptsCopyrightPrefix(): void
    {
        $navigator = $this->createDummyNavigator();

        self::assertTrue($navigator->isPrintableFourcc("\xA9nam"));
        self::assertTrue($navigator->isPrintableFourcc("\xA9ART"));
    }

    /**
     * Rejects non-printable and wrong-length fourcc codes.
     */
    #[Test]
    public function isPrintableFourccRejectsInvalid(): void
    {
        $navigator = $this->createDummyNavigator();

        self::assertFalse($navigator->isPrintableFourcc(''));
        self::assertFalse($navigator->isPrintableFourcc('ab'));
        self::assertFalse($navigator->isPrintableFourcc('abcde'));
        self::assertFalse($navigator->isPrintableFourcc("\x00\x00\x00\x01"));
    }

    // =========================================================================
    // normalizeFourcc — tests
    // =========================================================================

    /**
     * Returns printable fourcc codes unchanged.
     */
    #[Test]
    public function normalizeFourccReturnsPrintableUnchanged(): void
    {
        $navigator = $this->createDummyNavigator();

        self::assertSame('ftyp', $navigator->normalizeFourcc('ftyp'));
    }

    /**
     * Converts non-printable fourcc codes to uppercase hex.
     */
    #[Test]
    public function normalizeFourccConvertsNonPrintableToHex(): void
    {
        $navigator = $this->createDummyNavigator();

        self::assertSame('00000001', $navigator->normalizeFourcc("\x00\x00\x00\x01"));
    }

    // =========================================================================
    // readAll — tests
    // =========================================================================

    /**
     * Reads the complete contents of a stream window.
     */
    #[Test]
    public function readAllReturnsEntirePayload(): void
    {
        $data                    = 'HELLOWORLD';
        [$navigator, $container] = $this->createNavigatorWithContainer($data);

        $result = $navigator->readAll($container->window);

        self::assertSame('HELLOWORLD', $result);
    }

    /**
     * Returns an empty string for a zero-length window.
     */
    #[Test]
    public function readAllReturnsEmptyForZeroSizeWindow(): void
    {
        $stream    = $this->createIsoBmffTempStream('X');
        $navigator = new BoxNavigator($stream);
        $window    = $stream->window(0, 0);

        self::assertSame('', $navigator->readAll($window));
    }

    // =========================================================================
    // readFullBoxHeader — positive tests
    // =========================================================================

    /**
     * Reads a FullBox header with version 0 and flags 0.
     *
     * ISO/IEC 14496-12 §4.2: FullBox = 1-byte version + 3-byte flags.
     */
    #[Test]
    public function readFullBoxHeaderReadsVersionZeroFlagsZero(): void
    {
        // version=0, flags=0x000000
        $data   = "\x00\x00\x00\x00";
        $window = $this->createIsoBmffTempWindow($data);
        $window->seek(0);

        $navigator = $this->createDummyNavigator();
        $header    = $navigator->readFullBoxHeader($window);

        self::assertSame(0, $header->version);
        self::assertSame(0, $header->flags);
    }

    /**
     * Reads a FullBox header with version 1 and flags 0.
     */
    #[Test]
    public function readFullBoxHeaderReadsVersionOneFlags(): void
    {
        // version=1, flags=0x000000
        $data   = "\x01\x00\x00\x00";
        $window = $this->createIsoBmffTempWindow($data);
        $window->seek(0);

        $navigator = $this->createDummyNavigator();
        $header    = $navigator->readFullBoxHeader($window);

        self::assertSame(1, $header->version);
        self::assertSame(0, $header->flags);
    }

    /**
     * Reads a FullBox header with non-zero flags preserving all 24 bits.
     */
    #[Test]
    public function readFullBoxHeaderReadsNonZeroFlags(): void
    {
        // version=0, flags=0x010001 (bit 0 and bit 16 set)
        $data   = "\x00\x01\x00\x01";
        $window = $this->createIsoBmffTempWindow($data);
        $window->seek(0);

        $navigator = $this->createDummyNavigator();
        $header    = $navigator->readFullBoxHeader($window);

        self::assertSame(0, $header->version);
        self::assertSame(0x010001, $header->flags);
    }

    /**
     * Reads a FullBox header with maximum version and all flag bits set.
     */
    #[Test]
    public function readFullBoxHeaderReadsMaxVersionAndFlags(): void
    {
        // version=255, flags=0xFFFFFF
        $data   = "\xFF\xFF\xFF\xFF";
        $window = $this->createIsoBmffTempWindow($data);
        $window->seek(0);

        $navigator = $this->createDummyNavigator();
        $header    = $navigator->readFullBoxHeader($window);

        self::assertSame(255, $header->version);
        self::assertSame(0xFFFFFF, $header->flags);
    }
}
