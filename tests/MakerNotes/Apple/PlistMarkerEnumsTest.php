<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\PlistMarkerType;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistSimpleMarker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the PlistMarkerType and PlistSimpleMarker enums expose the expected cases and values.
 */
final class PlistMarkerEnumsTest extends TestCase
{
    #[Test]
    public function plistMarkerTypeEnumExistsWithExpectedCases(): void
    {
        $actual = [];

        foreach (PlistMarkerType::cases() as $case) {
            $actual[$case->name] = $case->value;
        }

        self::assertSame(
            [
                'Simple'     => 0x0,
                'Integer'    => 0x1,
                'Real'       => 0x2,
                'Date'       => 0x3,
                'Data'       => 0x4,
                'Ascii'      => 0x5,
                'Unicode'    => 0x6,
                'Utf8'       => 0x7,
                'Uid'        => 0x8,
                'Array'      => 0xA,
                'Set'        => 0xB,
                'Dictionary' => 0xD,
            ],
            $actual,
        );
    }

    #[Test]
    public function plistSimpleMarkerEnumExistsWithExpectedCases(): void
    {
        $actual = [];

        foreach (PlistSimpleMarker::cases() as $case) {
            $actual[$case->name] = $case->value;
        }

        self::assertSame(
            [
                'Null'    => 0x0,
                'False'   => 0x8,
                'True'    => 0x9,
                'Url'     => 0xC,
                'BaseUrl' => 0xD,
                'Uuid'    => 0xE,
                'Fill'    => 0xF,
            ],
            $actual,
        );
    }
}
