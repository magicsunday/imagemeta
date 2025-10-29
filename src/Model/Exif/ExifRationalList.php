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
     */
    public function __construct(array $values)
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException('Rational EXIF values must form a list.');
        }

        foreach ($values as $value) {
            if (!$value instanceof ExifRational) {
                throw new InvalidArgumentException('Rational EXIF lists may only contain ExifRational instances.');
            }
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
