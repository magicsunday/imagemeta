<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

use function array_find;
use function str_starts_with;
use function strtolower;

/**
 * Registry that stores maker note decoders by vendor prefixes and allows lookups.
 */
final class Registry
{
    /** @var array<string, MakerNotesDecoderInterface> make prefix => decoder */
    private array $decoders = [];

    public function register(string $makePrefix, MakerNotesDecoderInterface $decoder): void
    {
        $this->decoders[strtolower($makePrefix)] = $decoder;
    }

    public function find(string $make): ?MakerNotesDecoderInterface
    {
        $make = strtolower($make);

        return array_find(
            $this->decoders,
            static fn (MakerNotesDecoderInterface $decoder, string $prefix): bool => $prefix !== ''
                && str_starts_with($make, $prefix)
        );
    }
}
