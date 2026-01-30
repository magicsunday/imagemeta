<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\BitMask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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

    /**
     * Verifies that BitMask::{$constant} equals $this->fromHex($hex).
     *
     * @return void
     */
    #[DataProvider('bitMaskValueProvider')]
    #[Test]
    public function bitMaskConstantsMatchExpectedHexValues(string $constant, string $hex): void
    {
        self::assertSame(
            $this->fromHex($hex),
            BitMask::{$constant}
        );
    }

    /**
     * @return iterable<string, array{expected: string, bits: non-empty-list<string>}>
     */
    public static function bitCombinationProvider(): iterable
    {
        yield 'low nibble' => [
            'expected' => 'LOW_NIBBLE',
            'bits'     => ['BIT_0', 'BIT_1', 'BIT_2', 'BIT_3'],
        ];
        yield 'high nibble' => [
            'expected' => 'HIGH_NIBBLE',
            'bits'     => ['BIT_4', 'BIT_5', 'BIT_6', 'BIT_7'],
        ];
        yield 'low byte' => [
            'expected' => 'LOW_BYTE',
            'bits'     => ['LOW_NIBBLE', 'HIGH_NIBBLE'],
        ];
        yield 'six bit mask' => [
            'expected' => 'SIX_BIT_MASK',
            'bits'     => ['BIT_0', 'BIT_1', 'BIT_2', 'BIT_3', 'BIT_4', 'BIT_5'],
        ];
        yield 'seven bit mask' => [
            'expected' => 'SEVEN_BIT_MASK',
            'bits'     => ['SIX_BIT_MASK', 'BIT_6'],
        ];
    }

    /**
     * Verifies that $this->bitMaskValue($expected) equals $mask.
     *
     * @param array<int, string> $bits
     *
     * @return void
     */
    #[DataProvider('bitCombinationProvider')]
    #[Test]
    public function bitCombinationsMatchExpectedMasks(string $expected, array $bits): void
    {
        $mask = $this->combineMasks($bits);

        self::assertSame($mask, $this->bitMaskValue($expected));
    }

    /**
     * @return iterable<string, array{source: string, shift: int, expected: string}>
     */
    public static function shiftedMaskProvider(): iterable
    {
        yield 'high byte' => [
            'source'   => 'LOW_BYTE',
            'shift'    => 8,
            'expected' => 'HIGH_BYTE',
        ];
    }

    /**
     * Verifies that $this->bitMaskValue($expected) equals $shifted.
     *
     * @return void
     */
    #[DataProvider('shiftedMaskProvider')]
    #[Test]
    public function shiftedMasksMatchExpectedValue(string $source, int $shift, string $expected): void
    {
        $shifted = $this->bitMaskValue($source) << $shift;

        self::assertSame($shifted, $this->bitMaskValue($expected));
    }

    /**
     * @return iterable<string, array{source: string, expected: string}>
     */
    public static function incrementedMaskProvider(): iterable
    {
        yield 'uint16 base' => [
            'source'   => 'UINT16_MAX',
            'expected' => 'UINT16_BASE',
        ];
        yield 'uint32 base' => [
            'source'   => 'UINT32_MAX',
            'expected' => 'UINT32_BASE',
        ];
    }

    /**
     * Verifies that $this->bitMaskValue($expected) equals $incremented.
     *
     * @return void
     */
    #[DataProvider('incrementedMaskProvider')]
    #[Test]
    public function incrementedMasksMatchExpectedValue(string $source, string $expected): void
    {
        $incremented = $this->bitMaskValue($source) + 1;

        self::assertSame($incremented, $this->bitMaskValue($expected));
    }

    /**
     * @return iterable<string, array{source: string, expected: string}>
     */
    public static function halvedMaskProvider(): iterable
    {
        yield 'uint16 sign bit' => [
            'source'   => 'UINT16_BASE',
            'expected' => 'SIGN_BIT_16',
        ];
        yield 'uint32 sign bit' => [
            'source'   => 'UINT32_BASE',
            'expected' => 'SIGN_BIT_32',
        ];
    }

    /**
     * Verifies that $this->bitMaskValue($expected) equals $halved.
     *
     * @return void
     */
    #[DataProvider('halvedMaskProvider')]
    #[Test]
    public function halvedMasksMatchExpectedValue(string $source, string $expected): void
    {
        $halved = intdiv($this->bitMaskValue($source), 2);

        self::assertSame($halved, $this->bitMaskValue($expected));
    }

    /**
     * Verifies that $this->bitMaskValue('INT31_MAX') equals $decremented.
     *
     * @return void
     */
    #[Test]
    public function int31MaxIsSignBit32MinusOne(): void
    {
        $decremented = $this->bitMaskValue('SIGN_BIT_32') - 1;

        self::assertSame($decremented, $this->bitMaskValue('INT31_MAX'));
    }

    private function fromHex(string $hex): int
    {
        return (int) hexdec(str_replace(['_', '0x', '0X'], '', $hex));
    }

    /**
     * @param array<int|string, string> $constants
     */
    private function combineMasks(array $constants): int
    {
        $mask = 0;

        foreach ($constants as $name) {
            $mask |= $this->bitMaskValue($name);
        }

        return $mask;
    }

    private function bitMaskValue(string $name): int
    {
        /** @var int $value */
        $value = BitMask::{$name};

        return $value;
    }
}
