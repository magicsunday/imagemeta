<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Sony;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;

final class SonyMakerNotesDecoder implements MakerNotesDecoderInterface
{
    public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
    {
        return new MakerNotesRecord('Sony', strlen($raw), sha1($raw));
    }
}
