<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Focus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Focus value object.
 */
#[CoversClass(Focus::class)]
final class FocusTest extends TestCase
{
    #[Test]
    public function constructsWithFocusDistance(): void
    {
        $focus = new Focus(
            subjectDistanceM: 2.5,
            subjectAreaX: null,
            subjectAreaY: null,
            subjectAreaW: null,
            subjectAreaH: null,
            afMode: null,
        );

        self::assertSame(2.5, $focus->subjectDistanceM);
    }

    #[Test]
    public function constructsWithSubjectArea(): void
    {
        $focus = new Focus(
            subjectDistanceM: null,
            subjectAreaX: 100,
            subjectAreaY: 200,
            subjectAreaW: 50,
            subjectAreaH: 75,
            afMode: null,
        );

        self::assertSame(100, $focus->subjectAreaX);
        self::assertSame(200, $focus->subjectAreaY);
        self::assertSame(50, $focus->subjectAreaW);
        self::assertSame(75, $focus->subjectAreaH);
    }

    #[Test]
    public function constructsWithAFMode(): void
    {
        $focus = new Focus(
            subjectDistanceM: null,
            subjectAreaX: null,
            subjectAreaY: null,
            subjectAreaW: null,
            subjectAreaH: null,
            afMode: 'Continuous',
        );

        self::assertSame('Continuous', $focus->afMode);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $focus = new Focus(
            subjectDistanceM: null,
            subjectAreaX: null,
            subjectAreaY: null,
            subjectAreaW: null,
            subjectAreaH: null,
            afMode: null,
        );

        self::assertNull($focus->subjectDistanceM);
        self::assertNull($focus->subjectAreaX);
        self::assertNull($focus->afMode);
    }
}
