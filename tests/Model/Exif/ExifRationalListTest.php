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
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[UsesClass(ExifRational::class)]
#[CoversClass(ExifRationalList::class)]
final class ExifRationalListTest extends TestCase
{
    #[Test]
    public function acceptsListOfExifRationalValues(): void
    {
        $values = [
            new ExifRational(1, 2),
            new ExifRational(3, 4),
        ];

        $list = new ExifRationalList($values);

        self::assertSame($values, $list->toArray());
    }

    #[Test]
    public function rejectsNonListInput(): void
    {
        $values = [
            'first' => new ExifRational(1, 2),
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rational EXIF values must form a list.');

        // @phpstan-ignore-next-line: associative array passed intentionally to assert runtime validation.
        new ExifRationalList($values);
    }

    #[Test]
    public function rejectsNonExifRationalElements(): void
    {
        $values = [
            new ExifRational(1, 2),
            42,
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rational EXIF lists may only contain ExifRational instances.');

        // @phpstan-ignore-next-line: scalar element passed intentionally to assert runtime validation.
        new ExifRationalList($values);
    }
}
