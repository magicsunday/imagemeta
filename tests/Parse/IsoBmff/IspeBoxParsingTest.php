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
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeDataAtom;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\IsoBmff\AudioSampleEntryParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxPayloadCollection;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxPayloadCollector;
use MagicSunday\ImageMeta\Parse\IsoBmff\IlocBoxParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParser;
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

use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function strlen;

/**
 * Exercises parsing of the ispe (Image Spatial Extents) box from ISO BMFF containers.
 * Validates that display_width and display_height are correctly extracted through
 * the iprp/ipco/ispe box hierarchy.
 *
 * @internal
 */
#[CoversClass(IsoBmffParser::class)]
#[CoversClass(BoxPayloadCollector::class)]
#[UsesClass(AudioSampleEntryParser::class)]
#[UsesClass(BoxNavigator::class)]
#[UsesClass(BoxPayloadCollection::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesTrait(NormalizesOffsets::class)]
#[UsesTrait(IsoBmffBoxTrait::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesClass(IsoBmffDataReferenceMap::class)]
#[UsesClass(IsoBmffUnresolvedItem::class)]
#[UsesClass(QuickTimeDataAtom::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(ConstructionMethod::class)]
#[UsesClass(IlocBoxParser::class)]
#[UsesClass(ItemLocationResolver::class)]
#[UsesClass(ItemPayloadResolver::class)]
#[UsesClass(QuickTimeKeyResolver::class)]
#[UsesClass(QuickTimeMetadataDecoder::class)]
#[UsesClass(QuickTimeValueDecoder::class)]
#[UsesClass(TrackMediaParser::class)]
#[UsesClass(VideoSampleEntryParser::class)]
final class IspeBoxParsingTest extends TestCase
{
    use IsoBmffBoxTrait;

    /**
     * Constructs a minimal HEIC-style container with an ispe box inside meta/iprp/ipco.
     * Verifies the extracted width and height match the encoded values.
     */
    #[Test]
    public function extractReturnsIspeWidthAndHeight(): void
    {
        // ispe is a FullBox(version=0, flags=0) with display_width(u32) + display_height(u32)
        $ispe = $this->fullBox('ispe', pack('N', 4032) . pack('N', 3024));

        // ipco contains property boxes
        $ipco = $this->box('ipco', $ispe);

        // iprp contains ipco (and optionally ipma, which we omit)
        $iprp = $this->box('iprp', $ipco);

        // Build a valid meta box with hdlr (pict handler) + iprp
        $hdlr = $this->fullBox('hdlr', pack('N', 0) . 'pict' . str_repeat("\0", 12));
        $meta = $this->fullBox('meta', $hdlr . $iprp);
        $ftyp = $this->box('ftyp', 'heic' . pack('N', 0));

        $data      = $ftyp . $meta;
        $extractor = $this->createExtractor($data);
        $result    = $extractor->extract();

        self::assertSame(4032, $result[6]);
        self::assertSame(3024, $result[7]);
    }

    /**
     * Constructs a container without an ispe box.
     * Verifies the extracted dimensions are null.
     */
    #[Test]
    public function extractReturnsNullWhenNoIspePresent(): void
    {
        $exifPayload = pack('N', 0) . "MM\x00\x2Aprimary-exif";
        $meta        = $this->fullBox('meta', $this->box('Exif', $exifPayload));
        $ftyp        = $this->box('ftyp', 'isom' . pack('N', 0));
        $data        = $ftyp . $meta;

        $extractor = $this->createExtractor($data);
        $result    = $extractor->extract();

        self::assertNull($result[6]);
        self::assertNull($result[7]);
    }

    /**
     * Constructs a container with a truncated ispe box (too few bytes).
     * Verifies the extracted dimensions are null rather than causing an error.
     */
    #[Test]
    public function extractReturnsNullForTruncatedIspe(): void
    {
        // ispe with only version/flags but no width/height payload
        $ispe = $this->fullBox('ispe', '');

        $ipco = $this->box('ipco', $ispe);
        $iprp = $this->box('iprp', $ipco);
        $hdlr = $this->fullBox('hdlr', pack('N', 0) . 'pict' . str_repeat("\0", 12));
        $meta = $this->fullBox('meta', $hdlr . $iprp);
        $ftyp = $this->box('ftyp', 'heic' . pack('N', 0));

        $data      = $ftyp . $meta;
        $extractor = $this->createExtractor($data);
        $result    = $extractor->extract();

        self::assertNull($result[6]);
        self::assertNull($result[7]);
    }

    /**
     * Constructs a container with AVIF brand and ispe box.
     * Verifies the parser also handles AVIF-style containers.
     */
    #[Test]
    public function extractIspeFromAvifContainer(): void
    {
        $ispe = $this->fullBox('ispe', pack('N', 1920) . pack('N', 1080));
        $ipco = $this->box('ipco', $ispe);
        $iprp = $this->box('iprp', $ipco);
        $hdlr = $this->fullBox('hdlr', pack('N', 0) . 'pict' . str_repeat("\0", 12));
        $meta = $this->fullBox('meta', $hdlr . $iprp);
        $ftyp = $this->box('ftyp', 'avif' . pack('N', 0));

        $data      = $ftyp . $meta;
        $extractor = $this->createExtractor($data);
        $result    = $extractor->extract();

        self::assertSame(1920, $result[6]);
        self::assertSame(1080, $result[7]);
    }

    /**
     * Creates an IsoBmffParser from a synthetic binary payload.
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
}
