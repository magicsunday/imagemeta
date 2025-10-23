<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;
use function fopen;
use function fwrite;
use function hex2bin;
use function pack;
use function rewind;
use function strlen;
use function substr;

/**
 * Exercises the ISO BMFF extractor against synthetic container layouts.
 *
 * @covers \MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffExtractor
 */
final class IsoBmffExtractorTest extends TestCase
{
    /**
     * Ensures EXIF blobs embedded directly in the meta box are returned.
     */
    #[Test]
    public function testExtractExifFromExifBox(): void
    {
        $exifPayload = "Exif\0\0primary-exif";
        $meta        = self::fullBox('meta', self::box('Exif', $exifPayload));
        $ftyp        = self::box('ftyp', 'isom');
        $data        = $ftyp . $meta;

        $extractor           = $this->createExtractor($data);
        [$exifs, $xmps, $qt] = $extractor->extract();

        self::assertSame(['primary-exif'], $exifs);
        self::assertSame([], $xmps);
        self::assertNull($qt);
    }

    /**
     * Verifies fragmented EXIF data referenced via iloc extents is reassembled.
     */
    #[Test]
    public function testResolveIlocMultiExtent(): void
    {
        $exifBlob = "Exif\0\0" . 'segment-onesegment-two';
        $part1    = substr($exifBlob, 0, 10);
        $part2    = substr($exifBlob, 10);

        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0" . 'application/octet-stream' . "\0\0";
        $infe        = self::box('infe', $infePayload);
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

            return self::box('iloc', $payload);
        };

        $iinf = self::box('iinf', $iinfPayload);

        // Build once to compute offsets
        $metaPayload = $iinf . $ilocBuilder(0, 0, strlen($part1), strlen($part2));
        $meta        = self::fullBox('meta', $metaPayload);
        $ftyp        = self::box('ftyp', 'isom');
        $mdatPayload = $part1 . $part2;
        $mdat        = self::box('mdat', $mdatPayload);

        $offsetBase = strlen($ftyp) + strlen($meta) + 8; // mdat payload offset
        $iloc       = $ilocBuilder($offsetBase, $offsetBase + strlen($part1), strlen($part1), strlen($part2));
        $meta       = self::fullBox('meta', $iinf . $iloc);
        $data       = $ftyp . $meta . $mdat;

        $extractor = $this->createExtractor($data);
        [$exifs]   = $extractor->extract();

        self::assertSame(['segment-onesegment-two'], $exifs);
    }

    /**
     * Ensures XMP payloads are collected from uuid boxes and item locations.
     */
    #[Test]
    public function testExtractXmpFromUuidAndItem(): void
    {
        $uuidGuid  = hex2bin('be7acfcb97a942e89c71999491e3afac');
        $uuidXmp   = '<x:xmpmeta xmlns:x="adobe:ns:meta/">uuid</x:xmpmeta>';
        $directXmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/">direct</x:xmpmeta>';
        $itemXmp   = '<x:xmpmeta xmlns:x="adobe:ns:meta/">item</x:xmpmeta>';

        $uuidBox = self::box('uuid', $uuidGuid . $uuidXmp);

        $infePayload = "\x02\0\0\0" . pack('n', 2) . pack('n', 0) . 'xmp' . "\0" . 'application/rdf+xml' . "\0\0";
        $infe        = self::box('infe', $infePayload);
        $iinfPayload = "\0\0\0\0" . pack('n', 1) . $infe;
        $iinf        = self::box('iinf', $iinfPayload);

        $pitmPayload = "\0\0\0\0" . pack('n', 2);
        $pitm        = self::box('pitm', $pitmPayload);

        $ilocBuilder = function (int $offset, int $length): string {
            $payload = "\0\0\0\0";
            $payload .= "\x44"; // offset/length = 4 bytes
            $payload .= "\0";
            $payload .= pack('n', 1);
            $payload .= pack('n', 2);
            $payload .= pack('n', 0);
            $payload .= pack('n', 1);
            $payload .= pack('N', $offset) . pack('N', $length);

            return self::box('iloc', $payload);
        };

        $xmpData     = $itemXmp;
        $metaPayload = $pitm . $iinf . $ilocBuilder(0, strlen($xmpData)) . self::box('XMP ', $directXmp);
        $meta        = self::fullBox('meta', $metaPayload);
        $ftyp        = self::box('ftyp', 'isom');
        $mdat        = self::box('mdat', $xmpData);

        $offsetBase = strlen($ftyp) + strlen($uuidBox) + strlen($meta) + 8;
        $iloc       = $ilocBuilder($offsetBase, strlen($xmpData));
        $meta       = self::fullBox('meta', $pitm . $iinf . $iloc . self::box('XMP ', $directXmp));
        $data       = $ftyp . $uuidBox . $meta . $mdat;

        $extractor = $this->createExtractor($data);
        [, $xmps]  = $extractor->extract();

        self::assertSame([$itemXmp, $directXmp, $uuidXmp], $xmps);
    }

    /**
     * Confirms QuickTime identifiers are populated from keys and mdta boxes.
     */
    #[Test]
    public function testReadContentIdentifierFromKeysOrMdta(): void
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
     * Ensures items referencing external data are skipped.
     */
    #[Test]
    public function testSkipDataRefIndexNotZero(): void
    {
        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0\0\0";
        $infe        = self::box('infe', $infePayload);
        $iinfPayload = "\0\0\0\0" . pack('n', 1) . $infe;
        $iinf        = self::box('iinf', $iinfPayload);

        $payload = "\0\0\0\0";
        $payload .= "\x44";
        $payload .= "\0";
        $payload .= pack('n', 1);
        $payload .= pack('n', 1);
        $payload .= pack('n', 1); // data_reference_index = 1
        $payload .= pack('n', 1);
        $payload .= pack('N', 0) . pack('N', 4);
        $iloc = self::box('iloc', $payload);

        $meta = self::fullBox('meta', $iinf . $iloc);
        $ftyp = self::box('ftyp', 'isom');
        $mdat = self::box('mdat', 'Exif');
        $data = $ftyp . $meta . $mdat;

        $extractor = $this->createExtractor($data);
        [$exifs]   = $extractor->extract();

        self::assertSame([], $exifs);
    }

    /**
     * Verifies invalid extent definitions trigger a parse error.
     */
    #[Test]
    public function testInvalidBoxSizesThrowParseError(): void
    {
        $infePayload = "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "\0\0\0";
        $iinf        = self::box('iinf', "\0\0\0\0" . pack('n', 1) . self::box('infe', $infePayload));

        $ilocPayload = "\0\0\0\0";
        $ilocPayload .= "\x44";
        $ilocPayload .= "\0";
        $ilocPayload .= pack('n', 1);
        $ilocPayload .= pack('n', 1);
        $ilocPayload .= pack('n', 0);
        $ilocPayload .= pack('n', 1);
        $ilocPayload .= pack('N', 0x7FFF0000) . pack('N', 8);
        $iloc = self::box('iloc', $ilocPayload);

        $meta = self::fullBox('meta', $iinf . $iloc);
        $data = self::box('ftyp', 'isom') . $meta;

        $extractor = $this->createExtractor($data);

        try {
            $extractor->extract();
            self::fail('Expected ParseError for invalid iloc extent');
        } catch (ParseError $exception) {
            self::assertStringContainsString('iloc', $exception->getMessage());
        }
    }

    /**
     * Builds a QuickTime structure containing a `keys` metadata entry.
     *
     * @param string $value Identifier value stored under the QuickTime key.
     */
    private function createFileWithQuickTimeKeys(string $value): string
    {
        $keysEntry = pack('N', 8 + strlen('com.apple.quicktime.content.identifier'))
            . 'mdta'
            . 'com.apple.quicktime.content.identifier';
        $keys = self::box('keys', "\0\0\0\0" . pack('N', 1) . $keysEntry);

        $dataBox   = self::box('data', pack('N', 1) . pack('N', 0) . $value);
        $ilstEntry = self::box(pack('N', 1), $dataBox);
        $ilst      = self::box('ilst', $ilstEntry);

        $metaPayload = "\0\0\0\0" . $keys . $ilst;
        $meta        = self::box('meta', $metaPayload);
        $moov        = self::box('moov', self::box('udta', $meta));

        return self::box('ftyp', 'isom') . $moov;
    }

    /**
     * Builds a QuickTime structure containing an mdta free-form identifier.
     *
     * @param string $value Identifier value encoded within the mdta structure.
     */
    private function createFileWithMdtaIdentifier(string $value): string
    {
        $mean     = self::box('mean', pack('N', 1) . pack('N', 0) . 'com.apple.quicktime');
        $name     = self::box('name', pack('N', 1) . pack('N', 0) . 'content.identifier');
        $data     = self::box('data', pack('N', 1) . pack('N', 0) . $value);
        $freeform = self::box('----', $mean . $name . $data);
        $ilst     = self::box('ilst', $freeform);

        $metaPayload = "\0\0\0\0" . $ilst;
        $meta        = self::box('meta', $metaPayload);
        $moov        = self::box('moov', $meta);

        return self::box('ftyp', 'isom') . $moov;
    }

    /**
     * Wraps raw bytes in a temporary stream-backed extractor.
     *
     * @param string $data Raw ISO BMFF file contents.
     */
    private function createExtractor(string $data): IsoBmffExtractor
    {
        $fh = fopen('php://temp', 'wb+');
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
    private static function box(string $type, string $payload): string
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
    private static function fullBox(string $type, string $payload, int $version = 0, int $flags = 0): string
    {
        $header = chr($version) . chr(($flags >> 16) & 0xFF) . chr(($flags >> 8) & 0xFF) . chr($flags & 0xFF);

        return self::box($type, $header . $payload);
    }
}
