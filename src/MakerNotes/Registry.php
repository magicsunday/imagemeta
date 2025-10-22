<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

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
        foreach ($this->decoders as $pref => $dec) {
            if ($pref !== '' && str_starts_with($make, $pref)) {
                return $dec;
            }
        }
        return null;
    }
}
