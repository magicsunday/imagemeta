<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

use InvalidArgumentException;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use TypeError;

use function array_is_list;

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
     * @param list<int|float|UInt64> $values Ordered list of numeric components.
     *
     * @phpstan-param array<int, int|float|UInt64> $values Ordered list of numeric components.
     *
     * @psalm-param list<int|float|UInt64> $values
     */
    public function __construct(array $values)
    {
        $this->assertList($values);

        foreach ($values as $value) {
            try {
                $this->assertNumericComponent($value);
            } catch (TypeError $exception) {
                throw new InvalidArgumentException('Numeric EXIF lists may only contain integers, floats, or UInt64 values.', 0, $exception);
            }
        }

        /** @var list<int|float|UInt64> $values */
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
     * @param list<int|float|UInt64> $values
     *
     * @phpstan-param array<int, int|float|UInt64> $values
     *
     * @phpstan-assert list<int|float|UInt64> $values
     */
    private function assertList(array $values): void
    {
        if (array_is_list($values)) {
            return;
        }

        throw new InvalidArgumentException('Numeric EXIF values must form a list.');
    }

    private function assertNumericComponent(int|float|UInt64 $value): void
    {
        // The union type enforces numeric values at the call site. The method body remains intentionally empty.
    }
}
