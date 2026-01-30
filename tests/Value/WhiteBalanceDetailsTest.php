<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the WhiteBalanceDetails value object.
 */
#[CoversClass(WhiteBalanceDetails::class)]
final class WhiteBalanceDetailsTest extends TestCase
{
    /**
     * Verifies that $wb->mode equals WhiteBalance::AUTO.
     *
     * @return void
     */
    #[Test]
    public function constructsWithMode(): void
    {
        $wb = new WhiteBalanceDetails(
            mode: WhiteBalance::AUTO,
            kelvin: null,
            rgGain: null,
            bgGain: null,
        );

        self::assertSame(WhiteBalance::AUTO, $wb->mode);
    }

    /**
     * Verifies that $wb->mode equals WhiteBalance::MANUAL.
     *
     * @return void
     */
    #[Test]
    public function constructsWithColorTemperature(): void
    {
        $wb = new WhiteBalanceDetails(
            mode: WhiteBalance::MANUAL,
            kelvin: 5500,
            rgGain: 1.2,
            bgGain: 0.8,
        );

        self::assertSame(WhiteBalance::MANUAL, $wb->mode);
        self::assertSame(5500, $wb->kelvin);
        self::assertSame(1.2, $wb->rgGain);
        self::assertSame(0.8, $wb->bgGain);
    }

    /**
     * Verifies that $wb->mode is null.
     *
     * @return void
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $wb = new WhiteBalanceDetails(
            mode: null,
            kelvin: null,
            rgGain: null,
            bgGain: null,
        );

        self::assertNull($wb->mode);
        self::assertNull($wb->kelvin);
        self::assertNull($wb->rgGain);
        self::assertNull($wb->bgGain);
    }
}
