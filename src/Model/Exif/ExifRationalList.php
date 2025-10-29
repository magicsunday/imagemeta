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
use TypeError;

use function array_is_list;
use function array_map;

/**
 * Represents a list of rational EXIF values.
 */
final readonly class ExifRationalList
{
    /**
     * @var list<ExifRational>
     */
    public array $values;

    /**
     * @param list<ExifRational> $values Ordered list of rational components.
     *
     * @phpstan-param array<int, ExifRational> $values Ordered list of rational components.
     *
     * @psalm-param list<ExifRational> $values
     *
     * @throws InvalidArgumentException If the provided values are not a sequential list of ExifRational objects.
     */
    public function __construct(array $values)
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException('Rational EXIF values must form a list.');
        }

        try {
            $values = array_map(
                static function (ExifRational $value): ExifRational {
                    return $value;
                },
                $values
            );
        } catch (TypeError $exception) {
            throw new InvalidArgumentException('Rational EXIF lists may only contain ExifRational instances.', 0, $exception);
        }

        /** @var list<ExifRational> $values */
        $this->values = $values;
    }

    /**
     * Returns the list of rational values as a plain array.
     *
     * @return list<ExifRational>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
