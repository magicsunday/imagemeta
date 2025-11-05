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
    public function fromReturnsLittleEndianForII(): void
    {
        $result = Endian::from('II');
        
        self::assertSame('II', $result->value);
        self::assertSame('Little', $result->name);
    }

    #[Test]
    public function fromReturnsBigEndianForMM(): void
    {
        $result = Endian::from('MM');
        
        self::assertSame('MM', $result->value);
        self::assertSame('Big', $result->name);
    }

    #[Test]
    public function fromThrowsValueErrorForInvalidValue(): void
    {
        $this->expectException(\ValueError::class);
        
        Endian::from('XX');
    }

    #[Test]
    public function tryFromReturnsLittleEndianForII(): void
    {
        $result = Endian::tryFrom('II');
        
        self::assertInstanceOf(Endian::class, $result);
        self::assertSame('II', $result->value);
    }

    #[Test]
    public function tryFromReturnsBigEndianForMM(): void
    {
        $result = Endian::tryFrom('MM');
        
        self::assertInstanceOf(Endian::class, $result);
        self::assertSame('MM', $result->value);
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        $result = Endian::tryFrom('XX');
        
        self::assertNull($result);
    }

    #[Test]
    public function casesReturnsAllEnumCases(): void
    {
        $cases = Endian::cases();
        
        self::assertCount(2, $cases);
        self::assertContains(Endian::Little, $cases);
        self::assertContains(Endian::Big, $cases);
    }
}
