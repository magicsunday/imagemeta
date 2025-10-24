<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;

/**
 * Placeholder resolver for maker note specific fields. The current dataset only exposes vendor metadata,
 * therefore all lookups gracefully return null.
 */
final readonly class MakerNotesResolver
{
    public function __construct(private ?MakerNotesMetadata $metadata)
    {
    }

    /**
     * Returns null until dedicated maker notes decoders are implemented.
     */
    public function string(string $key): ?string
    {
        if (!$this->metadata instanceof MakerNotesMetadata) {
            return null;
        }

        return match ($key) {
            'vendor' => $this->metadata->vendor(),
            'sha1'   => $this->metadata->sha1(),
            default  => null,
        };
    }

    /**
     * Returns null until dedicated maker notes decoders are implemented.
     */
    public function int(string $key): ?int
    {
        if (!$this->metadata instanceof MakerNotesMetadata) {
            return null;
        }

        return match ($key) {
            'length' => $this->metadata->length(),
            default  => null,
        };
    }

    /**
     * Returns null until dedicated maker notes decoders are implemented.
     */
    public function bool(string $key): ?bool
    {
        if (!$this->metadata instanceof MakerNotesMetadata) {
            return null;
        }

        return match ($key) {
            'present' => true,
            default   => null,
        };
    }
}
