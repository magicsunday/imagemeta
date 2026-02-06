<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\QuickTime;

/**
 * Represents a single data value atom from a QuickTime metadata item.
 *
 * QuickTime File Format 2012, "Value Atom" (p. 139): each metadata item may
 * contain multiple data value atoms with different type and locale indicators.
 * The locale indicator is a 32-bit field where the upper 16 bits encode a
 * country code and the lower 16 bits encode a language code.
 */
final readonly class QuickTimeDataAtom
{
    /**
     * @param int                   $typeIndicator Well-known type code (bits 0–23 of the type indicator field).
     * @param int                   $locale        32-bit locale indicator (country << 16 | language).
     * @param string|int|float|bool $value         Decoded payload value.
     */
    public function __construct(
        public int $typeIndicator,
        public int $locale,
        public string|int|float|bool $value,
    ) {
    }

    /**
     * Returns the country indicator (upper 16 bits of the locale field).
     */
    public function countryIndicator(): int
    {
        return ($this->locale >> 16) & 0xFFFF;
    }

    /**
     * Returns the language indicator (lower 16 bits of the locale field).
     */
    public function languageIndicator(): int
    {
        return $this->locale & 0xFFFF;
    }
}
