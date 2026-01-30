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
 * Tests for the RunTime value object (CoreMedia CMTime structure).
 */
#[CoversClass(RunTime::class)]
final class RunTimeTest extends TestCase
{
    /**
     * Verifies that $runTime->epoch equals 0.
     *
     * @return void
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
     * Verifies that $runTime->epoch is null.
     *
     * @return void
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
     * Verifies that $runTime->timescale equals 600.
     *
     * @return void
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
