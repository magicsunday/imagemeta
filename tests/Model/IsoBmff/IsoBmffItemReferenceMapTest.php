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

#[CoversClass(IsoBmffItemReference::class)]
#[CoversClass(IsoBmffItemReferenceMap::class)]
final class IsoBmffItemReferenceMapTest extends TestCase
{
    #[Test]
    public function mapProvidesReferencesBySourceId(): void
    {
        $reference = new IsoBmffItemReference('cdsc', 12);
        $map       = new IsoBmffItemReferenceMap([5 => [$reference]]);

        self::assertSame([5], $map->fromItemIds());
        self::assertSame([$reference], $map->referencesFor(5));
        self::assertSame([], $map->referencesFor(9));
        self::assertFalse($map->isEmpty());
    }

    #[Test]
    public function emptyMapReportsNoReferences(): void
    {
        $map = new IsoBmffItemReferenceMap([]);

        self::assertSame([], $map->fromItemIds());
        self::assertTrue($map->isEmpty());
    }
}
