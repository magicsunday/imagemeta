<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
use Stringable;

use function sprintf;

/**
 * Represents a geographic coordinate with optional hemisphere reference.
 */
final readonly class GpsCoordinate implements Stringable
{
    public float $signed;

    public ?string $reference;

    /**
     * Creates a coordinate when a value is available, or returns null.
     */
    public static function fromNullable(?float $value, ?GpsLatLonRef $reference, bool $isLatitude): ?self
    {
        if ($value === null) {
            return null;
        }

        return new self($value, $reference?->value, $isLatitude);
    }

    /**
     * @param float       $value      Coordinate value.
     * @param string|null $reference  Hemisphere reference.
     * @param bool        $isLatitude True when latitude, false when longitude.
     */
    public function __construct(
        public float $value,
        ?string $reference,
        public bool $isLatitude,
    ) {
        $this->reference = $this->normaliseReference($reference);
        $this->signed    = $this->calculateSigned($this->value, $this->reference, $this->isLatitude);
    }

    /**
     * Formats the coordinate as a human-readable string.
     */
    public function __toString(): string
    {
        $signed = $this->signed;

        if ($this->reference === null) {
            return sprintf('%s°', $this->formatDecimal($signed));
        }

        $formattedValue = $this->formatDecimal(abs($signed));

        return sprintf('%s° %s', $formattedValue, $this->reference);
    }

    /**
     * Normalises the hemisphere reference to a single uppercase character.
     *
     * @param string|null $reference Raw reference value.
     *
     * @return string|null Normalised reference or null.
     */
    private function normaliseReference(?string $reference): ?string
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $normalized = strtoupper($reference);

        return $normalized[0] ?? null;
    }

    /**
     * Computes a signed coordinate value from the reference.
     *
     * @param float       $value      Coordinate magnitude.
     * @param string|null $reference  Hemisphere reference.
     * @param bool        $isLatitude True when latitude, false when longitude.
     *
     * @return float Signed coordinate value.
     */
    private function calculateSigned(float $value, ?string $reference, bool $isLatitude): float
    {
        if ($reference === null) {
            return $value;
        }

        $magnitude = abs($value);

        if ($isLatitude) {
            if ($reference === 'S') {
                return -$magnitude;
            }

            if ($reference === 'N') {
                return $magnitude;
            }

            return $value;
        }

        if ($reference === 'W') {
            return -$magnitude;
        }

        if ($reference === 'E') {
            return $magnitude;
        }

        return $value;
    }

    /**
     * Formats a coordinate value with fixed decimal precision.
     *
     * @param float $value Coordinate value.
     *
     * @return string Formatted decimal string.
     */
    private function formatDecimal(float $value): string
    {
        $formatted = sprintf('%.6F', $value);

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
