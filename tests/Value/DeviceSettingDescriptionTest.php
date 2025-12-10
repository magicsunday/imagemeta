<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\DeviceSettingDescription;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DeviceSettingDescription value object.
 */
#[CoversClass(DeviceSettingDescription::class)]
final class DeviceSettingDescriptionTest extends TestCase
{
    #[Test]
    public function constructsWithBasicInfo(): void
    {
        $desc = new DeviceSettingDescription(
            columns: 5,
            rows: 10,
            settings: [],
        );

        self::assertSame(5, $desc->columns);
        self::assertSame(10, $desc->rows);
        self::assertSame([], $desc->settings);
    }

    #[Test]
    public function constructsWithFullInfo(): void
    {
        $desc = new DeviceSettingDescription(
            columns: 3,
            rows: 7,
            settings: ['ISO:100 WB:Auto Sharpness:Normal'],
        );

        self::assertSame(3, $desc->columns);
        self::assertSame(7, $desc->rows);
        self::assertSame(['ISO:100 WB:Auto Sharpness:Normal'], $desc->settings);
    }

    #[Test]
    public function handlesNullSettings(): void
    {
        $desc = new DeviceSettingDescription(
            columns: 1,
            rows: 1,
            settings: [],
        );

        self::assertSame(1, $desc->columns);
        self::assertSame(1, $desc->rows);
        self::assertSame([], $desc->settings);
    }
}
