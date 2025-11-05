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
            version: '0100',
        );

        self::assertSame('R98', $interop->index);
        self::assertSame('0100', $interop->version);
    }

    #[Test]
    public function constructsWithRelatedImageInfo(): void
    {
        $interop = new Interop(
            index: 'R98',
            version: '0100',
            relatedImageFileFormat: 'JPEG',
            relatedImageWidth: 1920,
            relatedImageLength: 1080,
        );

        self::assertSame('JPEG', $interop->relatedImageFileFormat);
        self::assertSame(1920, $interop->relatedImageWidth);
        self::assertSame(1080, $interop->relatedImageLength);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $interop = new Interop(
            index: null,
            version: null,
        );

        self::assertNull($interop->index);
        self::assertNull($interop->version);
        self::assertNull($interop->relatedImageFileFormat);
        self::assertNull($interop->relatedImageWidth);
        self::assertNull($interop->relatedImageLength);
    }

    #[Test]
    public function usesDefaultNullForOptionalParameters(): void
    {
        $interop = new Interop(
            index: 'R98',
            version: '0100',
        );

        self::assertNull($interop->relatedImageFileFormat);
        self::assertNull($interop->relatedImageWidth);
        self::assertNull($interop->relatedImageLength);
    }
}
