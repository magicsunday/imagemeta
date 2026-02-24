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
 * Exercises the Interop value object for interoperability index metadata.
 * It verifies the index string is stored and exposed as provided.
 * The suite confirms null values are accepted for missing metadata.
 * This keeps interop tag handling predictable in structured output.
 */
#[CoversClass(Interop::class)]
final class InteropTest extends TestCase
{
    /**
     * Stores the interoperability index value.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithInteropIndex(): void
    {
        $interop = new Interop(
            index: 'R98',
        );

        self::assertSame('R98', $interop->index);
    }

    /**
     * Accepts a null interoperability index.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $interop = new Interop(
            index: null,
        );

        self::assertNull($interop->index);
    }
}
