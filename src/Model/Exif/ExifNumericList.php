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

use function array_is_list;
use function is_float;
use function is_int;

/**
 * Represents a list of numeric EXIF values.
 */
final readonly class ExifNumericList
{
    /**
     * @var list<int|float>
     */
    public array $values;

    /**
     * @param array<int|string, mixed> $values Ordered list of numeric components.
     */
    public function __construct(array $values)
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException('Numeric EXIF values must form a list.');
        }

        foreach ($values as $value) {
            if (is_int($value)) {
                continue;
            }

            if (is_float($value)) {
                continue;
            }

            throw new InvalidArgumentException('Numeric EXIF lists may only contain integers or floats.');
        }

        /** @var list<int|float> $values */
        $this->values = $values;
    }

    /**
     * Returns the list of numeric values as a plain array.
     *
     * @return list<int|float>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
