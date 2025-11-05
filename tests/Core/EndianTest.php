<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\Endian;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Endian enum representing byte ordering.
 */
#[CoversClass(Endian::class)]
final class EndianTest extends TestCase
{
    #[Test]
    public function littleEndianHasCorrectValue(): void
    {
        $value = Endian::Little->value;
        
        self::assertIsString($value);
        self::assertSame(2, strlen($value));
        self::assertNotSame(Endian::Big->value, $value);
    }

    #[Test]
    public function bigEndianHasCorrectValue(): void
    {
        $value = Endian::Big->value;
        
        self::assertIsString($value);
        self::assertSame(2, strlen($value));
        self::assertNotSame(Endian::Little->value, $value);
    }

    #[Test]
    public function canConstructFromValue(): void
    {
        $little = Endian::from('II');
        $big = Endian::from('MM');
        
        self::assertNotSame($little, $big);
        self::assertSame(Endian::Little, $little);
        self::assertSame(Endian::Big, $big);
    }
}
