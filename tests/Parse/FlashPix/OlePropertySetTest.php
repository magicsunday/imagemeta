<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\FlashPix;

use MagicSunday\ImageMeta\Parse\FlashPix\OlePropertySet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies OlePropertySet stores codepage and property values correctly.
 */
#[CoversClass(OlePropertySet::class)]
final class OlePropertySetTest extends TestCase
{
    #[Test]
    public function constructorStoresCodepageAndProperties(): void
    {
        $set = new OlePropertySet(1252, [1 => 1252, 2 => 'Test Title']);

        self::assertSame(1252, $set->codepage);
        self::assertSame('Test Title', $set->property(2));
        self::assertNull($set->property(999));
    }

    #[Test]
    public function allReturnsCompletePropertyMap(): void
    {
        $properties = [1 => 1252, 2 => 'Title', 4 => 'Author'];
        $set        = new OlePropertySet(1252, $properties);

        self::assertSame($properties, $set->all());
    }

    #[Test]
    public function propertyReturnsNullForEmptySet(): void
    {
        $set = new OlePropertySet(1200, []);

        self::assertNull($set->property(1));
    }
}
