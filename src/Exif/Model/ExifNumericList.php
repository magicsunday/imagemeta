<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Model;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;

use function array_all;
use function array_is_list;
use function is_float;
use function is_int;

/**
 * Represents a list of numeric EXIF values.
 */
final readonly class ExifNumericList
{
    /**
     * @var list<int|float|UInt64>
     */
    public array $values;

    /**
     * @param array<int|string, bool|float|int|string|UInt64> $values Ordered list of numeric components.
     */
    public function __construct(array $values)
    {
        $this->assertList($values);
        $this->assertNumericValues($values);
        $this->values = $values;
    }

    /**
     * Returns the list of numeric values as a plain array.
     *
     * @return list<int|float|UInt64>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @param array<int|string, bool|float|int|string|UInt64> $values
     *
     * @phpstan-assert list<int|float|UInt64> $values
     */
    private function assertList(array $values): void
    {
        if (array_is_list($values)) {
            return;
        }

        throw new ParseError('Numeric EXIF values must form a list.', 1865);
    }

    /**
     * Validates that all values are numeric (int, float, or UInt64).
     *
     * @param list<bool|float|int|string|UInt64> $values
     */
    private function assertNumericValues(array $values): void
    {
        if (!array_all(
            $values,
            static fn (bool|float|int|string|UInt64 $value): bool => is_int($value) || is_float($value) || $value instanceof UInt64,
        )) {
            throw new ParseError('Numeric EXIF lists may only contain integers, floats, or UInt64 values.', 1866);
        }
    }
}
