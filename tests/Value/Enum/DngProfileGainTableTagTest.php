<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value\Enum;

use MagicSunday\ImageMeta\Value\Enum\DngProfileGainTableTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Value\Enum\DngProfileGainTableTag
 */
#[CoversClass(DngProfileGainTableTag::class)]
final class DngProfileGainTableTagTest extends TestCase
{
    #[Test]
    public function exposesLabelForGainTableMap(): void
    {
        $tag = DngProfileGainTableTag::fromTagId(0xC7A4);

        self::assertNotNull($tag);
        self::assertSame('ProfileGainTableMap', $tag->label());
    }

    #[Test]
    public function returnsNullForUnknownTag(): void
    {
        self::assertNull(DngProfileGainTableTag::fromTagId(0xC7A5));
    }
}
