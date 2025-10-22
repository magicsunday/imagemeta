<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

interface MakerNotesDecoderInterface
{
    /** @return array<string, mixed> decoded map; may be partial */
    public function decode(string $raw, string $make, ?string $model): array;
}
