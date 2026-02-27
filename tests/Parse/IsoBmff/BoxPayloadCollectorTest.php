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
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Parse\IsoBmff\AudioSampleEntryParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxPayloadCollection;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxPayloadCollector;
use MagicSunday\ImageMeta\Parse\IsoBmff\IlocBoxParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParseContext;
use MagicSunday\ImageMeta\Parse\IsoBmff\ItemLocationResolver;
use MagicSunday\ImageMeta\Parse\IsoBmff\ItemPayloadResolver;
use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeKeyResolver;
use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeMetadataDecoder;
use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeValueDecoder;
use MagicSunday\ImageMeta\Parse\IsoBmff\TrackMediaParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\VideoSampleEntryParser;
use MagicSunday\ImageMeta\Tests\Helpers\IsoBmffBoxTrait;
use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_map;
use function chr;
use function pack;
use function str_repeat;
use function strlen;

/**
 * Tests the BoxPayloadCollector orchestration of meta box child dispatching.
 * Validates collection of direct Exif, direct XMP, idat payloads, and pitm.
 * Covers error paths for duplicate hdlr boxes, ambiguous meta layouts, and
 * meta box version validation.
 */
#[CoversClass(BoxPayloadCollector::class)]
#[UsesClass(BoxPayloadCollection::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(BoxNavigator::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(ConstructionMethod::class)]
#[UsesClass(IlocBoxParser::class)]
#[UsesClass(ItemLocationResolver::class)]
#[UsesClass(ItemPayloadResolver::class)]
#[UsesClass(PayloadGuard::class)]
#[UsesClass(QuickTimeKeyResolver::class)]
#[UsesClass(QuickTimeMetadataDecoder::class)]
#[UsesClass(QuickTimeValueDecoder::class)]
#[UsesClass(TrackMediaParser::class)]
#[UsesClass(VideoSampleEntryParser::class)]
#[UsesClass(AudioSampleEntryParser::class)]
#[UsesClass(IsoBmffDataReference::class)]
#[UsesClass(IsoBmffItemReference::class)]
#[UsesTrait(NormalizesOffsets::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
#[UsesTrait(IsoBmffBoxTrait::class)]
final class BoxPayloadCollectorTest extends TestCase
{
    use IsoBmffBoxTrait;

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Creates a fully wired BoxPayloadCollector from raw binary data.
     *
     * @return array{0: BoxPayloadCollector, 1: BoxNavigator}
     */
    private function createCollector(string $data): array
    {
        $stream    = $this->createIsoBmffTempStream($data);
        $navigator = new BoxNavigator($stream);

        $keyResolver     = new QuickTimeKeyResolver($navigator);
        $ilocParser      = new IlocBoxParser($navigator);
        $payloadResolver = new ItemPayloadResolver($stream, $navigator, 1_048_576);

        $valueDecoder = new QuickTimeValueDecoder(
            static fn (string $payload): array => ['keys' => [], 'atoms' => []],
        );

        $qtDecoder = new QuickTimeMetadataDecoder($navigator, $keyResolver, $valueDecoder);

        $trackMediaParser = new TrackMediaParser(
            $navigator,
            static function (BoxDescriptor $box, IsoBmffParseContext $ctx): void {},
            $ilocParser->parseDinf(...),
        );

        $collector = new BoxPayloadCollector(
            $navigator,
            $trackMediaParser,
            $ilocParser,
            $qtDecoder,
            $keyResolver,
            $payloadResolver,
            1_048_576,
        );

        return [$collector, $navigator];
    }

    /**
     * Builds a valid hdlr box payload.
     */
    private function hdlrPayload(string $handlerType): string
    {
        // FullBox version=0, flags=0
        $data = chr(0) . chr(0) . chr(0) . chr(0);
        // pre_defined (4 bytes)
        $data .= pack('N', 0);
        // handler_type (4 bytes)
        $data .= $handlerType;
        // reserved (12 bytes)
        $data .= str_repeat("\0", 12);
        // name (NUL-terminated)
        $data .= "\0";

        return $data;
    }

    // =========================================================================
    // collect — positive tests
    // =========================================================================

    /**
     * Collects a direct Exif box from a meta container.
     */
    #[Test]
    public function collectDirectExifFromMetaBox(): void
    {
        $exifBlob = pack('N', 0) . "MM\x00\x2Atest-exif";
        $exifBox  = $this->box('Exif', $exifBlob);
        $hdlrBox  = $this->box('hdlr', $this->hdlrPayload('pict'));

        // meta as FullBox: version=0, flags=0 + children
        $metaPayload = chr(0) . chr(0) . chr(0) . chr(0) . $hdlrBox . $exifBox;
        $metaData    = $this->box('meta', $metaPayload);

        [$collector, $navigator] = $this->createCollector($metaData);
        $metaBox                 = $navigator->readBoxAt(0, strlen($metaData), true);
        $collection              = $collector->collect($metaBox, false);

        self::assertSame(["MM\x00\x2Atest-exif"], $collection->directExif);
        self::assertSame([], $collection->directXmp);
    }

    /**
     * Collects a direct XMP box from a meta container.
     */
    #[Test]
    public function collectDirectXmpFromMetaBox(): void
    {
        $xmpData = '<x:xmpmeta>test</x:xmpmeta>';
        $xmpBox  = $this->box('XMP ', $xmpData);
        $hdlrBox = $this->box('hdlr', $this->hdlrPayload('pict'));

        $metaPayload = chr(0) . chr(0) . chr(0) . chr(0) . $hdlrBox . $xmpBox;
        $metaData    = $this->box('meta', $metaPayload);

        [$collector, $navigator] = $this->createCollector($metaData);
        $metaBox                 = $navigator->readBoxAt(0, strlen($metaData), true);
        $collection              = $collector->collect($metaBox, false);

        self::assertSame([$xmpData], $collection->directXmp);
        self::assertSame([], $collection->directExif);
    }

    /**
     * Collects an idat payload from a meta container.
     */
    #[Test]
    public function collectIdatFromMetaBox(): void
    {
        $idatPayload = 'binary-payload-data';
        $idatBox     = $this->box('idat', $idatPayload);
        $hdlrBox     = $this->box('hdlr', $this->hdlrPayload('pict'));

        $metaPayload = chr(0) . chr(0) . chr(0) . chr(0) . $hdlrBox . $idatBox;
        $metaData    = $this->box('meta', $metaPayload);

        [$collector, $navigator] = $this->createCollector($metaData);
        $metaBox                 = $navigator->readBoxAt(0, strlen($metaData), true);
        $collection              = $collector->collect($metaBox, false);

        self::assertSame('binary-payload-data', $collection->idatPayload);
    }

    /**
     * Collects a primary item ID from pitm.
     */
    #[Test]
    public function collectPrimaryItemIdFromPitm(): void
    {
        // pitm v0: version=0, flags=0, item_ID=42
        $pitmPayload = chr(0) . chr(0) . chr(0) . chr(0) . pack('n', 42);
        $pitmBox     = $this->box('pitm', $pitmPayload);
        $hdlrBox     = $this->box('hdlr', $this->hdlrPayload('pict'));

        // Also need an iloc or iinf with item 42 for pitm to validate
        // iloc v0: single item (id=42, dataRefIndex=0, baseOffset=0, 1 extent at offset=0, length=10)
        $ilocPayload = chr(0) . chr(0) . chr(0) . chr(0)
            . chr((4 << 4) | 4)
            . chr((4 << 4) | 0)
            . pack('n', 1)
            . pack('n', 42)
            . pack('n', 0)
            . pack('N', 0)
            . pack('n', 1)
            . pack('N', 0)
            . pack('N', 10);
        $ilocBox = $this->box('iloc', $ilocPayload);

        $metaPayload = chr(0) . chr(0) . chr(0) . chr(0) . $hdlrBox . $pitmBox . $ilocBox;
        $metaData    = $this->box('meta', $metaPayload);

        [$collector, $navigator] = $this->createCollector($metaData);
        $metaBox                 = $navigator->readBoxAt(0, strlen($metaData), true);
        $collection              = $collector->collect($metaBox, false);

        self::assertSame(42, $collection->primaryItemId);
    }

    // =========================================================================
    // collect — negative tests
    // =========================================================================

    /**
     * Rejects a meta box with duplicate hdlr boxes.
     */
    #[Test]
    public function collectRejectsDuplicateHdlr(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('meta must contain exactly one hdlr box');

        $hdlrBox1 = $this->box('hdlr', $this->hdlrPayload('pict'));
        $hdlrBox2 = $this->box('hdlr', $this->hdlrPayload('mdta'));

        $metaPayload = chr(0) . chr(0) . chr(0) . chr(0) . $hdlrBox1 . $hdlrBox2;
        $metaData    = $this->box('meta', $metaPayload);

        [$collector, $navigator] = $this->createCollector($metaData);
        $metaBox                 = $navigator->readBoxAt(0, strlen($metaData), true);
        $collector->collect($metaBox, false);
    }

    /**
     * Rejects a meta box with unsupported version in the FullBox header.
     */
    #[Test]
    public function collectRejectsUnsupportedMetaVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported meta box version');

        $hdlrBox = $this->box('hdlr', $this->hdlrPayload('pict'));

        // meta FullBox with version=1 (unsupported)
        $metaPayload = chr(1) . chr(0) . chr(0) . chr(0) . $hdlrBox;
        $metaData    = $this->box('meta', $metaPayload);

        [$collector, $navigator] = $this->createCollector($metaData);
        $metaBox                 = $navigator->readBoxAt(0, strlen($metaData), true);
        $collector->collect($metaBox, false);
    }

    /**
     * Rejects duplicate idat boxes.
     */
    #[Test]
    public function collectRejectsDuplicateIdat(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('meta context must contain at most one idat box');

        $hdlrBox  = $this->box('hdlr', $this->hdlrPayload('pict'));
        $idatBox1 = $this->box('idat', 'first');
        $idatBox2 = $this->box('idat', 'second');

        $metaPayload = chr(0) . chr(0) . chr(0) . chr(0) . $hdlrBox . $idatBox1 . $idatBox2;
        $metaData    = $this->box('meta', $metaPayload);

        [$collector, $navigator] = $this->createCollector($metaData);
        $metaBox                 = $navigator->readBoxAt(0, strlen($metaData), true);
        $collector->collect($metaBox, false);
    }

    /**
     * Collects from a non-full meta box even when QuickTime compatibility is disabled.
     */
    #[Test]
    public function collectAcceptsNonFullMetaWhenQuickTimeDisabled(): void
    {
        $exifBlob = pack('N', 0) . "MM\x00\x2Aisom-exif";
        $exifBox  = $this->box('Exif', $exifBlob);

        // meta without FullBox header (children start immediately)
        $metaData = $this->box('meta', $exifBox);

        [$collector, $navigator] = $this->createCollector($metaData);
        $metaBox                 = $navigator->readBoxAt(0, strlen($metaData), true);
        $collection              = $collector->collect($metaBox, false);

        self::assertSame(["MM\x00\x2Aisom-exif"], $collection->directExif);
    }

    /**
     * Collects from a non-full meta box when QuickTime compatibility is enabled.
     */
    #[Test]
    public function collectAcceptsNonFullMetaWhenQuickTimeEnabled(): void
    {
        $exifBlob = pack('N', 0) . "MM\x00\x2Aqt-exif";
        $exifBox  = $this->box('Exif', $exifBlob);

        // meta without FullBox header (children start immediately)
        $metaData = $this->box('meta', $exifBox);

        [$collector, $navigator] = $this->createCollector($metaData);
        $metaBox                 = $navigator->readBoxAt(0, strlen($metaData), true);
        $collection              = $collector->collect($metaBox, true);

        self::assertSame(["MM\x00\x2Aqt-exif"], $collection->directExif);
    }

    /**
     * Guards the readability refactor by requiring focused dispatch helper methods.
     */
    #[Test]
    public function collectUsesFocusedDispatchHelpers(): void
    {
        $reflection = new ReflectionClass(BoxPayloadCollector::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertContains('collectDirectExifPayload', $methods);
        self::assertContains('collectDirectXmpPayload', $methods);
        self::assertContains('collectUuidXmpPayload', $methods);
        self::assertContains('collectIdatPayload', $methods);
        self::assertContains('assertPayloadWithinLimit', $methods);
    }

    /**
     * Guards item payload resolver refactoring by requiring method-specific handlers.
     */
    #[Test]
    public function itemPayloadResolverUsesConstructionMethodHandlers(): void
    {
        $reflection = new ReflectionClass(ItemPayloadResolver::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertContains('resolveFileOffsetItemData', $methods);
        self::assertContains('resolveIdatOffsetItemData', $methods);
        self::assertContains('resolveItemOffsetItemData', $methods);
        self::assertContains('resolveReferencedItemData', $methods);
    }
}
