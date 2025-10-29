<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core\BitMask;

use MagicSunday\ImageMeta\Core\BitMask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BitMask::class)]
final class BitMaskTest extends TestCase
{
    /**
     * @return iterable<string, array{constant: string, hex: string}>
     */
    public static function bitMaskValueProvider(): iterable
    {
        yield 'bit 0' => ['constant' => 'BIT_0', 'hex' => '01'];
        yield 'bit 1' => ['constant' => 'BIT_1', 'hex' => '02'];
        yield 'bit 2' => ['constant' => 'BIT_2', 'hex' => '04'];
        yield 'bit 3' => ['constant' => 'BIT_3', 'hex' => '08'];
        yield 'bit 4' => ['constant' => 'BIT_4', 'hex' => '10'];
        yield 'bit 5' => ['constant' => 'BIT_5', 'hex' => '20'];
        yield 'bit 6' => ['constant' => 'BIT_6', 'hex' => '40'];
        yield 'bit 7' => ['constant' => 'BIT_7', 'hex' => '80'];
        yield 'low nibble' => ['constant' => 'LOW_NIBBLE', 'hex' => '0F'];
        yield 'high nibble' => ['constant' => 'HIGH_NIBBLE', 'hex' => 'F0'];
        yield 'low byte' => ['constant' => 'LOW_BYTE', 'hex' => 'FF'];
        yield 'high byte' => ['constant' => 'HIGH_BYTE', 'hex' => 'FF00'];
        yield 'six bit mask' => ['constant' => 'SIX_BIT_MASK', 'hex' => '3F'];
        yield 'seven bit mask' => ['constant' => 'SEVEN_BIT_MASK', 'hex' => '7F'];
        yield 'int31 max' => ['constant' => 'INT31_MAX', 'hex' => '7FFF_FFFF'];
        yield 'sign bit 16' => ['constant' => 'SIGN_BIT_16', 'hex' => '8000'];
        yield 'sign bit 32' => ['constant' => 'SIGN_BIT_32', 'hex' => '8000_0000'];
        yield 'uint16 max' => ['constant' => 'UINT16_MAX', 'hex' => 'FFFF'];
        yield 'uint16 base' => ['constant' => 'UINT16_BASE', 'hex' => '1_0000'];
        yield 'uint32 max' => ['constant' => 'UINT32_MAX', 'hex' => 'FFFF_FFFF'];
        yield 'uint32 base' => ['constant' => 'UINT32_BASE', 'hex' => '1_0000_0000'];
    }

    #[DataProvider('bitMaskValueProvider')]
    public function testBitMaskConstantsMatchExpectedHexValues(string $constant, string $hex): void
    {
        self::assertSame(
            self::fromHex($hex),
            constant(BitMask::class . '::' . $constant)
        );
    }

    public function testLowNibbleContainsFourLowestBits(): void
    {
        $expected = BitMask::BIT_0
            | BitMask::BIT_1
            | BitMask::BIT_2
            | BitMask::BIT_3;

        self::assertSame($expected, BitMask::LOW_NIBBLE);
    }

    public function testHighNibbleContainsFourHighestBitsOfByte(): void
    {
        $expected = BitMask::BIT_4
            | BitMask::BIT_5
            | BitMask::BIT_6
            | BitMask::BIT_7;

        self::assertSame($expected, BitMask::HIGH_NIBBLE);
    }

    public function testLowByteCombinesLowAndHighNibble(): void
    {
        self::assertSame(BitMask::LOW_NIBBLE | BitMask::HIGH_NIBBLE, BitMask::LOW_BYTE);
    }

    public function testHighByteIsLowByteShiftedByEightBits(): void
    {
        self::assertSame(BitMask::LOW_BYTE << 8, BitMask::HIGH_BYTE);
    }

    public function testSixBitMaskCoversLowerSixBits(): void
    {
        $expected = BitMask::BIT_0
            | BitMask::BIT_1
            | BitMask::BIT_2
            | BitMask::BIT_3
            | BitMask::BIT_4
            | BitMask::BIT_5;

        self::assertSame($expected, BitMask::SIX_BIT_MASK);
    }

    public function testSevenBitMaskCoversLowerSevenBits(): void
    {
        $expected = BitMask::SIX_BIT_MASK | BitMask::BIT_6;

        self::assertSame($expected, BitMask::SEVEN_BIT_MASK);
    }

    public function testUint16BaseIsOneMoreThanMax(): void
    {
        self::assertSame(BitMask::UINT16_MAX + 1, BitMask::UINT16_BASE);
    }

    public function testUint32BaseIsOneMoreThanMax(): void
    {
        self::assertSame(BitMask::UINT32_MAX + 1, BitMask::UINT32_BASE);
    }

    public function testSignBit16EqualsHalfOfUint16Base(): void
    {
        self::assertSame(BitMask::UINT16_BASE >> 1, BitMask::SIGN_BIT_16);
    }

    public function testSignBit32EqualsHalfOfUint32Base(): void
    {
        self::assertSame(BitMask::UINT32_BASE >> 1, BitMask::SIGN_BIT_32);
    }

    public function testInt31MaxIsSignBit32MinusOne(): void
    {
        self::assertSame(BitMask::SIGN_BIT_32 - 1, BitMask::INT31_MAX);
    }

    private static function fromHex(string $hex): int
    {
        return (int) hexdec(str_replace(['_', '0x', '0X'], '', $hex));
    }
}
