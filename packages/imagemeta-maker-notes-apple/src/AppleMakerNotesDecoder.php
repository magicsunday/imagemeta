<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;

final class AppleMakerNotesDecoder implements MakerNotesDecoderInterface
{
    public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
    {
        return new MakerNotesRecord('Apple', strlen($raw), sha1($raw));
    }
}
