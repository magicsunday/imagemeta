<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Derived;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Derived value object holding calculated exposure and optics metrics.
 * It verifies EV100, hyperfocal distance, and circle of confusion values are stored.
 * The suite covers field-of-view angles and 35mm equivalents when present.
 * This ensures derived calculations remain intact for presentation and analysis.
 */
#[CoversClass(Derived::class)]
final class DerivedTest extends TestCase
{
    /**
     * Stores exposure value metrics.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithExposureValue(): void
    {
        $derived = new Derived(
            ev100: 10.5,
            hyperfocalDistanceMetres: null,
            circleOfConfusionMm: null,
            fieldOfViewDiagonalDeg: null,
            fieldOfViewHorizontalDeg: null,
            fieldOfViewVerticalDeg: null,
            equivalent35mm: null,
            cropFactor: null,
        );

        self::assertSame(10.5, $derived->ev100);
    }

    /**
     * Stores all derived metrics when provided.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithAllDerivedMetrics(): void
    {
        $derived = new Derived(
            ev100: 12.0,
            hyperfocalDistanceMetres: 5.2,
            circleOfConfusionMm: 0.03,
            fieldOfViewDiagonalDeg: 75.0,
            fieldOfViewHorizontalDeg: 65.0,
            fieldOfViewVerticalDeg: 50.0,
            equivalent35mm: 50,
            cropFactor: 1.5,
        );

        self::assertSame(12.0, $derived->ev100);
        self::assertSame(5.2, $derived->hyperfocalDistanceMetres);
        self::assertSame(0.03, $derived->circleOfConfusionMm);
        self::assertSame(75.0, $derived->fieldOfViewDiagonalDeg);
        self::assertSame(65.0, $derived->fieldOfViewHorizontalDeg);
        self::assertSame(50.0, $derived->fieldOfViewVerticalDeg);
        self::assertSame(50, $derived->equivalent35mm);
        self::assertSame(1.5, $derived->cropFactor);
    }

    /**
     * Accepts null derived metrics.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $derived = new Derived(
            ev100: null,
            hyperfocalDistanceMetres: null,
            circleOfConfusionMm: null,
            fieldOfViewDiagonalDeg: null,
            fieldOfViewHorizontalDeg: null,
            fieldOfViewVerticalDeg: null,
            equivalent35mm: null,
            cropFactor: null,
        );

        self::assertNull($derived->ev100);
        self::assertNull($derived->hyperfocalDistanceMetres);
        self::assertNull($derived->circleOfConfusionMm);
        self::assertNull($derived->fieldOfViewDiagonalDeg);
        self::assertNull($derived->equivalent35mm);
        self::assertNull($derived->cropFactor);
    }

    /**
     * Stores full-frame equivalents and crop factors.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
    #[Test]
    public function handlesFullFrameEquivalent(): void
    {
        $derived = new Derived(
            ev100: null,
            hyperfocalDistanceMetres: null,
            circleOfConfusionMm: null,
            fieldOfViewDiagonalDeg: null,
            fieldOfViewHorizontalDeg: null,
            fieldOfViewVerticalDeg: null,
            equivalent35mm: 50,
            cropFactor: 1.0,
        );

        self::assertSame(50, $derived->equivalent35mm);
        self::assertSame(1.0, $derived->cropFactor);
    }
}
