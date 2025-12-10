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
use MagicSunday\ImageMeta\Value\SourceExposureTimes;
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
            sourceExposureTimes: null,
        );

        self::assertSame(CompositeImage::GENERAL_COMPOSITE, $info->type);
    }

    #[Test]
    public function constructsWithSourceCounts(): void
    {
        $info = new CompositeImageInfo(
            type: CompositeImage::CAPTURED_WHILE_SHOOTING,
            counts: [5, 3],
            sourceExposureTimes: null,
        );

        self::assertSame([5, 3], $info->counts);
    }

    #[Test]
    public function constructsWithExposureTimes(): void
    {
        $exposures = new SourceExposureTimes(
            totalExposurePeriod: 2.5,
            usedExposureTimeSum: 2.0,
            allExposureTimeSum: 2.5,
            sourceImageCount: 3.0,
            maxUsedExposureTime: 1.6,
            minUsedExposureTime: 0.4,
            longestSourceExposureTime: 1.6,
            shortestSourceExposureTime: 0.2,
            sequences: [[0.2, 0.8, 1.5]],
        );

        $info = new CompositeImageInfo(
            type: CompositeImage::GENERAL_COMPOSITE,
            counts: [3, 3],
            sourceExposureTimes: $exposures,
        );

        self::assertSame($exposures, $info->sourceExposureTimes);
        self::assertSame([3, 3], $info->counts);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $info = new CompositeImageInfo(
            type: null,
            counts: null,
            sourceExposureTimes: null,
        );

        self::assertNull($info->type);
        self::assertNull($info->counts);
        self::assertNull($info->sourceExposureTimes);
    }
}
