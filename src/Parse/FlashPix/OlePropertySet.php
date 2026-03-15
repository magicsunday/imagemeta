<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\FlashPix;

use DateTimeImmutable;

/**
 * Represents a parsed OLE property set section with codepage and typed property values.
 *
 * @phpstan-type PropertyValue string|int|float|bool|DateTimeImmutable|list<string>|list<int>|null
 */
final readonly class OlePropertySet
{
    /**
     * @param int                       $codepage   Windows codepage identifier (1252 = ANSI, 1200 = UTF-16LE).
     * @param array<int, PropertyValue> $properties PID → typed value map.
     */
    public function __construct(
        public int $codepage,
        private array $properties,
    ) {
    }

    /**
     * Returns the value for the given property identifier, or null if absent.
     *
     * @return PropertyValue
     */
    public function property(int $pid): string|int|float|bool|DateTimeImmutable|array|null
    {
        return $this->properties[$pid] ?? null;
    }

    /**
     * Returns all properties as a PID → value map.
     *
     * @return array<int, PropertyValue>
     */
    public function all(): array
    {
        return $this->properties;
    }
}
