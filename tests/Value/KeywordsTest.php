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
 * Exercises the Keywords value object for flat and hierarchical keyword sets.
 * It verifies flat keyword arrays and optional hierarchical lists are preserved.
 * The suite covers both single-mode and combined keyword storage.
 * This ensures keyword metadata stays structured for search and display.
 */
#[CoversClass(Keywords::class)]
final class KeywordsTest extends TestCase
{
    /**
     * Stores flat keyword lists.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
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

    /**
     * Stores flat and hierarchical keyword lists together.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
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

    /**
     * Accepts empty keyword lists.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
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
