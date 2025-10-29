<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\BinaryPlistDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;
use function intdiv;
use function pack;
use function str_repeat;
use function strlen;

/**
 * @covers \MagicSunday\ImageMeta\MakerNotes\Apple\BinaryPlistDecoder
 */
final class BinaryPlistDecoderTest extends TestCase
{
    #[Test]
    public function decodeRecognisesUidValues(): void
    {
        $decoder = new BinaryPlistDecoder();
        $plist   = $this->createBinaryPlistWithUid("\x01");

        $result = $decoder->decode($plist);

        self::assertSame(['Uid' => 1], $result);
    }

    #[Test]
    public function decodeReturnsStringForLargeUidValues(): void
    {
        $decoder = new BinaryPlistDecoder();
        $plist   = $this->createBinaryPlistWithUid(chr(0x80) . str_repeat("\x00", 7));

        $result = $decoder->decode($plist);

        self::assertSame(['Uid' => '9223372036854775808'], $result);
    }

    private function createBinaryPlistWithUid(string $uidBytes): string
    {
        $header = 'bplist00';

        $key       = 'Uid';
        $keyMarker = chr(0x50 | strlen($key));
        $keyObject = $keyMarker . $key;

        $uidLength = strlen($uidBytes);
        self::assertGreaterThan(0, $uidLength);

        if ($uidLength > 0x0F) {
            self::fail('Test fixture generator does not support UID payloads larger than 15 bytes.');
        }

        $uidMarker = chr(0x80 | ($uidLength - 1));
        $uidObject = $uidMarker . $uidBytes;

        $dictionaryObject = "\xD1\x00\x01";

        $offsetKey        = strlen($header);
        $offsetUid        = $offsetKey + strlen($keyObject);
        $offsetDictionary = $offsetUid + strlen($uidObject);
        $offsetTableStart = $offsetDictionary + strlen($dictionaryObject);

        $offsetTable = pack('C*', $offsetKey, $offsetUid, $offsetDictionary);

        $trailer = str_repeat("\x00", 6)
            . "\x01"
            . "\x01"
            . $this->packUint64(3)
            . $this->packUint64(2)
            . $this->packUint64($offsetTableStart);

        return $header . $keyObject . $uidObject . $dictionaryObject . $offsetTable . $trailer;
    }

    private function packUint64(int $value): string
    {
        $high = intdiv($value, 0x100000000);
        $low  = $value % 0x100000000;

        return pack('N2', $high, $low);
    }
}
