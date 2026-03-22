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
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffQueuedResolveResult;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeDataAtom;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\IsoBmff\AudioSampleEntryParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxPayloadCollection;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxPayloadCollector;
use MagicSunday\ImageMeta\Parse\IsoBmff\FullBoxHeader;
use MagicSunday\ImageMeta\Parse\IsoBmff\IlocBoxParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParserConfig;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParseResult;
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

use function pack;
use function str_repeat;

/**
 * Exercises extraction of ICC profiles from colr boxes in ISO BMFF containers.
 *
 * ISO/IEC 14496-12 §12.1.5: colr box with colour_type 'prof' or 'rICC'
 * contains an embedded ICC profile.
 *
 * @internal
 */
#[CoversClass(IsoBmffParser::class)]
#[CoversClass(BoxPayloadCollector::class)]
#[UsesClass(AudioSampleEntryParser::class)]
#[UsesClass(BoxNavigator::class)]
#[UsesClass(BoxPayloadCollection::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(FullBoxHeader::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesTrait(NormalizesOffsets::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesClass(IsoBmffDataReferenceMap::class)]
#[UsesClass(IsoBmffUnresolvedItem::class)]
#[UsesClass(QuickTimeDataAtom::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(ConstructionMethod::class)]
#[UsesClass(IsoBmffParseResult::class)]
#[UsesClass(IlocBoxParser::class)]
#[UsesClass(ItemLocationResolver::class)]
#[UsesClass(ItemPayloadResolver::class)]
#[UsesClass(QuickTimeKeyResolver::class)]
#[UsesClass(QuickTimeMetadataDecoder::class)]
#[UsesClass(QuickTimeValueDecoder::class)]
#[UsesClass(TrackMediaParser::class)]
#[UsesClass(VideoSampleEntryParser::class)]
#[UsesClass(IsoBmffQueuedResolveResult::class)]
#[UsesClass(IsoBmffParserConfig::class)]
final class ColrBoxParsingTest extends TestCase
{
    use IsoBmffBoxTrait;

    /**
     * Extracts an ICC profile from a colr box with colour_type 'prof'.
     *
     * ISO/IEC 14496-12 §12.1.5: when colour_type is 'prof', the remaining
     * payload is an unrestricted ICC profile.
     */
    #[Test]
    public function extractReturnsIccProfileFromProfColrBox(): void
    {
        $iccBlob = $this->syntheticIccProfile();
        $data    = $this->buildColrContainer('prof', $iccBlob);

        $result = $this->createExtractor($data)->extract();

        self::assertSame($iccBlob, $result->iccProfile);
    }

    /**
     * Extracts an ICC profile from a colr box with colour_type 'rICC'.
     *
     * ISO/IEC 14496-12 §12.1.5: 'rICC' contains a restricted ICC profile.
     */
    #[Test]
    public function extractReturnsIccProfileFromRiccColrBox(): void
    {
        $iccBlob = $this->syntheticIccProfile();
        $data    = $this->buildColrContainer('rICC', $iccBlob);

        $result = $this->createExtractor($data)->extract();

        self::assertSame($iccBlob, $result->iccProfile);
    }

    /**
     * Returns null for colr box with colour_type 'nclx' (no ICC profile).
     */
    #[Test]
    public function extractReturnsNullForNclxColrBox(): void
    {
        // nclx: 2+2+2+1 = 7 bytes of colour parameters
        $nclxPayload = pack('nnn', 1, 13, 6) . "\x80";
        $data        = $this->buildColrContainer('nclx', $nclxPayload);

        $result = $this->createExtractor($data)->extract();

        self::assertNull($result->iccProfile);
    }

    /**
     * Returns null when no colr box is present in ipco.
     */
    #[Test]
    public function extractReturnsNullWhenNoColrPresent(): void
    {
        $ispe = $this->fullBox('ispe', pack('N', 100) . pack('N', 200));
        $ipco = $this->box('ipco', $ispe);
        $iprp = $this->box('iprp', $ipco);
        $hdlr = $this->fullBox('hdlr', pack('N', 0) . 'pict' . str_repeat("\0", 12));
        $meta = $this->fullBox('meta', $hdlr . $iprp);
        $ftyp = $this->box('ftyp', 'heic' . pack('N', 0));
        $data = $ftyp . $meta;

        $result = $this->createExtractor($data)->extract();

        self::assertNull($result->iccProfile);
    }

    /**
     * Returns null for colr box with truncated payload (< 4 bytes for colour_type).
     */
    #[Test]
    public function extractReturnsNullForTruncatedColrBox(): void
    {
        $data = $this->buildColrContainer('pro', '');

        $result = $this->createExtractor($data)->extract();

        self::assertNull($result->iccProfile);
    }

    /**
     * Builds a minimal HEIC-style container with a colr box inside meta/iprp/ipco.
     */
    private function buildColrContainer(string $colourType, string $iccPayload): string
    {
        $colr = $this->box('colr', $colourType . $iccPayload);
        $ispe = $this->fullBox('ispe', pack('N', 100) . pack('N', 200));
        $ipco = $this->box('ipco', $ispe . $colr);
        $iprp = $this->box('iprp', $ipco);
        $hdlr = $this->fullBox('hdlr', pack('N', 0) . 'pict' . str_repeat("\0", 12));
        $meta = $this->fullBox('meta', $hdlr . $iprp);
        $ftyp = $this->box('ftyp', 'heic' . pack('N', 0));

        return $ftyp . $meta;
    }

    /**
     * Creates a synthetic ICC profile blob for testing.
     * Minimal valid structure: 128-byte header with 'acsp' signature at offset 36.
     */
    private function syntheticIccProfile(): string
    {
        $profile = str_repeat("\0", 128);
        // ICC profile size at offset 0 (4 bytes BE)
        $profile[0] = "\x00";
        $profile[1] = "\x00";
        $profile[2] = "\x00";
        $profile[3] = "\x80";
        // 'acsp' signature at offset 36
        $profile[36] = 'a';
        $profile[37] = 'c';
        $profile[38] = 's';
        $profile[39] = 'p';

        return $profile;
    }

    /**
     * Creates an IsoBmffParser from a synthetic binary payload.
     */
    private function createExtractor(string $data): IsoBmffParser
    {
        return new IsoBmffParser($this->createIsoBmffTempStream($data));
    }
}
