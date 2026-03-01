<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jxl;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;
use MagicSunday\ImageMeta\Parse\Jxl\JxlParser;
use MagicSunday\ImageMeta\Tests\Helpers\IsoBmffBoxTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function assert;
use function fopen;
use function fwrite;
use function is_resource;
use function pack;
use function rewind;
use function strlen;

/**
 * Exercises JPEG XL container parsing with synthetic JXL box layouts.
 * It verifies EXIF and XMP extraction from top-level Exif and xml boxes.
 * Error cases ensure malformed payloads raise ParseError without crashes.
 * Empty containers produce empty results instead of errors.
 */
#[CoversClass(JxlParser::class)]
#[UsesClass(BoxNavigator::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(Unpack::class)]
#[UsesTrait(NormalizesOffsets::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
#[UsesTrait(IsoBmffBoxTrait::class)]
final class JxlParserTest extends TestCase
{
    use IsoBmffBoxTrait;

    /**
     * 12-byte JXL signature box per ISO/IEC 18181-2 §A.2.1.
     */
    private const string JXL_SIGNATURE = "\x00\x00\x00\x0C\x4A\x58\x4C\x20\x0D\x0A\x87\x0A";

    /**
     * Minimal little-endian TIFF header (byte order marker + magic + IFD offset 0).
     */
    private const string TIFF_LE_HEADER = "II\x2A\x00\x00\x00\x00\x00";

    /**
     * Extracts EXIF from a JXL container with a top-level Exif box.
     * Verifies the TIFF header is returned after stripping the 4-byte offset prefix.
     */
    #[Test]
    public function extractsExifFromTopLevelExifBox(): void
    {
        $tiff     = self::TIFF_LE_HEADER;
        $exifBlob = pack('N', 0) . $tiff;

        $jxl = self::JXL_SIGNATURE
            . $this->box('ftyp', 'jxl ' . pack('N', 0))
            . $this->box('Exif', $exifBlob)
            . $this->box('jxlc', 'codestream-data');

        [$exifBlobs, $xmpBlobs] = $this->extractFromJxl($jxl);

        self::assertCount(1, $exifBlobs);
        self::assertSame($tiff, $exifBlobs[0]);
        self::assertSame([], $xmpBlobs);
    }

    /**
     * Extracts XMP from a JXL container with a top-level xml box.
     */
    #[Test]
    public function extractsXmpFromTopLevelXmlBox(): void
    {
        $xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" dc:creator="Agent" />'
            . '</rdf:RDF>'
            . '</x:xmpmeta>';

        $jxl = self::JXL_SIGNATURE
            . $this->box('ftyp', 'jxl ' . pack('N', 0))
            . $this->box('xml ', $xmp)
            . $this->box('jxlc', 'codestream-data');

        [$exifBlobs, $xmpBlobs] = $this->extractFromJxl($jxl);

        self::assertSame([], $exifBlobs);
        self::assertCount(1, $xmpBlobs);
        self::assertSame($xmp, $xmpBlobs[0]);
    }

    /**
     * Extracts both EXIF and XMP from a JXL container that has both boxes.
     */
    #[Test]
    public function extractsBothExifAndXmpFromJxl(): void
    {
        $tiff     = self::TIFF_LE_HEADER;
        $exifBlob = pack('N', 0) . $tiff;
        $xmp      = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" /></x:xmpmeta>';

        $jxl = self::JXL_SIGNATURE
            . $this->box('ftyp', 'jxl ' . pack('N', 0))
            . $this->box('Exif', $exifBlob)
            . $this->box('xml ', $xmp)
            . $this->box('jxlc', 'codestream-data');

        [$exifBlobs, $xmpBlobs] = $this->extractFromJxl($jxl);

        self::assertCount(1, $exifBlobs);
        self::assertSame($tiff, $exifBlobs[0]);
        self::assertCount(1, $xmpBlobs);
        self::assertSame($xmp, $xmpBlobs[0]);
    }

    /**
     * Returns empty results for a JXL container with no metadata boxes.
     */
    #[Test]
    public function returnsEmptyForJxlWithoutMetadata(): void
    {
        $jxl = self::JXL_SIGNATURE
            . $this->box('ftyp', 'jxl ' . pack('N', 0))
            . $this->box('jxlc', 'codestream-data');

        [$exifBlobs, $xmpBlobs] = $this->extractFromJxl($jxl);

        self::assertSame([], $exifBlobs);
        self::assertSame([], $xmpBlobs);
    }

    /**
     * Handles Exif box with non-zero TIFF offset prefix (skips Exif\0\0 signature).
     */
    #[Test]
    public function handlesExifBoxWithNonZeroOffset(): void
    {
        $tiff     = self::TIFF_LE_HEADER;
        $exifBlob = pack('N', 6) . "Exif\x00\x00" . $tiff;

        $jxl = self::JXL_SIGNATURE
            . $this->box('Exif', $exifBlob);

        [$exifBlobs, $xmpBlobs] = $this->extractFromJxl($jxl);

        self::assertCount(1, $exifBlobs);
        self::assertSame($tiff, $exifBlobs[0]);
        self::assertSame([], $xmpBlobs);
    }

    /**
     * Throws ParseError when Exif box payload is too short for the 4-byte offset.
     */
    #[Test]
    public function throwsForExifBoxPayloadTooShort(): void
    {
        $jxl = self::JXL_SIGNATURE
            . $this->box('Exif', "\x00\x00");

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1562);

        $this->extractFromJxl($jxl);
    }

    /**
     * Throws ParseError when Exif TIFF-header offset points beyond payload.
     */
    #[Test]
    public function throwsForExifOffsetOutOfRange(): void
    {
        $jxl = self::JXL_SIGNATURE
            . $this->box('Exif', pack('N', 255) . 'II');

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1563);

        $this->extractFromJxl($jxl);
    }

    /**
     * Throws ParseError when Exif TIFF-header offset does not point to valid TIFF signature.
     */
    #[Test]
    public function throwsForExifInvalidTiffSignature(): void
    {
        $jxl = self::JXL_SIGNATURE
            . $this->box('Exif', pack('N', 0) . 'XX' . "\x2A\x00\x00\x00\x00\x00");

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1564);

        $this->extractFromJxl($jxl);
    }

    /**
     * Throws ParseError when Exif box payload exceeds the maximum payload size.
     */
    #[Test]
    public function throwsForOversizedExifPayload(): void
    {
        $tiff     = self::TIFF_LE_HEADER;
        $exifBlob = pack('N', 0) . $tiff;

        $jxl = self::JXL_SIGNATURE
            . $this->box('Exif', $exifBlob);

        $stream = $this->streamFromString($jxl);
        $parser = new JxlParser($stream, maxPayloadSize: 4);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1560);

        $parser->extract();
    }

    /**
     * Throws ParseError when xml box payload exceeds the maximum payload size.
     */
    #[Test]
    public function throwsForOversizedXmlPayload(): void
    {
        $xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/" />';

        $jxl = self::JXL_SIGNATURE
            . $this->box('xml ', $xmp);

        $stream = $this->streamFromString($jxl);
        $parser = new JxlParser($stream, maxPayloadSize: 4);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1561);

        $parser->extract();
    }

    /**
     * Enforces an aggregate metadata box count limit during JXL metadata extraction.
     */
    #[Test]
    public function throwsWhenMetadataBoxCountExceedsAggregateLimit(): void
    {
        $xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/" />';

        $jxl = self::JXL_SIGNATURE
            . $this->box('xml ', $xmp)
            . $this->box('xml ', $xmp)
            . $this->box('xml ', $xmp);

        $stream = $this->streamFromString($jxl);
        $parser = new JxlParser($stream, maxMetadataBoxCount: 2);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2084);

        $parser->extract();
    }

    /**
     * Accepts metadata when the aggregate metadata payload size equals the configured limit.
     */
    #[Test]
    public function acceptsMetadataWhenAggregateSizeMatchesLimit(): void
    {
        $xmp      = '<x:xmpmeta xmlns:x="adobe:ns:meta/" />';
        $tiff     = self::TIFF_LE_HEADER;
        $exifBlob = pack('N', 0) . $tiff;

        $jxl = self::JXL_SIGNATURE
            . $this->box('Exif', $exifBlob)
            . $this->box('xml ', $xmp);

        $aggregateLimit = strlen($exifBlob) + strlen($xmp);

        $stream = $this->streamFromString($jxl);
        $parser = new JxlParser($stream, maxTotalMetadataBytes: $aggregateLimit);

        [$exifBlobs, $xmpBlobs] = $parser->extract();

        self::assertCount(1, $exifBlobs);
        self::assertSame($tiff, $exifBlobs[0]);
        self::assertCount(1, $xmpBlobs);
        self::assertSame($xmp, $xmpBlobs[0]);
    }

    /**
     * Ignores unknown box types without raising errors.
     */
    #[Test]
    public function ignoresUnknownBoxTypes(): void
    {
        $jxl = self::JXL_SIGNATURE
            . $this->box('ftyp', 'jxl ' . pack('N', 0))
            . $this->box('jxlc', 'codestream-data')
            . $this->box('jxlp', 'partial-codestream')
            . $this->box('jbrd', 'brotli-data');

        [$exifBlobs, $xmpBlobs] = $this->extractFromJxl($jxl);

        self::assertSame([], $exifBlobs);
        self::assertSame([], $xmpBlobs);
    }

    /**
     * Handles a bare JXL signature box without any subsequent boxes.
     */
    #[Test]
    public function handlesBareSignatureOnly(): void
    {
        [$exifBlobs, $xmpBlobs] = $this->extractFromJxl(self::JXL_SIGNATURE);

        self::assertSame([], $exifBlobs);
        self::assertSame([], $xmpBlobs);
    }

    /**
     * Extracts the gain map blob from a JXL container with a top-level hrgm box.
     */
    #[Test]
    public function extractsHrgmFromTopLevelBox(): void
    {
        $gainMapPayload = 'gain-map-image-data';

        $jxl = self::JXL_SIGNATURE
            . $this->box('ftyp', 'jxl ' . pack('N', 0))
            . $this->box('hrgm', $gainMapPayload)
            . $this->box('jxlc', 'codestream-data');

        [, , $hrgmBlob] = $this->extractFromJxl($jxl);

        self::assertSame($gainMapPayload, $hrgmBlob);
    }

    /**
     * Returns null gain map blob when no hrgm box is present in the container.
     */
    #[Test]
    public function returnsNullHrgmWhenAbsent(): void
    {
        $jxl = self::JXL_SIGNATURE
            . $this->box('ftyp', 'jxl ' . pack('N', 0))
            . $this->box('jxlc', 'codestream-data');

        [, , $hrgmBlob] = $this->extractFromJxl($jxl);

        self::assertNull($hrgmBlob);
    }

    /**
     * Throws ParseError when hrgm box payload exceeds the maximum payload size.
     */
    #[Test]
    public function throwsForOversizedHrgmPayload(): void
    {
        $jxl = self::JXL_SIGNATURE
            . $this->box('hrgm', 'large-gain-map-data');

        $stream = $this->streamFromString($jxl);
        $parser = new JxlParser($stream, maxPayloadSize: 4);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2085);

        $parser->extract();
    }

    /**
     * Counts the hrgm box toward the aggregate metadata box count limit.
     */
    #[Test]
    public function countsHrgmBoxInAggregateLimit(): void
    {
        $xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/" />';

        $jxl = self::JXL_SIGNATURE
            . $this->box('xml ', $xmp)
            . $this->box('hrgm', 'gain-map')
            . $this->box('xml ', $xmp);

        $stream = $this->streamFromString($jxl);
        $parser = new JxlParser($stream, maxMetadataBoxCount: 2);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2084);

        $parser->extract();
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: ?string}
     */
    private function extractFromJxl(string $jxl): array
    {
        $stream = $this->streamFromString($jxl);

        return (new JxlParser($stream))->extract();
    }

    private function streamFromString(string $data): Stream
    {
        $fh = fopen('php://memory', 'r+b');
        assert(is_resource($fh));
        fwrite($fh, $data);
        rewind($fh);

        return new Stream($fh, strlen($data));
    }
}
