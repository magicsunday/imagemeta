<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Canon;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;

final class CanonMakerNotesDecoder implements MakerNotesDecoderInterface
{
    public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
    {
        return new MakerNotesRecord('Canon', strlen($raw), sha1($raw));
    }
}
