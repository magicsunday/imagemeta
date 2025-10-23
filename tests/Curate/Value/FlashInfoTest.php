<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Value;

use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\FlashInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Value\FlashInfo
 */
final class FlashInfoTest extends TestCase
{
    /**
     * Ensures flash bit fields are decoded into typed information.
     */
    #[Test]
    public function createsInstanceFromExifBitField(): void
    {
        $info = FlashInfo::fromExifValue(127);

        self::assertNotNull($info);
        self::assertTrue($info->fired);
        self::assertSame(FlashMode::AUTO, $info->mode);
        self::assertSame(FlashReturn::DETECTED, $info->returnDetection);
        self::assertSame(FlashFunction::ABSENT, $info->functionPresence);
        self::assertTrue($info->redEyeReduction);
    }

    /**
     * Ensures null is returned when no value is supplied.
     */
    #[Test]
    public function returnsNullWhenNoValueIsProvided(): void
    {
        self::assertNull(FlashInfo::fromExifValue(null));
    }
}
