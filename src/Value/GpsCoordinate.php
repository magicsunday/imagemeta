<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Represents a geographic coordinate with optional hemisphere reference.
 */
final readonly class GpsCoordinate
{
    private float $value;

    private ?string $reference;

    private bool $isLatitude;

    public function __construct(float $value, ?string $reference, bool $isLatitude)
    {
        $this->value = $value;
        $this->reference = $this->normaliseReference($reference);
        $this->isLatitude = $isLatitude;
    }

    public function value(): float
    {
        return $this->value;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function isLatitude(): bool
    {
        return $this->isLatitude;
    }

    public function signed(): float
    {
        if ($this->reference === null) {
            return $this->value;
        }

        $magnitude = abs($this->value);

        if ($this->isLatitude) {
            if ($this->reference === 'S') {
                return -$magnitude;
            }

            if ($this->reference === 'N') {
                return $magnitude;
            }

            return $this->value;
        }

        if ($this->reference === 'W') {
            return -$magnitude;
        }

        if ($this->reference === 'E') {
            return $magnitude;
        }

        return $this->value;
    }

    public function __toString(): string
    {
        $signed = $this->signed();

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

    private function formatDecimal(float $value): string
    {
        $formatted = sprintf('%.6F', $value);

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
