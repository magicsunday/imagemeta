<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\CompositeImageInfo;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CompositeImageInfo value object.
 */
#[CoversClass(CompositeImageInfo::class)]
final class CompositeImageInfoTest extends TestCase
{
    #[Test]
    public function constructsWithCompositeType(): void
    {
        $info = new CompositeImageInfo(
            type: CompositeImage::GENERAL_COMPOSITE,
            counts: null,
            exposureTimesTotal: null,
        );

        self::assertSame(CompositeImage::GENERAL_COMPOSITE, $info->type);
    }

    #[Test]
    public function constructsWithSourceCounts(): void
    {
        $info = new CompositeImageInfo(
            type: CompositeImage::CAPTURED_WHILE_SHOOTING,
            counts: [5, 3],
            exposureTimesTotal: null,
        );

        self::assertSame([5, 3], $info->counts);
    }

    #[Test]
    public function constructsWithExposureTimes(): void
    {
        $info = new CompositeImageInfo(
            type: CompositeImage::GENERAL_COMPOSITE,
            counts: [3, 3],
            exposureTimesTotal: [0.01, 0.1, 1.0],
        );

        self::assertSame([0.01, 0.1, 1.0], $info->exposureTimesTotal);
        self::assertSame([3, 3], $info->counts);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $info = new CompositeImageInfo(
            type: null,
            counts: null,
            exposureTimesTotal: null,
        );

        self::assertNull($info->type);
        self::assertNull($info->counts);
        self::assertNull($info->exposureTimesTotal);
    }
}
