<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Keywords;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Keywords value object.
 */
#[CoversClass(Keywords::class)]
final class KeywordsTest extends TestCase
{
    #[Test]
    public function constructsWithFlatKeywords(): void
    {
        $keywords = new Keywords(
            flat: ['landscape', 'mountain', 'nature'],
            hierarchical: null,
        );

        self::assertSame(['landscape', 'mountain', 'nature'], $keywords->flat);
        self::assertNull($keywords->hierarchical);
    }

    #[Test]
    public function constructsWithHierarchicalKeywords(): void
    {
        $keywords = new Keywords(
            flat: ['landscape', 'mountain'],
            hierarchical: ['Nature|Landscape|Mountain', 'Places|Europe|Alps'],
        );

        self::assertSame(['landscape', 'mountain'], $keywords->flat);
        self::assertSame(['Nature|Landscape|Mountain', 'Places|Europe|Alps'], $keywords->hierarchical);
    }

    #[Test]
    public function handlesEmptyKeywordList(): void
    {
        $keywords = new Keywords(
            flat: [],
            hierarchical: [],
        );

        self::assertSame([], $keywords->flat);
        self::assertSame([], $keywords->hierarchical);
    }
}
