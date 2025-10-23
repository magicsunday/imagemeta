<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\ValueConverters;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\FlashInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Core\ValueConverters
 */
final class ValueConvertersTest extends TestCase
{
    #[Test]
    public function convertsRationalLikeStructures(): void
    {
        self::assertSame(0.5, ValueConverters::rationalToFloat([1, 2]));
        self::assertSame(10.0, ValueConverters::rationalToFloat(10));
        self::assertNull(ValueConverters::rationalToFloat([1, 0]));
    }

    #[Test]
    public function convertsApexToFNumber(): void
    {
        self::assertEqualsWithDelta(2.8, ValueConverters::apexToFNumber(log(2.8 ** 2, 2.0)), 0.0001);
    }

    #[Test]
    public function decodesFlashBitField(): void
    {
        $flash = ValueConverters::flashFromShort(0x49);
        self::assertInstanceOf(FlashInfo::class, $flash);
        self::assertTrue($flash->fired);
        self::assertSame(FlashMode::COMPULSORY_FIRE, $flash->mode);
        self::assertSame(FlashReturn::NO_STROBE_DETECTION, $flash->returnDetection);
        self::assertTrue($flash->redEyeReduction);
    }

    #[Test]
    public function convertsGpsSpeedsToMetresPerSecond(): void
    {
        self::assertEqualsWithDelta(13.8889, ValueConverters::gpsSpeedToMs(50.0, 'K'), 0.0001);
        self::assertEqualsWithDelta(22.352, ValueConverters::gpsSpeedToMs(50.0, 'M'), 0.001);
        self::assertEqualsWithDelta(25.722, ValueConverters::gpsSpeedToMs(50.0, 'N'), 0.001);
    }

    #[Test]
    public function parsesOffsetStrings(): void
    {
        $zone = ValueConverters::parseOffset('+01:30');
        self::assertNotNull($zone);
        self::assertSame('+01:30', $zone->getName());
        self::assertNull(ValueConverters::parseOffset('invalid'));
    }

    #[Test]
    public function normalisesSubjectArea(): void
    {
        $rect = ValueConverters::subjectAreaToRect([100, 200, 50, 60]);
        self::assertSame(['x' => 100, 'y' => 200, 'w' => 50, 'h' => 60], $rect);

        $circle = ValueConverters::subjectAreaToRect([100, 200, 25]);
        self::assertSame(['x' => 75, 'y' => 175, 'w' => 50, 'h' => 50], $circle);
    }

    #[Test]
    public function calculatesDerivedExposureValues(): void
    {
        $ev = ValueConverters::calcEv100(0.01, 2.8, 200);
        self::assertEqualsWithDelta(8.6147, $ev, 0.0001);

        $hyperfocal = ValueConverters::calcHyperfocalM(50.0, 2.0, 0.029);
        self::assertEqualsWithDelta(43.153, $hyperfocal, 0.001);

        $fov = ValueConverters::calcFovDeg(50, null);
        self::assertEqualsWithDelta(46.8, $fov, 0.1);
    }
}
