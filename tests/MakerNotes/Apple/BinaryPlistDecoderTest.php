<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

require_once __DIR__ . '/AppleDecoderKeyedArchiveTest.php';

use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistArray;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistDictionary;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistScalar;
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

        self::assertInstanceOf(ApplePlistDictionary::class, $result);
        $uid = $result->get('Uid');
        self::assertInstanceOf(ApplePlistScalar::class, $uid);
        self::assertSame(1, $uid->value());
    }

    #[Test]
    public function decodeReturnsStringForLargeUidValues(): void
    {
        $decoder = new BinaryPlistDecoder();
        $plist   = $this->createBinaryPlistWithUid(chr(0x80) . str_repeat("\x00", 7));

        $result = $decoder->decode($plist);

        self::assertInstanceOf(ApplePlistDictionary::class, $result);
        $uid = $result->get('Uid');
        self::assertInstanceOf(ApplePlistScalar::class, $uid);
        self::assertSame('9223372036854775808', $uid->value());
    }

    #[Test]
    public function decodeParsesNestedCollections(): void
    {
        $encoder = new BinaryPlistEncoder();

        $plist = $encoder->encode(
            new BinaryPlistDictionaryValue([
                'Flag'    => new BinaryPlistBoolValue(true),
                'Name'    => new BinaryPlistStringValue('Alpha'),
                'Numbers' => new BinaryPlistArrayValue([
                    new BinaryPlistIntValue(1),
                    new BinaryPlistFloatValue(2.5),
                ]),
                'Nested'  => new BinaryPlistDictionaryValue([
                    'Inner' => new BinaryPlistStringValue('Value'),
                ]),
            ])
        );

        $decoder = new BinaryPlistDecoder();
        $result  = $decoder->decode($plist);

        self::assertInstanceOf(ApplePlistDictionary::class, $result);

        $flag = $result->get('Flag');
        self::assertInstanceOf(ApplePlistScalar::class, $flag);
        self::assertTrue($flag->isBool());
        self::assertTrue($flag->value());

        $name = $result->get('Name');
        self::assertInstanceOf(ApplePlistScalar::class, $name);
        self::assertSame('Alpha', $name->value());

        $numbers = $result->get('Numbers');
        self::assertInstanceOf(ApplePlistArray::class, $numbers);
        self::assertSame(2, $numbers->count());

        $firstNumber = $numbers->get(0);
        self::assertInstanceOf(ApplePlistScalar::class, $firstNumber);
        self::assertTrue($firstNumber->isInt());
        self::assertSame(1, $firstNumber->value());

        $secondNumber = $numbers->get(1);
        self::assertInstanceOf(ApplePlistScalar::class, $secondNumber);
        self::assertTrue($secondNumber->isFloat());
        self::assertEqualsWithDelta(2.5, $secondNumber->asFloat(), 0.000001);

        $nested = $result->get('Nested');
        self::assertInstanceOf(ApplePlistDictionary::class, $nested);
        $inner = $nested->get('Inner');
        self::assertInstanceOf(ApplePlistScalar::class, $inner);
        self::assertSame('Value', $inner->value());
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
