<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\PhotoCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates photographic calculations for hyperfocal distance, crop factor,
 * circle of confusion, and field of view.
 *
 * @internal
 */
#[CoversClass(PhotoCalculator::class)]
final class PhotoCalculatorTest extends TestCase
{
    private PhotoCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PhotoCalculator();
    }

    /**
     * Calculates the hyperfocal distance for known parameters.
     */
    #[Test]
    public function calcHyperfocalMReturnsCorrectValue(): void
    {
        // f=50mm, f/8, CoC=0.03mm -> H = 50^2/(8*0.03) + 50 = 2500/0.24 + 50 = 10466.67 + 50 = 10516.67mm -> 10.517m
        self::assertEqualsWithDelta(10.517, $this->calculator->calcHyperfocalM(50.0, 8.0, 0.03), 0.001);
    }

    /**
     * Returns null for zero focal length.
     */
    #[Test]
    public function calcHyperfocalMReturnsNullForZeroFocalLength(): void
    {
        self::assertNull($this->calculator->calcHyperfocalM(0.0, 8.0, 0.03));
    }

    /**
     * Returns null for null focal length.
     */
    #[Test]
    public function calcHyperfocalMReturnsNullForNullFocalLength(): void
    {
        self::assertNull($this->calculator->calcHyperfocalM(null, 8.0, 0.03));
    }

    /**
     * Returns null for zero f-number.
     */
    #[Test]
    public function calcHyperfocalMReturnsNullForZeroFNumber(): void
    {
        self::assertNull($this->calculator->calcHyperfocalM(50.0, 0.0, 0.03));
    }

    /**
     * Returns null for null circle of confusion.
     */
    #[Test]
    public function calcHyperfocalMReturnsNullForNullCoC(): void
    {
        self::assertNull($this->calculator->calcHyperfocalM(50.0, 8.0, null));
    }

    /**
     * Returns null for zero circle of confusion.
     */
    #[Test]
    public function calcHyperfocalMReturnsNullForZeroCoC(): void
    {
        self::assertNull($this->calculator->calcHyperfocalM(50.0, 8.0, 0.0));
    }

    /**
     * Returns null for negative focal length.
     */
    #[Test]
    public function calcHyperfocalMReturnsNullForNegativeFocalLength(): void
    {
        self::assertNull($this->calculator->calcHyperfocalM(-10.0, 8.0, 0.03));
    }

    /**
     * Calculates crop factor from 35mm equivalent and actual focal length.
     */
    #[Test]
    public function calcCropFactorReturnsCorrectValue(): void
    {
        // 50mm on APS-C: 35mm equiv = 75mm, crop = 75/50 = 1.5
        self::assertEqualsWithDelta(1.5, $this->calculator->calcCropFactor(75, 50.0), 0.0001);
    }

    /**
     * Returns null for zero actual focal length.
     */
    #[Test]
    public function calcCropFactorReturnsNullForZeroFocalLength(): void
    {
        self::assertNull($this->calculator->calcCropFactor(75, 0.0));
    }

    /**
     * Returns null for null 35mm equivalent.
     */
    #[Test]
    public function calcCropFactorReturnsNullForNull35mm(): void
    {
        self::assertNull($this->calculator->calcCropFactor(null, 50.0));
    }

    /**
     * Returns null for zero 35mm equivalent.
     */
    #[Test]
    public function calcCropFactorReturnsNullForZero35mm(): void
    {
        self::assertNull($this->calculator->calcCropFactor(0, 50.0));
    }

    /**
     * Returns the full-frame CoC when crop factor is null.
     */
    #[Test]
    public function calcCircleOfConfusionMmReturnsFullFrameForNull(): void
    {
        self::assertEqualsWithDelta(0.030, $this->calculator->calcCircleOfConfusionMm(null), 0.0001);
    }

    /**
     * Calculates CoC adjusted by crop factor.
     */
    #[Test]
    public function calcCircleOfConfusionMmAdjustsByCropFactor(): void
    {
        // Full frame CoC / 1.5 = 0.030 / 1.5 = 0.020
        self::assertEqualsWithDelta(0.020, $this->calculator->calcCircleOfConfusionMm(1.5), 0.0001);
    }

    /**
     * Returns null for zero crop factor.
     */
    #[Test]
    public function calcCircleOfConfusionMmReturnsNullForZeroCropFactor(): void
    {
        self::assertNull($this->calculator->calcCircleOfConfusionMm(0.0));
    }

    /**
     * Returns null for negative crop factor.
     */
    #[Test]
    public function calcCircleOfConfusionMmReturnsNullForNegativeCropFactor(): void
    {
        self::assertNull($this->calculator->calcCircleOfConfusionMm(-1.0));
    }

    /**
     * Calculates diagonal FOV from 35mm equivalent focal length.
     */
    #[Test]
    public function calcFovDegReturnsValueFrom35mmEquiv(): void
    {
        // 50mm on full frame: FOV ≈ 46.8 degrees
        $fov = $this->calculator->calcFovDeg(50, null);

        self::assertNotNull($fov);
        self::assertEqualsWithDelta(46.8, $fov, 0.5);
    }

    /**
     * Calculates diagonal FOV using focal length and crop factor.
     */
    #[Test]
    public function calcFovDegReturnsValueFromFocalLengthAndCropFactor(): void
    {
        // 50mm on APS-C (crop 1.5): sensor diagonal = 43.27/1.5 = 28.84mm
        // FOV = 2*atan(28.84/(2*50)) ≈ 32.2°
        $fov = $this->calculator->calcFovDeg(null, 1.5, 50.0);

        self::assertNotNull($fov);
        self::assertEqualsWithDelta(32.2, $fov, 0.5);
    }

    /**
     * Returns null when all parameters are null.
     */
    #[Test]
    public function calcFovDegReturnsNullForAllNull(): void
    {
        self::assertNull($this->calculator->calcFovDeg(null, null));
    }

    /**
     * Calculates horizontal FOV from 35mm equivalent focal length.
     */
    #[Test]
    public function calcHorizontalFovDegReturnsValue(): void
    {
        // 50mm on full frame horizontal: FOV = 2*atan(36/(2*50)) ≈ 39.6°
        $fov = $this->calculator->calcHorizontalFovDeg(50, null);

        self::assertNotNull($fov);
        self::assertEqualsWithDelta(39.6, $fov, 0.5);
    }

    /**
     * Returns null for horizontal FOV when no parameters are provided.
     */
    #[Test]
    public function calcHorizontalFovDegReturnsNullForNoParameters(): void
    {
        self::assertNull($this->calculator->calcHorizontalFovDeg(null, null));
    }

    /**
     * Calculates vertical FOV from 35mm equivalent focal length.
     */
    #[Test]
    public function calcVerticalFovDegReturnsValue(): void
    {
        // 50mm on full frame vertical: FOV = 2*atan(24/(2*50)) ≈ 27.0°
        $fov = $this->calculator->calcVerticalFovDeg(50, null);

        self::assertNotNull($fov);
        self::assertEqualsWithDelta(27.0, $fov, 0.5);
    }

    /**
     * Returns null for vertical FOV when no parameters are provided.
     */
    #[Test]
    public function calcVerticalFovDegReturnsNullForNoParameters(): void
    {
        self::assertNull($this->calculator->calcVerticalFovDeg(null, null));
    }
}
