<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeDataType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the QuickTimeDataType enum exposes the expected data type cases and values.
 */
final class QuickTimeDataTypeTest extends TestCase
{
    #[Test]
    public function enumExistsAndExposesExpectedQuickTimeDataTypeValues(): void
    {
        $actual = [];
        foreach (QuickTimeDataType::cases() as $case) {
            $actual[$case->name] = $case->value;
        }

        self::assertSame(
            [
                'Utf8'           => 0x01,
                'Utf16'          => 0x02,
                'ShiftJis'       => 0x03,
                'Utf8Sort'       => 0x04,
                'Utf16Sort'      => 0x05,
                'MacRoman'       => 0x07,
                'JpegWrapper'    => 0x0D,
                'PngWrapper'     => 0x0E,
                'SignedInt'      => 0x15,
                'UnsignedInt'    => 0x16,
                'Float32'        => 0x17,
                'Float64'        => 0x18,
                'BmpWrapper'     => 0x1B,
                'NestedMetadata' => 0x1C,
            ],
            $actual,
        );
    }
}
