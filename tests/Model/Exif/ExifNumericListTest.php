<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use InvalidArgumentException;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Model\Exif\ExifNumericList
 */
#[CoversClass(ExifNumericList::class)]
#[UsesClass(UInt64::class)]
final class ExifNumericListTest extends TestCase
{
    #[Test]
    public function acceptsListOfNumericValues(): void
    {
        $values = [
            1,
            2.5,
            UInt64::fromInt(3),
        ];

        $list = new ExifNumericList($values);

        self::assertSame($values, $list->toArray());
    }

    #[Test]
    public function rejectsNonListInput(): void
    {
        $values = [
            'first' => 1,
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Numeric EXIF values must form a list.');

        // @phpstan-ignore-next-line: associative array passed intentionally to assert runtime validation.
        new ExifNumericList($values);
    }

    #[Test]
    public function rejectsUnsupportedComponents(): void
    {
        $values = [
            1,
            'two',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Numeric EXIF lists may only contain integers, floats, or UInt64 values.');

        // @phpstan-ignore-next-line: string element passed intentionally to assert runtime validation.
        new ExifNumericList($values);
    }
}
