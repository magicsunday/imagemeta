<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use InvalidArgumentException;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ExifRationalList with ordered collections of ExifRational instances.
 * It confirms round-tripping via toArray() preserves identity and order.
 * The suite rejects associative arrays and non-rational elements with exceptions.
 * This keeps rational EXIF sequences strict and consistent.
 *
 * @internal
 */
#[UsesClass(ExifRational::class)]
#[CoversClass(ExifRationalList::class)]
final class ExifRationalListTest extends TestCase
{
    /**
     * Builds a list of ExifRational objects and verifies round-tripping via toArray().
     * Confirms the list preserves object identity and order.
     */
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

    /**
     * Supplies an associative array instead of a numeric list of rationals.
     * Ensures the constructor rejects non-list input and raises an InvalidArgumentException.
     */
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

    /**
     * Mixes an ExifRational with a scalar to violate element type requirements.
     * Verifies the constructor validates element types and reports the misuse.
     */
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
