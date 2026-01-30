<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\ExifFlash;
use MagicSunday\ImageMeta\Value\FlashInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExifFlash::class)]
#[UsesClass(FlashInfo::class)]
final class ExifFlashTest extends TestCase
{
    /**
     * Verifies that $info is not null.
     *
     * @return void
     */
    #[Test]
    public function createsInstanceFromExifBitField(): void
    {
        $info = ExifFlash::fromExifValue(127);

        self::assertNotNull($info);
        self::assertTrue($info->fired);
        self::assertSame(FlashMode::AUTO, $info->mode);
        self::assertSame(FlashReturn::RETURN_DETECTED, $info->returnDetection);
        self::assertSame(FlashFunction::ABSENT, $info->functionPresence);
        self::assertTrue($info->redEyeReduction);
    }

    /**
     * Verifies that $info is not null.
     *
     * @return void
     */
    #[Test]
    public function exposesReservedReturnDetection(): void
    {
        $info = ExifFlash::fromExifValue(2);

        self::assertNotNull($info);
        self::assertFalse($info->fired);
        self::assertSame(FlashMode::UNKNOWN, $info->mode);
        self::assertSame(FlashReturn::RESERVED, $info->returnDetection);
        self::assertSame(FlashFunction::PRESENT, $info->functionPresence);
        self::assertFalse($info->redEyeReduction);
    }

    /**
     * Verifies that ExifFlash::fromExifValue(null) is null.
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenNoValueIsProvided(): void
    {
        self::assertNull(ExifFlash::fromExifValue(null));
    }
}
