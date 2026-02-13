<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\IsoBmff;

use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises IsoBmffItemReference and IsoBmffItemReferenceMap lookup behavior.
 * It verifies references are grouped by source item id and returned in order.
 * The suite checks empty maps report no references and isEmpty() returns true.
 * This ensures item-reference metadata remains predictable for ISO BMFF parsing.
 *
 * @internal
 */
#[CoversClass(IsoBmffItemReference::class)]
#[CoversClass(IsoBmffItemReferenceMap::class)]
final class IsoBmffItemReferenceMapTest extends TestCase
{
    /**
     * Exposes reference sources and lookups by item id.
     * It validates the transformation using representative inputs.
     *
     * @return void
     */
    #[Test]
    public function mapProvidesReferencesBySourceId(): void
    {
        $reference = new IsoBmffItemReference('cdsc', 12);
        $map       = new IsoBmffItemReferenceMap([100 => [5 => [$reference]]]);

        self::assertSame([5], $map->fromItemIds());
        self::assertSame([100], $map->contextOffsets());
        self::assertSame([$reference], $map->referencesForContext(100, 5));
        self::assertSame([$reference], $map->referencesFor(5));
        self::assertSame([], $map->referencesFor(9));
        self::assertFalse($map->isEmpty());
    }

    /**
     * Keeps overlapping source item identifiers separate across metadata contexts.
     *
     * @return void
     */
    #[Test]
    public function mapKeepsOverlappingSourceIdsScopedByContext(): void
    {
        $firstReference  = new IsoBmffItemReference('dimg', 2);
        $secondReference = new IsoBmffItemReference('thmb', 3);

        $map = new IsoBmffItemReferenceMap([
            32 => [1 => [$firstReference]],
            96 => [1 => [$secondReference]],
        ]);

        self::assertSame([1], $map->fromItemIds());
        self::assertSame([32, 96], $map->contextOffsets());
        self::assertSame([$firstReference], $map->referencesForContext(32, 1));
        self::assertSame([$secondReference], $map->referencesForContext(96, 1));
        self::assertSame([], $map->referencesFor(1));
    }

    /**
     * Reports empty reference maps correctly.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function emptyMapReportsNoReferences(): void
    {
        $map = new IsoBmffItemReferenceMap([]);

        self::assertSame([], $map->fromItemIds());
        self::assertTrue($map->isEmpty());
    }
}
