<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuickTimeDataTypeTest extends TestCase
{
    #[Test]
    public function enumExistsAndExposesExpectedQuickTimeDataTypeValues(): void
    {
        $class = 'MagicSunday\\ImageMeta\\Parse\\IsoBmff\\QuickTimeDataType';

        self::assertTrue(enum_exists($class));

        if (!enum_exists($class)) {
            return;
        }

        self::assertSame(1, $class::Utf8->value);
        self::assertSame(2, $class::Utf16->value);
        self::assertSame(0x15, $class::SignedInt->value);
        self::assertSame(0x16, $class::UnsignedInt->value);
        self::assertSame(0x17, $class::Float32->value);
        self::assertSame(0x18, $class::Float64->value);
        self::assertSame(0x1C, $class::NestedMetadata->value);
    }
}

