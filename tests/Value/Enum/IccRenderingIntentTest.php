<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value\Enum;

use MagicSunday\ImageMeta\Value\Enum\IccRenderingIntent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Value\Enum\IccRenderingIntent
 */
#[CoversClass(IccRenderingIntent::class)]
final class IccRenderingIntentTest extends TestCase
{
    #[Test]
    public function mapsKnownRenderingIntents(): void
    {
        self::assertSame('Perceptual', IccRenderingIntent::fromProfileHeaderValue(0)?->label());
        self::assertSame('Media-Relative Colorimetric', IccRenderingIntent::fromProfileHeaderValue(1)?->label());
        self::assertSame('Saturation', IccRenderingIntent::fromProfileHeaderValue(2)?->label());
        self::assertSame('ICC-Absolute Colorimetric', IccRenderingIntent::fromProfileHeaderValue(3)?->label());
    }

    #[Test]
    public function returnsNullForUnknownIntent(): void
    {
        self::assertNull(IccRenderingIntent::fromProfileHeaderValue(4));
    }
}
