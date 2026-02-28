<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Parse\Tiff\TiffFieldType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the TiffFieldType enum exposes the expected TIFF field type cases and values.
 */
final class TiffFieldTypeTest extends TestCase
{
    #[Test]
    public function enumExistsAndExposesExpectedFieldTypeValues(): void
    {
        $actual = [];
        foreach (TiffFieldType::cases() as $case) {
            $actual[$case->name] = $case->value;
        }

        self::assertSame(
            [
                'Byte'      => 0x01,
                'Ascii'     => 0x02,
                'Short'     => 0x03,
                'Long'      => 0x04,
                'Rational'  => 0x05,
                'SByte'     => 0x06,
                'Undefined' => 0x07,
                'SShort'    => 0x08,
                'SLong'     => 0x09,
                'SRational' => 0x0A,
                'Float'     => 0x0B,
                'Double'    => 0x0C,
                'Ifd'       => 0x0D,
                'Long8'     => 0x10,
                'SLong8'    => 0x11,
                'Ifd8'      => 0x12,
            ],
            $actual,
        );
    }
}
