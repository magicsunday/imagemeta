<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Jpeg;

require_once __DIR__ . '/../../src/Core/Exceptions.php';

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JpegExtractorTest extends TestCase
{
    private const XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";
    private const EXIF_SIGNATURE = "Exif\0\0";

    #[DataProvider('provideApp1Orders')]
    public function testExtractsXmpRegardlessOfApp1Order(array $order, string $expectedXml): void
    {
        $segments = [];
        foreach ($order as $type) {
            if ($type === 'exif') {
                $segments[] = $this->segment(0xE1, self::EXIF_SIGNATURE . 'dummy-exif');
            } elseif ($type === 'xmp') {
                $segments[] = $this->segment(0xE1, self::XMP_SIGNATURE . $expectedXml);
            }
        }

        $jpeg = $this->jpeg(...$segments);
        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$expectedXml], $extractor->extractXmpPackets());
    }

    /** @return iterable<string, array{0: list<'exif'|'xmp'>, 1: string}> */
    public static function provideApp1Orders(): iterable
    {
        $xmpXml = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Test</x:xmpmeta>';

        yield 'exif-before-xmp' => [['exif', 'xmp'], $xmpXml];
        yield 'xmp-before-exif' => [['xmp', 'exif'], $xmpXml];
    }

    public function testSkipsRestartMarkersAndStuffedBytes(): void
    {
        $xmpXml = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Restart</x:xmpmeta>';

        $jpeg = $this->jpeg(
            $this->segment(0xE1, self::EXIF_SIGNATURE . 'preface'),
            $this->segment(0xE1, self::XMP_SIGNATURE . $xmpXml),
            "\xFF\xDA" . pack('n', 8) . "\x01\x02\x00\x00\x3F\x00", // SOS marker with header
            "\xFF\x00\xAA\xFF\xD0\xBB", // stuffed byte followed by restart marker
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
    }

    public function testSupportsLargeExifSegment(): void
    {
        $xmpXml = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Large</x:xmpmeta>';
        $largeExifPayload = self::EXIF_SIGNATURE . str_repeat('A', 70_000);

        $jpeg = $this->jpeg(
            $this->segment(0xE1, $largeExifPayload),
            $this->segment(0xE1, self::XMP_SIGNATURE . $xmpXml),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
    }

    public function testExtractsRawIccAndIptcPayloads(): void
    {
        $xmpXml = '<x:xmpmeta xmlns:x="adobe:ns:meta/">Ancillary</x:xmpmeta>';
        $iccPayload = "ICC_PROFILE\0\1\1" . 'icc-data';
        $iptcPayload = 'Photoshop 3.0\0' . 'iptc-data';

        $jpeg = $this->jpeg(
            $this->segment(0xE2, $iccPayload),
            $this->segment(0xED, $iptcPayload),
            $this->segment(0xE1, self::XMP_SIGNATURE . $xmpXml),
        );

        $extractor = $this->createExtractor($jpeg);

        self::assertTrue(method_exists($extractor, 'getIccPayloads'), 'Expected getIccPayloads() accessor');
        self::assertTrue(method_exists($extractor, 'getIptcPayloads'), 'Expected getIptcPayloads() accessor');

        if (method_exists($extractor, 'getIccPayloads')) {
            self::assertSame([$iccPayload], $extractor->getIccPayloads(), 'ICC payloads should be returned verbatim');
        }

        if (method_exists($extractor, 'getIptcPayloads')) {
            self::assertSame([$iptcPayload], $extractor->getIptcPayloads(), 'IPTC payloads should be returned verbatim');
        }

        self::assertSame([$xmpXml], $extractor->extractXmpPackets());
    }

    public function testInvalidSegmentLengthThrowsParseError(): void
    {
        $invalidSegment = "\xFF\xE1\x00\x01"; // length field smaller than minimum
        $jpeg = $this->jpeg($invalidSegment);

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/length/i');
        $extractor->extractXmpPackets();
    }

    public function testUnexpectedEndOfImageTriggersParseError(): void
    {
        $declaredLength = 10;
        $payload = 'trunc'; // only 5 bytes available instead of 8 (=len-2)
        $segment = "\xFF\xE1" . pack('n', $declaredLength) . $payload;
        $jpeg = "\xFF\xD8" . $segment; // omit EOI entirely

        $extractor = $this->createExtractor($jpeg);

        $this->expectException(ParseError::class);
        $extractor->extractXmpPackets();
    }

    private function jpeg(string ...$segments): string
    {
        return "\xFF\xD8" . implode('', $segments) . "\xFF\xD9";
    }

    private function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    private function createExtractor(string $jpeg): JpegExtractor
    {
        $fh = fopen('php://temp', 'wb+');
        fwrite($fh, $jpeg);
        rewind($fh);

        return new JpegExtractor(new Stream($fh, strlen($jpeg)));
    }
}
