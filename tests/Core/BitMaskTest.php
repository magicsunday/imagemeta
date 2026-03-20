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

/**
 * Validates the BitMask constants against their expected hexadecimal values.
 * It composes masks from primitive bits and checks that named aliases match the combinations.
 * The suite covers derived masks created via shifting, incrementing, and halving operations.
 * It also verifies boundary constants like INT31_MAX remain consistent with sign-bit math.
 *
 * @internal
 */
#[CoversClass(BitMask::class)]
final class BitMaskTest extends TestCase
{
    /**
     * @return iterable<string, array{constant: string, hex: string}>
     */
    public static function bitMaskValueProvider(): iterable
    {
        yield 'bit 0' => ['constant' => 'BIT_0', 'hex' => '01'];
        yield 'low nibble' => ['constant' => 'LOW_NIBBLE', 'hex' => '0F'];
        yield 'int31 max' => ['constant' => 'INT31_MAX', 'hex' => '7FFF_FFFF'];
        yield 'sign bit 16' => ['constant' => 'SIGN_BIT_16', 'hex' => '8000'];
        yield 'sign bit 32' => ['constant' => 'SIGN_BIT_32', 'hex' => '8000_0000'];
        yield 'uint16 base' => ['constant' => 'UINT16_BASE', 'hex' => '1_0000'];
        yield 'uint32 max' => ['constant' => 'UINT32_MAX', 'hex' => 'FFFF_FFFF'];
        yield 'uint32 base' => ['constant' => 'UINT32_BASE', 'hex' => '1_0000_0000'];
    }

    /**
     * Confirms bitmask constants match their expected hex values.
     * This ensures the constants remain correct when refactoring bit operations.
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
     * @return iterable<string, array{source: string, expected: string}>
     */
    public static function incrementedMaskProvider(): iterable
    {
        yield 'uint32 base' => [
            'source'   => 'UINT32_MAX',
            'expected' => 'UINT32_BASE',
        ];
    }

    /**
     * Confirms incremented masks equal the expected base constants.
     * This guards against off-by-one mistakes in base and max constants.
     */
    #[DataProvider('incrementedMaskProvider')]
    #[Test]
    public function incrementedMasksMatchExpectedValue(string $source, string $expected): void
    {
        $incremented = $this->bitMaskValue($source) + 1;

        self::assertSame($this->bitMaskValue($expected), $incremented);
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
     * Confirms halved masks equal the expected sign-bit constants.
     * This verifies sign-bit constants align with their base values.
     */
    #[DataProvider('halvedMaskProvider')]
    #[Test]
    public function halvedMasksMatchExpectedValue(string $source, string $expected): void
    {
        $halved = intdiv($this->bitMaskValue($source), 2);

        self::assertSame($this->bitMaskValue($expected), $halved);
    }

    /**
     * Defines INT31_MAX as one less than the 32-bit sign bit.
     * This asserts the signed 31-bit maximum boundary is derived correctly.
     */
    #[Test]
    public function int31MaxIsSignBit32MinusOne(): void
    {
        $decremented = $this->bitMaskValue('SIGN_BIT_32') - 1;

        self::assertSame($this->bitMaskValue('INT31_MAX'), $decremented);
    }

    private function fromHex(string $hex): int
    {
        return (int) hexdec(str_replace(['_', '0x', '0X'], '', $hex));
    }

    private function bitMaskValue(string $name): int
    {
        /** @var int $value */
        $value = BitMask::{$name};

        return $value;
    }
}
