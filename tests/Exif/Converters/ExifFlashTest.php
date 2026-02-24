<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\ExifFlash;
use MagicSunday\ImageMeta\Value\Enum\FlashFunction;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\FlashReturn;
use MagicSunday\ImageMeta\Value\FlashInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ExifFlash decoding of EXIF flash bitfields into FlashInfo values.
 * It verifies flag combinations for fired state, mode, and return detection.
 * The suite covers reserved values and null inputs for safe failure behavior.
 * This ensures flash metadata is normalized consistently from raw EXIF values.
 *
 * @internal
 */
#[CoversClass(ExifFlash::class)]
#[UsesClass(FlashInfo::class)]
final class ExifFlashTest extends TestCase
{
    /**
     * Builds flash info from EXIF bitfields.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function createsInstanceFromExifBitField(): void
    {
        $info = ExifFlash::fromExifValue(127);

        self::assertNotNull($info);
        self::assertTrue($info->fired);
        self::assertSame(FlashMode::Auto, $info->mode);
        self::assertSame(FlashReturn::ReturnDetected, $info->returnDetection);
        self::assertSame(FlashFunction::Absent, $info->functionPresence);
        self::assertTrue($info->redEyeReduction);
    }

    /**
     * Preserves reserved return-detection values in flash info.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function exposesReservedReturnDetection(): void
    {
        $info = ExifFlash::fromExifValue(2);

        self::assertNotNull($info);
        self::assertFalse($info->fired);
        self::assertSame(FlashMode::Unknown, $info->mode);
        self::assertSame(FlashReturn::Reserved, $info->returnDetection);
        self::assertSame(FlashFunction::Present, $info->functionPresence);
        self::assertFalse($info->redEyeReduction);
    }

    /**
     * Returns null when ExifFlash::fromExifValue(null) is unavailable or invalid.
     * This ensures optional metadata paths fail safely.
     */
    #[Test]
    public function returnsNullWhenNoValueIsProvided(): void
    {
        self::assertNull(ExifFlash::fromExifValue(null));
    }
}
