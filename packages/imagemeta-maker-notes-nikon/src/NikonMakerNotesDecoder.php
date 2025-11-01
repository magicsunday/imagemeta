<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Nikon;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;

final class NikonMakerNotesDecoder implements MakerNotesDecoderInterface
{
    public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
    {
        return new MakerNotesRecord('Nikon', strlen($raw), sha1($raw));
    }
}
