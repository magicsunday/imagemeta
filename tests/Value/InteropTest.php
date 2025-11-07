<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Interop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Interop value object.
 */
#[CoversClass(Interop::class)]
final class InteropTest extends TestCase
{
    #[Test]
    public function constructsWithInteropIndex(): void
    {
        $interop = new Interop(
            index: 'R98',
        );

        self::assertSame('R98', $interop->index);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $interop = new Interop(
            index: null,
        );

        self::assertNull($interop->index);
    }
}
