<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ExifNumericList with mixed numeric inputs such as int, float, and UInt64.
 * It verifies list construction preserves order and returns the same values via toArray().
 * The suite rejects non-list input and invalid element types with clear exceptions.
 * This ensures numeric EXIF arrays remain type-safe and deterministic.
 *
 * @internal
 */
#[UsesClass(UInt64::class)]
#[CoversClass(ExifNumericList::class)]
final class ExifNumericListTest extends TestCase
{
    /**
     * Creates a list containing an int, float, and UInt64 value.
     * Confirms the list accepts mixed numeric types and preserves them in order.
     */
    #[Test]
    public function acceptsListOfNumericValues(): void
    {
        $values = [
            1,
            2.5,
            UInt64::fromInt(3),
        ];

        $list   = new ExifNumericList($values);

        self::assertSame($values, $list->toArray());
    }

    /**
     * Supplies an associative array instead of a numeric list.
     * Ensures the constructor rejects non-list input and throws the expected exception.
     */
    #[Test]
    public function rejectsNonListInput(): void
    {
        $values = [
            'first' => 1,
        ];

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Numeric EXIF values must form a list.');

        new ExifNumericList($values);
    }

    /**
     * Includes a string alongside numeric values to violate type constraints.
     * Verifies the constructor enforces allowed numeric component types and fails fast.
     */
    #[Test]
    public function rejectsUnsupportedComponents(): void
    {
        $values = [
            1,
            'two',
        ];

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Numeric EXIF lists may only contain integers, floats, or UInt64 values.');

        new ExifNumericList($values);
    }
}
