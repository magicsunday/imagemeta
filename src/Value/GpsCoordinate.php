<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use function sprintf;

/**
 * Represents a geographic coordinate with optional hemisphere reference.
 */
final readonly class GpsCoordinate
{
    public float $signed;

    public ?string $reference;

    public function __construct(
        public float $value,
        ?string $reference,
        public bool $isLatitude,
    ) {
        $this->reference = $this->normaliseReference($reference);
        $this->signed    = $this->calculateSigned($this->value, $this->reference, $this->isLatitude);
    }

    public function __toString(): string
    {
        $signed = $this->signed;

        if ($this->reference === null) {
            return sprintf('%s°', $this->formatDecimal($signed));
        }

        $formattedValue = $this->formatDecimal(abs($signed));

        return sprintf('%s° %s', $formattedValue, $this->reference);
    }

    private function normaliseReference(?string $reference): ?string
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $normalized = strtoupper($reference);

        return $normalized[0] ?? null;
    }

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

    private function formatDecimal(float $value): string
    {
        $formatted = sprintf('%.6F', $value);

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
