<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlistMarkerEnumsTest extends TestCase
{
    #[Test]
    public function plistMarkerTypeEnumExistsWithExpectedCases(): void
    {
        $class = 'MagicSunday\\ImageMeta\\MakerNotes\\Apple\\PlistMarkerType';

        self::assertTrue(enum_exists($class));

        if (!enum_exists($class)) {
            return;
        }

        self::assertSame(0, $class::Simple->value);
        self::assertSame(1, $class::Integer->value);
        self::assertSame(4, $class::Data->value);
        self::assertSame(13, $class::Dictionary->value);
    }

    #[Test]
    public function plistSimpleMarkerEnumExistsWithExpectedCases(): void
    {
        $class = 'MagicSunday\\ImageMeta\\MakerNotes\\Apple\\PlistSimpleMarker';

        self::assertTrue(enum_exists($class));

        if (!enum_exists($class)) {
            return;
        }

        self::assertSame(0, $class::Null->value);
        self::assertSame(8, $class::False->value);
        self::assertSame(9, $class::True->value);
        self::assertSame(15, $class::Fill->value);
    }
}

