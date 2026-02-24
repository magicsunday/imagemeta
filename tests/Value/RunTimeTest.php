<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\RunTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the RunTime value object representing CoreMedia CMTime fields.
 * It verifies epoch, timescale, value, and flags are preserved.
 * The suite covers null handling for optional runtime metadata.
 * This keeps timebase metadata consistent for video-related outputs.
 */
#[CoversClass(RunTime::class)]
final class RunTimeTest extends TestCase
{
    /**
     * Stores CoreMedia time values and flags.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithTimescaleAndValue(): void
    {
        $runTime = new RunTime(
            epoch: 0,
            timescale: 1000,
            value: 5000,
            flags: 1,
        );

        self::assertSame(0, $runTime->epoch);
        self::assertSame(1000, $runTime->timescale);
        self::assertSame(5000, $runTime->value);
        self::assertSame(1, $runTime->flags);
    }

    /**
     * Accepts null runtime values.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $runTime = new RunTime(
            epoch: null,
            timescale: null,
            value: null,
            flags: null,
        );

        self::assertNull($runTime->epoch);
        self::assertNull($runTime->timescale);
        self::assertNull($runTime->value);
        self::assertNull($runTime->flags);
    }

    /**
     * Stores runtime values across different timescales.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function handlesVariousTimescales(): void
    {
        $runTime = new RunTime(
            epoch: 0,
            timescale: 600,
            value: 3000,
            flags: 0,
        );

        self::assertSame(600, $runTime->timescale);
        self::assertSame(3000, $runTime->value);
    }
}
