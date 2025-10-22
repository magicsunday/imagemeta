<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

final class AppleDecoder implements MakerNotesDecoderInterface
{
    public function decode(string $raw, string $make, ?string $model): array
    {
        return [
            '_vendor' => 'Apple',
            '_length' => strlen($raw),
            '_sha1'   => sha1($raw, false),
        ];
    }
}
