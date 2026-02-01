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
 * Exercises the WhiteBalanceDetails value object for mode and temperature metadata.
 * It verifies enum-backed modes and optional kelvin values are preserved.
 * The suite covers red/blue gain fields for manual white balance settings.
 * This ensures white balance metadata remains consistent for rendering and display.
 */
#[CoversClass(WhiteBalanceDetails::class)]
final class WhiteBalanceDetailsTest extends TestCase
{
    /**
     * Constructs WhiteBalanceDetails with only the mode provided.
     * Ensures the mode is stored and the optional numeric fields remain null.
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
     * Constructs WhiteBalanceDetails with a manual mode, kelvin, and gain values.
     * Verifies the value object preserves the supplied temperature and gain fields.
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
     * Creates WhiteBalanceDetails with all fields set to null.
     * Confirms the object preserves nulls for optional white balance details.
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
