<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Riff;

use MagicSunday\ImageMeta\Model\Riff\RiffAviHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RiffAviHeader::class)]
final class RiffAviHeaderTest extends TestCase
{
    #[Test]
    public function storesAviHeaderFields(): void
    {
        $header = new RiffAviHeader(
            microSecPerFrame: 33333,
            width: 1920,
            height: 1080,
            totalFrames: 3000,
            streams: 2,
        );

        self::assertSame(33333, $header->microSecPerFrame);
        self::assertSame(1920, $header->width);
        self::assertSame(1080, $header->height);
        self::assertSame(3000, $header->totalFrames);
        self::assertSame(2, $header->streams);
    }
}
