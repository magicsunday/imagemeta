<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TiffFieldTypeTest extends TestCase
{
    #[Test]
    public function enumExistsAndExposesExpectedFieldTypeValues(): void
    {
        $class = 'MagicSunday\\ImageMeta\\Parse\\Tiff\\TiffFieldType';

        self::assertTrue(enum_exists($class));

        if (!enum_exists($class)) {
            return;
        }

        self::assertSame(1, $class::Byte->value);
        self::assertSame(2, $class::Ascii->value);
        self::assertSame(3, $class::Short->value);
        self::assertSame(4, $class::Long->value);
        self::assertSame(5, $class::Rational->value);
        self::assertSame(16, $class::Long8->value);
        self::assertSame(18, $class::Ifd8->value);
    }
}

