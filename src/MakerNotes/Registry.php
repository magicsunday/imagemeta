<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

/**
 * Registry that stores maker note decoders by vendor prefixes and allows lookups.
 */
final class Registry
{
    /** @var array<string, MakerNotesDecoderInterface> make prefix => decoder */
    private array $decoders = [];

    /**
     * Registers a decoder instance for the given case-insensitive make prefix.
     *
     * @param string                     $makePrefix Prefix of the camera make used to select the decoder.
     * @param MakerNotesDecoderInterface $decoder    Decoder responsible for handling maker note metadata.
     */
    public function register(string $makePrefix, MakerNotesDecoderInterface $decoder): void
    {
        $this->decoders[strtolower($makePrefix)] = $decoder;
    }

    /**
     * Finds the first decoder whose registered prefix matches the provided make string.
     *
     * @param string $make The camera make string from the metadata.
     *
     * @return MakerNotesDecoderInterface|null The matching decoder or null when no decoder is registered for the make.
     */
    public function find(string $make): ?MakerNotesDecoderInterface
    {
        $make = strtolower($make);
        return array_find(
            $this->decoders,
            fn (MakerNotesDecoderInterface $decoder, int|string $prefix): bool => $prefix !== ''
                && str_starts_with($make, (string) $prefix)
        );
    }
}
