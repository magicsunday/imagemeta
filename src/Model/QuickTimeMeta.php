<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

final class QuickTimeMeta
{
    /** @param array<string, string|int|float|bool> $keys */
    public function __construct(public readonly array $keys) {}

    public function contentIdentifier(): ?string
    {
        $k1 = 'com.apple.quicktime.content.identifier';
        $k2 = 'com.apple.quicktime.live-photo.still-image-display-time'; // Beispiel, weitere Keys möglich
        return isset($this->keys[$k1]) ? (string)$this->keys[$k1] : null;
    }
}
